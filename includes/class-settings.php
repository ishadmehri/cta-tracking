<?php
/**
 * What is counted: CTAs (what was clicked) and zones (where on the page), kept in one option.
 *
 * @package CtaTracking
 */

namespace CtaTracking;

defined( 'ABSPATH' ) || exit;

/**
 * A CTA is {key, label, selector, priority, group, context, names}: `context` is a JavaScript regex run on the
 * link's href (its first group is the value, e.g. the phone number), `names` turns values into names ("989…=کارشناس ۲")
 * when reports are drawn, so renaming fixes the past too. A zone is {key, label, selector}. Clicks store keys only.
 */
final class Settings {

	public const OPTION = 'cta_tracking_settings';

	/** Defaults for Elementor sites with call and messenger links (Elementor law-firm sites were the first users). */
	public static function defaults(): array {
		return array(
			'ctas'                => array(
				array( 'key' => 'tel', 'label' => 'تماس تلفنی', 'selector' => 'a[href^="tel:"]', 'priority' => 10, 'group' => 'تماس', 'context' => 'tel:\+?(\d+)', 'names' => '' ),
				array( 'key' => 'whatsapp', 'label' => 'واتساپ', 'selector' => 'a[href*="wa.me/"], a[href*="whatsapp.com/send"]', 'priority' => 10, 'group' => 'پیام‌رسان بین‌المللی', 'context' => '(?:wa\.me\/|phone=)\+?(\d+)', 'names' => '' ),
				array( 'key' => 'telegram', 'label' => 'تلگرام', 'selector' => 'a[href*="t.me/"], a[href*="telegram.me/"]', 'priority' => 10, 'group' => 'پیام‌رسان بین‌المللی', 'context' => 't(?:elegram)?\.me\/(\w+)', 'names' => '' ),
				array( 'key' => 'bale', 'label' => 'بله', 'selector' => 'a[href*="ble.ir/"]', 'priority' => 10, 'group' => 'پیام‌رسان ایرانی', 'context' => 'ble\.ir\/(\w+)', 'names' => '' ),
				array( 'key' => 'eitaa', 'label' => 'ایتا', 'selector' => 'a[href*="eitaa.com/"]', 'priority' => 10, 'group' => 'پیام‌رسان ایرانی', 'context' => 'eitaa\.com\/(\w+)', 'names' => '' ),
				array( 'key' => 'rubika', 'label' => 'روبیکا', 'selector' => 'a[href*="rubika.ir/"]', 'priority' => 10, 'group' => 'پیام‌رسان ایرانی', 'context' => 'rubika\.ir\/(\w+)', 'names' => '' ),
				array( 'key' => 'soroush', 'label' => 'سروش', 'selector' => 'a[href*="splus.ir/"]', 'priority' => 10, 'group' => 'پیام‌رسان ایرانی', 'context' => 'splus\.ir\/(\w+)', 'names' => '' ),
			),
			'zones'               => array(
				array( 'key' => 'popup', 'label' => 'پاپ‌آپ', 'selector' => '[data-elementor-type="popup"], [role="dialog"]' ),
				array( 'key' => 'header', 'label' => 'هدر', 'selector' => '[data-elementor-type="header"], .site-header, body > header' ),
				array( 'key' => 'footer', 'label' => 'فوتر', 'selector' => '[data-elementor-type="footer"], .site-footer, body > footer' ),
				array( 'key' => 'sidebar', 'label' => 'سایدبار', 'selector' => 'aside, .sidebar, .widget-area' ),
				array( 'key' => 'content', 'label' => 'محتوا', 'selector' => 'main, [data-elementor-type="wp-page"], [data-elementor-type="single-post"], [data-elementor-type="single-page"], .entry-content' ),
			),
			'keep_days'           => 400,
			'delete_on_uninstall' => false,
		);
	}

	/** Seeds the defaults once; an existing setup is never overwritten. */
	public static function seed(): void {
		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, self::defaults() );
		}
	}

	public static function get(): array {
		$saved = get_option( self::OPTION );
		return is_array( $saved ) ? array_merge( self::defaults(), $saved ) : self::defaults();
	}

	/**
	 * Cleans what the settings form sent. Keys are slugs and unique; rows without key or selector are dropped.
	 *
	 * @param array $raw Unslashed form data.
	 */
	public static function sanitize( array $raw ): array {
		$out = array(
			'ctas'                => array(),
			'zones'               => array(),
			'keep_days'           => max( 30, min( 3650, (int) ( $raw['keep_days'] ?? 400 ) ) ),
			'delete_on_uninstall' => ! empty( $raw['delete_on_uninstall'] ),
		);
		foreach ( array( 'ctas', 'zones' ) as $list ) {
			$seen = array();
			foreach ( (array) ( $raw[ $list ] ?? array() ) as $row ) {
				$key      = substr( sanitize_key( $row['key'] ?? '' ), 0, 40 );
				$selector = self::text( $row['selector'] ?? '', 500 );
				if ( '' === $key || '' === $selector || isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$clean        = array(
					'key'      => $key,
					'label'    => self::text( $row['label'] ?? '', 80 ),
					'selector' => $selector,
				);
				if ( 'ctas' === $list ) {
					$clean += array(
						'priority' => (int) ( $row['priority'] ?? 10 ),
						'group'    => self::text( $row['group'] ?? '', 80 ),
						'context'  => self::text( $row['context'] ?? '', 200 ),
						'names'    => sanitize_textarea_field( $row['names'] ?? '' ),
					);
				}
				$out[ $list ][] = $clean;
			}
		}
		return $out;
	}

	/** Plain text without tags or line breaks; keeps quotes, brackets and backslashes (selectors and regexes need them). */
	private static function text( $value, int $max ): string {
		$value = trim( preg_replace( '/[\r\n\t]+/', ' ', wp_strip_all_tags( (string) $value ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}

	/** What the page script needs: [key, selector, priority, context regex] and [key, selector]. No labels. */
	public static function front_config(): array {
		$s = self::get();
		return array(
			'ctas'  => array_map(
				static function ( $c ) {
					return array( $c['key'], $c['selector'], (int) $c['priority'], $c['context'] );
				},
				$s['ctas']
			),
			'zones' => array_map(
				static function ( $z ) {
					return array( $z['key'], $z['selector'] );
				},
				$s['zones']
			),
		);
	}

	/** key => row, for CTAs or zones. */
	public static function by_key( string $list ): array {
		$out = array();
		foreach ( self::get()[ $list ] as $row ) {
			$out[ $row['key'] ] = $row;
		}
		return $out;
	}

	/** "value=name" lines of one CTA as an array. */
	public static function names( array $cta ): array {
		$out = array();
		// Not \R: without /u it also splits on byte 0x85, which is inside the UTF-8 of «م».
		foreach ( preg_split( '/\r\n|\r|\n/', (string) ( $cta['names'] ?? '' ) ) as $line ) {
			$parts = explode( '=', $line, 2 );
			if ( 2 === count( $parts ) && '' !== trim( $parts[0] ) ) {
				$out[ trim( $parts[0] ) ] = trim( $parts[1] );
			}
		}
		return $out;
	}
}
