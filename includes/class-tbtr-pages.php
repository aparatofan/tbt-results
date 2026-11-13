<?php
/**
 * The two shortcodes and their assets.
 *
 *   [tbt_results]     teachers: all results, one student, one activity
 *   [tbt_my_results]  students: their own results
 *
 * No hero: the Divi page supplies its header. The server prints the
 * messages that need no data (Notes missing, logged out, not a teacher, no
 * classes yet); everything else is drawn by assets/js/results.js from the
 * REST routes.
 *
 * The design tokens are TBT Hub's, under the shared handle 'tbt-tokens'.
 * When Hub is inactive, the vendored copy in assets/css/tbt-tokens.css is
 * registered under that same handle, so Hub's copy wins whenever it is
 * present (the Test Maker / Drag & Drop pattern).
 */

defined( 'ABSPATH' ) || exit;

class TBTR_Pages {

	const TEACHER = 'tbt_results';
	const STUDENT = 'tbt_my_results';

	public function __construct() {
		add_shortcode( self::TEACHER, array( $this, 'teacher' ) );
		add_shortcode( self::STUDENT, array( $this, 'student' ) );
		// After Hub registers 'tbt-tokens' at priority 5.
		add_action( 'wp_enqueue_scripts', array( $this, 'early_styles' ), 20 );
		add_action( 'template_redirect', array( $this, 'maybe_nocache' ) );
		add_action( 'wp_ajax_tbtr_nonce', array( $this, 'fresh_nonce' ) );
	}

	/* ---------- Assets ---------- */

	/**
	 * Register the shared stylesheets. Safe to call more than once.
	 */
	private static function register() {
		if ( ! wp_style_is( 'tbt-tokens', 'registered' ) ) {
			wp_register_style( 'tbt-tokens', TBTR_URL . 'assets/css/tbt-tokens.css', array(), self::file_version( 'assets/css/tbt-tokens.css' ) );
		}
		if ( ! wp_style_is( 'tbtr-fonts', 'registered' ) ) {
			wp_register_style( 'tbtr-fonts', TBTR_URL . 'assets/css/fonts.css', array(), TBTR_VERSION );
		}
		if ( ! wp_style_is( 'tbtr-results', 'registered' ) ) {
			wp_register_style( 'tbtr-results', TBTR_URL . 'assets/css/results.css', array( 'tbt-tokens', 'tbtr-fonts' ), TBTR_VERSION );
		}
	}

	private static function enqueue_styles() {
		self::register();
		wp_enqueue_style( 'tbtr-results' );
	}

	/**
	 * Styles in the head on a page holding either shortcode, so nothing
	 * flashes unstyled. The script is added by the shortcode itself.
	 */
	public function early_styles() {
		if ( self::page_has( self::TEACHER ) || self::page_has( self::STUDENT ) ) {
			self::enqueue_styles();
		}
	}

	/**
	 * A per-visitor page: keep it out of every cache.
	 */
	public function maybe_nocache() {
		if ( ! self::page_has( self::TEACHER ) && ! self::page_has( self::STUDENT ) ) {
			return;
		}
		if ( headers_sent() ) {
			return;
		}
		nocache_headers();
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}

	/**
	 * The script and its boot data.
	 *
	 * @param string $mode 'teacher' or 'student'.
	 */
	private static function enqueue_app( $mode ) {
		static $done = false;
		self::enqueue_styles();
		if ( $done ) {
			return;
		}
		$done = true;
		wp_enqueue_script( 'tbtr-results', TBTR_URL . 'assets/js/results.js', array(), TBTR_VERSION, true );
		wp_localize_script(
			'tbtr-results',
			'TBTR_RESULTS',
			array(
				'rest'  => esc_url_raw( rest_url( TBTR_Rest::NS . '/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'ajax'  => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
				'mode'  => $mode,
			)
		);
	}

	/**
	 * A fresh REST nonce for a page left open long enough for its nonce to
	 * expire. Logged-in users only (wp_ajax_, not wp_ajax_nopriv_).
	 */
	public function fresh_nonce() {
		nocache_headers();
		wp_send_json_success( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
	}

	/**
	 * Is the current singular page one that contains this shortcode?
	 *
	 * @param string $shortcode Shortcode tag.
	 * @return bool
	 */
	private static function page_has( $shortcode ) {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_post();
		return $post && has_shortcode( (string) $post->post_content, $shortcode );
	}

	/**
	 * Cache buster for the vendored token file: its modification time.
	 *
	 * @param string $relative Path relative to the plugin folder.
	 * @return string
	 */
	private static function file_version( $relative ) {
		$mtime = @filemtime( TBTR_DIR . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $mtime ? TBTR_VERSION . '.' . $mtime : TBTR_VERSION;
	}

	/* ---------- Shortcodes ---------- */

	/**
	 * [tbt_results]
	 *
	 * @return string
	 */
	public function teacher() {
		self::enqueue_styles();

		if ( ! TBTR_Access::notes_ready() ) {
			return self::wrap( self::line( __( 'Results need TBT Notes 1.22.0 or later.', 'tbt-results' ) ) );
		}
		if ( ! is_user_logged_in() ) {
			return self::wrap(
				self::login_card(
					__( 'Log in to see results', 'tbt-results' ),
					__( 'Results are for TBT teachers. Log in and they open straight away.', 'tbt-results' )
				)
			);
		}
		$user_id = get_current_user_id();
		if ( ! TBTR_Access::is_teacher( $user_id ) ) {
			return self::wrap( self::line( __( 'This page is for teachers.', 'tbt-results' ) ) );
		}
		if ( null === TBTR_Access::scope( $user_id, 'teacher' ) ) {
			return self::wrap( self::line( __( 'Results appear here once you have a class in TBT Notes and your students finish something.', 'tbt-results' ) ) );
		}

		self::enqueue_app( 'teacher' );
		return self::app( 'teacher' );
	}

	/**
	 * [tbt_my_results]
	 *
	 * @return string
	 */
	public function student() {
		self::enqueue_styles();

		if ( ! TBTR_Access::notes_ready() ) {
			return self::wrap( self::line( __( 'Results need TBT Notes 1.22.0 or later.', 'tbt-results' ) ) );
		}
		if ( ! is_user_logged_in() ) {
			return self::wrap(
				self::login_card(
					__( 'Log in to see your results', 'tbt-results' ),
					__( 'Everything you finish on The Blue Tree is here. Log in and it opens straight away.', 'tbt-results' )
				)
			);
		}

		self::enqueue_app( 'student' );
		return self::app( 'student' );
	}

	/* ---------- Markup ---------- */

	/**
	 * The mount point. The noscript line is the only content without JS.
	 *
	 * @param string $mode 'teacher' or 'student'.
	 * @return string
	 */
	private static function app( $mode ) {
		return sprintf(
			'<div class="tbtr tbtr--%1$s" data-tbtr-app="%1$s"><p class="tbtr-loading" role="status">%2$s</p><noscript><p class="tbtr-muted">%3$s</p></noscript></div>',
			esc_attr( $mode ),
			esc_html__( 'Loading results…', 'tbt-results' ),
			esc_html__( 'Results need JavaScript. Turn it on and reload the page.', 'tbt-results' )
		);
	}

	/**
	 * @param string $inner Markup.
	 * @return string
	 */
	private static function wrap( $inner ) {
		return '<div class="tbtr">' . $inner . '</div>';
	}

	/**
	 * One line on its own card.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function line( $text ) {
		return '<section class="tbtr-stage tbtr-message"><p class="tbtr-muted">' . esc_html( $text ) . '</p></section>';
	}

	/**
	 * The login card, as Test Maker shows it: heading, one line, Log in.
	 *
	 * @param string $title Heading.
	 * @param string $text  One line.
	 * @return string
	 */
	private static function login_card( $title, $text ) {
		$here = get_permalink();
		return sprintf(
			'<section class="tbtr-stage tbtr-message"><h2 class="tbtr-stage__title">%1$s</h2><p class="tbtr-muted">%2$s</p><p><a class="tbtr-btn tbtr-btn--primary" href="%3$s">%4$s</a></p></section>',
			esc_html( $title ),
			esc_html( $text ),
			esc_url( wp_login_url( $here ? $here : '' ) ),
			esc_html__( 'Log in', 'tbt-results' )
		);
	}
}
