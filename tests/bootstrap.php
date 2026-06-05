<?php
/**
 * Minimal bootstrap for isolated unit tests (no WP stack).
 *
 * The pricing core (Cart\MealPricing::price_product_group) and the bundle
 * algorithm (Cart\BundlePricer::calculate) are pure PHP, so these suites run
 * against the Composer PSR-4 autoloader alone — no WordPress required.
 *
 * @package FastNutrition\MealPrep\Tests
 */

declare( strict_types=1 );

require_once __DIR__ . '/../vendor/autoload.php';
