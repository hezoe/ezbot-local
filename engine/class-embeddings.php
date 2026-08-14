<?php

declare(strict_types=1);

namespace NoviSign\Chatbot;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Voyage AI 埋め込みクライアント（WP HTTP API 使用、Composer 不要）。
 *
 * @see https://docs.voyageai.com/reference/embeddings-api
 */
final class Embeddings
{
	private const ENDPOINT = 'https://api.voyageai.com/v1/embeddings';

	/**
	 * 複数テキストをまとめて埋め込む。
	 *
	 * @param string[] $texts
	 * @param string   $input_type 'document'（索引側）or 'query'（質問側）
	 * @return float[][]|\WP_Error 入力順に対応するベクトル配列
	 */
	public static function embed(array $texts, string $input_type = 'document')
	{
		$texts = array_values(array_filter(array_map('strval', $texts), static fn ($t) => trim($t) !== ''));
		if (empty($texts)) {
			return [];
		}

		$key = Settings::voyage_key();
		if ($key === '') {
			return new \WP_Error('nsbot_no_voyage_key', 'Voyage API キーが未設定です。');
		}

		$model = (string) Settings::get('voyage_model', 'voyage-3.5');

		$response = wp_remote_post(self::ENDPOINT, [
			'timeout' => 60,
			'headers' => [
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
			],
			'body' => wp_json_encode([
				'input'      => $texts,
				'model'      => $model,
				'input_type' => $input_type,
			]),
		]);

		if (is_wp_error($response)) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if ($code !== 200 || !is_array($body) || empty($body['data'])) {
			$msg = is_array($body) && isset($body['detail'])
				? (is_string($body['detail']) ? $body['detail'] : wp_json_encode($body['detail']))
				: ('HTTP ' . $code);
			return new \WP_Error('nsbot_voyage_error', 'Voyage 埋め込みに失敗：' . $msg);
		}

		// data は index を持つので順序を保証して並べ替える。
		$vectors = [];
		foreach ($body['data'] as $item) {
			$idx = isset($item['index']) ? (int) $item['index'] : count($vectors);
			$vectors[$idx] = array_map('floatval', $item['embedding'] ?? []);
		}
		ksort($vectors);

		return array_values($vectors);
	}

	/**
	 * 単一テキストの埋め込み。
	 *
	 * @return float[]|\WP_Error
	 */
	public static function embed_one(string $text, string $input_type = 'query')
	{
		$result = self::embed([$text], $input_type);
		if (is_wp_error($result)) {
			return $result;
		}
		return $result[0] ?? new \WP_Error('nsbot_voyage_empty', '埋め込み結果が空です。');
	}
}
