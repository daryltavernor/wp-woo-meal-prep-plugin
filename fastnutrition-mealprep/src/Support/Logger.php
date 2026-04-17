<?php
/**
 * Thin wrapper around the WooCommerce logger so we never break when WC isn't loaded.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

namespace FastNutrition\MealPrep\Support;

final class Logger {

	public const SOURCE = 'fn-mealprep';

	public static function debug( string $message, array $context = [] ): void {
		self::log( 'debug', $message, $context );
	}

	public static function error( string $message, array $context = [] ): void {
		self::log( 'error', $message, $context );
	}

	private static function log( string $level, string $message, array $context ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$logger = wc_get_logger();
		$logger->log( $level, $message . ( $context ? ' ' . wp_json_encode( $context ) : '' ), [ 'source' => self::SOURCE ] );
	}
}
