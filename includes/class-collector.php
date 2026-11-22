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
		// Arrays (c[]=x) become '' rather than "Array" plus a PHP warning per request.
		$in  = array_map(
			static function ( $v ) {
				return is_scalar( $v ) ? (string) $v : '';
			},
			$in
		);
		$cta = $in['c'] ?? '';
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
		self::store( $request->get_params(), (string) $request->get_header( 'user_agent' ), (bool) wp_validate_auth_cookie( '', 'logged_in' ) );
		$response = new \WP_REST_Response( null, 204 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * admin-ajax.php?action=cta_tracking_hit → 204. This is what the page script uses: some sites close the REST API
	 * to guests (one client site answered every /wp-json/ request with 403); admin-ajax is open wherever a theme works.
	 * The REST route stays for pages cached before 0.2.2.
	 */
	public static function ajax(): void {
		// phpcs:ignore WordPress.Security.NonceVerification -- public, cache-safe endpoint; every field is checked in row().
		self::store( wp_unslash( $_POST ), sanitize_text_field( wp_unslash( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ) ), is_user_logged_in() );
		nocache_headers();
		status_header( 204 );
		exit;
	}

	private static function store( array $in, string $ua, bool $logged_in ): void {
		$row = self::row( $in, $ua, $logged_in, Settings::by_key( 'ctas' ), Settings::by_key( 'zones' ) );
		if ( ! $row || Clicks::flooded() ) {
			return;
		}
		// The page id comes from the visitor; keep it only for published content, so reports never name drafts.
		if ( $row['post_id'] && 'publish' !== get_post_status( $row['post_id'] ) ) {
			$row['post_id'] = 0;
		}
		Clicks::insert( $row );
	}
}
