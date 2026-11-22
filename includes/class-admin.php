<?php
/**
 * Admin screen «آمار CTA»: charts, latest clicks with CTA health, settings.
 *
 * @package CtaTracking
 */

namespace CtaTracking;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public const SLUG = 'cta-tracking';

	public const DIM_LABELS = array(
		'cta'     => 'دکمه (CTA)',
		'group'   => 'گروه',
		'context' => 'مقصد (شماره/آیدی)',
		'zone'    => 'ناحیهٔ صفحه',
		'device'  => 'دستگاه',
		'os'      => 'سیستم‌عامل',
		'browser' => 'مرورگر',
		'dow'     => 'روز هفته',
		'hour'    => 'ساعت',
		'day'     => 'روز',
		'page'    => 'صفحه',
	);

	public static function hooks(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_post_cta_tracking_save', array( self::class, 'save' ) );
	}

	public static function menu(): void {
		add_menu_page( 'آمار CTA', 'آمار CTA', CAP, self::SLUG, array( self::class, 'render' ), 'dashicons-chart-bar', 58 );
	}

	public static function assets( string $hook ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'cta-tracking-admin', plugins_url( 'assets/admin.css', FILE ), array(), VERSION );
		wp_enqueue_script( 'cta-tracking-admin', plugins_url( 'assets/admin.js', FILE ), array(), VERSION, true );
		$s = Settings::get();
		wp_localize_script(
			'cta-tracking-admin',
			'ctaTrackingAdmin',
			array(
				'root'    => esc_url_raw( rest_url( 'cta-tracking/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'charts'  => Report::charts(),
				'dims'    => self::DIM_LABELS,
				'ctas'    => wp_list_pluck( $s['ctas'], 'label', 'key' ),
				'zones'   => wp_list_pluck( $s['zones'], 'label', 'key' ),
				'devices' => array( 'mobile' => 'موبایل', 'desktop' => 'دسکتاپ', 'tablet' => 'تبلت' ),
			)
		);
	}

	public static function render(): void {
		$tabs = array( 'charts' => 'نمودارها', 'clicks' => 'کلیک‌های اخیر' );
		if ( current_user_can( 'manage_options' ) ) {
			$tabs['settings'] = 'تنظیمات';
		}
		$tab = sanitize_key( (string) ( $_GET['tab'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$tab = isset( $tabs[ $tab ] ) ? $tab : 'charts';
		echo '<div class="wrap cta-wrap"><h1>آمار کلیک CTA</h1><nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $key ) ),
				$key === $tab ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>ذخیره شد.</p></div>';
		}
		if ( 'charts' === $tab ) {
			echo '<div id="cta-dashboard" class="cta-dashboard"></div>';
			echo '<p class="description">عددها حداقل‌اند: کلیک بازدیدکننده‌هایی که اسکریپت را مسدود می‌کنند (ad blocker) ثبت نمی‌شود. کلیک‌های مدیران واردشده و ربات‌ها در نمودارها حساب نمی‌شوند.</p>';
		} elseif ( 'clicks' === $tab ) {
			self::render_clicks();
		} else {
			self::render_settings();
		}
		echo '</div>';
	}

	private static function render_clicks(): void {
		$ctas   = Settings::by_key( 'ctas' );
		$zones  = Settings::by_key( 'zones' );
		$health = Clicks::health();
		echo '<h2>سلامت دکمه‌ها</h2><table class="widefat striped cta-health"><thead><tr><th>دکمه</th><th>آخرین کلیک</th><th>۷ روز اخیر</th><th></th></tr></thead><tbody>';
		foreach ( $ctas as $key => $cta ) {
			$h    = $health[ $key ] ?? null;
			$week = $h ? (int) $h['week'] : 0;
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $cta['label'] ? $cta['label'] : $key ),
				esc_html( $h ? self::when( $h['last_at'] ) : 'هرگز' ),
				esc_html( number_format_i18n( $week ) ),
				0 === $week ? '<span class="cta-warn">هفت روز بدون کلیک؛ سلکتور را با <code>?cta_debug=1</code> روی سایت بررسی کنید.</span>' : ''
			);
		}
		echo '</tbody></table>';

		$flags = array(
			UA::UNTRUSTED => 'اسکریپتی',
			UA::WEBDRIVER => 'خودکار',
			UA::BOT       => 'ربات',
			UA::TEAM      => 'مدیر واردشده',
		);
		echo '<h2>۱۰۰ کلیک آخر</h2><table class="widefat striped"><thead><tr><th>زمان</th><th>دکمه</th><th>مقصد</th><th>ناحیه</th><th>دستگاه</th><th>مرورگر</th><th>عرض</th><th>صفحه</th><th>علامت</th></tr></thead><tbody>';
		foreach ( Clicks::recent() as $r ) {
			$why = array();
			foreach ( $flags as $bit => $label ) {
				if ( (int) $r['flags'] & $bit ) {
					$why[] = $label;
				}
			}
			$post = (int) $r['post_id'];
			printf(
				'<tr%s><td>%s</td><td>%s</td><td dir="ltr">%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				$why ? ' class="cta-flagged"' : '',
				esc_html( self::when( $r['clicked_at'] ) ),
				esc_html( isset( $ctas[ $r['cta'] ] ) && $ctas[ $r['cta'] ]['label'] ? $ctas[ $r['cta'] ]['label'] : $r['cta'] ),
				esc_html( $r['context'] ),
				esc_html( isset( $zones[ $r['zone'] ] ) ? $zones[ $r['zone'] ]['label'] : $r['zone'] ),
				esc_html( $r['device'] . ' / ' . $r['os'] ),
				esc_html( $r['browser'] ),
				esc_html( $r['vw'] ),
				$post ? '<a href="' . esc_url( get_permalink( $post ) ) . '" target="_blank">' . esc_html( get_the_title( $post ) ) . '</a>' : '—',
				esc_html( implode( '، ', $why ) )
			);
		}
		echo '</tbody></table>';
	}

	/** A stored UTC time in the site's format and timezone (Jalali when a date plugin is active). */
	private static function when( string $utc ): string {
		return wp_date( 'Y/m/d H:i', strtotime( $utc . ' UTC' ) );
	}

	private static function render_settings(): void {
		$s = Settings::get();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="cta-settings">';
		wp_nonce_field( 'cta_tracking_save' );
		echo '<input type="hidden" name="action" value="cta_tracking_save">';

		echo '<h2>دکمه‌ها (CTA)</h2><p class="description">هر کلیک به نزدیک‌ترین عنصری که با یک سلکتور می‌خواند نسبت داده می‌شود؛ اولویت بیشتر فقط وقتی مهم است که دو سلکتور روی یک عنصر بخوانند. «الگوی مقصد» یک regex جاوااسکریپت روی href است که گروه اولش (مثلاً شماره) ذخیره می‌شود. «نام مقصدها»: هر خط <code>مقدار=نام</code>، مثل <code>989121234567=کارشناس ۱</code>. کلید را بعد از ثبت کلیک عوض نکنید.</p>';
		echo '<table class="widefat cta-rows" data-list="ctas"><thead><tr><th>کلید</th><th>نام</th><th>سلکتور CSS</th><th>اولویت</th><th>گروه</th><th>الگوی مقصد</th><th>نام مقصدها</th><th></th></tr></thead><tbody>';
		foreach ( array_merge( $s['ctas'], array( array() ) ) as $i => $c ) {
			$n = 'ctas[' . $i . ']';
			printf(
				'<tr><td><input name="%1$s[key]" value="%2$s" dir="ltr" size="10"></td><td><input name="%1$s[label]" value="%3$s" size="12"></td><td><input name="%1$s[selector]" value="%4$s" dir="ltr" class="cta-selector large-text"></td><td><input type="number" name="%1$s[priority]" value="%5$s" class="small-text"></td><td><input name="%1$s[group]" value="%6$s" size="10"></td><td><input name="%1$s[context]" value="%7$s" dir="ltr" class="cta-regex"></td><td><textarea name="%1$s[names]" rows="2" dir="auto">%8$s</textarea></td><td><button type="button" class="button-link cta-remove">حذف</button></td></tr>',
				esc_attr( $n ),
				esc_attr( $c['key'] ?? '' ),
				esc_attr( $c['label'] ?? '' ),
				esc_attr( $c['selector'] ?? '' ),
				esc_attr( (string) ( $c['priority'] ?? 10 ) ),
				esc_attr( $c['group'] ?? '' ),
				esc_attr( $c['context'] ?? '' ),
				esc_textarea( $c['names'] ?? '' )
			);
		}
		echo '</tbody></table><p><button type="button" class="button cta-add" data-list="ctas">+ دکمهٔ جدید</button></p>';

		echo '<h2>ناحیه‌ها</h2><p class="description">ناحیه از روی محل کلیک تعیین می‌شود: نزدیک‌ترین والدی که با یکی از این سلکتورها بخواند. اگر یک عنصر با چند ناحیه بخواند، ردیف بالاتر برنده است.</p>';
		echo '<table class="widefat cta-rows" data-list="zones"><thead><tr><th>کلید</th><th>نام</th><th>سلکتور CSS</th><th></th></tr></thead><tbody>';
		foreach ( array_merge( $s['zones'], array( array() ) ) as $i => $z ) {
			$n = 'zones[' . $i . ']';
			printf(
				'<tr><td><input name="%1$s[key]" value="%2$s" dir="ltr" size="10"></td><td><input name="%1$s[label]" value="%3$s" size="12"></td><td><input name="%1$s[selector]" value="%4$s" dir="ltr" class="cta-selector large-text"></td><td><button type="button" class="button-link cta-remove">حذف</button></td></tr>',
				esc_attr( $n ),
				esc_attr( $z['key'] ?? '' ),
				esc_attr( $z['label'] ?? '' ),
				esc_attr( $z['selector'] ?? '' )
			);
		}
		echo '</tbody></table><p><button type="button" class="button cta-add" data-list="zones">+ ناحیهٔ جدید</button></p>';

		printf(
			'<h2>نگه‌داری داده</h2><p><label>کلیک‌های قدیمی‌تر از <input type="number" name="keep_days" value="%d" min="30" max="3650" class="small-text"> روز پاک شوند.</label></p><p><label><input type="checkbox" name="delete_on_uninstall" value="1"%s> هنگام حذف افزونه، همهٔ کلیک‌ها و تنظیمات هم پاک شوند.</label></p>',
			(int) $s['keep_days'],
			checked( $s['delete_on_uninstall'], true, false )
		);
		submit_button( 'ذخیرهٔ تنظیمات' );
		echo '</form>';
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی ندارید.', 403 );
		}
		check_admin_referer( 'cta_tracking_save' );
		update_option( Settings::OPTION, Settings::sanitize( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized field by field.
		/** Cached pages carry the old definitions inline; cache plugins can purge on this. */
		do_action( 'cta_tracking_config_changed' );
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=settings&saved=1' ) );
		exit;
	}
}
