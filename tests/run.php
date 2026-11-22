<?php
/**
 * Pure-PHP checks (no WordPress): php tests/run.php
 *
 * @package CtaTracking
 */

namespace CtaTracking;

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../includes/class-settings.php';
require __DIR__ . '/../includes/class-ua.php';
require __DIR__ . '/../includes/class-collector.php';
require __DIR__ . '/../includes/class-report.php';

$fails = 0;
$count = 0;
function same( $expected, $actual, string $what ): void {
	global $fails, $count;
	$count++;
	if ( $expected !== $actual ) {
		$fails++;
		echo "FAIL {$what}\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
	}
}

// ---- UA ----------------------------------------------------------------------------------------------------------
$ua = array(
	'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1' => array( 'mobile', 'iOS', 'Safari', false ),
	'Mozilla/5.0 (Linux; Android 14; SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36' => array( 'mobile', 'Android', 'Chrome', false ),
	'Mozilla/5.0 (Linux; Android 13; SM-X200) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36' => array( 'tablet', 'Android', 'Chrome', false ),
	'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0' => array( 'desktop', 'Windows', 'Edge', false ),
	'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0' => array( 'desktop', 'Windows', 'Firefox', false ),
	'Mozilla/5.0 (Linux; Android 14; SM-S918B Build/UP1A; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/130.0 Mobile Safari/537.36 Instagram 350.0.0.0' => array( 'mobile', 'Android', 'Instagram', false ),
	'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Telegram-iOS/11.2' => array( 'mobile', 'iOS', 'Telegram', false ),
	'Mozilla/5.0 (Linux; Android 12; SM-A525F) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36' => array( 'mobile', 'Android', 'Samsung', false ),
	'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15' => array( 'desktop', 'macOS', 'Safari', false ),
	'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' => array( 'desktop', 'Other', 'Other', true ),
	'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/130.0.0.0 Safari/537.36' => array( 'desktop', 'Linux', 'Chrome', true ),
	'' => array( 'desktop', 'Other', 'Other', true ),
);
foreach ( $ua as $string => $want ) {
	$p = UA::parse( $string );
	same( $want, array( $p['device'], $p['os'], $p['browser'], $p['bot'] ), 'UA ' . substr( $string, 0, 60 ) );
}
// iPadOS presents itself as a Mac; touch points give it away.
$ipad = UA::parse( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15', 5 );
same( array( 'tablet', 'iOS' ), array( $ipad['device'], $ipad['os'] ), 'iPadOS by touch points' );

// ---- Collector::row ----------------------------------------------------------------------------------------------
$known = array( 'whatsapp' => 1, 'tel' => 1 );
$zones = array( 'footer' => 1 );
$chrome = 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0 Safari/537.36';
same( null, Collector::row( array( 'c' => 'evil' ), $chrome, false, $known, $zones ), 'unknown CTA is refused' );
$row = Collector::row( array( 'c' => 'whatsapp', 'z' => 'nowhere', 'x' => "98912<script>'", 'w' => '99999999', 'p' => '-3', 'f' => '255' ), $chrome, false, $known, $zones );
same( '', $row['zone'], 'unknown zone becomes empty' );
same( '98912script', $row['context'], 'context keeps a safe alphabet' );
same( 65535, $row['vw'], 'width bounded' );
same( 0, $row['post_id'], 'post id not negative' );
same( UA::UNTRUSTED | UA::WEBDRIVER, $row['flags'], 'client may only set its own two flags' );
same( UA::TEAM, Collector::row( array( 'c' => 'tel' ), $chrome, true, $known, $zones )['flags'], 'logged-in click is flagged' );
same( UA::BOT, Collector::row( array( 'c' => 'tel' ), 'curl/8.0', false, $known, $zones )['flags'], 'robot is flagged' );

// Hostile shapes: arrays where strings belong must not warn or match.
same( null, Collector::row( array( 'c' => array( 'tel' ) ), $chrome, false, $known, $zones ), 'array as CTA is refused' );
same( '', Collector::row( array( 'c' => 'tel', 'z' => array( 'footer' ), 'x' => array( 1 ) ), $chrome, false, $known, $zones )['zone'], 'array as zone becomes empty' );

// ---- Report::query / sql
$q = Report::query( array( 'x' => array( 'cta' ), 's' => array( 'zone' ), 'ctas' => array( 'tel', array( 'x' ), ' bale ' ) ) );
same( array( 'cta', '', array( 'tel', 'bale' ) ), array( $q['x'], $q['s'], $q['ctas'] ), 'arrays in a report request are ignored, not fatal' );
$q = Report::query( array( 'x' => 'x; DROP TABLE', 's' => 'cta', 'days' => '9999', 'ctas' => 'tel, whatsapp,', 'zones' => array( 'footer' ) ) );
same( 'cta', $q['x'], 'unknown x falls back' );
same( '', $q['s'], 'series equal to x is dropped' );
same( 400, $q['days'], 'days capped' );
same( array( 'tel', 'whatsapp' ), $q['ctas'], 'comma list trimmed' );
list( $sql, $args ) = Report::sql( $q, 'wp_cta_clicks', '2026-01-01', '2026-01-31' );
same( 'SELECT cta, COUNT(*) AS n FROM wp_cta_clicks WHERE day BETWEEN %s AND %s AND flags = 0 AND cta IN (%s,%s) AND zone IN (%s) GROUP BY cta', $sql, 'sql with filters' );
same( array( '2026-01-01', '2026-01-31', 'tel', 'whatsapp', 'footer' ), $args, 'sql args in order' );
list( $sql ) = Report::sql( Report::query( array( 'x' => 'context', 's' => 'cta', 'all' => 1 ) ), 't', 'a', 'b' );
same( 'SELECT cta, context, COUNT(*) AS n FROM t WHERE day BETWEEN %s AND %s GROUP BY cta, context', $sql, 'columns not repeated; all=1 keeps flagged' );

// ---- Report::pivot -----------------------------------------------------------------------------------------------
$maps = array(
	'ctas'  => array(
		'tel'      => array( 'label' => 'تماس', 'group' => 'تماس', 'names' => '' ),
		'whatsapp' => array( 'label' => 'واتساپ', 'group' => 'پیام‌رسان', 'names' => "989100000001=کارشناس ۱\n" ),
		'bale'     => array( 'label' => 'بله', 'group' => 'پیام‌رسان', 'names' => '' ),
	),
	'zones' => array( 'footer' => array( 'label' => 'فوتر' ) ),
);
$rows = array(
	array( 'cta' => 'tel', 'n' => '3' ),
	array( 'cta' => 'whatsapp', 'n' => '5' ),
	array( 'cta' => 'bale', 'n' => '2' ),
	array( 'cta' => 'gone', 'n' => '1' ),
);
$out = Report::pivot( $rows, Report::query( array( 'x' => 'cta' ) ), $maps, '2026-01-01', '2026-01-01' );
same( array( 'واتساپ', 'تماس', 'بله', 'gone' ), $out['labels'], 'categories sorted by total; deleted CTA shows its key' );
same( array( array( 'name' => 'کلیک', 'data' => array( 5, 3, 2, 1 ) ) ), $out['series'], 'single series' );
same( 11, $out['total'], 'total' );

$out = Report::pivot( $rows, Report::query( array( 'x' => 'group' ) ), $maps, 'a', 'a' );
same( array( 'پیام‌رسان', 'تماس', 'بدون گروه' ), $out['labels'], 'groups merge CTAs without duplicating clicks' );
same( array( 7, 3, 1 ), $out['series'][0]['data'], 'group sums' );

$out = Report::pivot(
	array(
		array( 'cta' => 'whatsapp', 'context' => '989100000001', 'n' => '4' ),
		array( 'cta' => 'whatsapp', 'context' => '989100000002', 'n' => '1' ),
	),
	Report::query( array( 'x' => 'context' ) ),
	$maps,
	'a',
	'a'
);
same( array( 'کارشناس ۱', 'واتساپ 989100000002' ), $out['labels'], 'context names applied at report time' );

$out = Report::pivot(
	array(
		array( 'day' => '2026-01-02', 'cta' => 'tel', 'n' => '2' ),
		array( 'day' => '2026-01-02', 'cta' => 'whatsapp', 'n' => '1' ),
	),
	Report::query( array( 'x' => 'day', 's' => 'cta' ) ),
	$maps,
	'2026-01-01',
	'2026-01-03'
);
same( array( '2026-01-01', '2026-01-02', '2026-01-03' ), $out['labels'], 'every day in range, zeros filled' );
same( array( array( 'name' => 'تماس', 'data' => array( 0, 2, 0 ) ), array( 'name' => 'واتساپ', 'data' => array( 0, 1, 0 ) ) ), $out['series'], 'series by total' );

$out = Report::pivot( array( array( 'dow' => '5', 'n' => '1' ) ), Report::query( array( 'x' => 'dow' ) ), $maps, 'a', 'a' );
same( 'شنبه', $out['labels'][0], 'week starts on Saturday' );
same( array( 0, 0, 0, 0, 0, 0, 1 ), $out['series'][0]['data'], 'Friday last' );

$out = Report::pivot( array( array( 'hour' => '23', 'n' => '2' ) ), Report::query( array( 'x' => 'hour' ) ), $maps, 'a', 'a' );
same( 24, count( $out['labels'] ), '24 hours' );
same( 2, $out['series'][0]['data'][23], 'hour 23' );

same(
	array( '989120000002' => 'کارشناس ۲ (مهریه)', '989120000003' => 'محمدی' ),
	Settings::names( array( 'names' => "989120000002=کارشناس ۲ (مهریه)\r\n989120000003 = محمدی\nبدون مساوی" ) ),
	'names keep «م» (UTF-8 byte 0x85), trim, skip lines without =',
);

// Heat map: hours across, weekdays down; every weekday is a row, Saturday first, even with no clicks.
$out = Report::pivot(
	array(
		array( 'hour' => '21', 'dow' => '0', 'n' => '9' ),
		array( 'hour' => '10', 'dow' => '6', 'n' => '2' ),
	),
	Report::query( array( 'x' => 'hour', 's' => 'dow' ) ),
	$maps,
	'a',
	'a'
);
same( array( 'شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه' ), array_column( $out['series'], 'name' ), 'weekday rows in week order, not by total' );
same( 2, $out['series'][0]['data'][10], 'Saturday 10:00' );
same( 9, $out['series'][1]['data'][21], 'Sunday 21:00' );
same( array_fill( 0, 24, 0 ), $out['series'][6]['data'], 'empty Friday row is zeros' );

$many = array();
for ( $i = 0; $i < 10; $i++ ) {
	$many[] = array( 'cta' => 'tel', 'browser' => 'b' . $i, 'n' => (string) ( 20 - $i ) );
}
$out = Report::pivot( $many, Report::query( array( 'x' => 'cta', 's' => 'browser' ) ), $maps, 'a', 'a' );
same( Report::MAX_SERIES, count( $out['series'] ), 'series capped' );
same( 'سایر', $out['series'][ Report::MAX_SERIES - 1 ]['name'], 'tail folded into سایر' );
same( 13 + 12 + 11, $out['series'][ Report::MAX_SERIES - 1 ]['data'][0], 'سایر sums the tail' );

echo $fails ? "\n{$fails} of {$count} failed\n" : "{$count} passed\n";
exit( $fails ? 1 : 0 );
