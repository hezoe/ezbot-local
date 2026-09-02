<?php

declare(strict_types=1);

namespace NoviSign\Chatbot;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * 設定の取得・保存。WordPress option `nsbot_settings` に集約。
 *
 * ※ API キーは本来 wp-config 等の方が安全だが、運用簡便のため option に格納。
 *   将来必要なら定数 NSBOT_ANTHROPIC_KEY / NSBOT_VOYAGE_KEY での上書きに対応済み。
 */
final class Settings
{
	private const OPTION = 'nsbot_settings';

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array
	{
		return [
			'anthropic_key'   => '',
			'anthropic_model' => 'claude-haiku-4-5-20251001',
			'voyage_key'      => '',
			'voyage_model'    => 'voyage-3.5',
			// Teams ボット（Phase 2）。Azure Bot の MicrosoftAppId / クライアントシークレット / テナント。
			// 定数 NSBOT_TEAMS_APP_ID / NSBOT_TEAMS_APP_PASSWORD / NSBOT_TEAMS_TENANT_ID で上書き可。
			'teams_app_id'       => '',
			'teams_app_password' => '',
			'teams_tenant_id'    => '',
			// マルチテナント Bot は 'common'、シングルテナントはテナントGUID（accessor が tenant_id を優先）。
			'top_k'           => 8,
			// voyage-3.5 の日本語コサイン類似度は 0.4〜0.65 に固まりやすい。
			// 0.55 は正しい知識まで却下する誤判定が多かったため 0.45 を既定とし、
			// 最終的な該当/非該当判定は Claude の <<NO_ANSWER>> に委ねる設計。
			'threshold'       => 0.45,
			// threshold を1件も超えなかったときだけ、ここまでのスコアを
			// 「関連度の低い候補」として Claude に見せる（0.45 との僅差で落ちた
			// 正しい知識を拾うため）。文脈を空にすると必ず引き継ぎに倒れてしまう。
			'threshold_floor' => 0.35,
			// 普段は「要点を簡潔に」のプロンプト指示で短く収まる。これは長い手順を
			// 出すときでも文の途中で切れないための保険枠（参照リンクは末尾にあるため
			// 真っ先に巻き添えになる）。短答指示があるため毎回フルに使うわけではない。
			'max_tokens'      => 1500,
			'chunk_size'      => 1000,
			'chunk_overlap'   => 150,
			// URL取り込み時に巡回する最大ページ数（指定URLの配下を幅優先で巡回）。1=指定ページのみ。
			// 巡回しながら数ページずつ逐次RAG追加するため、多ページでもタイムアウトしない。
			'crawl_max_pages' => 120,
			// 即引き継がず、まず確認質問で掘り下げる回数の上限。
			'max_clarify'     => 2,
			'fallback'        => 'すみません、その内容はまだ私の知識にありません。担当者が確認して追ってお答えしますので、よろしければご連絡先のメールアドレスを教えてください。',
			// 連絡先取得済みのセッションで、追加の不明点が出たときの返答（メールは再取得しない）。
			'noted_message'   => '承知しました。その点もあわせて担当者に確認し、追ってご連絡します。',
			'email_thanks'    => 'ありがとうございます。担当者より追ってご連絡いたします。',
			// Teams で担当者が回答したときに提示する「お客様へのメール返信例」。
			// 差し込み記号：{question}（お客様の質問）/ {answer}（担当者の回答）/ {email}（返信先）。
			'mail_subject'    => 'お問い合わせいただいた件について',
			'mail_template'   => "お世話になっております。\nこのたびはお問い合わせいただき、誠にありがとうございます。\n頂きましたご質問につきまして、下記のとおり回答いたします。\n\n【ご質問】\n{question}\n\n【回答】\n{answer}\n\nご不明な点がございましたら、お気軽にご返信ください。\n今後ともよろしくお願いいたします。",
			// 感情（ヒート）検知。LLMが各応答に <<HEAT:NN>> を付与→抽出。
			'heat_threshold'  => 70,   // これ以上で「高ヒート」＝確認質問を飛ばして即人へ＋共感＋カード強調
			'empathy_note'    => 'お困りのところ恐れ入ります。',  // 高ヒート時の引き継ぎ文の冒頭に添える一言
			// Phase 3：週次レポート＆チャットログ。
			'chatlog_enabled'        => 1,   // 全 /chat を軽量ログ（レポート集計元）
			'report_enabled'         => 1,   // 週次レポートを Teams へ自動送信
			'chatlog_retention_days' => 90,  // これより古いログは週次で自動削除（PII配慮）
			'inquiry_retention_days' => 0,   // 回答済み問い合わせの保持日数（0=無期限。PII配慮で設定）
			// 未対応リマインド：N営業日(土日除外)以上 未対応なら通知スレッドへ返信（毎時チェック）。
			'reminders_enabled'      => 1,
			'reminder_business_days' => 1,
			'widget_enabled'  => 1,
			'widget_title'    => '自分ボット',
			'widget_greeting' => 'こんにちは🐱 取り込んだ知識のことなら何でも聞いてください。',
			// ランチャー横に時々表示する誘導吹き出し（Botだと気づいてもらうため）。
			'bubble_enabled'  => 1,
			'bubble_text'     => 'なんでも聞いてにゃん🐱',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function all(): array
	{
		$saved = get_option(self::OPTION, []);
		if (!is_array($saved)) {
			$saved = [];
		}
		return array_merge(self::defaults(), $saved);
	}

	public static function get(string $key, mixed $default = null): mixed
	{
		$all = self::all();
		return $all[$key] ?? $default;
	}

	/**
	 * 定数があれば優先（キーの安全な投入経路）。
	 */
	public static function anthropic_key(): string
	{
		if (defined('NSBOT_ANTHROPIC_KEY') && NSBOT_ANTHROPIC_KEY) {
			return (string) NSBOT_ANTHROPIC_KEY;
		}
		return (string) self::get('anthropic_key', '');
	}

	public static function voyage_key(): string
	{
		if (defined('NSBOT_VOYAGE_KEY') && NSBOT_VOYAGE_KEY) {
			return (string) NSBOT_VOYAGE_KEY;
		}
		return (string) self::get('voyage_key', '');
	}

	public static function teams_app_id(): string
	{
		if (defined('NSBOT_TEAMS_APP_ID') && NSBOT_TEAMS_APP_ID) {
			return (string) NSBOT_TEAMS_APP_ID;
		}
		return (string) self::get('teams_app_id', '');
	}

	public static function teams_app_password(): string
	{
		if (defined('NSBOT_TEAMS_APP_PASSWORD') && NSBOT_TEAMS_APP_PASSWORD) {
			return (string) NSBOT_TEAMS_APP_PASSWORD;
		}
		return (string) self::get('teams_app_password', '');
	}

	/**
	 * AAD トークン取得・JWT 検証で使うテナント。未設定時は 'common'（マルチテナント Bot）。
	 */
	public static function teams_tenant_id(): string
	{
		if (defined('NSBOT_TEAMS_TENANT_ID') && NSBOT_TEAMS_TENANT_ID) {
			return (string) NSBOT_TEAMS_TENANT_ID;
		}
		$t = (string) self::get('teams_tenant_id', '');
		return $t !== '' ? $t : 'common';
	}

	public static function teams_configured(): bool
	{
		return self::teams_app_id() !== '' && self::teams_app_password() !== '';
	}

	public static function option_name(): string
	{
		return self::OPTION;
	}

	/**
	 * 設定フォームの入力をサニタイズ。
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function sanitize(array $input): array
	{
		$out = self::all();

		$text_keys = ['anthropic_model', 'voyage_model', 'widget_title', 'teams_app_id', 'teams_tenant_id', 'mail_subject', 'empathy_note', 'bubble_text'];
		foreach ($text_keys as $k) {
			if (isset($input[$k])) {
				$out[$k] = sanitize_text_field((string) $input[$k]);
			}
		}

		$secret_keys = ['anthropic_key', 'voyage_key'];
		foreach ($secret_keys as $k) {
			if (isset($input[$k])) {
				$out[$k] = self::clean_api_key((string) $input[$k]);
			}
		}

		// Azure クライアントシークレットは記号（. ~ - _ 等）を含み得るため clean_api_key は使わず、
		// マジッククォート除去＋前後空白トリムのみ。
		if (isset($input['teams_app_password'])) {
			$out['teams_app_password'] = trim(wp_unslash((string) $input['teams_app_password']));
		}

		$textarea_keys = ['fallback', 'noted_message', 'email_thanks', 'widget_greeting', 'mail_template'];
		foreach ($textarea_keys as $k) {
			if (isset($input[$k])) {
				$out[$k] = sanitize_textarea_field((string) $input[$k]);
			}
		}

		$int_keys = ['top_k', 'max_tokens', 'chunk_size', 'chunk_overlap', 'max_clarify', 'chatlog_retention_days', 'inquiry_retention_days', 'heat_threshold', 'reminder_business_days', 'crawl_max_pages'];
		foreach ($int_keys as $k) {
			if (isset($input[$k])) {
				$out[$k] = max(0, (int) $input[$k]);
			}
		}

		// チェックボックス（送信されなければ 0）。
		$out['chatlog_enabled']   = empty($input['chatlog_enabled']) ? 0 : 1;
		$out['report_enabled']    = empty($input['report_enabled']) ? 0 : 1;
		$out['reminders_enabled'] = empty($input['reminders_enabled']) ? 0 : 1;

		$float_keys = ['threshold', 'threshold_floor'];
		foreach ($float_keys as $k) {
			if (isset($input[$k])) {
				$out[$k] = min(1.0, max(0.0, (float) $input[$k]));
			}
		}

		$out['widget_enabled'] = empty($input['widget_enabled']) ? 0 : 1;
		$out['bubble_enabled'] = empty($input['bubble_enabled']) ? 0 : 1;

		return $out;
	}

	/**
	 * API キーのコピペ事故対策：内部の空白／改行を除去し、
	 * 前後のマークダウン記号・引用符・括弧（**, `, " ' < > [ ] ( ) など）を剥がす。
	 * API キーは英数字と `-` `_` のみで構成されるため、これらの除去は安全。
	 */
	private static function clean_api_key(string $v): string
	{
		$v = wp_unslash($v);                                  // WP マジッククォートが付与した \ を除去
		$v = preg_replace('/\s+/u', '', $v) ?? $v;            // 内部・前後の空白/改行を除去
		$v = trim($v, "*`\"'<>[](){}|, \t\r\n");               // 前後の装飾記号を剥がす
		// API キーは [A-Za-z0-9_-] のみ。混入した残りの記号（\ 等）を保険で除去。
		$v = preg_replace('/[^A-Za-z0-9_\-]/', '', $v) ?? $v;
		return $v;
	}
}
