<?php
/**
 * Plugin Name:       DCW Guide Tools
 * Plugin URI:        https://github.com/caedonts/dcw-guide-tools
 * Description:       Equipment finder for the Dependable Coffee &amp; Water buying guides. Renders a multi-step quiz that recommends a system category, exposed as a native Bricks element and a shortcode.
 * Version:           0.4.1
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Surefaze
 * Author URI:        https://surefaze.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dcw-guide-tools
 *
 * @package DCW\GuideTools
 */

namespace DCW\GuideTools;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.4.1';

/**
 * The GitHub repository this plugin updates from.
 *
 * Public repo, so no access token is stored on the site. Override with the
 * `dcw_guide_tools_github_repo` filter if the repo is ever moved or renamed.
 */
const GITHUB_REPO = 'caedonts/dcw-guide-tools';

define( __NAMESPACE__ . '\PLUGIN_FILE', __FILE__ );
define( __NAMESPACE__ . '\PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( __NAMESPACE__ . '\PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once PLUGIN_DIR . 'includes/class-config.php';
require_once PLUGIN_DIR . 'includes/class-scorer.php';
require_once PLUGIN_DIR . 'includes/class-renderer.php';
require_once PLUGIN_DIR . 'includes/class-assets.php';
require_once PLUGIN_DIR . 'includes/class-shortcode.php';
require_once PLUGIN_DIR . 'includes/class-admin.php';
require_once PLUGIN_DIR . 'includes/class-updater.php';

/**
 * Boot the plugin.
 *
 * Everything hangs off `plugins_loaded` so the Bricks theme and Fluent Forms
 * are both known-loaded before we look for them.
 */
function boot(): void {
	Assets::init();
	Shortcode::init();
	Admin::init();
	Updater::init();

	// Bricks registers custom elements on `init`, after the theme has declared
	// its own element base class. Guarded so the plugin stays harmless if the
	// site is ever moved to a non-Bricks theme.
	add_action(
		'init',
		static function (): void {
			if ( ! class_exists( '\Bricks\Elements' ) ) {
				return;
			}

			require_once PLUGIN_DIR . 'includes/class-bricks-element.php';
			\Bricks\Elements::register_element( PLUGIN_DIR . 'includes/class-bricks-element.php', 'dcw-finder', __NAMESPACE__ . '\Bricks_Element_Finder' );
		},
		11
	);

	// Name the custom element category so the finder does not land in an
	// unlabelled group in the builder's element panel.
	add_filter(
		'bricks/builder/i18n',
		static function ( $i18n ) {
			$i18n['dcw'] = esc_html__( 'Dependable', 'dcw-guide-tools' );

			return $i18n;
		}
	);
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\boot' );
