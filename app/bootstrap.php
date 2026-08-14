<?php

/**
 * ezbot-local ブートストラップ
 * ============================
 * 学習用スタンドアロン版の初期化を1か所に集約する。
 *
 *   1. パス定数・プラグイン互換定数を定義
 *   2. WordPress 互換シム（compat/wp-shim.php）を読み込む
 *   3. SQLite に接続し、$wpdb（SQLite版）を用意
 *   4. スキーマ（テーブル）を冪等に作成
 *   5. コアロジック（engine/*.php）を読み込む
 *   6. cron フック（URL クロールのバッチ等）を登録
 *
 * 本番の WordPress プラグインでは 1〜6 を WordPress 本体がやってくれる。
 * ここを読めば「WordPress が裏で何をしているか」も学べる。
 */

declare(strict_types=1);

mb_internal_encoding('UTF-8');

/* --- 1. 定数 ------------------------------------------------------------- */

define('EZBOT_ROOT', dirname(__DIR__));
define('EZBOT_DATA', EZBOT_ROOT . '/data');
define('EZBOT_UPLOADS', EZBOT_DATA . '/uploads');
define('EZBOT_DB_FILE', EZBOT_DATA . '/ezbot.sqlite');

// プラグイン本体が参照する定数（改変しないため、ここで満たす）。
if (!defined('ABSPATH')) {
	define('ABSPATH', EZBOT_ROOT . '/');
}
// 同梱 pdfparser の場所（class-knowledge.php が NSBOT_PLUGIN_DIR.'lib/pdfparser/' を見る）。
if (!defined('NSBOT_PLUGIN_DIR')) {
	define('NSBOT_PLUGIN_DIR', EZBOT_ROOT . '/engine/');
}
if (!defined('NSBOT_DB_VERSION')) {
	define('NSBOT_DB_VERSION', '1.0-standalone');
}

if (!is_dir(EZBOT_DATA)) {
	mkdir(EZBOT_DATA, 0775, true);
}
if (!is_dir(EZBOT_UPLOADS)) {
	mkdir(EZBOT_UPLOADS, 0775, true);
}

/* --- 2. WordPress 互換シム ---------------------------------------------- */

require_once EZBOT_ROOT . '/compat/wp-shim.php';

/* --- 3. SQLite 接続 & $wpdb -------------------------------------------- */

$pdo = new PDO('sqlite:' . EZBOT_DB_FILE);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA journal_mode = WAL;');
$pdo->exec('PRAGMA foreign_keys = ON;');

$GLOBALS['wpdb'] = new wpdb_sqlite($pdo);

/* --- 4. スキーマ（SQLite 方言。MySQL 版 class-database.php の対応表） ---- */

ezbot_install_schema($pdo);

/* --- 5. コアロジック（本番プラグインと同一） ---------------------------- */

require_once EZBOT_ROOT . '/engine/class-database.php';
require_once EZBOT_ROOT . '/engine/class-settings.php';
require_once EZBOT_ROOT . '/engine/class-embeddings.php';
require_once EZBOT_ROOT . '/engine/class-llm.php';
require_once EZBOT_ROOT . '/engine/class-knowledge.php';
require_once EZBOT_ROOT . '/engine/class-inquiries.php';
require_once EZBOT_ROOT . '/engine/class-chatlog.php';
require_once EZBOT_ROOT . '/engine/class-session.php';
require_once EZBOT_ROOT . '/engine/class-chat-engine.php';

/* --- 6. cron フック（WordPress の add_action 相当を手動登録） ------------ */

use NoviSign\Chatbot\Knowledge;
use NoviSign\Chatbot\Inquiries;

add_action('nsbot_ingest_source', static function ($id): void {
	Knowledge::ingest_source((int) $id);
});
add_action('nsbot_crawl_batch', static function ($id): void {
	Knowledge::crawl_batch((int) $id);
});
// 問い合わせ作成/更新/回答フック（本番では Teams 通知に使う。学習版では未使用）。
add_action('nsbot_inquiry_created', static function ($id, $q, $email): void {});
add_action('nsbot_inquiry_updated', static function ($id, $add): void {});
add_action('nsbot_inquiry_answered', static function ($id, $ans, $kid): void {});

/* ------------------------------------------------------------------------ */

/**
 * MySQL 版 class-database.php の CREATE TABLE を SQLite 方言へ写したもの。
 * 変換規則：BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY → INTEGER PRIMARY KEY AUTOINCREMENT、
 *           VARCHAR/LONGTEXT/TEXT → TEXT、INT/TINYINT/SMALLINT → INTEGER、FLOAT → REAL、
 *           DATETIME → TEXT。KEY(索引) は CREATE INDEX で別途。
 * 学習版だけの補助テーブル（options / transients）もここで作る。
 */
function ezbot_install_schema(PDO $pdo): void
{
	$stmts = [
		// 知識ソース（URL / ファイル）
		'CREATE TABLE IF NOT EXISTS nsbot_sources (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			type TEXT NOT NULL DEFAULT "url",
			ref TEXT NOT NULL,
			label TEXT NOT NULL DEFAULT "",
			status TEXT NOT NULL DEFAULT "pending",
			message TEXT NULL,
			chunks INTEGER NOT NULL DEFAULT 0,
			created_at TEXT NOT NULL,
			updated_at TEXT NOT NULL
		)',
		// 知識チャンク（＋埋め込みベクトル）
		'CREATE TABLE IF NOT EXISTS nsbot_knowledge (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			source_id INTEGER NOT NULL DEFAULT 0,
			source_type TEXT NOT NULL DEFAULT "url",
			source_label TEXT NOT NULL DEFAULT "",
			title TEXT NOT NULL DEFAULT "",
			chunk_text TEXT NOT NULL,
			embedding TEXT NULL,
			active INTEGER NOT NULL DEFAULT 1,
			created_at TEXT NOT NULL
		)',
		'CREATE INDEX IF NOT EXISTS idx_knowledge_source ON nsbot_knowledge (source_id)',
		'CREATE INDEX IF NOT EXISTS idx_knowledge_active ON nsbot_knowledge (active)',
		// 問い合わせ（知識に無くメール取得したもの）
		'CREATE TABLE IF NOT EXISTS nsbot_inquiries (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			question TEXT NOT NULL,
			email TEXT NOT NULL DEFAULT "",
			status TEXT NOT NULL DEFAULT "pending",
			channel TEXT NOT NULL DEFAULT "web",
			answer_text TEXT NULL,
			knowledge_id INTEGER NOT NULL DEFAULT 0,
			session TEXT NOT NULL DEFAULT "",
			teams_conv_id TEXT NOT NULL DEFAULT "",
			teams_service_url TEXT NOT NULL DEFAULT "",
			reminded_at TEXT NULL,
			created_at TEXT NOT NULL,
			answered_at TEXT NULL
		)',
		'CREATE INDEX IF NOT EXISTS idx_inq_status ON nsbot_inquiries (status)',
		'CREATE INDEX IF NOT EXISTS idx_inq_session ON nsbot_inquiries (session)',
		// チャットログ（週次レポートの集計元）
		'CREATE TABLE IF NOT EXISTS nsbot_chatlog (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			session TEXT NOT NULL DEFAULT "",
			question TEXT NOT NULL,
			answered INTEGER NOT NULL DEFAULT 0,
			type TEXT NOT NULL DEFAULT "",
			top_score REAL NOT NULL DEFAULT 0,
			heat INTEGER NOT NULL DEFAULT 0,
			embedding TEXT NULL,
			created_at TEXT NOT NULL
		)',
		'CREATE INDEX IF NOT EXISTS idx_log_session ON nsbot_chatlog (session)',
		'CREATE INDEX IF NOT EXISTS idx_log_created ON nsbot_chatlog (created_at)',
		// 学習版の補助：オプション（WordPress の wp_options 相当）
		'CREATE TABLE IF NOT EXISTS nsbot_options (
			option_name TEXT PRIMARY KEY,
			option_value TEXT
		)',
		// 学習版の補助：トランジェント（セッション状態の保存先）
		'CREATE TABLE IF NOT EXISTS nsbot_transients (
			t_name TEXT PRIMARY KEY,
			t_value TEXT,
			t_expires INTEGER NOT NULL DEFAULT 0
		)',
	];
	foreach ($stmts as $sql) {
		$pdo->exec($sql);
	}
}
