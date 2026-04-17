<?php
/**
 * Triggered when the user uninstalls the plugin. Delegates to Uninstaller.
 *
 * @package FastNutrition\MealPrep
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Install/Activator.php';
require_once __DIR__ . '/src/Install/Uninstaller.php';

\FastNutrition\MealPrep\Install\Uninstaller::uninstall();
