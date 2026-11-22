<?php
/**
 * Device, system and browser from a user agent. Pure PHP, no WordPress, so tests run without a site.
 *
 * @package CtaTracking
 */

namespace CtaTracking;

final class UA {

	/** Flag bits stored with each click; any set bit hides the click from reports by default. */
	public const UNTRUSTED = 1; // Click made by script (el.click()), not a person.
	public const WEBDRIVER = 2; // navigator.webdriver: automated browser.
	public const BOT       = 4; // Crawler or headless user agent.
	public const TEAM      = 8; // A logged-in WordPress user (the team testing).

	/**
	 * @param string $ua    User agent.
	 * @param int    $touch navigator.maxTouchPoints: iPadOS says «Macintosh» but has touch.
	 * @return array{device:string,os:string,browser:string,bot:bool}
	 */
	public static function parse( string $ua, int $touch = 0 ): array {
		$bot = '' === $ua || (bool) preg_match( '/bot|crawl|spider|slurp|headless|lighthouse|pagespeed|preview|facebookexternalhit|curl|wget|python|okhttp|java\//i', $ua );

		$os = 'Other';
		foreach ( array(
			'/iphone|ipad|ipod/i'   => 'iOS',
			'/android/i'            => 'Android',
			'/windows/i'            => 'Windows',
			'/mac os x|macintosh/i' => 'macOS',
			'/cros/i'               => 'ChromeOS',
			'/linux/i'              => 'Linux',
		) as $pattern => $name ) {
			if ( preg_match( $pattern, $ua ) ) {
				$os = $name;
				break;
			}
		}

		if ( 'macOS' === $os && $touch > 1 ) {
			$os     = 'iOS';
			$device = 'tablet';
		} elseif ( preg_match( '/ipad|tablet/i', $ua ) || ( 'Android' === $os && ! preg_match( '/mobile/i', $ua ) ) ) {
			$device = 'tablet';
		} else {
			$device = preg_match( '/mobi|iphone|ipod|android/i', $ua ) ? 'mobile' : 'desktop';
		}

		$browser = 'Other';
		foreach ( array(
			'/instagram/i'                => 'Instagram',
			'/fban|fbav/i'                => 'Facebook',
			'/telegram/i'                 => 'Telegram',
			'/samsungbrowser/i'           => 'Samsung',
			'/edg(e|a|ios)?\//i'          => 'Edge',
			'/opr\/|opera/i'              => 'Opera',
			'/yabrowser/i'                => 'Yandex',
			'/firefox|fxios/i'            => 'Firefox',
			'/chrome|crios|chromium/i'    => 'Chrome',
			'/safari/i'                   => 'Safari',
		) as $pattern => $name ) {
			if ( preg_match( $pattern, $ua ) ) {
				$browser = $name;
				break;
			}
		}

		return array(
			'device'  => $device,
			'os'      => $os,
			'browser' => $browser,
			'bot'     => $bot,
		);
	}
}
