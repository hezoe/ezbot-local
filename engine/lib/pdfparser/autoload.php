<?php

/**
 * smalot/pdfparser を Composer なしで読み込む遅延オートローダ。
 *
 * 本プラグインは「Composer 不要・ドロップイン設置」を方針とするため、
 * pdfparser を src ごと同梱し、ここで PSR-0 形式の autoloader を登録する。
 * `Smalot\PdfParser\*` の名前空間に限定し、必要時のみファイルを読み込む。
 *
 * バージョン: v2.12.0（LGPL-3.0、LICENSE.txt 同梱）
 */

if (!defined('ABSPATH')) {
	exit;
}

if (!defined('NSBOT_PDFPARSER_LOADED')) {
	define('NSBOT_PDFPARSER_LOADED', true);

	spl_autoload_register(static function (string $class): void {
		$prefix = 'Smalot\\PdfParser\\';
		if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
			return;
		}
		// PSR-0: 名前空間区切りをディレクトリ区切りへ。
		$path = __DIR__ . '/src/' . str_replace('\\', '/', $class) . '.php';
		if (is_file($path)) {
			require_once $path;
		}
	});
}
