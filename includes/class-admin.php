<?php
/**
 * The finder admin screen.
 *
 * A top-level "Finder Admin" menu rendering the design in
 * Notes/Finder Admin Design.md (Alt B, the Trustwards system): the plugin's own
 * sidebar, a breadcrumb, Save sitting under the title, and one card per
 * question with an editable heading and a scoring matrix.
 *
 * This is a real editor, not a mock — everything here writes back into the
 * `dcw_guide_tools_finders` option that Config reads.
 *
 * @package DCW\GuideTools
 */

namespace DCW\GuideTools;

defined( 'ABSPATH' ) || exit;

class Admin {

	public const SLUG      = 'dcw-finder';
	public const HANDLE    = 'dcw-guide-tools-admin';
	private const CAP      = 'manage_options';
	private const NONCE    = 'dcw_guide_tools_save';

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_post_dcw_guide_tools_save', [ self::class, 'handle_save' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'assets' ] );
	}

	public static function menu(): void {
		/*
		 * Position 22 puts this directly under Buying Guides. Pages and the
		 * Buying Guides post type both declare position 20, so WordPress
		 * bumps Buying Guides past Equipment (21) to resolve the collision —
		 * 22 clears both without colliding in turn, and stays above
		 * Comments (25).
		 */
		add_menu_page(
			esc_html__( 'Finder Admin', 'dcw-guide-tools' ),
			esc_html__( 'Finder Admin', 'dcw-guide-tools' ),
			self::CAP,
			self::SLUG,
			[ self::class, 'screen' ],
			'dashicons-editor-help',
			22
		);
	}

	public static function assets( string $hook ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}

		$dir = PLUGIN_DIR . 'assets/';
		$url = PLUGIN_URL . 'assets/';

		$ver = static function ( string $path ): string {
			return ( defined( 'WP_DEBUG' ) && WP_DEBUG && file_exists( $path ) ) ? (string) filemtime( $path ) : VERSION;
		};

		wp_enqueue_style( self::HANDLE, $url . 'admin.css', [], $ver( $dir . 'admin.css' ) );
		wp_enqueue_script( self::HANDLE, $url . 'admin.js', [], $ver( $dir . 'admin.js' ), true );
	}

	/**
	 * Which product line is being edited.
	 */
	private static function current_finder(): string {
		$slug = isset( $_GET['finder'] ) ? sanitize_key( wp_unslash( (string) $_GET['finder'] ) ) : 'coffee';

		return Config::get( $slug ) ? $slug : 'coffee';
	}

	private static function current_view(): string {
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( (string) $_GET['view'] ) ) : 'questions';

		return in_array( $view, [ 'questions', 'settings' ], true ) ? $view : 'questions';
	}

	private static function url( string $view, string $finder ): string {
		return add_query_arg(
			[
				'page'   => self::SLUG,
				'view'   => $view,
				'finder' => $finder,
			],
			admin_url( 'admin.php' )
		);
	}

	// ---------------------------------------------------------------- Save

	public static function handle_save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to edit the finder.', 'dcw-guide-tools' ) );
		}

		check_admin_referer( self::NONCE );

		$slug   = isset( $_POST['finder'] ) ? sanitize_key( wp_unslash( (string) $_POST['finder'] ) ) : 'coffee';
		$view   = isset( $_POST['view'] ) ? sanitize_key( wp_unslash( (string) $_POST['view'] ) ) : 'questions';
		$finder = Config::get( $slug );

		if ( ! $finder ) {
			wp_safe_redirect( self::url( 'questions', 'coffee' ) );
			exit;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each leaf is sanitized below.
		$raw   = isset( $_POST['dcw'] ) ? wp_unslash( (array) $_POST['dcw'] ) : [];
		$stored = get_option( Config::OPTION_KEY, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$entry  = $stored[ $slug ] ?? [];

		if ( 'settings' === $view ) {
			$entry['form_id']     = absint( $raw['form_id'] ?? 0 );
			$entry['explore_url'] = sanitize_text_field( (string) ( $raw['explore_url'] ?? '#options' ) );
			$entry['compare_url'] = sanitize_text_field( (string) ( $raw['compare_url'] ?? '#compare' ) );
			$entry['contact_url'] = sanitize_text_field( (string) ( $raw['contact_url'] ?? '/contact/' ) );
		} else {
			$categories = array_keys( (array) ( $finder['categories'] ?? [] ) );

			$entry['questions'] = self::sanitize_questions( (array) ( $raw['questions'] ?? [] ), $categories );
			$entry['tiebreak']  = self::sanitize_tiebreak( (array) ( $raw['tiebreak'] ?? [] ), $categories, (array) ( $finder['tiebreak'] ?? [] ) );

			if ( isset( $raw['categories'] ) && is_array( $raw['categories'] ) ) {
				foreach ( $raw['categories'] as $key => $values ) {
					$key = sanitize_key( (string) $key );

					if ( ! in_array( $key, $categories, true ) ) {
						continue;
					}

					$entry['categories'][ $key ] = [
						'label'    => sanitize_text_field( (string) ( $values['label'] ?? '' ) ),
						'result'   => sanitize_textarea_field( (string) ( $values['result'] ?? '' ) ),
						'consider' => sanitize_textarea_field( (string) ( $values['consider'] ?? '' ) ),
					];
				}
			}
		}

		$stored[ $slug ] = $entry;

		update_option( Config::OPTION_KEY, $stored );
		Config::flush();
		self::purge_caches();

		wp_safe_redirect( add_query_arg( 'saved', '1', self::url( $view, $slug ) ) );
		exit;
	}

	/**
	 * @param array    $rows       Raw posted questions.
	 * @param string[] $categories Valid category slugs.
	 */
	private static function sanitize_questions( array $rows, array $categories ): array {
		$out  = [];
		$seen = [];

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$title = trim( sanitize_text_field( (string) ( $row['title'] ?? '' ) ) );

			// A question with no title is a leftover empty row, not data.
			if ( '' === $title ) {
				continue;
			}

			$id = sanitize_key( (string) ( $row['id'] ?? '' ) );

			if ( '' === $id ) {
				$id = self::slugify( $title );
			}

			// Ids are the key answers are stored against, so they must be unique.
			$base = $id;
			$n    = 2;

			while ( in_array( $id, $seen, true ) ) {
				$id = $base . '-' . $n++;
			}

			$seen[] = $id;

			$type = 'gate' === ( $row['type'] ?? 'score' ) ? 'gate' : 'score';
			$nav  = trim( sanitize_text_field( (string) ( $row['nav'] ?? '' ) ) );

			$question = [
				'id'      => $id,
				'nav'     => '' !== $nav ? $nav : $title,
				'title'   => $title,
				'type'    => $type,
				'answers' => [],
			];

			foreach ( (array) ( $row['answers'] ?? [] ) as $answer ) {
				if ( ! is_array( $answer ) ) {
					continue;
				}

				$label = trim( sanitize_text_field( (string) ( $answer['label'] ?? '' ) ) );

				if ( '' === $label ) {
					continue;
				}

				$aid = sanitize_key( (string) ( $answer['id'] ?? '' ) );

				if ( '' === $aid ) {
					$aid = self::slugify( $label );
				}

				$clean = [
					'id'    => $aid,
					'label' => $label,
				];

				if ( 'gate' === $type ) {
					$eliminate = array_values(
						array_intersect(
							array_map( 'sanitize_key', (array) ( $answer['eliminate'] ?? [] ) ),
							$categories
						)
					);

					$clean['eliminate'] = $eliminate;
					$clean['note']      = sanitize_textarea_field( (string) ( $answer['note'] ?? '' ) );
				} else {
					$points = [];

					foreach ( $categories as $category ) {
						$points[ $category ] = max( 0, absint( $answer['points'][ $category ] ?? 0 ) );
					}

					$clean['points'] = $points;
				}

				$question['answers'][] = $clean;
			}

			// A question with no answers cannot be rendered or scored.
			if ( ! $question['answers'] ) {
				continue;
			}

			$out[] = $question;
		}

		return $out;
	}

	/**
	 * @param string[] $posted     Posted order.
	 * @param string[] $categories Valid slugs.
	 * @param string[] $fallback   Existing order.
	 */
	private static function sanitize_tiebreak( array $posted, array $categories, array $fallback ): array {
		$clean = array_values( array_unique( array_intersect( array_map( 'sanitize_key', $posted ), $categories ) ) );

		if ( ! $clean ) {
			return $fallback;
		}

		// Anything the form did not post still needs a defined position.
		foreach ( $categories as $category ) {
			if ( ! in_array( $category, $clean, true ) ) {
				$clean[] = $category;
			}
		}

		return $clean;
	}

	private static function slugify( string $text ): string {
		$slug = sanitize_title( $text );

		return '' !== $slug ? $slug : 'item-' . substr( md5( $text ), 0, 6 );
	}

	private static function purge_caches(): void {
		wp_cache_flush();

		if ( class_exists( '\FlyingPress\Purge' ) && method_exists( '\FlyingPress\Purge', 'purge_everything' ) ) {
			\FlyingPress\Purge::purge_everything();
		}
	}

	// -------------------------------------------------------------- Screen

	public static function screen(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$slug   = self::current_finder();
		$view   = self::current_view();
		$finder = Config::get( $slug );
		$ready  = ! empty( $finder['questions'] ) && ! empty( $finder['categories'] );
		?>
		<div class="dcwa">
			<?php self::render_sidebar( $slug, $view ); ?>

			<div class="dcwa__content">
				<?php if ( isset( $_GET['saved'] ) ) : ?>
					<div class="dcwa__saved"><?php esc_html_e( 'Changes saved. The finder is updated on the front end.', 'dcw-guide-tools' ); ?></div>
				<?php endif; ?>

				<div class="dcwa__crumb">
					<span class="dcwa__crumb-mark" aria-hidden="true"></span>
					<span class="dcwa__crumb-sep" aria-hidden="true">&rsaquo;</span>
					<span><?php echo esc_html( (string) ( $finder['label'] ?? $slug ) ); ?></span>

					<button
						type="button"
						class="dcwa__help"
						data-dcwa-help
						aria-expanded="false"
						aria-controls="dcwa-help-panel"
					><span aria-hidden="true">?</span><span class="screen-reader-text"><?php esc_html_e( 'How this screen works', 'dcw-guide-tools' ); ?></span></button>
				</div>

				<div class="dcwa__help-panel" id="dcwa-help-panel" data-dcwa-help-panel hidden>
					<p class="dcwa__help-title"><?php esc_html_e( 'How this screen works', 'dcw-guide-tools' ); ?></p>
					<ul>
						<li><?php esc_html_e( 'Each answer adds points to the systems it suits. The highest total wins, and the runner-up shows as "Also consider".', 'dcw-guide-tools' ); ?></li>
						<li><?php esc_html_e( 'The water-line question filters instead of scoring: ticking a system rules it out entirely, whatever it scored.', 'dcw-guide-tools' ); ?></li>
						<li><?php esc_html_e( 'Tie-break order decides equal totals. The system listed first wins the tie.', 'dcw-guide-tools' ); ?></li>
						<li><?php esc_html_e( 'Test drive scores what is on screen right now, including edits you have not saved. Nothing reaches the site until you press Save.', 'dcw-guide-tools' ); ?></li>
					</ul>
				</div>

				<h1 class="dcwa__title">
					<?php
					printf(
						/* translators: %s: product line, e.g. Coffee */
						esc_html__( '%s Finder', 'dcw-guide-tools' ),
						esc_html( (string) ( $finder['label'] ?? $slug ) )
					);
					?>
				</h1>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dcwa__form" data-dcwa-form>
					<?php wp_nonce_field( self::NONCE ); ?>
					<input type="hidden" name="action" value="dcw_guide_tools_save">
					<input type="hidden" name="finder" value="<?php echo esc_attr( $slug ); ?>">
					<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">

					<div class="dcwa__actions">
						<button type="submit" class="dcwa__save"><?php esc_html_e( 'Save', 'dcw-guide-tools' ); ?></button>

						<?php if ( 'questions' === $view && $ready ) : ?>
							<?php self::render_tiebreak( $finder ); ?>
						<?php endif; ?>
					</div>

					<?php if ( ! $ready && 'questions' === $view ) : ?>
						<div class="dcwa__empty">
							<strong><?php esc_html_e( 'Nothing to edit yet.', 'dcw-guide-tools' ); ?></strong>
							<?php esc_html_e( 'This product line has no categories or questions defined, so it renders nothing on the front end. Categories come from the equipment taxonomy and are added in code.', 'dcw-guide-tools' ); ?>
						</div>
					<?php elseif ( 'questions' === $view ) : ?>
						<div class="dcwa__body">
							<div class="dcwa__main">
								<?php self::render_questions( $finder ); ?>
								<?php self::render_result_copy( $finder ); ?>
							</div>
							<?php self::render_rail( $slug, $finder ); ?>
						</div>
					<?php else : ?>
						<?php self::render_settings( $finder ); ?>
					<?php endif; ?>
				</form>
			</div>
		</div>
		<?php
	}

	private static function render_sidebar( string $slug, string $view ): void {
		$lines = Config::all();
		?>
		<nav class="dcwa__nav" aria-label="<?php esc_attr_e( 'Finder sections', 'dcw-guide-tools' ); ?>">
			<a class="dcwa__nav-item <?php echo 'questions' === $view ? 'is-current' : ''; ?>" href="<?php echo esc_url( self::url( 'questions', $slug ) ); ?>">
				<span class="dcwa__nav-dot" aria-hidden="true"></span>
				<?php esc_html_e( 'Questions', 'dcw-guide-tools' ); ?>
			</a>
			<a class="dcwa__nav-item <?php echo 'settings' === $view ? 'is-current' : ''; ?>" href="<?php echo esc_url( self::url( 'settings', $slug ) ); ?>">
				<span class="dcwa__nav-dot" aria-hidden="true"></span>
				<?php esc_html_e( 'Settings', 'dcw-guide-tools' ); ?>
			</a>

			<?php if ( count( $lines ) > 1 ) : ?>
				<p class="dcwa__nav-label"><?php esc_html_e( 'Product line', 'dcw-guide-tools' ); ?></p>

				<?php foreach ( $lines as $key => $line ) : ?>
					<?php $has = ! empty( $line['questions'] ) && ! empty( $line['categories'] ); ?>
					<a class="dcwa__nav-item <?php echo $key === $slug ? 'is-current' : ''; ?>" href="<?php echo esc_url( self::url( $view, (string) $key ) ); ?>">
						<span class="dcwa__nav-dot" aria-hidden="true"></span>
						<?php echo esc_html( (string) ( $line['label'] ?? $key ) ); ?>
						<?php if ( ! $has ) : ?>
							<span class="dcwa__nav-note"><?php esc_html_e( 'empty', 'dcw-guide-tools' ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			<?php endif; ?>
		</nav>
		<?php
	}

	/**
	 * The tie-break control.
	 *
	 * The order lives in the DOM order of the rows, and each row carries its own
	 * hidden input — so reordering the list reorders what posts, with no separate
	 * index to keep in step. The chip's dots mirror the list and are rebuilt by
	 * the script whenever a row moves.
	 */
	private static function render_tiebreak( array $finder ): void {
		$order      = (array) ( $finder['tiebreak'] ?? [] );
		$categories = (array) ( $finder['categories'] ?? [] );
		?>
		<div class="dcwa__tiebreak" data-dcwa-tiebreak>
			<button
				type="button"
				class="dcwa__chip"
				data-dcwa-tiebreak-toggle
				aria-expanded="false"
				aria-controls="dcwa-tiebreak-pop"
			>
				<span class="dcwa__chip-label"><?php esc_html_e( 'Tie-break order', 'dcw-guide-tools' ); ?></span>
				<span class="dcwa__chip-dots" data-dcwa-chip-dots>
					<?php foreach ( $order as $key ) : ?>
						<?php $category = $categories[ $key ] ?? null; ?>
						<?php if ( $category ) : ?>
							<span class="dcwa-dot" style="background:<?php echo esc_attr( (string) ( $category['color'] ?? '#999' ) ); ?>"></span>
						<?php endif; ?>
					<?php endforeach; ?>
				</span>
				<span class="dcwa__chip-caret" aria-hidden="true"><?php self::icon_chevron(); ?></span>
			</button>

			<div class="dcwa__pop" id="dcwa-tiebreak-pop" data-dcwa-tiebreak-pop hidden>
				<p class="dcwa__pop-title"><?php esc_html_e( 'Tie-break order', 'dcw-guide-tools' ); ?></p>
				<p class="dcwa__pop-hint"><?php esc_html_e( 'When two systems score the same, the one higher in this list wins.', 'dcw-guide-tools' ); ?></p>

				<ol class="dcwa__pop-list" data-dcwa-tiebreak-list>
					<?php foreach ( $order as $key ) : ?>
						<?php $category = $categories[ $key ] ?? null; ?>
						<?php if ( ! $category ) : ?>
							<?php continue; ?>
						<?php endif; ?>
						<?php $label = (string) ( $category['label'] ?? $key ); ?>
						<li class="dcwa__pop-row" data-dcwa-tiebreak-row>
							<input type="hidden" name="dcw[tiebreak][]" value="<?php echo esc_attr( (string) $key ); ?>">
							<span class="dcwa-dot" style="background:<?php echo esc_attr( (string) ( $category['color'] ?? '#999' ) ); ?>"></span>
							<span class="dcwa__pop-name"><?php echo esc_html( $label ); ?></span>
							<span class="dcwa__pop-moves">
								<button
									type="button"
									class="dcwa-icon dcwa-icon--sm dcwa__pop-up"
									data-dcwa-tiebreak-move="up"
									aria-label="<?php echo esc_attr( sprintf( /* translators: %s: system name */ __( 'Move %s up', 'dcw-guide-tools' ), $label ) ); ?>"
								><?php self::icon_chevron(); ?></button>
								<button
									type="button"
									class="dcwa-icon dcwa-icon--sm"
									data-dcwa-tiebreak-move="down"
									aria-label="<?php echo esc_attr( sprintf( /* translators: %s: system name */ __( 'Move %s down', 'dcw-guide-tools' ), $label ) ); ?>"
								><?php self::icon_chevron(); ?></button>
							</span>
						</li>
					<?php endforeach; ?>
				</ol>

				<p class="dcwa__pop-foot"><?php esc_html_e( 'Takes effect on the front end once you press Save.', 'dcw-guide-tools' ); ?></p>
			</div>
		</div>
		<?php
	}

	private static function render_questions( array $finder ): void {
		$categories = (array) $finder['categories'];
		$questions  = (array) $finder['questions'];
		?>
		<div class="dcwa__questions" data-dcwa-questions>
			<?php foreach ( $questions as $index => $question ) : ?>
				<?php self::render_question_card( (int) $index, (array) $question, $categories, 0 === (int) $index ); ?>
			<?php endforeach; ?>
		</div>

		<button type="button" class="dcwa__add" data-dcwa-add-question>
			<span aria-hidden="true">+</span> <?php esc_html_e( 'Add question', 'dcw-guide-tools' ); ?>
		</button>

		<?php self::render_templates( $categories ); ?>
		<?php
	}

	private static function render_question_card( int $index, array $question, array $categories, bool $open ): void {
		$type    = (string) ( $question['type'] ?? 'score' );
		$is_gate = 'gate' === $type;
		$name    = 'dcw[questions][' . $index . ']';
		?>
		<section class="dcwa-card <?php echo $open ? 'is-open' : ''; ?>" data-dcwa-card>
			<header class="dcwa-card__head">
				<span class="dcwa-card__num" data-dcwa-num><?php echo esc_html( (string) ( $index + 1 ) ); ?></span>

				<?php
				/*
				 * `readonly` while the card is closed, never `disabled`: a
				 * disabled control is not submitted, so collapsing a question
				 * and hitting Save would silently wipe its title. Read-only
				 * fields post their value exactly as normal ones do.
				 */
				?>
				<input
					type="text"
					class="dcwa-card__title"
					name="<?php echo esc_attr( $name ); ?>[title]"
					value="<?php echo esc_attr( (string) ( $question['title'] ?? '' ) ); ?>"
					aria-label="<?php esc_attr_e( 'Question', 'dcw-guide-tools' ); ?>"
					<?php echo $open ? '' : 'readonly tabindex="-1"'; ?>
				>

				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[id]" value="<?php echo esc_attr( (string) ( $question['id'] ?? '' ) ); ?>">
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[type]" value="<?php echo esc_attr( $type ); ?>">
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[nav]" value="<?php echo esc_attr( (string) ( $question['nav'] ?? '' ) ); ?>" data-dcwa-nav>

				<button type="button" class="dcwa-icon dcwa-icon--danger" data-dcwa-delete-question aria-label="<?php esc_attr_e( 'Delete question', 'dcw-guide-tools' ); ?>">
					<?php self::icon_trash(); ?>
				</button>

				<button type="button" class="dcwa-icon" data-dcwa-toggle aria-expanded="<?php echo $open ? 'true' : 'false'; ?>" aria-label="<?php esc_attr_e( 'Expand or collapse question', 'dcw-guide-tools' ); ?>">
					<?php self::icon_chevron(); ?>
				</button>
			</header>

			<div class="dcwa-card__body">
				<?php if ( $is_gate ) : ?>
					<p class="dcwa-card__note"><?php esc_html_e( 'This question filters the options rather than scoring them. Tick the systems each answer rules out.', 'dcw-guide-tools' ); ?></p>
				<?php endif; ?>

				<div class="dcwa-matrix <?php echo $is_gate ? 'dcwa-matrix--gate' : ''; ?>">
					<div class="dcwa-matrix__head">
						<span class="dcwa-matrix__lead"><?php esc_html_e( 'Answer', 'dcw-guide-tools' ); ?></span>
						<?php foreach ( $categories as $key => $category ) : ?>
							<?php
							/*
							 * The head shows the short name so four columns fit
							 * beside the answer text; `data-dcwa-label` carries
							 * the full one, because the test-drive rail reads
							 * these columns to label its score bars and should
							 * still say "Traditional Brewers" there.
							 */
							?>
							<span
								class="dcwa-matrix__col"
								data-dcwa-cat="<?php echo esc_attr( (string) $key ); ?>"
								data-dcwa-label="<?php echo esc_attr( (string) ( $category['label'] ?? $key ) ); ?>"
								title="<?php echo esc_attr( (string) ( $category['label'] ?? $key ) ); ?>"
							>
								<span class="dcwa-dot" style="background:<?php echo esc_attr( (string) ( $category['color'] ?? '#999' ) ); ?>"></span>
								<?php echo esc_html( (string) ( $category['short'] ?? $category['label'] ?? $key ) ); ?>
							</span>
						<?php endforeach; ?>
						<span class="dcwa-matrix__end"></span>
					</div>

					<div class="dcwa-matrix__rows" data-dcwa-rows>
						<?php foreach ( (array) ( $question['answers'] ?? [] ) as $ai => $answer ) : ?>
							<?php self::render_answer_row( $name, (int) $ai, (array) $answer, $categories, $is_gate ); ?>
						<?php endforeach; ?>
					</div>
				</div>

				<button type="button" class="dcwa__add dcwa__add--sm" data-dcwa-add-answer>
					<span aria-hidden="true">+</span> <?php esc_html_e( 'Add answer', 'dcw-guide-tools' ); ?>
				</button>
			</div>
		</section>
		<?php
	}

	private static function render_answer_row( string $prefix, int $ai, array $answer, array $categories, bool $is_gate ): void {
		$name   = $prefix . '[answers][' . $ai . ']';
		$points = (array) ( $answer['points'] ?? [] );
		$best   = $points ? max( $points ) : 0;
		?>
		<div class="dcwa-row" data-dcwa-row>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[id]" value="<?php echo esc_attr( (string) ( $answer['id'] ?? '' ) ); ?>">

			<input
				type="text"
				class="dcwa-row__label"
				name="<?php echo esc_attr( $name ); ?>[label]"
				value="<?php echo esc_attr( (string) ( $answer['label'] ?? '' ) ); ?>"
				aria-label="<?php esc_attr_e( 'Answer', 'dcw-guide-tools' ); ?>"
			>

			<?php foreach ( $categories as $key => $category ) : ?>
				<?php if ( $is_gate ) : ?>
					<label class="dcwa-row__gate">
						<input
							type="checkbox"
							name="<?php echo esc_attr( $name ); ?>[eliminate][]"
							value="<?php echo esc_attr( (string) $key ); ?>"
							<?php checked( in_array( $key, (array) ( $answer['eliminate'] ?? [] ), true ) ); ?>
						>
						<span class="dcwa-row__gate-box" aria-hidden="true"></span>
						<span class="screen-reader-text">
							<?php
							printf(
								/* translators: %s: category name */
								esc_html__( 'Rules out %s', 'dcw-guide-tools' ),
								esc_html( (string) ( $category['label'] ?? $key ) )
							);
							?>
						</span>
					</label>
				<?php else : ?>
					<?php $value = (int) ( $points[ $key ] ?? 0 ); ?>
					<input
						type="number"
						min="0"
						max="99"
						class="dcwa-row__points <?php echo ( $value > 0 && $value === $best ) ? 'is-best' : ''; ?> <?php echo 0 === $value ? 'is-zero' : ''; ?>"
						name="<?php echo esc_attr( $name ); ?>[points][<?php echo esc_attr( (string) $key ); ?>]"
						value="<?php echo esc_attr( (string) $value ); ?>"
						data-dcwa-points
						aria-label="<?php echo esc_attr( sprintf( '%s points', (string) ( $category['label'] ?? $key ) ) ); ?>"
					>
				<?php endif; ?>
			<?php endforeach; ?>

			<button type="button" class="dcwa-icon dcwa-icon--sm" data-dcwa-delete-row aria-label="<?php esc_attr_e( 'Delete answer', 'dcw-guide-tools' ); ?>">
				<?php self::icon_trash(); ?>
			</button>
		</div>

		<?php if ( $is_gate ) : ?>
			<div class="dcwa-row dcwa-row--note">
				<input
					type="text"
					class="dcwa-row__note"
					name="<?php echo esc_attr( $name ); ?>[note]"
					value="<?php echo esc_attr( (string) ( $answer['note'] ?? '' ) ); ?>"
					placeholder="<?php esc_attr_e( 'Optional note shown on the result…', 'dcw-guide-tools' ); ?>"
					aria-label="<?php esc_attr_e( 'Note shown on the result', 'dcw-guide-tools' ); ?>"
				>
			</div>
		<?php endif; ?>
		<?php
	}

	private static function render_result_copy( array $finder ): void {
		?>
		<section class="dcwa-card" data-dcwa-card>
			<header class="dcwa-card__head">
				<span class="dcwa-card__num dcwa-card__num--icon" aria-hidden="true"><?php self::icon_pencil(); ?></span>
				<span class="dcwa-card__static"><?php esc_html_e( 'Result copy', 'dcw-guide-tools' ); ?></span>
				<button type="button" class="dcwa-icon" data-dcwa-toggle aria-expanded="false" aria-label="<?php esc_attr_e( 'Expand or collapse result copy', 'dcw-guide-tools' ); ?>">
					<?php self::icon_chevron(); ?>
				</button>
			</header>

			<div class="dcwa-card__body">
				<?php foreach ( (array) $finder['categories'] as $key => $category ) : ?>
					<div class="dcwa-copy">
						<div class="dcwa-copy__head">
							<span class="dcwa-dot" style="background:<?php echo esc_attr( (string) ( $category['color'] ?? '#999' ) ); ?>"></span>
							<input
								type="text"
								class="dcwa-copy__label"
								name="dcw[categories][<?php echo esc_attr( (string) $key ); ?>][label]"
								value="<?php echo esc_attr( (string) ( $category['label'] ?? '' ) ); ?>"
								aria-label="<?php esc_attr_e( 'Category name', 'dcw-guide-tools' ); ?>"
							>
						</div>

						<label class="dcwa-copy__field">
							<span><?php esc_html_e( 'Shown when this wins', 'dcw-guide-tools' ); ?></span>
							<textarea name="dcw[categories][<?php echo esc_attr( (string) $key ); ?>][result]" rows="3"><?php echo esc_textarea( (string) ( $category['result'] ?? '' ) ); ?></textarea>
						</label>

						<label class="dcwa-copy__field">
							<span><?php esc_html_e( 'Shown as “Also consider”', 'dcw-guide-tools' ); ?></span>
							<textarea name="dcw[categories][<?php echo esc_attr( (string) $key ); ?>][consider]" rows="2"><?php echo esc_textarea( (string) ( $category['consider'] ?? '' ) ); ?></textarea>
						</label>
					</div>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * The test-drive rail. Reads the live form values, so it scores whatever is
	 * on screen right now — including unsaved edits.
	 */
	private static function render_rail( string $slug, array $finder ): void {
		?>
		<aside class="dcwa__rail">
			<div class="dcwa-panel">
				<div class="dcwa-panel__head">
					<h2><?php esc_html_e( 'Test drive', 'dcw-guide-tools' ); ?></h2>
					<button type="button" class="dcwa-panel__reset" data-dcwa-reset><?php esc_html_e( 'Reset', 'dcw-guide-tools' ); ?></button>
				</div>
				<p class="dcwa-panel__sub"><?php esc_html_e( 'Scores the questions as they stand on this screen, including edits you have not saved.', 'dcw-guide-tools' ); ?></p>
				<div class="dcwa-panel__fields" data-dcwa-test></div>
			</div>

			<div class="dcwa-panel" data-dcwa-outcome hidden>
				<div class="dcwa-result">
					<span class="dcwa-dot" data-dcwa-outcome-dot></span>
					<div>
						<p class="dcwa-result__kicker"><?php esc_html_e( 'Best match', 'dcw-guide-tools' ); ?></p>
						<p class="dcwa-result__name" data-dcwa-outcome-name></p>
					</div>
					<span class="dcwa-result__pts" data-dcwa-outcome-pts></span>
				</div>
				<div class="dcwa-bars" data-dcwa-bars></div>
				<p class="dcwa-panel__note" data-dcwa-outcome-note></p>
			</div>

			<div class="dcwa-panel">
				<p class="dcwa-panel__label"><?php esc_html_e( 'Where this renders', 'dcw-guide-tools' ); ?></p>
				<p class="dcwa-panel__text"><?php esc_html_e( 'The DCW Finder element in Bricks, or the shortcode below.', 'dcw-guide-tools' ); ?></p>
				<code class="dcwa-code">[dcw_finder finder="<?php echo esc_attr( $slug ); ?>"]</code>
			</div>
		</aside>
		<?php
	}

	private static function render_settings( array $finder ): void {
		?>
		<div class="dcwa__settings">
			<div class="dcwa-panel">
				<label class="dcwa-copy__field">
					<span><?php esc_html_e( 'Report form', 'dcw-guide-tools' ); ?></span>
					<select name="dcw[form_id]">
						<option value="0"><?php esc_html_e( '— None —', 'dcw-guide-tools' ); ?></option>
						<?php foreach ( self::fluent_forms() as $id => $title ) : ?>
							<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( (int) ( $finder['form_id'] ?? 0 ), (int) $id ); ?>>
								<?php echo esc_html( $title . ' (#' . $id . ')' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<p class="dcwa-panel__text"><?php esc_html_e( 'Shown inside the result panel. Quiz answers ride along as hidden fields.', 'dcw-guide-tools' ); ?></p>
			</div>

			<div class="dcwa-panel">
				<label class="dcwa-copy__field">
					<span><?php esc_html_e( 'Explore link', 'dcw-guide-tools' ); ?></span>
					<input type="text" name="dcw[explore_url]" value="<?php echo esc_attr( (string) ( $finder['explore_url'] ?? '' ) ); ?>">
				</label>
				<label class="dcwa-copy__field">
					<span><?php esc_html_e( 'Compare link', 'dcw-guide-tools' ); ?></span>
					<input type="text" name="dcw[compare_url]" value="<?php echo esc_attr( (string) ( $finder['compare_url'] ?? '' ) ); ?>">
				</label>
				<label class="dcwa-copy__field">
					<span><?php esc_html_e( 'Book a call link', 'dcw-guide-tools' ); ?></span>
					<input type="text" name="dcw[contact_url]" value="<?php echo esc_attr( (string) ( $finder['contact_url'] ?? '' ) ); ?>">
				</label>
				<p class="dcwa-panel__text"><?php esc_html_e( 'Relative URLs or in-page anchors. Never paste the staging hostname — it changes at launch.', 'dcw-guide-tools' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Blank rows cloned by JS when adding a question or an answer.
	 */
	private static function render_templates( array $categories ): void {
		?>
		<template data-dcwa-template="question">
			<?php
			self::render_question_card(
				9999,
				[
					'id'      => '',
					'title'   => '',
					'nav'     => '',
					'type'    => 'score',
					'answers' => [ [ 'id' => '', 'label' => '', 'points' => [] ] ],
				],
				$categories,
				true
			);
			?>
		</template>

		<template data-dcwa-template="answer">
			<?php self::render_answer_row( 'dcw[questions][9999]', 9999, [ 'id' => '', 'label' => '', 'points' => [] ], $categories, false ); ?>
		</template>

		<template data-dcwa-template="answer-gate">
			<?php self::render_answer_row( 'dcw[questions][9999]', 9999, [ 'id' => '', 'label' => '', 'eliminate' => [], 'note' => '' ], $categories, true ); ?>
		</template>
		<?php
	}

	/**
	 * @return array<int, string>
	 */
	private static function fluent_forms(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'fluentform_forms';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
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

	private static function icon_trash(): void {
		echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 10v7M14 10v7"/></svg>';
	}

	private static function icon_chevron(): void {
		echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
	}

	private static function icon_pencil(): void {
		echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4z"/></svg>';
	}
}
