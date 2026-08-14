<?php

declare(strict_types=1);

namespace NoviSign\Chatbot;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * 問い合わせ（知識に無く、メール取得した質問）の保存・管理。
 * Phase 2 でここから Teams 通知・学習へつなぐ。
 */
final class Inquiries
{
	/**
	 * @return int 新規 inquiry ID
	 */
	public static function create(string $question, string $email, string $session = '', string $channel = 'web'): int
	{
		global $wpdb;
		$wpdb->insert(Database::inquiries_table(), [
			'question'   => $question,
			'email'      => $email,
			'status'     => 'pending',
			'channel'    => $channel,
			'session'    => $session,
			'created_at' => current_time('mysql'),
		]);
		$id = (int) $wpdb->insert_id;

		/**
		 * 問い合わせ作成フック。Phase 2 の Teams 通知はここにフックする。
		 */
		do_action('nsbot_inquiry_created', $id, $question, $email);

		return $id;
	}

	/**
	 * 既存の問い合わせに、同一セッションの追加質問を追記する（1件に集約）。
	 * 未対応（pending）状態は維持。
	 */
	public static function append_question(int $id, string $additional): void
	{
		$additional = trim($additional);
		if ($id <= 0 || $additional === '') {
			return;
		}
		$inquiry = self::get($id);
		if (!$inquiry) {
			return;
		}
		$merged = rtrim((string) $inquiry->question)
			. "\n\n--- 追加質問（" . current_time('mysql') . "）---\n" . $additional;

		global $wpdb;
		$wpdb->update(Database::inquiries_table(), [
			'question' => $merged,
		], ['id' => $id]);

		do_action('nsbot_inquiry_updated', $id, $additional);
	}

	/**
	 * @return array<int, object>
	 */
	public static function list(int $limit = 100, string $status = ''): array
	{
		global $wpdb;
		$table = Database::inquiries_table();
		if ($status !== '') {
			return $wpdb->get_results($wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d",
				$status,
				$limit
			)) ?: [];
		}
		return $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d",
			$limit
		)) ?: [];
	}

	public static function get(int $id): ?object
	{
		global $wpdb;
		$table = Database::inquiries_table();
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
	}

	/**
	 * 担当者回答で解決（Phase 2：Teams からの回答もここを通す）。
	 * 回答内容は知識として学習する。
	 */
	public static function answer(int $id, string $answer_text): void
	{
		$inquiry = self::get($id);
		if (!$inquiry) {
			return;
		}

		$knowledge_id = 0;
		$learned = Knowledge::add_qa((string) $inquiry->question, $answer_text);
		if (!is_wp_error($learned)) {
			$knowledge_id = (int) $learned;
		}

		global $wpdb;
		$wpdb->update(Database::inquiries_table(), [
			'status'       => 'answered',
			'answer_text'  => $answer_text,
			'knowledge_id' => $knowledge_id,
			'answered_at'  => current_time('mysql'),
		], ['id' => $id]);

		do_action('nsbot_inquiry_answered', $id, $answer_text, $knowledge_id);
	}

	public static function count_pending(): int
	{
		global $wpdb;
		$table = Database::inquiries_table();
		return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending'");
	}

	/**
	 * 保持期間（PII配慮）：回答済みで作成から $days 日を超えた問い合わせを削除。
	 * 未対応（pending）は対象外（取りこぼし防止）。戻り値＝削除件数。
	 */
	public static function prune(int $days): int
	{
		if ($days <= 0) {
			return 0;
		}
		global $wpdb;
		$table = Database::inquiries_table();
		$cutoff = gmdate('Y-m-d H:i:s', (int) current_time('timestamp') - $days * DAY_IN_SECONDS);
		return (int) $wpdb->query($wpdb->prepare(
			"DELETE FROM {$table} WHERE status = 'answered' AND created_at < %s",
			$cutoff
		));
	}

	/**
	 * 通知カードを投稿したスレッド（会話参照）を保存。後のリマインド返信に使う。
	 */
	public static function set_teams_thread(int $id, string $conv_id, string $service_url): void
	{
		global $wpdb;
		$wpdb->update(Database::inquiries_table(), [
			'teams_conv_id'     => $conv_id,
			'teams_service_url' => $service_url,
		], ['id' => $id]);
	}

	/**
	 * リマインド候補：未対応(pending)・通知スレッドあり・未リマインド。
	 * 経過営業日の判定は呼び出し側（Cron）で行う。
	 *
	 * @return array<int, object>
	 */
	public static function due_for_reminder(): array
	{
		global $wpdb;
		$t = Database::inquiries_table();
		return $wpdb->get_results(
			"SELECT * FROM {$t} WHERE status = 'pending' AND reminded_at IS NULL AND teams_conv_id <> '' ORDER BY id ASC"
		) ?: [];
	}

	public static function mark_reminded(int $id): void
	{
		global $wpdb;
		$wpdb->update(Database::inquiries_table(), ['reminded_at' => current_time('mysql')], ['id' => $id]);
	}
}
