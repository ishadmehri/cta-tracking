<?php
/**
 * The click table.
 *
 * @package CtaTracking
 */

namespace CtaTracking;

defined( 'ABSPATH' ) || exit;

/**
 * One row per click. Day, weekday and hour are the site's local time, fixed when the click is stored, so reports
 * group without timezone maths. No IP, no cookie. Keys (not ids) point at CTAs and zones in the settings.
 */
final class Clicks {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'cta_clicks';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  clicked_at datetime NOT NULL,
  day date NOT NULL,
  dow tinyint(1) unsigned NOT NULL,
  hour tinyint(2) unsigned NOT NULL,
  cta varchar(40) NOT NULL,
  zone varchar(40) NOT NULL DEFAULT '',
  context varchar(64) NOT NULL DEFAULT '',
  device varchar(10) NOT NULL DEFAULT '',
  os varchar(20) NOT NULL DEFAULT '',
  browser varchar(20) NOT NULL DEFAULT '',
  flags tinyint(3) unsigned NOT NULL DEFAULT 0,
  vw smallint(5) unsigned NOT NULL DEFAULT 0,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY day_cta (day,cta)
) " . $wpdb->get_charset_collate() . ';'
		);
		update_option( 'cta_tracking_db', DB_VERSION );
	}

	/**
	 * Stores one click; the time is now.
	 *
	 * @param array $row cta, zone, context, device, os, browser, flags, vw, post_id.
	 */
	public static function insert( array $row ): void {
		global $wpdb;
		$now = new \DateTimeImmutable( 'now', wp_timezone() );
		$wpdb->insert(
			self::table(),
			array(
				'clicked_at' => gmdate( 'Y-m-d H:i:s' ),
				'day'        => $now->format( 'Y-m-d' ),
				'dow'        => (int) $now->format( 'w' ), // 0 = Sunday.
				'hour'       => (int) $now->format( 'G' ),
			) + $row
		);
	}

	/** Clicks stored per minute at most, site-wide. Real sites stay far below; a script flooding the receiver stops here. */
	public const MAX_PER_MINUTE = 120;

	/**
	 * True when MAX_PER_MINUTE clicks were stored in the last 60 seconds. Reads one row by primary key (ids grow with
	 * time), so it needs no IP address, no transient and no extra write.
	 */
	public static function flooded(): bool {
		global $wpdb;
		$at = $wpdb->get_var( $wpdb->prepare( 'SELECT clicked_at FROM ' . self::table() . ' ORDER BY id DESC LIMIT 1 OFFSET %d', self::MAX_PER_MINUTE - 1 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $at && strtotime( $at . ' UTC' ) > time() - MINUTE_IN_SECONDS;
	}

	/** Daily: removes clicks older than the kept days. */
	public static function cleanup(): void {
		global $wpdb;
		$days   = (int) Settings::get()['keep_days'];
		$before = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->modify( "-{$days} days" )->format( 'Y-m-d' );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE day < %s', $before ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** The latest clicks, flagged ones included (so a test click by the team shows up). */
	public static function recent( int $limit = 100 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Per CTA key: last click time (UTC) within HEALTH_DAYS and clean clicks in the last 7 days. The day range keeps
	 * the query on the (day, cta) index instead of scanning a year of clicks on every visit to the screen.
	 */
	public const HEALTH_DAYS = 90;

	public static function health(): array {
		global $wpdb;
		$today = new \DateTimeImmutable( 'now', wp_timezone() );
		$since = $today->modify( '-6 days' )->format( 'Y-m-d' );
		$from  = $today->modify( '-' . self::HEALTH_DAYS . ' days' )->format( 'Y-m-d' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( 'SELECT cta, MAX(clicked_at) AS last_at, SUM(day >= %s AND flags = 0) AS week FROM ' . self::table() . ' WHERE day >= %s GROUP BY cta', $since, $from ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $r ) {
			$out[ $r['cta'] ] = $r;
		}
		return $out;
	}
}
