<?php
/**
 * Chart data: a whitelisted query and a pivot into {labels, series}.
 *
 * @package CtaTracking
 */

namespace CtaTracking;

/**
 * Every dimension maps to fixed columns; the request only picks names from this list, never SQL. Labels (CTA names,
 * groups, context names) are applied after the query, in PHP, so a CTA belongs to one group without a JOIN and a
 * renamed label changes old clicks too.
 */
final class Report {

	public const DIMS = array(
		'cta'     => array( 'cta' ),
		'group'   => array( 'cta' ),
		'context' => array( 'cta', 'context' ),
		'zone'    => array( 'zone' ),
		'device'  => array( 'device' ),
		'os'      => array( 'os' ),
		'browser' => array( 'browser' ),
		'dow'     => array( 'dow' ),
		'hour'    => array( 'hour' ),
		'day'     => array( 'day' ),
		'page'    => array( 'post_id' ),
	);
	public const FILTERS    = array( 'ctas' => 'cta', 'zones' => 'zone', 'devices' => 'device' );
	public const MAX_DAYS   = 400;
	public const MAX_LABELS = 30;
	public const MAX_SERIES = 8;
	public const OTHER      = 'سایر';
	private const DOW_ORDER = array( 6, 0, 1, 2, 3, 4, 5 ); // The Iranian week starts on Saturday.
	private const DOW_NAMES = array( 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه' );
	private const DEVICES   = array( 'mobile' => 'موبایل', 'desktop' => 'دسکتاپ', 'tablet' => 'تبلت' );

	/**
	 * Normalises a request: unknown dimensions fall back, days are bounded, lists are trimmed strings.
	 *
	 * @param array $in x, s, days, ctas, zones, devices (arrays or comma lists), all.
	 */
	public static function query( array $in ): array {
		$q = array(
			'x'    => isset( self::DIMS[ $in['x'] ?? '' ] ) ? $in['x'] : 'cta',
			's'    => isset( self::DIMS[ $in['s'] ?? '' ] ) ? $in['s'] : '',
			'days' => max( 1, min( self::MAX_DAYS, (int) ( $in['days'] ?? 30 ) ) ),
			'all'  => ! empty( $in['all'] ),
		);
		if ( $q['s'] === $q['x'] ) {
			$q['s'] = '';
		}
		foreach ( array_keys( self::FILTERS ) as $f ) {
			$list    = is_array( $in[ $f ] ?? null ) ? $in[ $f ] : explode( ',', (string) ( $in[ $f ] ?? '' ) );
			$q[ $f ] = array_values( array_filter( array_map( 'strval', array_map( 'trim', $list ) ), 'strlen' ) );
		}
		return $q;
	}

	/**
	 * SQL with placeholders, and its arguments, for a normalised query.
	 *
	 * @param array  $q     From query().
	 * @param string $table Table name.
	 * @param string $from  First day, Y-m-d.
	 * @param string $to    Last day, Y-m-d.
	 * @return array{0:string,1:array}
	 */
	public static function sql( array $q, string $table, string $from, string $to ): array {
		$cols  = array_values( array_unique( array_merge( self::DIMS[ $q['x'] ], $q['s'] ? self::DIMS[ $q['s'] ] : array() ) ) );
		$where = array( 'day BETWEEN %s AND %s' );
		$args  = array( $from, $to );
		if ( ! $q['all'] ) {
			$where[] = 'flags = 0';
		}
		foreach ( self::FILTERS as $f => $col ) {
			if ( $q[ $f ] ) {
				$where[] = $col . ' IN (' . implode( ',', array_fill( 0, count( $q[ $f ] ), '%s' ) ) . ')';
				$args    = array_merge( $args, $q[ $f ] );
			}
		}
		$group = implode( ', ', $cols );
		return array( "SELECT {$group}, COUNT(*) AS n FROM {$table} WHERE " . implode( ' AND ', $where ) . " GROUP BY {$group}", $args );
	}

	/**
	 * Key and label of a row along one dimension.
	 *
	 * @param string $dim  Dimension.
	 * @param array  $row  Query row.
	 * @param array  $maps ctas (key => row), zones (key => row), title (callable id => string), date (callable Y-m-d => string).
	 * @return array{0:string,1:string}
	 */
	public static function key_label( string $dim, array $row, array $maps ): array {
		$cta   = $maps['ctas'][ $row['cta'] ?? '' ] ?? null;
		$label = $cta && '' !== $cta['label'] ? $cta['label'] : (string) ( $row['cta'] ?? '' );
		switch ( $dim ) {
			case 'cta':
				return array( $row['cta'], $label );
			case 'group':
				$group = $cta && '' !== $cta['group'] ? $cta['group'] : 'بدون گروه';
				return array( $group, $group );
			case 'context':
				$names = $cta ? Settings::names( $cta ) : array();
				$value = (string) $row['context'];
				$name  = $names[ $value ] ?? ( '' === $value ? $label : $label . ' ' . $value );
				return array( $row['cta'] . '|' . $value, $name );
			case 'zone':
				$zone = $maps['zones'][ $row['zone'] ] ?? null;
				return array( $row['zone'], '' === $row['zone'] ? 'بدون ناحیه' : ( $zone && '' !== $zone['label'] ? $zone['label'] : $row['zone'] ) );
			case 'device':
				return array( $row['device'], self::DEVICES[ $row['device'] ] ?? $row['device'] );
			case 'dow':
				return array( (string) (int) $row['dow'], self::DOW_NAMES[ (int) $row['dow'] ] );
			case 'hour':
				return array( (string) (int) $row['hour'], sprintf( '%02d', (int) $row['hour'] ) );
			case 'day':
				return array( $row['day'], isset( $maps['date'] ) ? ( $maps['date'] )( $row['day'] ) : $row['day'] );
			case 'page':
				$id = (int) $row['post_id'];
				return array( (string) $id, 0 === $id ? 'نامشخص' : ( isset( $maps['title'] ) ? ( $maps['title'] )( $id ) : '#' . $id ) );
			default:
				return array( (string) $row[ $dim ], (string) $row[ $dim ] );
		}
	}

	/**
	 * Rows → {labels, series, total}. Time dimensions keep their natural order with zeros filled in; the others are
	 * sorted by total, longest first, and cut at MAX_LABELS. Series beyond MAX_SERIES are merged into «سایر».
	 *
	 * @param array  $rows Query rows with n.
	 * @param array  $q    From query().
	 * @param array  $maps See key_label().
	 * @param string $from First day, Y-m-d.
	 * @param string $to   Last day, Y-m-d.
	 */
	public static function pivot( array $rows, array $q, array $maps, string $from, string $to ): array {
		$data    = array();
		$xlabels = array();
		$slabels = array();
		foreach ( $rows as $row ) {
			list( $xk, $xl ) = self::key_label( $q['x'], $row, $maps );
			list( $sk, $sl ) = $q['s'] ? self::key_label( $q['s'], $row, $maps ) : array( '', 'کلیک' );
			$xlabels[ $xk ]  = $xl;
			$slabels[ $sk ]  = $sl;
			$data[ $sk ][ $xk ] = ( $data[ $sk ][ $xk ] ?? 0 ) + (int) $row['n'];
		}

		// X order.
		$domain = self::domain( $q['x'], $from, $to );
		if ( null !== $domain ) {
			$xkeys = array_keys( $domain );
			foreach ( $domain as $k => $default ) {
				$xlabels[ $k ] = $xlabels[ $k ] ?? ( 'day' === $q['x'] && isset( $maps['date'] ) ? ( $maps['date'] )( $k ) : $default );
			}
		} else {
			$xtotal = array();
			foreach ( $data as $points ) {
				foreach ( $points as $xk => $n ) {
					$xtotal[ $xk ] = ( $xtotal[ $xk ] ?? 0 ) + $n;
				}
			}
			arsort( $xtotal );
			$xkeys = array_slice( array_map( 'strval', array_keys( $xtotal ) ), 0, self::MAX_LABELS );
		}

		// Series order: time dimensions in their natural order with every row present (the heat map's weekdays),
		// the others by total with the tail merged.
		$stotal  = array_map( 'array_sum', $data );
		$sdomain = $q['s'] ? self::domain( $q['s'], $from, $to ) : null;
		if ( null !== $sdomain ) {
			// ponytail: not folded, so day × hour over a long range means many rows; cap if that gets used.
			$skeys = array_map( 'strval', array_keys( $sdomain ) );
			foreach ( $sdomain as $k => $default ) {
				$slabels[ $k ] = $slabels[ $k ] ?? ( 'day' === $q['s'] && isset( $maps['date'] ) ? ( $maps['date'] )( $k ) : $default );
			}
		} else {
			arsort( $stotal );
			$skeys = array_map( 'strval', array_keys( $stotal ) );
		}
		if ( null === $sdomain && count( $skeys ) > self::MAX_SERIES ) {
			$other = array();
			foreach ( array_slice( $skeys, self::MAX_SERIES - 1 ) as $sk ) {
				foreach ( $data[ $sk ] as $xk => $n ) {
					$other[ $xk ] = ( $other[ $xk ] ?? 0 ) + $n;
				}
			}
			$skeys                  = array_slice( $skeys, 0, self::MAX_SERIES - 1 );
			$skeys[]                = "\0other";
			$data["\0other"]        = $other;
			$slabels["\0other"]     = self::OTHER;
		}

		$series = array();
		foreach ( $skeys as $sk ) {
			$series[] = array(
				'name' => $slabels[ $sk ],
				'data' => array_map(
					static function ( $xk ) use ( $data, $sk ) {
						return $data[ $sk ][ $xk ] ?? 0;
					},
					$xkeys
				),
			);
		}
		return array(
			'labels' => array_map(
				static function ( $xk ) use ( $xlabels ) {
					return $xlabels[ $xk ];
				},
				$xkeys
			),
			'series' => $series,
			'total'  => array_sum( $stotal ),
		);
	}

	/** Full ordered key => label list for time dimensions; null for the others. */
	private static function domain( string $dim, string $from, string $to ): ?array {
		if ( 'hour' === $dim ) {
			$out = array();
			for ( $h = 0; $h < 24; $h++ ) {
				$out[ (string) $h ] = sprintf( '%02d', $h );
			}
			return $out;
		}
		if ( 'dow' === $dim ) {
			$out = array();
			foreach ( self::DOW_ORDER as $d ) {
				$out[ (string) $d ] = self::DOW_NAMES[ $d ];
			}
			return $out;
		}
		if ( 'day' === $dim ) {
			$out = array();
			for ( $d = new \DateTimeImmutable( $from ), $end = new \DateTimeImmutable( $to ); $d <= $end; $d = $d->modify( '+1 day' ) ) {
				$out[ $d->format( 'Y-m-d' ) ] = $d->format( 'Y-m-d' );
			}
			return $out;
		}
		return null;
	}

	// ---- WordPress side --------------------------------------------------------------------------------------------

	public static function register_routes(): void {
		$can = static function () {
			return current_user_can( CAP );
		};
		register_rest_route(
			'cta-tracking/v1',
			'/report',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'rest_report' ),
				'permission_callback' => $can,
			)
		);
		register_rest_route(
			'cta-tracking/v1',
			'/charts',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest_save_charts' ),
				'permission_callback' => $can,
			)
		);
	}

	/** Runs a query for the last `days` days, today included. */
	public static function run( array $in ): array {
		global $wpdb;
		$q    = self::query( $in );
		$now  = new \DateTimeImmutable( 'now', wp_timezone() );
		$to   = $now->format( 'Y-m-d' );
		$from = $now->modify( '-' . ( $q['days'] - 1 ) . ' days' )->format( 'Y-m-d' );
		list( $sql, $args ) = self::sql( $q, Clicks::table(), $from, $to );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from the whitelist above.
		$maps = array(
			'ctas'  => Settings::by_key( 'ctas' ),
			'zones' => Settings::by_key( 'zones' ),
			'title' => static function ( $id ) {
				$title = get_the_title( $id );
				return '' !== $title ? html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) : '#' . $id;
			},
			// wp_date() follows a Jalali date plugin when one is active, which is what the labels should show.
			'date'  => static function ( $ymd ) {
				return wp_date( 'j F', ( new \DateTimeImmutable( $ymd . ' 12:00', wp_timezone() ) )->getTimestamp() );
			},
		);
		return self::pivot( (array) $rows, $q, $maps, $from, $to ) + array( 'from' => $from, 'to' => $to );
	}

	public static function rest_report( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( self::run( $request->get_params() ) );
	}

	/** Saves the dashboard's chart list (shared by everyone who can see reports). */
	public static function rest_save_charts( \WP_REST_Request $request ): \WP_REST_Response {
		$charts = self::clean_charts( (array) $request->get_param( 'charts' ) );
		update_option( 'cta_tracking_charts', $charts, false );
		return new \WP_REST_Response( $charts );
	}

	public static function clean_charts( array $raw ): array {
		$out = array();
		foreach ( array_slice( $raw, 0, 40 ) as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$q     = self::query( $c );
			$out[] = array(
				'id'    => preg_replace( '/[^a-z0-9]/', '', strtolower( (string) ( $c['id'] ?? '' ) ) ) ?: uniqid(),
				'title' => sanitize_text_field( (string) ( $c['title'] ?? '' ) ),
				'type'  => in_array( $c['type'] ?? '', array( 'bar', 'line', 'heatmap' ), true ) ? $c['type'] : 'bar',
				'x'     => $q['x'],
				's'     => $q['s'],
				'days'  => $q['days'],
				'ctas'  => array_map( 'sanitize_key', $q['ctas'] ),
				'zones' => array_map( 'sanitize_key', $q['zones'] ),
				'devices' => array_map( 'sanitize_key', $q['devices'] ),
			);
		}
		return $out;
	}

	/** Charts shown on a fresh install. */
	public static function charts(): array {
		$saved = get_option( 'cta_tracking_charts' );
		if ( is_array( $saved ) ) {
			return $saved;
		}
		return array(
			array( 'id' => 'c1', 'title' => 'کلیک هر دکمه', 'type' => 'bar', 'x' => 'cta', 's' => '', 'days' => 30, 'ctas' => array(), 'zones' => array(), 'devices' => array() ),
			array( 'id' => 'c2', 'title' => 'روند روزانه', 'type' => 'line', 'x' => 'day', 's' => 'cta', 'days' => 30, 'ctas' => array(), 'zones' => array(), 'devices' => array() ),
			array( 'id' => 'c3', 'title' => 'ساعت‌های شبانه‌روز در هفته', 'type' => 'heatmap', 'x' => 'hour', 's' => 'dow', 'days' => 30, 'ctas' => array(), 'zones' => array(), 'devices' => array() ),
			array( 'id' => 'c4', 'title' => 'کدام ناحیهٔ صفحه', 'type' => 'bar', 'x' => 'zone', 's' => 'cta', 'days' => 30, 'ctas' => array(), 'zones' => array(), 'devices' => array() ),
		);
	}
}
