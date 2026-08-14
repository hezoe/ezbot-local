<?php

declare(strict_types=1);

namespace NoviSign\Chatbot;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * 全 /chat の軽量ログ（週次レポートの集計元）。
 * embedding は ChatEngine が算出済みの query ベクトルを再利用するため、追加の埋め込みコストは無い。
 */
final class Chatlog
{
	/**
	 * 1ターン分を記録。記録は失敗しても会話本体を妨げない（best-effort）。
	 *
	 * @param array<int, float>|null $embedding ChatEngine の query ベクトル（クラスタリング用）
	 */
	public static function record(string $session, string $question, bool $answered, string $type, float $top_score, int $heat = 0, ?array $embedding = null): void
	{
		if (!Settings::get('chatlog_enabled', 1)) {
			return;
		}
		$q = trim($question);
		if ($q === '') {
			return;
		}
		if (mb_strlen($q) > 1000) {
			$q = mb_substr($q, 0, 1000);
		}

		global $wpdb;
		$wpdb->insert(Database::chatlog_table(), [
			'session'    => $session,
			'question'   => $q,
			'answered'   => $answered ? 1 : 0,
			'type'       => $type,
			'top_score'  => $top_score,
			'heat'       => max(0, min(100, $heat)),
			'embedding'  => is_array($embedding) ? wp_json_encode($embedding) : null,
			'created_at' => current_time('mysql'),
		]);
	}

	/**
	 * 指定セッションの直近 $within_sec 秒の最大ヒート（お客様の不満度）を返す。
	 * 問い合わせ通知カードの優先度表示などに使う。
	 */
	public static function max_heat_for_session(string $session, int $within_sec = 7200): int
	{
		if ($session === '') {
			return 0;
		}
		global $wpdb;
		$t = Database::chatlog_table();
		$since = gmdate('Y-m-d H:i:s', (int) current_time('timestamp') - max(60, $within_sec));
		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT MAX(heat) FROM {$t} WHERE session = %s AND created_at >= %s",
			$session,
			$since
		));
	}

	/**
	 * 期間内（[$from, $to)）の行を返す。
	 *
	 * @return array<int, object>
	 */
	public static function rows_between(string $from, string $to): array
	{
		global $wpdb;
		$t = Database::chatlog_table();
		return $wpdb->get_results($wpdb->prepare(
			"SELECT id, session, question, answered, type, top_score, heat, embedding, created_at
			 FROM {$t} WHERE created_at >= %s AND created_at < %s ORDER BY id ASC",
			$from,
			$to
		)) ?: [];
	}

	/**
	 * 集計の素早いカウント（embedding を読まない軽量版）。
	 *
	 * @return array{total:int, answered:int, sessions:int}
	 */
	public static function totals_between(string $from, string $to): array
	{
		global $wpdb;
		$t = Database::chatlog_table();
		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT COUNT(*) AS total,
			        SUM(answered) AS answered,
			        COUNT(DISTINCT session) AS sessions
			 FROM {$t} WHERE created_at >= %s AND created_at < %s",
			$from,
			$to
		));
		return [
			'total'    => (int) ($row->total ?? 0),
			'answered' => (int) ($row->answered ?? 0),
			'sessions' => (int) ($row->sessions ?? 0),
		];
	}

	public static function count(): int
	{
		global $wpdb;
		return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Database::chatlog_table());
	}

	/**
	 * 保持期間を超えた古いログを削除（PII配慮）。戻り値＝削除件数。
	 */
	public static function prune(int $days): int
	{
		if ($days <= 0) {
			return 0;
		}
		global $wpdb;
		$t = Database::chatlog_table();
		$cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS + (int) (get_option('gmt_offset', 0) * HOUR_IN_SECONDS));
		return (int) $wpdb->query($wpdb->prepare("DELETE FROM {$t} WHERE created_at < %s", $cutoff));
	}
}
