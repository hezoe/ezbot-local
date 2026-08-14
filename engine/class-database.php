<?php

declare(strict_types=1);

namespace NoviSign\Chatbot;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * カスタムテーブルの作成・名前解決。
 */
final class Database
{
	public static function sources_table(): string
	{
		global $wpdb;
		return $wpdb->prefix . 'nsbot_sources';
	}

	public static function knowledge_table(): string
	{
		global $wpdb;
		return $wpdb->prefix . 'nsbot_knowledge';
	}

	public static function inquiries_table(): string
	{
		global $wpdb;
		return $wpdb->prefix . 'nsbot_inquiries';
	}

	public static function teams_refs_table(): string
	{
		global $wpdb;
		return $wpdb->prefix . 'nsbot_teams_refs';
	}

	public static function chatlog_table(): string
	{
		global $wpdb;
		return $wpdb->prefix . 'nsbot_chatlog';
	}

	/**
	 * dbDelta でテーブルを作成（冪等）。
	 */
	public static function install(): void
	{
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$sources = self::sources_table();
		$knowledge = self::knowledge_table();
		$inquiries = self::inquiries_table();
		$teams_refs = self::teams_refs_table();
		$chatlog = self::chatlog_table();

		$sql = [];

		$sql[] = "CREATE TABLE {$sources} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			type VARCHAR(10) NOT NULL DEFAULT 'url',
			ref TEXT NOT NULL,
			label VARCHAR(255) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			message TEXT NULL,
			chunks INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$knowledge} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			source_type VARCHAR(10) NOT NULL DEFAULT 'url',
			source_label VARCHAR(255) NOT NULL DEFAULT '',
			title VARCHAR(255) NOT NULL DEFAULT '',
			chunk_text LONGTEXT NOT NULL,
			embedding LONGTEXT NULL,
			active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY source_id (source_id),
			KEY active (active)
		) {$charset};";

		$sql[] = "CREATE TABLE {$inquiries} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			question TEXT NOT NULL,
			email VARCHAR(255) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			channel VARCHAR(20) NOT NULL DEFAULT 'web',
			answer_text LONGTEXT NULL,
			knowledge_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			session VARCHAR(64) NOT NULL DEFAULT '',
			teams_conv_id VARCHAR(255) NOT NULL DEFAULT '',
			teams_service_url VARCHAR(255) NOT NULL DEFAULT '',
			reminded_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			answered_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY session (session)
		) {$charset};";

		// Teams への proactive 送信に使う会話参照（Bot 追加・受信時に upsert）。
		$sql[] = "CREATE TABLE {$teams_refs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			service_url VARCHAR(255) NOT NULL DEFAULT '',
			conversation_id VARCHAR(255) NOT NULL DEFAULT '',
			conversation_type VARCHAR(20) NOT NULL DEFAULT '',
			tenant_id VARCHAR(64) NOT NULL DEFAULT '',
			bot_id VARCHAR(64) NOT NULL DEFAULT '',
			name VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY conversation_id (conversation_id(191))
		) {$charset};";

		// 全 /chat の軽量ログ（週次レポート＝自己解決率・話題クラスタ・ピーク時間帯の集計元）。
		// embedding は ChatEngine が算出した query ベクトルを再利用して保存（クラスタリング用）。
		$sql[] = "CREATE TABLE {$chatlog} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session VARCHAR(64) NOT NULL DEFAULT '',
			question TEXT NOT NULL,
			answered TINYINT(1) NOT NULL DEFAULT 0,
			type VARCHAR(20) NOT NULL DEFAULT '',
			top_score FLOAT NOT NULL DEFAULT 0,
			heat SMALLINT NOT NULL DEFAULT 0,
			embedding LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY session (session),
			KEY created_at (created_at)
		) {$charset};";

		foreach ($sql as $statement) {
			dbDelta($statement);
		}

		update_option('nsbot_db_version', NSBOT_DB_VERSION);
	}
}
