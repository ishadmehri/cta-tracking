<?php
/**
 * Plugin Name:       CTA Tracking
 * Plugin URI:        https://github.com/ishadmehri/cta-tracking
 * Description:       شمارش کلیک دکمه‌های تماس و پیام‌رسان با سلکتور CSS، و داشبورد نمودار (میله‌ای، خطی، حرارتی).
 * Version:           0.2.1
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Iman Shadmehri
 * Text Domain:       cta-tracking
 * License:           GPL-2.0-or-later
 *
 * @package CtaTracking
 */

namespace CtaTracking;

defined( 'ABSPATH' ) || exit;

const VERSION    = '0.2.1';
const DB_VERSION = 1;
const CAP        = 'view_cta_reports';
const FILE       = __FILE__;

require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-ua.php';
require_once __DIR__ . '/includes/class-clicks.php';
require_once __DIR__ . '/includes/class-collector.php';
require_once __DIR__ . '/includes/class-report.php';
require_once __DIR__ . '/includes/class-admin.php';

register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );

/** Table, default CTAs and zones, the report capability, the daily cleanup. */
function activate(): void {
	Clicks::install();
	Settings::seed();
	$admin = get_role( 'administrator' );
	if ( $admin ) {
		$admin->add_cap( CAP );
	}
	if ( ! wp_next_scheduled( 'cta_tracking_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'cta_tracking_cleanup' );
	}
}

function deactivate(): void {
	wp_clear_scheduled_hook( 'cta_tracking_cleanup' );
}

// A new zip replaces the files without running activation, so the table is checked on load.
add_action(
	'plugins_loaded',
	static function () {
		if ( (int) get_option( 'cta_tracking_db' ) !== DB_VERSION ) {
			Clicks::install();
		}
	}
);

add_action( 'cta_tracking_cleanup', array( Clicks::class, 'cleanup' ) );
add_action( 'rest_api_init', array( Collector::class, 'register_routes' ) );
add_action( 'rest_api_init', array( Report::class, 'register_routes' ) );
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue' );
Admin::hooks();

/**
 * The tracker: one deferred script with the definitions inline. The HTML is the same for every visitor (no nonce,
 * nothing per user), so cached pages keep working; post_id is the same for everyone who sees this URL.
 */
function enqueue(): void {
	/** Lets a consent plugin switch the tracker off. */
	if ( ! apply_filters( 'cta_tracking_enabled', true ) ) {
		return;
	}
	$config = Settings::front_config();
	if ( ! $config['ctas'] ) {
		return;
	}
	$config['url']  = rest_url( 'cta-tracking/v1/hit' );
	$config['post'] = (int) get_queried_object_id();
	wp_enqueue_script( 'cta-tracking', plugins_url( 'assets/tracker.js', FILE ), array(), VERSION, array( 'strategy' => 'defer', 'in_footer' => true ) );
	wp_add_inline_script( 'cta-tracking', 'var ctaTrackingConfig=' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';', 'before' );
}

// WP Rocket's Delay JS would lose the first click, so the tracker and its config are kept out of the delay.
// ponytail: WP Rocket only (both client sites use it); other cache plugins need the same two strings excluded by hand.
add_filter(
	'rocket_delay_js_exclusions',
	static function ( $list ) {
		return array_merge( (array) $list, array( 'cta-tracking', 'ctaTrackingConfig' ) );
	}
);
