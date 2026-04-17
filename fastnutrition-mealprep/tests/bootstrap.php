<?php
/**
 * Minimal bootstrap for isolated unit tests (no WP stack).
 *
 * @package FastNutrition\MealPrep\Tests
 */

declare( strict_types=1 );

if ( file_exists( __DIR__ . '/../vendor/autoload.php' ) ) {
	require_once __DIR__ . '/../vendor/autoload.php';
}
