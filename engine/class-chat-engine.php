<?php

declare(strict_types=1);

namespace NoviSign\Chatbot;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * 回答ロジックの「単一の真実」。チャネル非依存・マルチターン対応。
 *
 * 方針（ユーザー指示）：
 *  - わからない時に即・担当者へ引き継がない。まず確認質問で掘り下げる（上限あり）。
 *  - 連絡先（メール）は同一セッションで一度だけ取得する。
 *  - 1セッションの未解決質問は1件に集約して引き継ぐ。
 *
 * 流れ：質問＋会話履歴 → Voyage 埋め込み（話題を考慮）→ コサイン検索 →
 *       Claude（文脈限定・3モード：回答／確認質問／引き継ぎ）→ セッション状態を更新。
 */
final class ChatEngine
{
	/** Claude に「文脈に無い＝人へ」と判断させるためのセンチネル。 */
	private const SENTINEL = '<<NO_ANSWER>>';

	/** Claude が「もう少し情報がほしい」と確認質問を返すときの目印。 */
	private const ASK = '<<ASK>>';

	/**
	 * @param string $message     今回のユーザー発話
	 * @param array<int, array{role:string, content:string}> $history  直近の会話履歴（user/assistant）
	 * @param string $session_id  クライアント由来のセッションID
	 * @return array{reply:string, type:string, answered:bool, ask_email:bool}
	 */
	public static function answer(string $message, array $history = [], string $session_id = ''): array
	{
		$message = trim($message);
		if ($message === '') {
			return self::result('ご質問を入力してください。', 'answer', true, false);
		}

		$sid = Session::sanitize_id($session_id);
		$state = Session::get($sid);
		$max_clarify = max(0, (int) Settings::get('max_clarify', 2));
		$clarify_left = $max_clarify - (int) $state['clarify'];

		// 1) 話題を踏まえた検索クエリ（直近のユーザー発話を結合）で埋め込み
		$query_text = self::retrieval_query($history, $message);
		$qvec = Embeddings::embed_one($query_text, 'query');
		if (is_wp_error($qvec)) {
			$out = self::escalate($sid, self::topic($history, $message), $qvec->get_error_message(), $message);
			self::log_turn($sid, $message, $out, 0.0, null);
			return $out;
		}

		// 2) 類似チャンク検索（生の最高スコアを記録用に保持し、しきい値超えだけ文脈に）
		$k = (int) Settings::get('top_k', 5);
		$threshold = (float) Settings::get('threshold', 0.45);
		$searched = Knowledge::search($qvec, $k);
		$top_score = !empty($searched) ? max(array_map(static fn($h) => (float) $h['score'], $searched)) : 0.0;
		$hits = array_values(array_filter($searched, static fn($h) => $h['score'] >= $threshold));

		// 3) Claude に文脈限定で応答させる（回答／確認質問／引き継ぎ）
		$system = self::system_prompt($clarify_left > 0);
		$messages = self::build_messages($history, $message, $hits);

		$reply = LLM::chat($system, $messages);
		if (is_wp_error($reply)) {
			$out = self::escalate($sid, self::topic($history, $message), $reply->get_error_message(), $message);
			self::log_turn($sid, $message, $out, $top_score, $qvec, 0);
			return $out;
		}
		$reply = trim($reply);

		// 3.5) ヒート（お客様の不満度 0-100）を抽出し、本文からは除去する。
		$heat = self::extract_heat($reply);
		$reply = self::strip_heat($reply);
		$hot = $heat >= (int) Settings::get('heat_threshold', 70);

		// 4) モード判定 → 結果を組み立て、最後に1か所でログ記録。
		if ($reply === '' || strpos($reply, self::SENTINEL) !== false) {
			// 掘り下げても無理 → 人へ（セッションに集約）
			$out = self::escalate($sid, self::topic($history, $message), '', $message, $heat);
		} elseif (strpos($reply, self::ASK) !== false) {
			$question = trim(str_replace(self::ASK, '', $reply));
			// 高ヒート（苛立ち）時は確認質問で引き延ばさず、すぐ人へ引き継ぐ。
			if ($clarify_left > 0 && $question !== '' && !$hot) {
				Session::bump_clarify($sid);
				$out = self::result($question, 'clarify', true, false);
			} else {
				$out = self::escalate($sid, self::topic($history, $message), '', $message, $heat);
			}
		} else {
			// 根拠ある回答 → 確認質問カウントをリセット
			Session::reset_clarify($sid);
			$out = self::result($reply, 'answer', true, false);
		}

		self::log_turn($sid, $message, $out, $top_score, $qvec, $heat);
		return $out;
	}

	/** 応答末尾の <<HEAT:NN>> を読む（無ければ 0）。 */
	private static function extract_heat(string $reply): int
	{
		if (preg_match('/<<\s*HEAT\s*:\s*(\d{1,3})\s*>>/i', $reply, $m)) {
			return min(100, max(0, (int) $m[1]));
		}
		return 0;
	}

	/** 応答本文から <<HEAT:NN>> マーカーを除去。 */
	private static function strip_heat(string $reply): string
	{
		return trim((string) preg_replace('/<<\s*HEAT\s*:\s*\d{1,3}\s*>>/i', '', $reply));
	}

	/**
	 * 1ターンを chatlog に記録（週次レポート用）。query ベクトルを再利用するので追加コストなし。
	 *
	 * @param array{reply:string, type:string, answered:bool, ask_email:bool} $out
	 * @param array<int, float>|null $qvec
	 */
	private static function log_turn(string $sid, string $message, array $out, float $top_score, ?array $qvec, int $heat = 0): void
	{
		Chatlog::record($sid, $message, (bool) $out['answered'], (string) $out['type'], $top_score, $heat, $qvec);
	}

	/**
	 * 引き継ぎ（人へ）。セッション状態に応じて「メールを聞く」か「既存問い合わせに追記」かを決める。
	 *
	 * @return array{reply:string, type:string, answered:bool, ask_email:bool}
	 */
	private static function escalate(string $sid, string $topic, string $log = '', string $message = '', int $heat = 0): array
	{
		if ($log !== '' && defined('WP_DEBUG') && WP_DEBUG) {
			error_log('[nsbot] ' . $log . ' / Q=' . $message);
		}

		// 高ヒート（苛立ち）時は、引き継ぎ文の冒頭に一言お詫び・共感を添える。
		$prefix = '';
		if ($heat >= (int) Settings::get('heat_threshold', 70)) {
			$note = trim((string) Settings::get('empathy_note', 'お困りのところ恐れ入ります。'));
			if ($note !== '') {
				$prefix = $note . "\n";
			}
		}

		$outcome = Session::add_unresolved($sid, $topic);

		if (!empty($outcome['ask_email'])) {
			// 連絡先 未取得 → 一度だけ聞く
			$fallback = (string) Settings::get('fallback', '');
			return self::result($prefix . $fallback, 'ask_email', false, true);
		}

		// 連絡先 取得済み → 再度は聞かず、まとめて確認する旨だけ返す
		$noted = (string) Settings::get('noted_message', '承知しました。その点もあわせて担当者に確認し、追ってご連絡します。');
		return self::result($prefix . $noted, 'noted', false, false);
	}

	/**
	 * 検索クエリ：直近のユーザー発話＋今回の発話を結合し、確認質問後の短い返答でも話題を保つ。
	 *
	 * @param array<int, array{role:string, content:string}> $history
	 */
	private static function retrieval_query(array $history, string $message): string
	{
		$users = [];
		foreach (array_reverse($history) as $m) {
			if (($m['role'] ?? '') === 'user') {
				$users[] = trim((string) $m['content']);
				if (count($users) >= 2) {
					break;
				}
			}
		}
		$users = array_reverse($users);
		$users[] = $message;
		return trim(implode(' / ', array_filter($users)));
	}

	/**
	 * 引き継ぎ時に記録する「未解決の質問」。直近のユーザー発話をまとめて文脈ごと残す。
	 *
	 * @param array<int, array{role:string, content:string}> $history
	 */
	private static function topic(array $history, string $message): string
	{
		$users = [];
		foreach (array_reverse($history) as $m) {
			if (($m['role'] ?? '') === 'user') {
				$users[] = trim((string) $m['content']);
				if (count($users) >= 3) {
					break;
				}
			}
		}
		$users = array_reverse($users);
		$users[] = $message;
		$users = array_values(array_unique(array_filter($users)));
		return implode(' / ', $users);
	}

	/**
	 * Claude へ渡すメッセージ列を構築。履歴 ＋ （参考情報＋今回の質問）。
	 *
	 * @param array<int, array{role:string, content:string}> $history
	 * @param array<int, array{text:string, label:string, score:float}> $hits
	 * @return array<int, array{role:string, content:string}>
	 */
	private static function build_messages(array $history, string $message, array $hits): array
	{
		$messages = [];
		foreach ($history as $m) {
			$role = (($m['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
			$content = trim((string) ($m['content'] ?? ''));
			if ($content !== '') {
				$messages[] = ['role' => $role, 'content' => $content];
			}
		}

		$context = empty($hits)
			? '（該当する参考情報は見つかりませんでした）'
			: self::build_context($hits);

		$messages[] = [
			'role'    => 'user',
			'content' => "■参考情報（知識ベースより）\n{$context}\n\n■質問\n{$message}",
		];

		return $messages;
	}

	/**
	 * @param array<int, array{text:string, label:string, score:float}> $hits
	 */
	private static function build_context(array $hits): string
	{
		$parts = [];
		foreach ($hits as $i => $hit) {
			$n = $i + 1;
			$label = $hit['label'] !== '' ? $hit['label'] : 'source';
			$parts[] = "[{$n}] （出典: {$label}）\n" . $hit['text'];
		}
		return implode("\n\n----\n\n", $parts);
	}

	private static function system_prompt(bool $allow_clarify): string
	{
		// ボット名は管理画面の「ウィジェットのタイトル」を流用する。
		// 特定の製品・サービスに固定せず、取り込まれた知識ベースが守備範囲になる。
		$bot = trim((string) Settings::get('widget_title', ''));
		if ($bot === '') {
			$bot = 'サポート';
		}

		$lines = [
			"あなたは「{$bot}」という日本語のチャットボットです。",
			'特定の製品や分野に限定されない。「参考情報」として渡された知識ベースの内容が、そのままあなたの守備範囲になる。',
			'目的は、ユーザーの困りごとをできるだけ自分で解決すること。安易に人へ引き継がない。',
			'',
			'以下のルールを厳守してください：',
			'- 回答は「参考情報」に書かれている内容を根拠にすること。推測で補わない。ただし、一般論で解決できる場合は回答してよい。',
			'- 参考情報に書かれていれば、分野を問わずどんな話題でも答えてよい。'
				. '「専門外」を理由に断らないこと（判断基準は分野ではなく、参考情報に書かれているか、または一般論で答えられるか）。',
			'- 会話履歴の文脈を踏まえること。直前に確認した内容を繰り返し聞かない。',
		];

		if ($allow_clarify) {
			$lines[] = '- 質問が曖昧で、対象・状況・条件などを確認すれば参考情報から答えられそうな場合は、'
				. '断定で引き継がず、まず1つだけ簡潔な確認質問をすること。'
				. 'その場合は必ず先頭に ' . self::ASK . ' を付け、続けて確認質問だけを書く（例：「' . self::ASK . ' どちらの製品についてのご質問でしょうか？」）。';
		} else {
			$lines[] = '- これ以上の確認質問はしないこと（' . self::ASK . ' は使わない）。';
		}

		$lines[] = '';
		$lines[] = '【答えられないとき／引き継ぎ — 最重要】';
		$lines[] = '- 参考情報にも一般論にも答えが無い・確信が持てない場合、または利用者が'
			. '「担当者につないで」「人に聞いて」等の引き継ぎを求めた場合は、' . self::SENTINEL . ' を出力する'
			. '（末尾の感情評価マーカー `<<HEAT:数値>>` だけは必ず併記する。それ以外の文章は書かない）。';
		$lines[] = '- このとき、謝罪文・「担当者に伝えます／引き継ぎます」等の予告・連絡先やメールの案内・会話の要約は'
			. '一切書かないこと（HEATマーカーを除き ' . self::SENTINEL . ' 以外の本文を書くと、実際の引き継ぎが発動せず担当者へ通知が飛ばない）。'
			. '引き継ぎとメールアドレスの取得はすべてシステムが行う。';
		$lines[] = '- 利用者がメールアドレスや自虐的な表現（例：自分を「クレーマー」と呼ぶ等）を述べても、'
			. 'それを復唱・要約・本文に書き起こさない。該当時は ' . self::SENTINEL . ' を出すだけにする。';
		$lines[] = '- 参考情報に「連絡先」しか見当たらない場合も回答ではない → ' . self::SENTINEL . '。';
		$lines[] = '- 一般常識や雑談・あいさつ（天気・世間話など）には、'
			. self::SENTINEL . ' を出さず、一般論の範囲で簡潔に答えること（この場合は連絡先取得・引き継ぎを行わない）。'
			. '参考情報に該当する記述があれば、話題の分野を問わず通常どおりそれを根拠に答える。';
		$lines[] = '';
		$lines[] = '- 回答は日本語で、簡潔かつ丁寧に。【短さ最優先】チャット画面なので長文は避ける。'
			. 'まず結論・要点を数行で示すこと。原則として全体で 5〜8 行程度に収め、冗長な前置きや言い換えはしない。';
		$lines[] = '- 手順がある場合も、全ステップを逐一書き出さず、要点となる流れだけを短い箇条書き（目安3〜5項目）にまとめる。'
			. '細かな画面名・クリック位置・注意書きまで網羅しようとしないこと。利用者が続けて尋ねたら、その箇所だけ掘り下げて答える。';
		$lines[] = '- 出典番号や「参考情報によると」等のメタ的な前置きは書かない。自然な回答だけを返す。';
		$lines[] = '- ただし、回答の根拠とした参考情報の「出典」が URL（http で始まる）の場合は、'
			. '回答の最後に改行してから「🔗 詳しくはこちら: <そのURL>」の形で、実際に根拠としたページの URL を1〜2件だけ案内すること。'
			. '出典が URL でない資料（ファイル名など）のときは URL を書かない。'
			. '参考情報に無い URL は絶対に書かない（推測・創作で URL を作らない）。';
		$lines[] = '';
		$lines[] = '【お客様の感情の評価 — 必須】';
		$lines[] = '- 応答の最後に、お客様の不満・苛立ちの度合いを 0〜100 の整数で評価し、必ず半角で '
			. '`<<HEAT:数値>>` を1つだけ付けること（0=平静 / 50=やや不満 / 80以上=強い怒り）。'
			. '丁寧語でも文面・状況から判断する。この目印はシステムが取り除くので本文には現れない。'
			. '通常回答・確認質問・' . self::SENTINEL . ' のいずれを出すときも必ず付けること。';
		$lines[] = '- 苛立ちや強い困りが感じられる場合（概ね60以上）で、かつ参考情報から回答できるときに限り、'
			. '回答の冒頭で一言だけ共感・お詫びを述べてから本題に入る。'
			. self::SENTINEL . ' を出すときは共感文を含め一切何も付け足さない（お詫びはシステム側が添える）。';

		return implode("\n", $lines);
	}

	/**
	 * @return array{reply:string, type:string, answered:bool, ask_email:bool}
	 */
	private static function result(string $reply, string $type, bool $answered, bool $ask_email): array
	{
		return [
			'reply'     => $reply,
			'type'      => $type,
			'answered'  => $answered,
			'ask_email' => $ask_email,
		];
	}
}
