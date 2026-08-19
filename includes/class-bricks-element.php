<?php
/**
 * Native Bricks element: "DCW Finder".
 *
 * Registering a real element (rather than dropping a shortcode into a Code
 * element) keeps the finder visible and selectable in the builder, which is the
 * house rule on this project: structure lives in Bricks, never hidden in code
 * blocks.
 *
 * @package DCW\GuideTools
 */

namespace DCW\GuideTools;

defined( 'ABSPATH' ) || exit;

// Bricks may not be the active theme; the loader guards registration, but the
// class body itself must not be parsed without its parent available.
if ( ! class_exists( '\Bricks\Element' ) ) {
	return;
}

class Bricks_Element_Finder extends \Bricks\Element {

	public $category = 'dcw';
	public $name     = 'dcw-finder';
	public $icon     = 'ti-help-alt';

	public function get_label(): string {
		return esc_html__( 'DCW Finder', 'dcw-guide-tools' );
	}

	public function set_control_groups(): void {
		$this->control_groups['finder'] = [
			'title' => esc_html__( 'Finder', 'dcw-guide-tools' ),
			'tab'   => 'content',
		];
	}

	public function set_controls(): void {
		$options = [];

		foreach ( Config::all() as $slug => $finder ) {
			$ready = ! empty( $finder['questions'] ) && ! empty( $finder['categories'] );

			$options[ $slug ] = $ready
				? (string) ( $finder['label'] ?? $slug )
				: sprintf(
					/* translators: %s: finder label */
					esc_html__( '%s (no questions yet)', 'dcw-guide-tools' ),
					(string) ( $finder['label'] ?? $slug )
				);
		}

		$this->controls['finder'] = [
			'tab'         => 'content',
			'group'       => 'finder',
			'label'       => esc_html__( 'Product line', 'dcw-guide-tools' ),
			'type'        => 'select',
			'options'     => $options,
			'default'     => 'coffee',
			'clearable'   => false,
			'description' => esc_html__( 'Questions, scoring and result copy are managed in Settings → DCW Guide Tools.', 'dcw-guide-tools' ),
		];
	}

	public function render(): void {
		$settings = $this->settings;
		$slug     = isset( $settings['finder'] ) ? sanitize_key( (string) $settings['finder'] ) : 'coffee';

		echo "<div {$this->render_attributes( '_root' )}>";
		echo Renderer::render( $slug ); // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped -- Renderer escapes its own output.
		echo '</div>';
	}
}
