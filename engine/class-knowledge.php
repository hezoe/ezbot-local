<?php

declare(strict_types=1);

namespace NoviSign\Chatbot;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * 知識ファイル（RAG 索引）の管理。
 * 取り込み（ファイル/URL）→ 本文抽出 → チャンク分割 → 埋め込み → DB 保存、
 * および質問ベクトルに対するコサイン類似度検索。
 */
final class Knowledge
{
	/* ---------------------------------------------------------------------
	 * ソース管理
	 * ------------------------------------------------------------------- */

	/**
	 * ソースを登録（type=url|file）。
	 *
	 * @return int 新規ソースID
	 */
	public static function add_source(string $type, string $ref, string $label = ''): int
	{
		global $wpdb;
		$now = current_time('mysql');
		$wpdb->insert(Database::sources_table(), [
			'type'       => $type === 'file' ? 'file' : 'url',
			'ref'        => $ref,
			'label'      => $label !== '' ? $label : $ref,
			'status'     => 'pending',
			'chunks'     => 0,
			'created_at' => $now,
			'updated_at' => $now,
		]);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array<int, object>
	 */
	public static function list_sources(): array
	{
		global $wpdb;
		$table = Database::sources_table();
		return $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC") ?: [];
	}

	public static function get_source(int $id): ?object
	{
		global $wpdb;
		$table = Database::sources_table();
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
	}

	public static function delete_source(int $id): void
	{
		global $wpdb;
		$wpdb->delete(Database::knowledge_table(), ['source_id' => $id]);
		$wpdb->delete(Database::sources_table(), ['id' => $id]);
	}

	/* ---------------------------------------------------------------------
	 * 取り込み（インデックス化）
	 * ------------------------------------------------------------------- */

	/**
	 * 指定ソースを取り込み、知識チャンクを再生成する。
	 *
	 * @return true|\WP_Error
	 */
	public static function ingest_source(int $source_id)
	{
		$source = self::get_source($source_id);
		if (!$source) {
			return new \WP_Error('nsbot_no_source', 'ソースが見つかりません。');
		}

		if ($source->type === 'url') {
			// URL は「巡回しながら逐次RAG追加」する分割バッチ方式（タイムアウト回避）。
			// 以降の取り込みは nsbot_crawl_batch（cron）で1バッチずつ進む。
			self::start_crawl($source_id, $source);
			return true;
		}

		$text = self::read_file((string) $source->ref);
		if (is_wp_error($text)) {
			self::update_source_status($source_id, 'error', 0, $text->get_error_message());
			return $text;
		}

		$text = trim((string) $text);
		if ($text === '') {
			self::update_source_status($source_id, 'error', 0, '本文が空でした。');
			return new \WP_Error('nsbot_empty', '本文が空でした。');
		}

		$chunks = self::chunk($text);
		if (empty($chunks)) {
			self::update_source_status($source_id, 'error', 0, 'チャンクを生成できませんでした。');
			return new \WP_Error('nsbot_no_chunks', 'チャンクを生成できませんでした。');
		}

		$vectors = Embeddings::embed($chunks, 'document');
		if (is_wp_error($vectors)) {
			self::update_source_status($source_id, 'error', 0, $vectors->get_error_message());
			return $vectors;
		}

		// 既存チャンクを置き換え。
		global $wpdb;
		$wpdb->delete(Database::knowledge_table(), ['source_id' => $source_id]);

		$now = current_time('mysql');
		$stored = 0;
		foreach ($chunks as $i => $chunk) {
			if (!isset($vectors[$i])) {
				continue;
			}
			$wpdb->insert(Database::knowledge_table(), [
				'source_id'    => $source_id,
				'source_type'  => $source->type,
				'source_label' => (string) $source->label,
				'title'        => self::derive_title($chunk),
				'chunk_text'   => $chunk,
				'embedding'    => wp_json_encode($vectors[$i]),
				'active'       => 1,
				'created_at'   => $now,
			]);
			$stored++;
		}

		self::update_source_status($source_id, 'indexed', $stored, null);
		return true;
	}

	/**
	 * 全ソースを再取り込み。
	 *
	 * @return array{ok:int, failed:int}
	 */
	public static function rebuild_all(): array
	{
		$ok = 0;
		$failed = 0;
		foreach (self::list_sources() as $source) {
			$result = self::ingest_source((int) $source->id);
			is_wp_error($result) ? $failed++ : $ok++;
		}
		return ['ok' => $ok, 'failed' => $failed];
	}

	/**
	 * 取り込みをバックグラウンド実行に回す（クロール/埋め込みが重く、同期だと
	 * サーバのタイムアウトに当たるため）。status=queued にして単発cronを予約、即ループバックを促す。
	 */
	public static function queue_ingest(int $id): void
	{
		$source = self::get_source($id);
		if (!$source) {
			return;
		}

		// URL は「巡回しながら逐次RAG追加」する分割バッチ方式で開始（タイムアウト回避）。
		if ($source->type === 'url') {
			self::start_crawl($id, $source);
			return;
		}

		// ファイル等は単発バックグラウンド取り込み。
		global $wpdb;
		$wpdb->update(Database::sources_table(), [
			'status'     => 'queued',
			'message'    => '取り込み中…（バックグラウンド処理）',
			'updated_at' => current_time('mysql'),
		], ['id' => $id]);

		if (!wp_next_scheduled('nsbot_ingest_source', [$id])) {
			wp_schedule_single_event(time(), 'nsbot_ingest_source', [$id]);
		}
		if (function_exists('spawn_cron')) {
			spawn_cron(); // 即時ループバックを促す（待たせない）
		}
	}

	/**
	 * 学習：担当者回答などの Q&A を知識として追加（Phase 2 で利用）。
	 *
	 * @return int|\WP_Error 追加した knowledge ID
	 */
	public static function add_qa(string $question, string $answer, string $label = 'staff-answer')
	{
		$text = "Q: {$question}\nA: {$answer}";
		$vector = Embeddings::embed_one($text, 'document');
		if (is_wp_error($vector)) {
			return $vector;
		}
		global $wpdb;
		$wpdb->insert(Database::knowledge_table(), [
			'source_id'    => 0,
			'source_type'  => 'qa',
			'source_label' => $label,
			'title'        => self::derive_title($question),
			'chunk_text'   => $text,
			'embedding'    => wp_json_encode($vector),
			'active'       => 1,
			'created_at'   => current_time('mysql'),
		]);
		return (int) $wpdb->insert_id;
	}

	/* ---------------------------------------------------------------------
	 * Phase 4：チャンクの個別管理（学習Q&Aの訂正・削除）
	 * ------------------------------------------------------------------- */

	/**
	 * 知識チャンク一覧（source_type で絞り込み可：qa / file / url）。
	 *
	 * @return array<int, object>
	 */
	public static function list_knowledge(string $source_type = '', int $limit = 1000): array
	{
		global $wpdb;
		$t = Database::knowledge_table();
		$cols = 'id, source_id, source_type, source_label, title, chunk_text, active, created_at';
		if ($source_type !== '') {
			return $wpdb->get_results($wpdb->prepare(
				"SELECT {$cols} FROM {$t} WHERE source_type = %s ORDER BY id DESC LIMIT %d",
				$source_type,
				$limit
			)) ?: [];
		}
		return $wpdb->get_results($wpdb->prepare(
			"SELECT {$cols} FROM {$t} ORDER BY id DESC LIMIT %d",
			$limit
		)) ?: [];
	}

	public static function get_chunk(int $id): ?object
	{
		global $wpdb;
		$t = Database::knowledge_table();
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", $id));
	}

	public static function delete_chunk(int $id): void
	{
		global $wpdb;
		$wpdb->delete(Database::knowledge_table(), ['id' => $id]);
	}

	/**
	 * 学習Q&A（source_type=qa）の本文を更新して再埋め込み（誤学習の訂正）。
	 *
	 * @return true|\WP_Error
	 */
	public static function update_qa(int $id, string $question, string $answer)
	{
		$chunk = self::get_chunk($id);
		if (!$chunk || $chunk->source_type !== 'qa') {
			return new \WP_Error('nsbot_not_qa', '対象の学習Q&Aが見つかりません。');
		}
		$text = "Q: {$question}\nA: {$answer}";
		$vector = Embeddings::embed_one($text, 'document');
		if (is_wp_error($vector)) {
			return $vector;
		}
		global $wpdb;
		$wpdb->update(Database::knowledge_table(), [
			'title'      => self::derive_title($question),
			'chunk_text' => $text,
			'embedding'  => wp_json_encode($vector),
		], ['id' => $id]);
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Phase 4：JSON エクスポート/インポート（バックアップ・環境間移行・学習の引き継ぎ）
	 * ------------------------------------------------------------------- */

	/**
	 * 知識ベース全体（ソース＋チャンク＝学習Q&A含む）を配列で書き出す。
	 *
	 * @return array<string, mixed>
	 */
	public static function export_json(): array
	{
		global $wpdb;
		$sources = $wpdb->get_results(
			'SELECT id, type, ref, label, status, chunks, created_at, updated_at FROM ' . Database::sources_table() . ' ORDER BY id ASC',
			ARRAY_A
		) ?: [];
		$knowledge = $wpdb->get_results(
			'SELECT source_id, source_type, source_label, title, chunk_text, embedding, active, created_at FROM ' . Database::knowledge_table() . ' ORDER BY id ASC',
			ARRAY_A
		) ?: [];

		return [
			'format'          => 'nsbot-knowledge',
			'version'         => 1,
			'exported_at'     => current_time('mysql'),
			'embedding_model' => (string) Settings::get('voyage_model', ''),
			'counts'          => ['sources' => count($sources), 'knowledge' => count($knowledge)],
			'sources'         => $sources,
			'knowledge'       => $knowledge,
		];
	}

	/**
	 * エクスポート JSON を取り込む。
	 *  - mode='merge'  ：既存に追加（chunk_text のハッシュで重複排除）
	 *  - mode='replace'：既存を全削除してから取り込み
	 * 旧ソースIDは新IDへ remap（qa は source_id=0 のまま）。
	 *
	 * @param array<string, mixed> $data
	 * @return array{added:int, skipped:int, sources:int, model_warning:?string}|\WP_Error
	 */
	public static function import_json(array $data, string $mode = 'merge')
	{
		if (($data['format'] ?? '') !== 'nsbot-knowledge') {
			return new \WP_Error('nsbot_bad_format', 'NoviSign の知識エクスポート（format=nsbot-knowledge）ではありません。');
		}
		$sources   = is_array($data['sources'] ?? null) ? $data['sources'] : [];
		$knowledge = is_array($data['knowledge'] ?? null) ? $data['knowledge'] : [];

		global $wpdb;
		$kt = Database::knowledge_table();
		$st = Database::sources_table();

		if ($mode === 'replace') {
			$wpdb->query("DELETE FROM {$kt}");
			$wpdb->query("DELETE FROM {$st}");
		}

		// ソースを入れ、旧ID→新IDの対応表を作る。
		$map = [];
		foreach ($sources as $s) {
			$old = (int) ($s['id'] ?? 0);
			$wpdb->insert($st, [
				'type'       => (($s['type'] ?? 'url') === 'file') ? 'file' : 'url',
				'ref'        => (string) ($s['ref'] ?? ''),
				'label'      => (string) ($s['label'] ?? ''),
				'status'     => (string) ($s['status'] ?? 'indexed'),
				'chunks'     => (int) ($s['chunks'] ?? 0),
				'created_at' => (string) ($s['created_at'] ?? current_time('mysql')),
				'updated_at' => current_time('mysql'),
			]);
			$map[$old] = (int) $wpdb->insert_id;
		}

		// merge 時のみ重複排除（replace は完全復元なので、同一文面の別チャンクも含めそのまま入れる）。
		$dedup = ($mode !== 'replace');
		$existing = [];
		if ($dedup) {
			foreach ((array) $wpdb->get_col("SELECT chunk_text FROM {$kt}") as $ct) {
				$existing[md5((string) $ct)] = true;
			}
		}

		$added = 0;
		$skipped = 0;
		foreach ($knowledge as $row) {
			$text = (string) ($row['chunk_text'] ?? '');
			if ($text === '') {
				continue;
			}
			if ($dedup) {
				$h = md5($text);
				if (isset($existing[$h])) {
					$skipped++;
					continue;
				}
				$existing[$h] = true;
			}

			$old_sid = (int) ($row['source_id'] ?? 0);
			$sid = $old_sid === 0 ? 0 : ($map[$old_sid] ?? 0);
			$emb = $row['embedding'] ?? null;

			$wpdb->insert($kt, [
				'source_id'    => $sid,
				'source_type'  => (string) ($row['source_type'] ?? 'url'),
				'source_label' => (string) ($row['source_label'] ?? ''),
				'title'        => (string) ($row['title'] ?? ''),
				'chunk_text'   => $text,
				'embedding'    => is_string($emb) ? $emb : wp_json_encode($emb),
				'active'       => (int) ($row['active'] ?? 1),
				'created_at'   => (string) ($row['created_at'] ?? current_time('mysql')),
			]);
			$added++;
		}

		$model   = (string) ($data['embedding_model'] ?? '');
		$current = (string) Settings::get('voyage_model', '');
		$warn = ($model !== '' && $current !== '' && $model !== $current)
			? "埋め込みモデルが異なります（エクスポート: {$model} / 現在: {$current}）。混在すると検索精度が落ちるため、全再インデックスを推奨します。"
			: null;

		return ['added' => $added, 'skipped' => $skipped, 'sources' => count($map), 'model_warning' => $warn];
	}

	private static function update_source_status(int $id, string $status, int $chunks, ?string $message): void
	{
		global $wpdb;
		$wpdb->update(Database::sources_table(), [
			'status'     => $status,
			'chunks'     => $chunks,
			'message'    => $message,
			'updated_at' => current_time('mysql'),
		], ['id' => $id]);
	}

	/* ---------------------------------------------------------------------
	 * 検索（コサイン類似度）
	 * ------------------------------------------------------------------- */

	/**
	 * 質問ベクトルに近いチャンクを上位 k 件返す。
	 *
	 * @param float[] $query_vector
	 * @return array<int, array{text:string, label:string, score:float}>
	 */
	public static function search(array $query_vector, int $k = 5): array
	{
		global $wpdb;
		$table = Database::knowledge_table();
		$rows = $wpdb->get_results("SELECT chunk_text, source_label, embedding FROM {$table} WHERE active = 1");
		if (empty($rows)) {
			return [];
		}

		$qnorm = self::norm($query_vector);
		if ($qnorm == 0.0) {
			return [];
		}

		$scored = [];
		foreach ($rows as $row) {
			$vec = json_decode((string) $row->embedding, true);
			if (!is_array($vec) || empty($vec)) {
				continue;
			}
			$score = self::cosine($query_vector, $vec, $qnorm);
			$scored[] = [
				'text'  => (string) $row->chunk_text,
				'label' => (string) $row->source_label,
				'score' => $score,
			];
		}

		usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);
		return array_slice($scored, 0, max(1, $k));
	}

	public static function count_active(): int
	{
		global $wpdb;
		$table = Database::knowledge_table();
		return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE active = 1");
	}

	/* ---------------------------------------------------------------------
	 * 取り込みヘルパ
	 * ------------------------------------------------------------------- */

	/**
	 * @return string|\WP_Error
	 */
	private static function fetch_url(string $url)
	{
		$response = wp_remote_get($url, [
			'timeout'    => 30,
			'user-agent' => 'NoviSignChatbot/1.0 (+WordPress)',
		]);
		if (is_wp_error($response)) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code($response);
		if ($code < 200 || $code >= 300) {
			return new \WP_Error('nsbot_fetch', 'URL 取得に失敗：HTTP ' . $code);
		}
		$body = (string) wp_remote_retrieve_body($response);
		$ctype = (string) wp_remote_retrieve_header($response, 'content-type');

		if (stripos($ctype, 'html') !== false || stripos($body, '<html') !== false) {
			return self::html_to_text($body);
		}
		return $body;
	}

	/** 1回のcronバッチで処理するページ数（短く保ってタイムアウトを回避）。 */
	private const CRAWL_BATCH = 5;
	/** クロール進捗（フロンティア）を保存する option キーの接頭辞。 */
	private const CRAWL_STATE = 'nsbot_crawl_';

	/**
	 * URL クロールを開始する。既存チャンクを消して状態を初期化し、最初のバッチを予約。
	 * 実際の巡回＋RAG追加は crawl_batch() が cron で1バッチずつ進める。
	 */
	private static function start_crawl(int $id, object $source): void
	{
		$start = self::strip_fragment((string) $source->ref);
		$pu = wp_parse_url($start);
		if (empty($pu['host']) || empty($pu['scheme'])) {
			self::update_source_status($id, 'error', 0, 'URL が不正です：' . $start);
			return;
		}

		global $wpdb;
		// 新規クロール＝このソースの既存チャンクを一旦クリア（再取込でも同様）。
		$wpdb->delete(Database::knowledge_table(), ['source_id' => $id]);

		$state = [
			'host'    => strtolower((string) $pu['host']),
			'prefix'  => rtrim((string) ($pu['path'] ?? '/'), '/'), // 配下判定の基準（例 /docs）
			'queue'   => [$start],
			'visited' => [],
			'done'    => 0,
			'max'     => max(1, (int) Settings::get('crawl_max_pages', 120)),
		];
		update_option(self::CRAWL_STATE . $id, $state, false);
		self::update_source_status($id, 'crawling', 0, '巡回開始…');

		if (!wp_next_scheduled('nsbot_crawl_batch', [$id])) {
			wp_schedule_single_event(time(), 'nsbot_crawl_batch', [$id]);
		}
		if (function_exists('spawn_cron')) {
			spawn_cron();
		}
	}

	/**
	 * クロール1バッチを処理：数ページ取得→チャンク→埋め込み→DB追加（逐次RAG）。
	 * 続きがあれば次バッチを予約して return（巡回しながら並行してRAGが育つ）。
	 */
	public static function crawl_batch(int $id): void
	{
		if (function_exists('set_time_limit')) {
			@set_time_limit(0);
		}
		$state = get_option(self::CRAWL_STATE . $id);
		if (!is_array($state)) {
			return;
		}
		if (!self::get_source($id)) {
			delete_option(self::CRAWL_STATE . $id);
			return;
		}

		global $wpdb;
		$now = current_time('mysql');
		$processed = 0;

		while (!empty($state['queue']) && $state['done'] < $state['max'] && $processed < self::CRAWL_BATCH) {
			$url = self::strip_fragment((string) array_shift($state['queue']));
			if (isset($state['visited'][$url])) {
				continue;
			}
			$state['visited'][$url] = 1;
			$processed++;

			$res = wp_remote_get($url, [
				'timeout'     => 20,
				'redirection' => 3,
				'user-agent'  => 'NoviSignChatbot/1.0 (+WordPress)',
			]);
			if (is_wp_error($res)) {
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code($res);
			if ($code < 200 || $code >= 300) {
				continue;
			}
			$ctype = (string) wp_remote_retrieve_header($res, 'content-type');
			$body  = (string) wp_remote_retrieve_body($res);
			if (stripos($ctype, 'html') === false && stripos($body, '<html') === false) {
				continue; // 非HTMLは対象外
			}

			// 本文→チャンク→埋め込み→このページ分をDBへ追加（出典＝ページURL）。
			$text = self::html_to_text($body);
			if ($text !== '') {
				$chunks = self::chunk($text);
				if (!empty($chunks)) {
					$vectors = Embeddings::embed($chunks, 'document');
					if (!is_wp_error($vectors)) {
						foreach ($chunks as $i => $chunk) {
							if (!isset($vectors[$i])) {
								continue;
							}
							$wpdb->insert(Database::knowledge_table(), [
								'source_id'    => $id,
								'source_type'  => 'url',
								'source_label' => $url, // ★ページURL＝回答時の出典案内に使う
								'title'        => self::derive_title($chunk),
								'chunk_text'   => $chunk,
								'embedding'    => wp_json_encode($vectors[$i]),
								'active'       => 1,
								'created_at'   => $now,
							]);
						}
						$state['done']++;
					}
				}
			}

			// 配下リンクをキューへ（暴走防止に探索範囲も制限）。
			if (count($state['visited']) + count($state['queue']) < $state['max'] * 4) {
				foreach (self::extract_links($body, $url) as $link) {
					$link = self::strip_fragment($link);
					if (isset($state['visited'][$link]) || in_array($link, $state['queue'], true)) {
						continue;
					}
					$lp = wp_parse_url($link);
					if (empty($lp['host']) || strtolower((string) $lp['host']) !== $state['host']) {
						continue;
					}
					if (!in_array(strtolower((string) ($lp['scheme'] ?? '')), ['http', 'https'], true)) {
						continue;
					}
					$lpath = (string) ($lp['path'] ?? '/');
					if (self::is_binary_path($lpath)) {
						continue;
					}
					if ($state['prefix'] === '' || $lpath === $state['prefix'] || strpos($lpath, $state['prefix'] . '/') === 0) {
						$state['queue'][] = $link;
					}
				}
			}
		}

		$count = (int) $wpdb->get_var($wpdb->prepare(
			'SELECT COUNT(*) FROM ' . Database::knowledge_table() . ' WHERE source_id = %d',
			$id
		));
		$finished = empty($state['queue']) || $state['done'] >= $state['max'];

		if ($finished) {
			if ($state['done'] === 0) {
				self::update_source_status($id, 'error', 0, 'ページ本文を取得できませんでした。');
			} else {
				self::update_source_status($id, 'indexed', $count, "{$state['done']} ページ巡回");
			}
			delete_option(self::CRAWL_STATE . $id);
		} else {
			self::update_source_status($id, 'crawling', $count, "巡回中… {$state['done']} ページ");
			update_option(self::CRAWL_STATE . $id, $state, false);
			if (!wp_next_scheduled('nsbot_crawl_batch', [$id])) {
				wp_schedule_single_event(time(), 'nsbot_crawl_batch', [$id]);
			}
			if (function_exists('spawn_cron')) {
				spawn_cron();
			}
		}
	}

	private static function strip_fragment(string $url): string
	{
		$h = strpos($url, '#');
		return $h === false ? $url : substr($url, 0, $h);
	}

	private static function is_binary_path(string $path): bool
	{
		return (bool) preg_match('/\.(pdf|jpe?g|png|gif|svg|webp|bmp|ico|css|js|json|xml|zip|gz|rar|7z|mp4|webm|mp3|wav|avi|mov|docx?|xlsx?|pptx?|woff2?|ttf|eot)(\?|$)/i', $path);
	}

	/**
	 * HTML から <a href> を絶対URLで抽出。
	 *
	 * @return string[]
	 */
	private static function extract_links(string $html, string $base): array
	{
		$out = [];
		if (preg_match_all('/<a\b[^>]*\bhref\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
			foreach ($m[1] as $href) {
				$href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
				if ($href === '' || $href[0] === '#'
					|| stripos($href, 'javascript:') === 0
					|| stripos($href, 'mailto:') === 0
					|| stripos($href, 'tel:') === 0) {
					continue;
				}
				$abs = self::resolve_url($href, $base);
				if ($abs !== '') {
					$out[] = $abs;
				}
			}
		}
		return array_values(array_unique($out));
	}

	/**
	 * 相対URLを絶対URLへ解決（簡易）。
	 */
	private static function resolve_url(string $href, string $base): string
	{
		if (preg_match('#^https?://#i', $href)) {
			return $href;
		}
		$b = wp_parse_url($base);
		if (empty($b['scheme']) || empty($b['host'])) {
			return '';
		}
		$origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
		if (strpos($href, '//') === 0) {
			return $b['scheme'] . ':' . $href; // protocol-relative
		}
		if ($href[0] === '/') {
			$path = $href; // root-relative
		} else {
			$dir  = preg_replace('#/[^/]*$#', '/', (string) ($b['path'] ?? '/')) ?? '/';
			$path = $dir . $href; // current-dir-relative
		}
		// ../ ./ を正規化。
		$segs = [];
		foreach (explode('/', $path) as $seg) {
			if ($seg === '' || $seg === '.') {
				continue;
			}
			if ($seg === '..') {
				array_pop($segs);
				continue;
			}
			$segs[] = $seg;
		}
		return $origin . '/' . implode('/', $segs);
	}

	/**
	 * @return string|\WP_Error
	 */
	private static function read_file(string $path)
	{
		if (!is_file($path) || !is_readable($path)) {
			return new \WP_Error('nsbot_file', 'ファイルが読めません：' . $path);
		}
		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

		switch ($ext) {
			case 'txt':
			case 'md':
			case 'markdown':
			case 'csv':
				return (string) file_get_contents($path);
			case 'html':
			case 'htm':
				return self::html_to_text((string) file_get_contents($path));
			case 'pdf':
				return self::pdf_to_text($path);
			case 'docx':
				return self::docx_to_text($path);
			case 'pptx':
				return self::pptx_to_text($path);
			default:
				// .doc/.ppt（旧バイナリ）等は本文抽出が困難なため非対応。
				return new \WP_Error('nsbot_unsupported', '未対応の形式です（txt/md/csv/html/pdf/docx/pptx）：.' . $ext);
		}
	}

	/**
	 * PDF からテキストを抽出（同梱 smalot/pdfparser）。
	 * 抽出量が極端に少ない場合（画像PDF・CIDフォント取りこぼし等）は、
	 * テキスト/Word での提供を促すエラーを返す。
	 *
	 * @return string|\WP_Error
	 */
	private static function pdf_to_text(string $path)
	{
		$autoload = NSBOT_PLUGIN_DIR . 'lib/pdfparser/autoload.php';
		if (!is_file($autoload)) {
			return new \WP_Error('nsbot_pdf_lib', 'PDF ライブラリが見つかりません（lib/pdfparser）。');
		}
		require_once $autoload;

		if (!class_exists('\\Smalot\\PdfParser\\Parser')) {
			return new \WP_Error('nsbot_pdf_lib', 'PDF パーサの読み込みに失敗しました。');
		}

		try {
			$parser = new \Smalot\PdfParser\Parser();
			$pdf = $parser->parseFile($path);
			$text = (string) $pdf->getText();
		} catch (\Throwable $e) {
			return new \WP_Error('nsbot_pdf_parse', 'PDF の解析に失敗しました：' . $e->getMessage());
		}

		$text = self::normalize_extracted($text);
		if (mb_strlen($text) < 20) {
			return new \WP_Error(
				'nsbot_pdf_empty',
				'PDF から十分なテキストを抽出できませんでした（画像主体／フォント埋め込みの可能性）。テキストまたは Word 形式での提供をご検討ください。'
			);
		}
		return $text;
	}

	/**
	 * .docx（Office Open XML）からテキストを抽出。
	 * docx は ZIP コンテナで、本文は word/document.xml にある。
	 * PHP 標準の ZipArchive のみで処理し、追加依存は持たない。
	 *
	 * @return string|\WP_Error
	 */
	private static function docx_to_text(string $path)
	{
		if (!class_exists('\\ZipArchive')) {
			return new \WP_Error('nsbot_docx_zip', 'ZipArchive 拡張が無効のため .docx を処理できません。');
		}

		$zip = new \ZipArchive();
		if ($zip->open($path) !== true) {
			return new \WP_Error('nsbot_docx_open', '.docx を開けませんでした（壊れている可能性）。');
		}
		$xml = (string) $zip->getFromName('word/document.xml');
		$zip->close();

		if ($xml === '') {
			return new \WP_Error('nsbot_docx_body', '.docx の本文（word/document.xml）が見つかりませんでした。');
		}

		// 段落 </w:p> と改行 <w:br/> を改行に、タブ <w:tab/> をタブに変換してからタグ除去。
		$xml = preg_replace('#<w:tab\b[^>]*/?>#i', "\t", $xml) ?? $xml;
		$xml = preg_replace('#<w:br\b[^>]*/?>#i', "\n", $xml) ?? $xml;
		$xml = preg_replace('#</w:p>#i', "\n", $xml) ?? $xml;
		$text = wp_strip_all_tags($xml);
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = self::normalize_extracted($text);

		if (mb_strlen($text) < 1) {
			return new \WP_Error('nsbot_docx_empty', '.docx から本文を抽出できませんでした。');
		}
		return $text;
	}

	/**
	 * .pptx（Office Open XML）からスライド本文＋ノートを抽出。
	 * pptx も ZIP コンテナで、各スライドは ppt/slides/slideN.xml、
	 * 発表者ノートは ppt/notesSlides/notesSlideN.xml に入る。
	 * 人が読むテキストは <a:t> 要素にある。docx 同様 ZipArchive のみで処理。
	 *
	 * 注意：図やスクリーンショット内の文字は画像なので抽出できない。
	 *
	 * @return string|\WP_Error
	 */
	private static function pptx_to_text(string $path)
	{
		if (!class_exists('\\ZipArchive')) {
			return new \WP_Error('nsbot_pptx_zip', 'ZipArchive 拡張が無効のため .pptx を処理できません。');
		}

		$zip = new \ZipArchive();
		if ($zip->open($path) !== true) {
			return new \WP_Error('nsbot_pptx_open', '.pptx を開けませんでした（壊れている可能性）。');
		}

		// スライド／ノートを番号順に集める。
		$slides = [];
		$notes = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string) $zip->getNameIndex($i);
			if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m)) {
				$slides[(int) $m[1]] = $name;
			} elseif (preg_match('#^ppt/notesSlides/notesSlide(\d+)\.xml$#', $name, $m)) {
				$notes[(int) $m[1]] = $name;
			}
		}
		ksort($slides);
		ksort($notes);

		$parts = [];
		foreach ($slides as $num => $entry) {
			$body = self::ooxml_runs_to_text((string) $zip->getFromName($entry));
			if ($body === '') {
				continue;
			}
			$block = "【スライド {$num}】\n" . $body;
			if (isset($notes[$num])) {
				$note = self::ooxml_runs_to_text((string) $zip->getFromName($notes[$num]));
				if ($note !== '') {
					$block .= "\n（ノート）" . $note;
				}
			}
			$parts[] = $block;
		}
		$zip->close();

		$text = self::normalize_extracted(implode("\n\n", $parts));
		if (mb_strlen($text) < 1) {
			return new \WP_Error(
				'nsbot_pptx_empty',
				'.pptx からテキストを抽出できませんでした（図・画像主体のスライドの可能性）。'
			);
		}
		return $text;
	}

	/**
	 * OOXML（pptx の slide/notes）の <a:t> テキストランを本文へ。
	 * 段落 </a:p> と改行 <a:br/> を改行に変換してからタグ除去する。
	 */
	private static function ooxml_runs_to_text(string $xml): string
	{
		if ($xml === '') {
			return '';
		}
		$xml = preg_replace('#<a:br\b[^>]*/?>#i', "\n", $xml) ?? $xml;
		$xml = preg_replace('#</a:p>#i', "\n", $xml) ?? $xml;
		$text = wp_strip_all_tags($xml);
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		return trim($text);
	}

	/**
	 * 抽出テキストの空白・改行を整える（PDF/docx/pptx 共通）。
	 */
	private static function normalize_extracted(string $text): string
	{
		$text = str_replace("\r\n", "\n", $text);
		$text = str_replace("\r", "\n", $text);
		$text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
		$text = preg_replace('/\n\s*\n\s*\n+/', "\n\n", $text) ?? $text;
		return trim($text);
	}

	private static function html_to_text(string $html): string
	{
		// script/style を除去 → タグ除去 → 実体参照復元 → 空白整理。
		$html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
		$html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
		$html = preg_replace('#</(p|div|h[1-6]|li|tr)>#i', "\n", $html) ?? $html;
		$text = wp_strip_all_tags($html);
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
		$text = preg_replace('/\n\s*\n\s*\n+/', "\n\n", $text) ?? $text;
		return trim($text);
	}

	/**
	 * 本文を段落境界優先で約 chunk_size 文字に分割（オーバーラップ付き）。
	 *
	 * @return string[]
	 */
	private static function chunk(string $text): array
	{
		$size = max(200, (int) Settings::get('chunk_size', 1000));
		$overlap = max(0, (int) Settings::get('chunk_overlap', 150));

		// 段落（空行）で粗く分割。
		$paragraphs = preg_split('/\n\s*\n/u', $text) ?: [$text];

		$chunks = [];
		$buffer = '';
		foreach ($paragraphs as $p) {
			$p = trim($p);
			if ($p === '') {
				continue;
			}
			// 1段落が大きすぎる場合は文字数で強制分割。
			if (mb_strlen($p) > $size) {
				if ($buffer !== '') {
					$chunks[] = $buffer;
					$buffer = '';
				}
				foreach (self::split_by_length($p, $size, $overlap) as $piece) {
					$chunks[] = $piece;
				}
				continue;
			}
			if ($buffer === '') {
				$buffer = $p;
			} elseif (mb_strlen($buffer) + mb_strlen($p) + 2 <= $size) {
				$buffer .= "\n\n" . $p;
			} else {
				$chunks[] = $buffer;
				$buffer = $p;
			}
		}
		if ($buffer !== '') {
			$chunks[] = $buffer;
		}

		return array_values(array_filter($chunks, static fn ($c) => trim($c) !== ''));
	}

	/**
	 * @return string[]
	 */
	private static function split_by_length(string $text, int $size, int $overlap): array
	{
		$pieces = [];
		$len = mb_strlen($text);
		$start = 0;
		$step = max(1, $size - $overlap);
		while ($start < $len) {
			$pieces[] = mb_substr($text, $start, $size);
			$start += $step;
		}
		return $pieces;
	}

	private static function derive_title(string $text): string
	{
		$first = trim((string) (preg_split('/\n/u', $text)[0] ?? ''));
		return mb_substr($first, 0, 120);
	}

	/* ---------------------------------------------------------------------
	 * ベクトル演算
	 * ------------------------------------------------------------------- */

	/**
	 * @param float[] $a
	 * @param float[] $b
	 */
	private static function cosine(array $a, array $b, ?float $anorm = null): float
	{
		$dot = 0.0;
		$bnorm = 0.0;
		$n = min(count($a), count($b));
		for ($i = 0; $i < $n; $i++) {
			$dot += $a[$i] * $b[$i];
			$bnorm += $b[$i] * $b[$i];
		}
		$anorm = $anorm ?? self::norm($a);
		$bnorm = sqrt($bnorm);
		if ($anorm == 0.0 || $bnorm == 0.0) {
			return 0.0;
		}
		return $dot / ($anorm * $bnorm);
	}

	/**
	 * @param float[] $v
	 */
	private static function norm(array $v): float
	{
		$s = 0.0;
		foreach ($v as $x) {
			$s += $x * $x;
		}
		return sqrt($s);
	}
}
