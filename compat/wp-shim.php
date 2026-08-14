<?php

/**
 * WordPress 互換シム（学習用スタンドアロン版）
 * =============================================
 * NoviSign チャットボットのプラグイン本体（novisign-chatbot/includes/*.php）を
 * WordPress 無しで動かすための最小の代替レイヤ。
 *
 * ここが提供するもの：
 *   1. プラグインが使う WordPress 関数（約20個）の代替実装
 *   2. WP_Error クラス / is_wp_error()
 *   3. $wpdb 相当の SQLite 版データベースラッパ（wpdb_sqlite）
 *   4. 単発 cron（wp_schedule_single_event）をその場で同期実行する簡易キュー
 *
 * ★プラグイン本体（includes/*.php）は一切改変しない。差し替えはすべてここに閉じる。
 *   → ここで学んだ ChatEngine / Knowledge / LLM / Embeddings のコードは
 *     そのまま本番の WordPress プラグインへ移せる。
 */

declare(strict_types=1);

/* ---------------------------------------------------------------------------
 * 定数
 * ------------------------------------------------------------------------- */

if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);
if (!defined('HOUR_IN_SECONDS'))   define('HOUR_IN_SECONDS', 3600);
if (!defined('DAY_IN_SECONDS'))    define('DAY_IN_SECONDS', 86400);

// get_results の出力形式（WordPress と同じ名前で提供）。
if (!defined('OBJECT'))  define('OBJECT', 'OBJECT');
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

/* ---------------------------------------------------------------------------
 * WP_Error
 * ------------------------------------------------------------------------- */

if (!class_exists('WP_Error')) {
	/**
	 * WordPress の WP_Error 最小互換。プラグインは code/message しか使わない。
	 */
	class WP_Error
	{
		/** @var array<int, array{code:string, message:string}> */
		private array $errors = [];

		public function __construct(string $code = '', string $message = '')
		{
			if ($code !== '') {
				$this->errors[] = ['code' => $code, 'message' => $message];
			}
		}

		public function get_error_message(): string
		{
			return $this->errors[0]['message'] ?? '';
		}

		public function get_error_code(): string
		{
			return $this->errors[0]['code'] ?? '';
		}
	}
}

if (!function_exists('is_wp_error')) {
	function is_wp_error($thing): bool
	{
		return $thing instanceof WP_Error;
	}
}

/* ---------------------------------------------------------------------------
 * 文字列・JSON・URL ユーティリティ
 * ------------------------------------------------------------------------- */

if (!function_exists('wp_json_encode')) {
	function wp_json_encode($data, int $options = 0, int $depth = 512)
	{
		return json_encode($data, $options | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, $depth);
	}
}

if (!function_exists('wp_unslash')) {
	function wp_unslash($value)
	{
		// WordPress はマジッククォート由来の \ を剥がす。PHP8 には無いので基本は素通し。
		return is_string($value) ? stripslashes($value) : $value;
	}
}

if (!function_exists('wp_strip_all_tags')) {
	function wp_strip_all_tags(string $string, bool $remove_breaks = false): string
	{
		$string = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $string) ?? $string;
		$string = strip_tags($string);
		if ($remove_breaks) {
			$string = preg_replace('/[\r\n\t ]+/', ' ', $string) ?? $string;
		}
		return trim($string);
	}
}

if (!function_exists('sanitize_text_field')) {
	function sanitize_text_field(string $str): string
	{
		$str = wp_strip_all_tags($str);
		$str = preg_replace('/[\r\n\t]+/', ' ', $str) ?? $str;
		$str = preg_replace('/\s{2,}/', ' ', $str) ?? $str;
		return trim($str);
	}
}

if (!function_exists('sanitize_textarea_field')) {
	function sanitize_textarea_field(string $str): string
	{
		// 改行は保持しつつタグを除去。
		$str = wp_strip_all_tags($str);
		return trim($str);
	}
}

if (!function_exists('wp_parse_url')) {
	function wp_parse_url(string $url, int $component = -1)
	{
		return parse_url($url, $component);
	}
}

/* ---------------------------------------------------------------------------
 * 時刻
 * ------------------------------------------------------------------------- */

if (!function_exists('current_time')) {
	/**
	 * WordPress の current_time 最小互換。
	 *  - 'mysql'     → 'Y-m-d H:i:s'
	 *  - 'timestamp' → time()
	 */
	function current_time(string $type, int $gmt = 0)
	{
		if ($type === 'timestamp' || $type === 'U') {
			return time();
		}
		return date('Y-m-d H:i:s');
	}
}

/* ---------------------------------------------------------------------------
 * HTTP（Anthropic / Voyage / URL 取り込みで使用）— cURL 実装
 * ------------------------------------------------------------------------- */

if (!function_exists('nsbot_http_request')) {
	/**
	 * @param array<string, mixed> $args
	 * @return array{response:array{code:int}, body:string, headers:array<string,string>}|WP_Error
	 */
	function nsbot_http_request(string $url, string $method, array $args)
	{
		$ch = curl_init($url);
		if ($ch === false) {
			return new WP_Error('http_request_failed', 'cURL の初期化に失敗しました。');
		}

		$headers = [];
		foreach (($args['headers'] ?? []) as $k => $v) {
			$headers[] = $k . ': ' . $v;
		}

		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER         => true,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_TIMEOUT        => (int) ($args['timeout'] ?? 30),
			CURLOPT_FOLLOWLOCATION => (int) ($args['redirection'] ?? 5) > 0,
			CURLOPT_MAXREDIRS      => (int) ($args['redirection'] ?? 5),
			CURLOPT_USERAGENT      => (string) ($args['user-agent'] ?? 'NoviSignChatbot/1.0 (standalone)'),
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_SSL_VERIFYPEER => true,
		]);
		if ($method === 'POST' && isset($args['body'])) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $args['body']);
		}

		$raw = curl_exec($ch);
		if ($raw === false) {
			$err = curl_error($ch);
			curl_close($ch);
			return new WP_Error('http_request_failed', 'HTTP 通信に失敗しました：' . $err);
		}

		$code        = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$header_size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		curl_close($ch);

		$raw = (string) $raw;
		$header_txt = substr($raw, 0, $header_size);
		$body       = substr($raw, $header_size);

		$hmap = [];
		foreach (preg_split('/\r?\n/', $header_txt) ?: [] as $line) {
			$pos = strpos($line, ':');
			if ($pos !== false) {
				$hmap[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
			}
		}

		return ['response' => ['code' => $code], 'body' => $body, 'headers' => $hmap];
	}
}

if (!function_exists('wp_remote_post')) {
	function wp_remote_post(string $url, array $args = [])
	{
		return nsbot_http_request($url, 'POST', $args);
	}
}

if (!function_exists('wp_remote_get')) {
	function wp_remote_get(string $url, array $args = [])
	{
		return nsbot_http_request($url, 'GET', $args);
	}
}

if (!function_exists('wp_remote_retrieve_response_code')) {
	function wp_remote_retrieve_response_code($response)
	{
		return is_array($response) ? ($response['response']['code'] ?? '') : '';
	}
}

if (!function_exists('wp_remote_retrieve_body')) {
	function wp_remote_retrieve_body($response): string
	{
		return is_array($response) ? (string) ($response['body'] ?? '') : '';
	}
}

if (!function_exists('wp_remote_retrieve_header')) {
	function wp_remote_retrieve_header($response, string $header): string
	{
		if (!is_array($response)) {
			return '';
		}
		return (string) ($response['headers'][strtolower($header)] ?? '');
	}
}

/* ---------------------------------------------------------------------------
 * オプション / トランジェント（SQLite に永続化）
 * ------------------------------------------------------------------------- */

if (!function_exists('get_option')) {
	function get_option(string $name, $default = false)
	{
		global $wpdb;
		$row = $wpdb->get_var($wpdb->prepare(
			'SELECT option_value FROM nsbot_options WHERE option_name = %s',
			$name
		));
		if ($row === null) {
			return $default;
		}
		$decoded = json_decode((string) $row, true);
		return $decoded === null && $row !== 'null' ? $default : $decoded;
	}
}

if (!function_exists('update_option')) {
	function update_option(string $name, $value, $autoload = null): bool
	{
		global $wpdb;
		$json = wp_json_encode($value);
		$wpdb->query($wpdb->prepare(
			'INSERT INTO nsbot_options (option_name, option_value) VALUES (%s, %s) '
			. 'ON CONFLICT(option_name) DO UPDATE SET option_value = %s',
			$name,
			$json,
			$json
		));
		return true;
	}
}

if (!function_exists('delete_option')) {
	function delete_option(string $name): bool
	{
		global $wpdb;
		$wpdb->query($wpdb->prepare('DELETE FROM nsbot_options WHERE option_name = %s', $name));
		return true;
	}
}

if (!function_exists('set_transient')) {
	function set_transient(string $name, $value, int $ttl = 0): bool
	{
		global $wpdb;
		$expires = $ttl > 0 ? time() + $ttl : 0;
		$json = wp_json_encode($value);
		$wpdb->query($wpdb->prepare(
			'INSERT INTO nsbot_transients (t_name, t_value, t_expires) VALUES (%s, %s, %d) '
			. 'ON CONFLICT(t_name) DO UPDATE SET t_value = %s, t_expires = %d',
			$name,
			$json,
			$expires,
			$json,
			$expires
		));
		return true;
	}
}

if (!function_exists('get_transient')) {
	function get_transient(string $name)
	{
		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare(
			'SELECT t_value, t_expires FROM nsbot_transients WHERE t_name = %s',
			$name
		));
		if (!$row) {
			return false;
		}
		if ((int) $row->t_expires !== 0 && (int) $row->t_expires < time()) {
			delete_transient($name);
			return false;
		}
		$decoded = json_decode((string) $row->t_value, true);
		return $decoded === null ? false : $decoded;
	}
}

if (!function_exists('delete_transient')) {
	function delete_transient(string $name): bool
	{
		global $wpdb;
		$wpdb->query($wpdb->prepare('DELETE FROM nsbot_transients WHERE t_name = %s', $name));
		return true;
	}
}

/* ---------------------------------------------------------------------------
 * フック（do_action / add_action）＋ 単発 cron の同期実行キュー
 * ------------------------------------------------------------------------- */

$GLOBALS['nsbot_actions'] = [];   // hook => callable[]
$GLOBALS['nsbot_cron']    = [];   // 予約済み単発イベント [hook, args]

if (!function_exists('add_action')) {
	function add_action(string $hook, callable $cb, int $priority = 10, int $accepted_args = 1): bool
	{
		$GLOBALS['nsbot_actions'][$hook][] = $cb;
		return true;
	}
}

if (!function_exists('do_action')) {
	function do_action(string $hook, ...$args): void
	{
		foreach ($GLOBALS['nsbot_actions'][$hook] ?? [] as $cb) {
			$cb(...$args);
		}
	}
}

if (!function_exists('wp_next_scheduled')) {
	function wp_next_scheduled(string $hook, array $args = [])
	{
		foreach ($GLOBALS['nsbot_cron'] as $ev) {
			if ($ev['hook'] === $hook && $ev['args'] === $args) {
				return time();
			}
		}
		return false;
	}
}

if (!function_exists('wp_schedule_single_event')) {
	function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool
	{
		$GLOBALS['nsbot_cron'][] = ['hook' => $hook, 'args' => $args];
		return true;
	}
}

if (!function_exists('nsbot_run_cron')) {
	/**
	 * 予約された単発イベントをその場で（同期的に）実行しきる。
	 * WordPress では WP-Cron がバックグラウンドで走るが、学習用では
	 * リクエスト内で完結させる方が挙動が追いやすい。URL クロールの
	 * バッチ（nsbot_crawl_batch）もこれで最後まで進む。
	 */
	function nsbot_run_cron(int $max_iterations = 1000): void
	{
		$i = 0;
		while (!empty($GLOBALS['nsbot_cron']) && $i < $max_iterations) {
			$ev = array_shift($GLOBALS['nsbot_cron']);
			$i++;
			do_action($ev['hook'], ...$ev['args']);
		}
	}
}

/* ---------------------------------------------------------------------------
 * $wpdb 相当：SQLite 版データベースラッパ
 * ------------------------------------------------------------------------- */

if (!class_exists('wpdb_sqlite')) {
	/**
	 * プラグインが使う $wpdb のメソッド群だけを SQLite(PDO) で再現する。
	 * insert / update / delete / get_results / get_row / get_var / get_col /
	 * query / prepare / insert_id / prefix。
	 */
	class wpdb_sqlite
	{
		public string $prefix = '';       // 学習用は接頭辞なし（テーブル名 = nsbot_*）
		public int $insert_id = 0;
		public string $last_error = '';
		private \PDO $pdo;

		public function __construct(\PDO $pdo)
		{
			$this->pdo = $pdo;
		}

		public function pdo(): \PDO
		{
			return $this->pdo;
		}

		public function get_charset_collate(): string
		{
			return '';
		}

		/**
		 * WordPress 風プレースホルダ（%s / %d / %f）を安全に埋め込む。
		 * 引数は可変長でも配列一つでも受ける（WP と同じ）。
		 */
		public function prepare(string $query, ...$args): string
		{
			if (count($args) === 1 && is_array($args[0])) {
				$args = $args[0];
			}
			$i = 0;
			return preg_replace_callback('/%[sdf]/', function ($m) use (&$i, $args) {
				$v = $args[$i] ?? '';
				$i++;
				switch ($m[0]) {
					case '%d':
						return (string) (int) $v;
					case '%f':
						return (string) (float) $v;
					default:
						return $this->pdo->quote((string) $v);
				}
			}, $query) ?? $query;
		}

		/**
		 * @param array<string, mixed> $data 列 => 値
		 */
		public function insert(string $table, array $data): int
		{
			$cols = array_keys($data);
			$ph   = array_map(static fn ($c) => ':' . $c, $cols);
			$sql  = "INSERT INTO {$table} (" . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')';
			$stmt = $this->pdo->prepare($sql);
			$this->bind($stmt, $data);
			$stmt->execute();
			$this->insert_id = (int) $this->pdo->lastInsertId();
			return $stmt->rowCount();
		}

		/**
		 * @param array<string, mixed> $data  更新する 列 => 値
		 * @param array<string, mixed> $where 条件 列 => 値（AND 結合）
		 */
		public function update(string $table, array $data, array $where): int
		{
			$set = [];
			foreach (array_keys($data) as $c) {
				$set[] = "{$c} = :set_{$c}";
			}
			$cond = [];
			foreach (array_keys($where) as $c) {
				$cond[] = "{$c} = :where_{$c}";
			}
			$sql  = "UPDATE {$table} SET " . implode(', ', $set) . ' WHERE ' . implode(' AND ', $cond);
			$stmt = $this->pdo->prepare($sql);
			foreach ($data as $c => $v) {
				$this->bindValue($stmt, ":set_{$c}", $v);
			}
			foreach ($where as $c => $v) {
				$this->bindValue($stmt, ":where_{$c}", $v);
			}
			$stmt->execute();
			return $stmt->rowCount();
		}

		/**
		 * @param array<string, mixed> $where
		 */
		public function delete(string $table, array $where): int
		{
			$cond = [];
			foreach (array_keys($where) as $c) {
				$cond[] = "{$c} = :{$c}";
			}
			$sql  = "DELETE FROM {$table} WHERE " . implode(' AND ', $cond);
			$stmt = $this->pdo->prepare($sql);
			$this->bind($stmt, $where);
			$stmt->execute();
			return $stmt->rowCount();
		}

		public function query(string $sql): int
		{
			$affected = $this->pdo->exec($sql);
			return $affected === false ? 0 : (int) $affected;
		}

		/**
		 * @return array<int, object|array>
		 */
		public function get_results(string $sql, string $output = OBJECT): array
		{
			$stmt = $this->pdo->query($sql);
			if ($stmt === false) {
				return [];
			}
			$mode = $output === ARRAY_A ? \PDO::FETCH_ASSOC : \PDO::FETCH_OBJ;
			return $stmt->fetchAll($mode) ?: [];
		}

		public function get_row(string $sql, string $output = OBJECT)
		{
			$stmt = $this->pdo->query($sql);
			if ($stmt === false) {
				return null;
			}
			$mode = $output === ARRAY_A ? \PDO::FETCH_ASSOC : \PDO::FETCH_OBJ;
			$row = $stmt->fetch($mode);
			return $row === false ? null : $row;
		}

		public function get_var(string $sql)
		{
			$stmt = $this->pdo->query($sql);
			if ($stmt === false) {
				return null;
			}
			$val = $stmt->fetchColumn(0);
			return $val === false ? null : $val;
		}

		/**
		 * @return array<int, mixed>
		 */
		public function get_col(string $sql): array
		{
			$stmt = $this->pdo->query($sql);
			if ($stmt === false) {
				return [];
			}
			return $stmt->fetchAll(\PDO::FETCH_COLUMN, 0) ?: [];
		}

		/**
		 * @param array<string, mixed> $data
		 */
		private function bind(\PDOStatement $stmt, array $data): void
		{
			foreach ($data as $c => $v) {
				$this->bindValue($stmt, ':' . $c, $v);
			}
		}

		private function bindValue(\PDOStatement $stmt, string $ph, $v): void
		{
			if ($v === null) {
				$stmt->bindValue($ph, null, \PDO::PARAM_NULL);
			} elseif (is_int($v)) {
				$stmt->bindValue($ph, $v, \PDO::PARAM_INT);
			} elseif (is_bool($v)) {
				$stmt->bindValue($ph, $v ? 1 : 0, \PDO::PARAM_INT);
			} else {
				$stmt->bindValue($ph, (string) $v, \PDO::PARAM_STR);
			}
		}
	}
}
