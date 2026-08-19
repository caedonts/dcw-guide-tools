<?php
/**
 * Shortcode: [dcw_finder finder="coffee"]
 *
 * The Bricks element is the primary way to place the finder; this exists so it
 * can also go in a classic editor, a widget, or another guide template without
 * needing the builder.
 *
 * @package DCW\GuideTools
 */

namespace DCW\GuideTools;

defined( 'ABSPATH' ) || exit;

class Shortcode {

	public const TAG = 'dcw_finder';

	public static function init(): void {
		add_shortcode( self::TAG, [ self::class, 'render' ] );
	}

	/**
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public static function render( $atts ): string {
		$atts = shortcode_atts(
			[ 'finder' => 'coffee' ],
			is_array( $atts ) ? $atts : [],
			self::TAG
		);

		return Renderer::render( sanitize_key( $atts['finder'] ) );
	}
}
