<?php
/**
 * Receives clicks from the page script.
 *
 * @package CtaTracking
 */

namespace CtaTracking;

defined( 'ABSPATH' ) || exit;

/**
 * POST /cta-tracking/v1/hit → 204. No nonce: cached pages would keep a stale one. Nothing the visitor sends is
 * stored as given: CTA and zone must be known keys, the context is cut to a safe alphabet, numbers are bounded.
 */
final class Collector {

	public static function register_routes(): void {
		register_rest_route(
			'cta-tracking/v1',
			'/hit',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'collect' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Turns a request's fields into a row, or null when it is not a known CTA.
	 *
	 * @param array  $in        c (cta), z (zone), x (context), p (post), w (width), t (touch points), f (client flags).
	 * @param string $ua        User agent.
	 * @param bool   $logged_in A WordPress user is signed in.
	 * @param array  $ctas      Known CTA keys as keys.
	 * @param array  $zones     Known zone keys as keys.
	 */
	public static function row( array $in, string $ua, bool $logged_in, array $ctas, array $zones ): ?array {
		$cta = (string) ( $in['c'] ?? '' );
		if ( ! isset( $ctas[ $cta ] ) ) {
			return null;
		}
		$zone   = (string) ( $in['z'] ?? '' );
		$parsed = UA::parse( $ua, (int) ( $in['t'] ?? 0 ) );
		$flags  = ( (int) ( $in['f'] ?? 0 ) ) & ( UA::UNTRUSTED | UA::WEBDRIVER );
		if ( $parsed['bot'] ) {
			$flags |= UA::BOT;
		}
		if ( $logged_in ) {
			$flags |= UA::TEAM;
		}
		return array(
			'cta'     => $cta,
			'zone'    => isset( $zones[ $zone ] ) ? $zone : '',
			'context' => substr( (string) preg_replace( '/[^\w+.@-]/', '', (string) ( $in['x'] ?? '' ) ), 0, 64 ),
			'device'  => $parsed['device'],
			'os'      => $parsed['os'],
			'browser' => $parsed['browser'],
			'flags'   => $flags,
			'vw'      => max( 0, min( 65535, (int) ( $in['w'] ?? 0 ) ) ),
			'post_id' => max( 0, (int) ( $in['p'] ?? 0 ) ),
		);
	}

	public static function collect( \WP_REST_Request $request ): \WP_REST_Response {
		// The REST API ignores the login cookie without a nonce, so the team is recognised from the cookie itself.
		$logged_in = (bool) wp_validate_auth_cookie( '', 'logged_in' );
		$row       = self::row(
			$request->get_params(),
			(string) $request->get_header( 'user_agent' ),
			$logged_in,
			Settings::by_key( 'ctas' ),
			Settings::by_key( 'zones' )
		);
		if ( $row ) {
			Clicks::insert( $row );
		}
		$response = new \WP_REST_Response( null, 204 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
