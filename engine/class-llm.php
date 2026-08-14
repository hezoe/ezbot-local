<?php

declare(strict_types=1);

namespace NoviSign\Chatbot;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Claude（Anthropic Messages API）クライアント（WP HTTP API 使用、Composer 不要）。
 *
 * @see https://docs.anthropic.com/en/api/messages
 */
final class LLM
{
	private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
	private const API_VERSION = '2023-06-01';

	/**
	 * @param string $system  システムプロンプト（静的指示。プロンプトキャッシュ対象）
	 * @param string $user    ユーザーメッセージ（コンテキスト＋質問）
	 * @return string|\WP_Error 生成テキスト
	 */
	public static function complete(string $system, string $user)
	{
		return self::chat($system, [['role' => 'user', 'content' => $user]]);
	}

	/**
	 * 会話履歴つきの生成。マルチターン（確認質問→回答）に対応。
	 *
	 * @param string $system   システムプロンプト（静的指示。プロンプトキャッシュ対象）
	 * @param array<int, array{role:string, content:string}> $messages  user/assistant の交互履歴（末尾が最新の user）
	 * @return string|\WP_Error 生成テキスト
	 */
	public static function chat(string $system, array $messages)
	{
		$key = Settings::anthropic_key();
		if ($key === '') {
			return new \WP_Error('nsbot_no_anthropic_key', 'Anthropic API キーが未設定です。');
		}

		$model = (string) Settings::get('anthropic_model', 'claude-haiku-4-5-20251001');
		$max_tokens = (int) Settings::get('max_tokens', 1500);

		// role を user/assistant に正規化し、空・連続重複を整理。
		$clean = [];
		foreach ($messages as $m) {
			$role = (($m['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
			$content = trim((string) ($m['content'] ?? ''));
			if ($content === '') {
				continue;
			}
			$clean[] = ['role' => $role, 'content' => $content];
		}
		// Anthropic は先頭を user 始まりにする必要がある。
		while (!empty($clean) && $clean[0]['role'] !== 'user') {
			array_shift($clean);
		}
		if (empty($clean)) {
			return new \WP_Error('nsbot_empty_messages', 'メッセージが空です。');
		}

		$payload = [
			'model'      => $model,
			'max_tokens' => $max_tokens > 0 ? $max_tokens : 1500,
			// 静的指示はキャッシュして再利用（コスト削減）。
			'system' => [
				[
					'type'          => 'text',
					'text'          => $system,
					'cache_control' => ['type' => 'ephemeral'],
				],
			],
			'messages' => $clean,
		];

		$response = wp_remote_post(self::ENDPOINT, [
			'timeout' => 60,
			'headers' => [
				'x-api-key'         => $key,
				'anthropic-version' => self::API_VERSION,
				'content-type'      => 'application/json',
			],
			'body' => wp_json_encode($payload),
		]);

		if (is_wp_error($response)) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if ($code !== 200 || !is_array($body)) {
			$msg = is_array($body) && isset($body['error']['message'])
				? (string) $body['error']['message']
				: ('HTTP ' . $code);
			return new \WP_Error('nsbot_anthropic_error', 'Claude 生成に失敗：' . $msg);
		}

		// content は text ブロックの配列。
		$text = '';
		foreach (($body['content'] ?? []) as $block) {
			if (($block['type'] ?? '') === 'text') {
				$text .= $block['text'] ?? '';
			}
		}

		return trim($text);
	}
}
