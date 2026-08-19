<?php
/**
 * Update this plugin from GitHub releases.
 *
 * Written by hand rather than vendoring a library: it is ~120 lines against a
 * documented API, and it keeps the plugin dependency-free.
 *
 * How it works: WordPress asks for plugin update data via the
 * `update_plugins_{hostname}` filter (WP 5.8+). We answer for our own plugin by
 * reading the newest GitHub release, comparing tags to the version header, and
 * pointing WordPress at the release's zipball.
 *
 * Requirements for an update to appear:
 *   1. A GitHub release exists (not just a tag).
 *   2. Its tag is a version higher than the Version: header, e.g. v0.2.0.
 *   3. The bumped version header is committed in that release.
 *
 * Forget step 3 and the update silently never shows — the classic failure mode
 * of GitHub-updated plugins.
 *
 * @package DCW\GuideTools
 */

namespace DCW\GuideTools;

defined( 'ABSPATH' ) || exit;

class Updater {

	private const TRANSIENT = 'dcw_guide_tools_release';
	private const TTL       = 6 * HOUR_IN_SECONDS;

	public static function init(): void {
		add_filter( 'update_plugins_github.com', [ self::class, 'check' ], 10, 3 );
		add_filter( 'plugins_api', [ self::class, 'info' ], 10, 3 );
		add_action( 'upgrader_process_complete', [ self::class, 'flush' ], 10, 0 );
	}

	private static function repo(): string {
		/**
		 * Filter the GitHub repo (owner/name) this plugin updates from.
		 *
		 * @param string $repo Repository in "owner/name" form.
		 */
		return (string) apply_filters( 'dcw_guide_tools_github_repo', GITHUB_REPO );
	}

	private static function basename(): string {
		return plugin_basename( PLUGIN_FILE );
	}

	/**
	 * Answer WordPress's update query for this plugin.
	 *
	 * @param array|false $update Update data, false if none.
	 * @param array       $data   Plugin headers.
	 * @param string      $file   Plugin basename being checked.
	 *
	 * @return array|false
	 */
	public static function check( $update, array $data, string $file ) {
		if ( self::basename() !== $file ) {
			return $update;
		}

		$release = self::release();

		if ( ! $release ) {
			return $update;
		}

		$remote = self::normalize( $release['tag'] );

		if ( ! $remote || version_compare( $remote, VERSION, '<=' ) ) {
			return $update;
		}

		return [
			'slug'    => dirname( self::basename() ),
			'version' => $remote,
			'url'     => 'https://github.com/' . self::repo(),
			'package' => $release['zip'],
		];
	}

	/**
	 * Populate the "View details" modal so the update does not look anonymous.
	 *
	 * @param mixed  $result Response object or false.
	 * @param string $action Requested action.
	 * @param object $args   Request args.
	 */
	public static function info( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ( $args->slug ?? '' ) !== dirname( self::basename() ) ) {
			return $result;
		}

		$release = self::release();

		if ( ! $release ) {
			return $result;
		}

		return (object) [
			'name'          => 'DCW Guide Tools',
			'slug'          => dirname( self::basename() ),
			'version'       => self::normalize( $release['tag'] ),
			'homepage'      => 'https://github.com/' . self::repo(),
			'download_link' => $release['zip'],
			'sections'      => [
				'description' => wp_kses_post( $release['body'] ?: 'Equipment finder for the Dependable Coffee &amp; Water buying guides.' ),
			],
		];
	}

	/**
	 * Newest release from GitHub, cached.
	 *
	 * @return array{tag:string, zip:string, body:string}|null
	 */
	private static function release(): ?array {
		$cached = get_transient( self::TRANSIENT );

		if ( is_array( $cached ) ) {
			return $cached ?: null;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::repo() . '/releases/latest',
			[
				'timeout' => 10,
				'headers' => [
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'dcw-guide-tools/' . VERSION,
				],
			]
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Cache the miss briefly so a broken repo or rate limit does not
			// hit the API on every admin page load.
			set_transient( self::TRANSIENT, [], 15 * MINUTE_IN_SECONDS );

			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			set_transient( self::TRANSIENT, [], 15 * MINUTE_IN_SECONDS );

			return null;
		}

		// Prefer an attached .zip asset (a proper build) over the auto-generated
		// zipball, which wraps everything in a commit-hash directory.
		$zip = (string) ( $body['zipball_url'] ?? '' );

		foreach ( (array) ( $body['assets'] ?? [] ) as $asset ) {
			if ( ! empty( $asset['browser_download_url'] ) && str_ends_with( (string) $asset['name'], '.zip' ) ) {
				$zip = (string) $asset['browser_download_url'];
				break;
			}
		}

		$release = [
			'tag'  => (string) $body['tag_name'],
			'zip'  => $zip,
			'body' => (string) ( $body['body'] ?? '' ),
		];

		set_transient( self::TRANSIENT, $release, self::TTL );

		return $release;
	}

	/**
	 * "v1.2.3" and "1.2.3" both mean 1.2.3.
	 */
	private static function normalize( string $tag ): string {
		return ltrim( trim( $tag ), 'vV' );
	}

	public static function flush(): void {
		delete_transient( self::TRANSIENT );
	}
}
