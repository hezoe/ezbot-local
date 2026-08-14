<?php

declare(strict_types=1);

namespace NoviSign\Chatbot;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * 会話セッションの状態管理（transient ベース）。
 *
 * チャットボットを「即・引き継がない／連絡先は一度だけ／不明点はまとめて1件」に
 * するための要。セッションIDごとに以下を保持する：
 *  - email      … 一度取得したら再取得しない
 *  - inquiry_id … そのセッションの問い合わせ（1件に集約）
 *  - pending    … メール取得前に溜まった未解決の質問
 *  - clarify    … 直近の根拠ある回答以降に行った確認質問の回数（掘り下げ上限の管理）
 */
final class Session
{
	private const PREFIX = 'nsbot_sess_';
	private const TTL = 2 * HOUR_IN_SECONDS;

	/**
	 * クライアント由来のセッションIDを無害化（英数・ハイフン・アンダースコアのみ、64文字）。
	 */
	public static function sanitize_id(string $sid): string
	{
		$sid = preg_replace('/[^A-Za-z0-9_\-]/', '', $sid) ?? '';
		return substr($sid, 0, 64);
	}

	/**
	 * @return array{email:string, inquiry_id:int, pending:array<int,string>, clarify:int}
	 */
	public static function get(string $sid): array
	{
		$defaults = ['email' => '', 'inquiry_id' => 0, 'pending' => [], 'clarify' => 0];
		if ($sid === '') {
			return $defaults;
		}
		$data = get_transient(self::PREFIX . $sid);
		if (!is_array($data)) {
			return $defaults;
		}
		return array_merge($defaults, $data);
	}

	/**
	 * @param array{email:string, inquiry_id:int, pending:array<int,string>, clarify:int} $state
	 */
	public static function save(string $sid, array $state): void
	{
		if ($sid === '') {
			return;
		}
		set_transient(self::PREFIX . $sid, $state, self::TTL);
	}

	/** 確認質問を1回使ったと記録。 */
	public static function bump_clarify(string $sid): void
	{
		$s = self::get($sid);
		$s['clarify']++;
		self::save($sid, $s);
	}

	/** 根拠ある回答ができたら確認質問カウントをリセット。 */
	public static function reset_clarify(string $sid): void
	{
		$s = self::get($sid);
		if ($s['clarify'] !== 0) {
			$s['clarify'] = 0;
			self::save($sid, $s);
		}
	}

	/**
	 * 未解決の質問をセッションに積む。
	 * - すでにメール取得済みなら、その場で既存の問い合わせに追記（再度メールは聞かない）。
	 * - 未取得なら pending に溜めて「メールを聞く」フラグを返す。
	 *
	 * @return array{ask_email:bool}
	 */
	public static function add_unresolved(string $sid, string $topic): array
	{
		$topic = trim($topic);
		$s = self::get($sid);

		if ($s['email'] !== '') {
			// 連絡先取得済み → 1件に集約して追記。再度は聞かない。
			if ($s['inquiry_id'] > 0) {
				Inquiries::append_question($s['inquiry_id'], $topic);
			} else {
				$s['inquiry_id'] = Inquiries::create($topic, $s['email'], $sid, 'web');
			}
			self::save($sid, $s);
			return ['ask_email' => false];
		}

		// 連絡先未取得 → 重複を避けつつ pending に追加。
		if ($topic !== '' && !in_array($topic, $s['pending'], true)) {
			$s['pending'][] = $topic;
		}
		self::save($sid, $s);
		return ['ask_email' => true];
	}

	/**
	 * メール取得時：そのセッションの未解決質問を「1件の問い合わせ」に集約して登録。
	 * 追加の質問は同じ問い合わせに追記される。
	 *
	 * @param string $fallback_question pending が空のときに使う質問文
	 * @return int 問い合わせID
	 */
	public static function register_email(string $sid, string $email, string $fallback_question = ''): int
	{
		$s = self::get($sid);
		$s['email'] = $email;

		$questions = $s['pending'];
		if (empty($questions) && trim($fallback_question) !== '') {
			$questions[] = trim($fallback_question);
		}
		if (empty($questions)) {
			$questions[] = '(質問内容の記録なし)';
		}

		$bundled = self::bundle($questions);

		if ($s['inquiry_id'] > 0) {
			Inquiries::append_question($s['inquiry_id'], $bundled);
		} else {
			$s['inquiry_id'] = Inquiries::create($bundled, $email, $sid, 'web');
		}

		$s['pending'] = [];
		self::save($sid, $s);

		return $s['inquiry_id'];
	}

	/**
	 * 複数の未解決質問を番号付きでまとめる。
	 *
	 * @param array<int,string> $questions
	 */
	private static function bundle(array $questions): string
	{
		$questions = array_values(array_filter(array_map('trim', $questions), fn($q) => $q !== ''));
		if (count($questions) === 1) {
			return $questions[0];
		}
		$lines = [];
		foreach ($questions as $i => $q) {
			$lines[] = ($i + 1) . '. ' . $q;
		}
		return "【このセッションで未解決の質問】\n" . implode("\n", $lines);
	}
}
