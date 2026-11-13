<?php
/**
 * Plugin Name:       TBT Results
 * Description:       Everything students finish on The Blue Tree, in one place. Reads the TBT Notes activity log; stores nothing.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            The Blue Tree
 * Text Domain:       tbt-results
 */

defined( 'ABSPATH' ) || exit;

define( 'TBTR_VERSION', '0.1.0' );
define( 'TBTR_FILE', __FILE__ );
define( 'TBTR_DIR', plugin_dir_path( __FILE__ ) );
define( 'TBTR_URL', plugin_dir_url( __FILE__ ) );

require_once TBTR_DIR . 'includes/class-tbtr-format.php';
require_once TBTR_DIR . 'includes/class-tbtr-access.php';
require_once TBTR_DIR . 'includes/class-tbtr-query.php';
require_once TBTR_DIR . 'includes/class-tbtr-rest.php';
require_once TBTR_DIR . 'includes/class-tbtr-pages.php';

/**
 * Register this plugin on the TBT Hub Overview page. A filter on Hub's own
 * hook: when Hub is absent nothing applies it.
 *
 * @param array $items Existing hub items.
 * @return array
 */
function tbtr_register_hub_item( $items ) {
	$items   = is_array( $items ) ? $items : array();
	$items[] = array(
		'slug'        => 'tbt-results',
		'title'       => 'TBT Results',
		'description' => 'Everything students finish on The Blue Tree, in one place.',
		'capability'  => defined( 'TBT_NOTES_CAP' ) ? TBT_NOTES_CAP : 'manage_options',
	);
	return $items;
}
add_filter( 'tbt_hub_items', 'tbtr_register_hub_item' );

// Notes loads on its own schedule, so readiness is checked when used, never
// here: the shortcodes print one line and the routes answer 503 without it.
add_action(
	'plugins_loaded',
	static function () {
		new TBTR_Rest();
		new TBTR_Pages();
	}
);
