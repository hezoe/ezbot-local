<?php

/**
 * ezbot-local フロントコントローラ（ルーター）
 * ===========================================
 * PHP 内蔵サーバーで動かす想定：
 *   php -S 127.0.0.1:8000 public/index.php
 *
 * すべてのリクエストがこのファイルを通る。パスで処理を振り分ける。
 * 画面：  /（チャット）  /admin（管理GUI）
 * API ：  /api/chat  /api/email
 * 管理操作：/admin/... （フォーム POST → 処理 → /admin へリダイレクト）
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use NoviSign\Chatbot\ChatEngine;
use NoviSign\Chatbot\Session;
use NoviSign\Chatbot\Settings;
use NoviSign\Chatbot\Knowledge;
use NoviSign\Chatbot\Inquiries;
use NoviSign\Chatbot\Chatlog;

/* --- ヘルパ ------------------------------------------------------------- */

/** HTML エスケープ。 */
function h($s): string
{
	return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** JSON を返して終了。 */
function json_out($data, int $code = 200): void
{
	http_response_code($code);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

/** リクエストボディを JSON として読む。 */
function json_in(): array
{
	$raw = file_get_contents('php://input') ?: '';
	$d = json_decode($raw, true);
	return is_array($d) ? $d : [];
}

/** /admin へフラッシュメッセージ付きでリダイレクト。 */
function redirect_admin(string $tab, string $msg = '', string $type = 'ok'): void
{
	$q = 'tab=' . rawurlencode($tab);
	if ($msg !== '') {
		$q .= '&msg=' . rawurlencode($msg) . '&mt=' . rawurlencode($type);
	}
	header('Location: /admin?' . $q);
	exit;
}

/** ビューを描画。 */
function render(string $view, array $vars = []): void
{
	extract($vars, EXTR_SKIP);
	require dirname(__DIR__) . '/app/views/' . $view . '.php';
	exit;
}

/* --- ルーティング ------------------------------------------------------- */

$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ========================= 画面 ========================= */

if ($path === '/' && $method === 'GET') {
	$cfg = Settings::all();
	render('chat', ['cfg' => $cfg]);
}

if ($path === '/admin' && $method === 'GET') {
	$tab = $_GET['tab'] ?? 'knowledge';
	render('admin', [
		'tab'      => $tab,
		'cfg'      => Settings::all(),
		'sources'  => Knowledge::list_sources(),
		'chunks'   => Knowledge::list_knowledge('', 500),
		'inquiries'=> Inquiries::list(200),
		'logs'     => Chatlog::rows_between('1970-01-01 00:00:00', '2999-01-01 00:00:00'),
		'stats'    => [
			'active_chunks' => Knowledge::count_active(),
			'pending'       => Inquiries::count_pending(),
			'logs'          => Chatlog::count(),
		],
		'msg'      => $_GET['msg'] ?? '',
		'mt'       => $_GET['mt'] ?? 'ok',
	]);
}

/* ========================= チャット API ========================= */

if ($path === '/api/chat' && $method === 'POST') {
	$in      = json_in();
	$message = trim((string) ($in['message'] ?? ''));
	$history = is_array($in['history'] ?? null) ? $in['history'] : [];
	$sid     = (string) ($in['session_id'] ?? '');

	// 直近の履歴だけ渡す（安全側に整形）。
	$clean_history = [];
	foreach (array_slice($history, -12) as $m) {
		$role    = (($m['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
		$content = trim((string) ($m['content'] ?? ''));
		if ($content !== '') {
			$clean_history[] = ['role' => $role, 'content' => $content];
		}
	}

	$out = ChatEngine::answer($message, $clean_history, $sid);
	json_out($out);
}

if ($path === '/api/email' && $method === 'POST') {
	$in       = json_in();
	$email    = trim((string) ($in['email'] ?? ''));
	$sid      = (string) ($in['session_id'] ?? '');
	$question = trim((string) ($in['question'] ?? ''));

	if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
		json_out(['ok' => false, 'reply' => 'メールアドレスの形式が正しくないようです。もう一度ご確認ください。'], 200);
	}

	Session::register_email(Session::sanitize_id($sid), $email, $question);
	$thanks = (string) Settings::get('email_thanks', 'ありがとうございます。担当者より追ってご連絡いたします。');
	json_out(['ok' => true, 'reply' => $thanks]);
}

/* ========================= 管理操作（フォーム POST） ========================= */

if ($path === '/admin/settings' && $method === 'POST') {
	$sanitized = Settings::sanitize($_POST);
	update_option(Settings::option_name(), $sanitized);
	redirect_admin('settings', '設定を保存しました。');
}

if ($path === '/admin/source/add' && $method === 'POST') {
	$kind  = $_POST['kind'] ?? 'url';
	$label = trim((string) ($_POST['label'] ?? ''));

	if ($kind === 'url') {
		$url = trim((string) ($_POST['url'] ?? ''));
		if ($url === '' || !preg_match('#^https?://#i', $url)) {
			redirect_admin('knowledge', 'URL の形式が正しくありません。', 'err');
		}
		$id = Knowledge::add_source('url', $url, $label !== '' ? $label : $url);
		$res = Knowledge::ingest_source($id);   // URL は start_crawl → cron 予約
		nsbot_run_cron();                        // 予約バッチをこの場で最後まで実行
		$src = Knowledge::get_source($id);
		$m = $src && $src->status === 'indexed'
			? "URL を取り込みました（{$src->chunks} チャンク）。"
			: '取り込みを実行しました（状態: ' . ($src->status ?? '?') . ' / ' . ($src->message ?? '') . '）。';
		redirect_admin('knowledge', $m, is_wp_error($res) ? 'err' : 'ok');
	}

	if ($kind === 'text') {
		$text = (string) ($_POST['text'] ?? '');
		if (trim($text) === '') {
			redirect_admin('knowledge', 'テキストが空です。', 'err');
		}
		$name = 'text-' . date('Ymd-His') . '-' . substr(md5($text), 0, 6) . '.txt';
		$file = EZBOT_UPLOADS . '/' . $name;
		file_put_contents($file, $text);
		$id = Knowledge::add_source('file', $file, $label !== '' ? $label : '貼り付けテキスト');
		$res = Knowledge::ingest_source($id);
		$src = Knowledge::get_source($id);
		$m = is_wp_error($res)
			? '取り込みに失敗：' . $res->get_error_message()
			: "テキストを取り込みました（{$src->chunks} チャンク）。";
		redirect_admin('knowledge', $m, is_wp_error($res) ? 'err' : 'ok');
	}

	if ($kind === 'file') {
		if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
			redirect_admin('knowledge', 'ファイルが選択されていません。', 'err');
		}
		$orig = basename((string) $_FILES['file']['name']);
		$safe = preg_replace('/[^A-Za-z0-9._\-]/', '_', $orig) ?: 'upload';
		$dest = EZBOT_UPLOADS . '/' . date('Ymd-His') . '-' . $safe;
		if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
			redirect_admin('knowledge', 'アップロードの保存に失敗しました。', 'err');
		}
		$id = Knowledge::add_source('file', $dest, $label !== '' ? $label : $orig);
		$res = Knowledge::ingest_source($id);
		$src = Knowledge::get_source($id);
		$m = is_wp_error($res)
			? '取り込みに失敗：' . $res->get_error_message()
			: "「{$orig}」を取り込みました（{$src->chunks} チャンク）。";
		redirect_admin('knowledge', $m, is_wp_error($res) ? 'err' : 'ok');
	}

	redirect_admin('knowledge', '不明な種別です。', 'err');
}

if ($path === '/admin/source/delete' && $method === 'POST') {
	Knowledge::delete_source((int) ($_POST['id'] ?? 0));
	redirect_admin('knowledge', 'ソースを削除しました。');
}

if ($path === '/admin/source/reingest' && $method === 'POST') {
	$id = (int) ($_POST['id'] ?? 0);
	$res = Knowledge::ingest_source($id);
	nsbot_run_cron();
	redirect_admin('knowledge', is_wp_error($res) ? ('再取り込み失敗：' . $res->get_error_message()) : '再取り込みしました。', is_wp_error($res) ? 'err' : 'ok');
}

if ($path === '/admin/chunk/delete' && $method === 'POST') {
	Knowledge::delete_chunk((int) ($_POST['id'] ?? 0));
	redirect_admin('chunks', 'チャンクを削除しました。');
}

if ($path === '/admin/inquiry/answer' && $method === 'POST') {
	$id     = (int) ($_POST['id'] ?? 0);
	$answer = trim((string) ($_POST['answer'] ?? ''));
	if ($answer === '') {
		redirect_admin('inquiries', '回答が空です。', 'err');
	}
	Inquiries::answer($id, $answer);   // ← 回答を知識として学習（add_qa）
	redirect_admin('inquiries', '回答を保存し、知識として学習しました。');
}

if ($path === '/admin/export' && $method === 'GET') {
	$data = Knowledge::export_json();
	header('Content-Type: application/json; charset=utf-8');
	header('Content-Disposition: attachment; filename="ezbot-knowledge-' . date('Ymd-His') . '.json"');
	echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
	exit;
}

if ($path === '/admin/import' && $method === 'POST') {
	if (empty($_FILES['file']['tmp_name'])) {
		redirect_admin('tools', 'ファイルが選択されていません。', 'err');
	}
	$data = json_decode((string) file_get_contents($_FILES['file']['tmp_name']), true);
	if (!is_array($data)) {
		redirect_admin('tools', 'JSON を読み込めませんでした。', 'err');
	}
	$mode = ($_POST['mode'] ?? 'merge') === 'replace' ? 'replace' : 'merge';
	$res  = Knowledge::import_json($data, $mode);
	if (is_wp_error($res)) {
		redirect_admin('tools', 'インポート失敗：' . $res->get_error_message(), 'err');
	}
	$m = "インポート完了：追加 {$res['added']} / 重複スキップ {$res['skipped']} / ソース {$res['sources']}。";
	if (!empty($res['model_warning'])) {
		$m .= ' ⚠️ ' . $res['model_warning'];
	}
	redirect_admin('tools', $m);
}

/* ========================= 404 ========================= */

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "404 Not Found: {$path}";
