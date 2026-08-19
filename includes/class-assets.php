<?php
/**
 * Asset registration.
 *
 * Registered on `wp_enqueue_scripts`, enqueued on demand by the renderer, so a
 * page without a finder ships no finder CSS or JS.
 *
 * @package DCW\GuideTools
 */

namespace DCW\GuideTools;

defined( 'ABSPATH' ) || exit;

class Assets {

	public const HANDLE = 'dcw-guide-tools-finder';

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', [ self::class, 'register' ] );
	}

	public static function register(): void {
		$dir = PLUGIN_DIR . 'assets/';
		$url = PLUGIN_URL . 'assets/';

		wp_register_style(
			self::HANDLE,
			$url . 'finder.css',
			[],
			self::version( $dir . 'finder.css' )
		);

		wp_register_script(
			self::HANDLE,
			$url . 'finder.js',
			[],
			self::version( $dir . 'finder.js' ),
			true
		);
	}

	/**
	 * Enqueue the finder assets. Safe to call more than once per request.
	 *
	 * Bricks renders elements after `wp_enqueue_scripts` has run in some
	 * contexts (notably the builder preview), so fall back to printing in the
	 * footer if we have already passed the enqueue phase.
	 */
	public static function enqueue(): void {
		if ( ! wp_style_is( self::HANDLE, 'registered' ) ) {
			self::register();
		}

		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * Cache-bust on file modification time in development, plugin version in
	 * production. Avoids the classic "my CSS change isn't showing" loop.
	 */
	private static function version( string $path ): string {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && file_exists( $path ) ) {
			return (string) filemtime( $path );
		}

		return VERSION;
	}
}
