<?php
/**
 * Minimal settings screen.
 *
 * Deliberately small: it wires the finder to a Fluent Form and exposes the two
 * result rules the team has open questions about. The full questions-and-
 * scoring editor is designed (see Notes/Finder Admin Design.md) and comes next;
 * until then the scoring matrix lives in class-config.php and is filterable.
 *
 * @package DCW\GuideTools
 */

namespace DCW\GuideTools;

defined( 'ABSPATH' ) || exit;

class Settings {

	public const PAGE = 'dcw-guide-tools';

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_init', [ self::class, 'register' ] );
	}

	public static function menu(): void {
		add_options_page(
			esc_html__( 'DCW Guide Tools', 'dcw-guide-tools' ),
			esc_html__( 'DCW Guide Tools', 'dcw-guide-tools' ),
			'manage_options',
			self::PAGE,
			[ self::class, 'screen' ]
		);
	}

	public static function register(): void {
		register_setting(
			self::PAGE,
			Config::OPTION_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => [ self::class, 'sanitize' ],
				'default'           => [],
			]
		);
	}

	/**
	 * Only the fields this screen actually exposes are accepted; anything else
	 * posted is discarded rather than merged blindly into the finder config.
	 *
	 * @param mixed $input Raw posted value.
	 *
	 * @return array<string, array>
	 */
	public static function sanitize( $input ): array {
		$clean = [];

		foreach ( (array) $input as $slug => $values ) {
			$slug = sanitize_key( (string) $slug );

			if ( ! Config::get( $slug ) ) {
				continue;
			}

			$clean[ $slug ] = [
				'form_id'              => absint( $values['form_id'] ?? 0 ),
				'also_consider_window' => max( 0, absint( $values['also_consider_window'] ?? 2 ) ),
				'explore_url'          => sanitize_text_field( (string) ( $values['explore_url'] ?? '#options' ) ),
				'compare_url'          => sanitize_text_field( (string) ( $values['compare_url'] ?? '#compare' ) ),
				'contact_url'          => sanitize_text_field( (string) ( $values['contact_url'] ?? '/contact/' ) ),
			];
		}

		Config::flush();

		return $clean;
	}

	public static function screen(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$finders = Config::all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'DCW Guide Tools', 'dcw-guide-tools' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Place the finder with the "DCW Finder" element in Bricks, or the shortcode [dcw_finder finder="coffee"].', 'dcw-guide-tools' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( self::PAGE ); ?>

				<?php foreach ( $finders as $slug => $finder ) : ?>
					<?php
					$ready = ! empty( $finder['questions'] ) && ! empty( $finder['categories'] );
					$field = Config::OPTION_KEY . '[' . $slug . ']';
					?>
					<h2>
						<?php echo esc_html( (string) ( $finder['label'] ?? $slug ) ); ?>
						<?php if ( ! $ready ) : ?>
							<span class="dashicons dashicons-warning" style="color:#b32d2e" aria-hidden="true"></span>
							<span style="font-size:13px;font-weight:400;color:#646970">
								<?php esc_html_e( 'No questions or categories defined yet — this finder renders nothing on the front end.', 'dcw-guide-tools' ); ?>
							</span>
						<?php endif; ?>
					</h2>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( $slug ); ?>-form"><?php esc_html_e( 'Report form', 'dcw-guide-tools' ); ?></label></th>
							<td>
								<select name="<?php echo esc_attr( $field ); ?>[form_id]" id="<?php echo esc_attr( $slug ); ?>-form">
									<option value="0"><?php esc_html_e( '— None —', 'dcw-guide-tools' ); ?></option>
									<?php foreach ( self::fluent_forms() as $id => $title ) : ?>
										<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( (int) ( $finder['form_id'] ?? 0 ), (int) $id ); ?>>
											<?php echo esc_html( $title . ' (#' . $id . ')' ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'The Fluent Form shown inside the result panel. Quiz answers are attached to the submission as hidden fields.', 'dcw-guide-tools' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( $slug ); ?>-window"><?php esc_html_e( '"Also consider" window', 'dcw-guide-tools' ); ?></label></th>
							<td>
								<input type="number" min="0" max="20" class="small-text" id="<?php echo esc_attr( $slug ); ?>-window"
									name="<?php echo esc_attr( $field ); ?>[also_consider_window]"
									value="<?php echo esc_attr( (string) ( $finder['also_consider_window'] ?? 2 ) ); ?>">
								<p class="description"><?php esc_html_e( 'How close a runner-up must be to count as a close call. Currently cosmetic: per the design, the result always shows the next-best option regardless.', 'dcw-guide-tools' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Links', 'dcw-guide-tools' ); ?></th>
							<td>
								<p>
									<label>
										<span style="display:inline-block;min-width:90px"><?php esc_html_e( 'Explore', 'dcw-guide-tools' ); ?></span>
										<input type="text" class="regular-text" name="<?php echo esc_attr( $field ); ?>[explore_url]" value="<?php echo esc_attr( (string) ( $finder['explore_url'] ?? '' ) ); ?>">
									</label>
								</p>
								<p>
									<label>
										<span style="display:inline-block;min-width:90px"><?php esc_html_e( 'Compare', 'dcw-guide-tools' ); ?></span>
										<input type="text" class="regular-text" name="<?php echo esc_attr( $field ); ?>[compare_url]" value="<?php echo esc_attr( (string) ( $finder['compare_url'] ?? '' ) ); ?>">
									</label>
								</p>
								<p>
									<label>
										<span style="display:inline-block;min-width:90px"><?php esc_html_e( 'Book a call', 'dcw-guide-tools' ); ?></span>
										<input type="text" class="regular-text" name="<?php echo esc_attr( $field ); ?>[contact_url]" value="<?php echo esc_attr( (string) ( $finder['contact_url'] ?? '' ) ); ?>">
									</label>
								</p>
								<p class="description"><?php esc_html_e( 'Relative URLs or in-page anchors. Never paste the staging hostname here — it changes at launch.', 'dcw-guide-tools' ); ?></p>
							</td>
						</tr>
					</table>
				<?php endforeach; ?>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Published Fluent Forms, id => title.
	 *
	 * @return array<int, string>
	 */
	private static function fluent_forms(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'fluentform_forms';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fluent Forms exposes no read API for this.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( $exists !== $table ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT id, title FROM {$table} WHERE status = 'published' ORDER BY id ASC" );

		$out = [];

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->id ] = (string) $row->title;
		}

		return $out;
	}
}
