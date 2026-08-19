<?php
/**
 * Server-side rendering.
 *
 * Everything is in the HTML on first paint: all questions, and all result
 * panels for every category. JavaScript only reveals and animates. That keeps
 * the guide's content readable by search engines and LLMs without running the
 * quiz (see Notes/GEO AEO Strategy.md), and it means the finder degrades to a
 * plain, usable form when JS fails.
 *
 * @package DCW\GuideTools
 */

namespace DCW\GuideTools;

defined( 'ABSPATH' ) || exit;

class Renderer {

	/**
	 * Incremented per instance so ids stay unique if a page ever holds two finders.
	 */
	private static int $instance = 0;

	/**
	 * Render a finder.
	 *
	 * @param string $slug Finder slug, e.g. "coffee".
	 */
	public static function render( string $slug ): string {
		$finder = Config::get( $slug );

		if ( ! $finder || empty( $finder['questions'] ) || empty( $finder['categories'] ) ) {
			return self::render_unavailable( $slug, $finder );
		}

		++self::$instance;
		$uid = 'dcw-finder-' . self::$instance;

		Assets::enqueue();

		$config_json = wp_json_encode( self::client_config( $slug, $finder ) );

		ob_start();
		?>
		<div
			class="dcw-finder"
			id="<?php echo esc_attr( $uid ); ?>"
			data-dcw-finder
			data-finder="<?php echo esc_attr( $slug ); ?>"
			data-config="<?php echo esc_attr( (string) $config_json ); ?>"
		>
			<?php
			self::render_quiz( $uid, $finder );
			self::render_results( $slug, $finder );
			?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The quiz card: sliding question track plus the progress rail.
	 */
	private static function render_quiz( string $uid, array $finder ): void {
		$questions = $finder['questions'];
		$total     = count( $questions );
		?>
		<div class="dcw-finder__card">
			<div class="dcw-finder__stage">
				<div class="dcw-finder__track" data-dcw-track>
					<?php foreach ( $questions as $index => $question ) : ?>
						<?php self::render_question( $uid, $question, (int) $index, $total ); ?>
					<?php endforeach; ?>
				</div>
			</div>

			<?php self::render_progress( $uid, $finder, $questions ); ?>
		</div>

		<p class="dcw-finder__live" data-dcw-live aria-live="polite"></p>
		<?php
	}

	/**
	 * One question slide.
	 */
	private static function render_question( string $uid, array $question, int $index, int $total ): void {
		$qid    = (string) ( $question['id'] ?? '' );
		$name   = $uid . '-' . $qid;
		$is_gate = 'gate' === ( $question['type'] ?? 'score' );
		?>
		<section
			class="dcw-finder__slide"
			data-dcw-slide="<?php echo esc_attr( (string) $index ); ?>"
			data-question="<?php echo esc_attr( $qid ); ?>"
			aria-labelledby="<?php echo esc_attr( $name ); ?>-title"
		>
			<fieldset class="dcw-finder__panel">
				<legend class="dcw-finder__legend">
					<span class="dcw-finder__kicker">
						<?php
						printf(
							/* translators: 1: current question number, 2: total questions */
							esc_html__( 'Question %1$d of %2$d', 'dcw-guide-tools' ),
							(int) $index + 1,
							(int) $total
						);
						?>
					</span>
					<span class="dcw-finder__title" id="<?php echo esc_attr( $name ); ?>-title">
						<?php echo esc_html( (string) ( $question['title'] ?? '' ) ); ?>
					</span>
				</legend>

				<div class="dcw-finder__options">
					<?php foreach ( (array) ( $question['answers'] ?? [] ) as $answer ) : ?>
						<?php
						$aid      = (string) ( $answer['id'] ?? '' );
						$input_id = $name . '-' . $aid;
						?>
						<label class="dcw-finder__option" for="<?php echo esc_attr( $input_id ); ?>">
							<input
								class="dcw-finder__radio"
								type="radio"
								id="<?php echo esc_attr( $input_id ); ?>"
								name="<?php echo esc_attr( $name ); ?>"
								value="<?php echo esc_attr( $aid ); ?>"
								data-dcw-answer
								data-question="<?php echo esc_attr( $qid ); ?>"
							>
							<span class="dcw-finder__marker" aria-hidden="true"></span>
							<span class="dcw-finder__option-label"><?php echo esc_html( (string) ( $answer['label'] ?? '' ) ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>

				<div class="dcw-finder__foot">
					<button type="button" class="dcw-finder__back" data-dcw-back <?php echo 0 === $index ? 'hidden' : ''; ?>>
						<span aria-hidden="true">&lsaquo;</span> <?php esc_html_e( 'Back', 'dcw-guide-tools' ); ?>
					</button>
					<p class="dcw-finder__hint">
						<?php
						echo $is_gate
							? esc_html__( 'This one filters the options rather than scoring them.', 'dcw-guide-tools' )
							: esc_html__( 'Answering moves you to the next question.', 'dcw-guide-tools' );
						?>
					</p>
				</div>
			</fieldset>
		</section>
		<?php
	}

	/**
	 * The progress rail. Each step is a real button so it can be clicked or
	 * tabbed to; JS enables the ones the visitor has actually reached.
	 */
	private static function render_progress( string $uid, array $finder, array $questions ): void {
		?>
		<nav class="dcw-finder__progress" aria-label="<?php esc_attr_e( 'Finder progress', 'dcw-guide-tools' ); ?>">
			<p class="dcw-finder__progress-kicker"><?php esc_html_e( 'Your progress', 'dcw-guide-tools' ); ?></p>

			<ol class="dcw-finder__steps">
				<?php foreach ( $questions as $index => $question ) : ?>
					<li class="dcw-finder__step" data-dcw-step="<?php echo esc_attr( (string) $index ); ?>">
						<button type="button" class="dcw-finder__step-btn" data-dcw-goto="<?php echo esc_attr( (string) $index ); ?>" disabled>
							<span class="dcw-finder__step-icon" aria-hidden="true"><?php echo esc_html( (string) ( (int) $index + 1 ) ); ?></span>
							<span class="dcw-finder__step-text">
								<span class="dcw-finder__step-label"><?php echo esc_html( (string) ( $question['nav'] ?? $question['title'] ?? '' ) ); ?></span>
								<span class="dcw-finder__step-answer" data-dcw-step-answer></span>
							</span>
						</button>
					</li>
				<?php endforeach; ?>

				<li class="dcw-finder__step dcw-finder__step--result" data-dcw-step-result>
					<button type="button" class="dcw-finder__step-btn" data-dcw-goto-result disabled>
						<span class="dcw-finder__step-icon dcw-finder__step-icon--result" aria-hidden="true">&#10003;</span>
						<span class="dcw-finder__step-text">
							<span class="dcw-finder__step-label"><?php esc_html_e( 'Your results', 'dcw-guide-tools' ); ?></span>
							<span class="dcw-finder__step-answer" data-dcw-result-status>
								<?php
								printf(
									/* translators: %d: number of questions */
									esc_html__( 'Unlocks after question %d', 'dcw-guide-tools' ),
									count( $questions )
								);
								?>
							</span>
						</span>
					</button>
				</li>
			</ol>
		</nav>
		<?php
	}

	/**
	 * Every result panel, one per category. All are in the DOM; JS reveals one.
	 */
	private static function render_results( string $slug, array $finder ): void {
		?>
		<div class="dcw-finder__results" data-dcw-results hidden>
			<?php foreach ( (array) $finder['categories'] as $key => $category ) : ?>
				<?php self::render_result_panel( $slug, $finder, (string) $key, (array) $category ); ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * One result panel. Mirrors "Result A" in Paper: explore-first on the left,
	 * the report form on the right.
	 */
	private static function render_result_panel( string $slug, array $finder, string $key, array $category ): void {
		$equipment = Scorer::equipment_for( $finder, $key );
		$form_id   = (int) ( $finder['form_id'] ?? 0 );
		?>
		<article
			class="dcw-result"
			data-dcw-result="<?php echo esc_attr( $key ); ?>"
			style="--dcw-result-color: <?php echo esc_attr( (string) ( $category['color'] ?? 'var(--dcw-coffee, #C25D02)' ) ); ?>"
			hidden
		>
			<div class="dcw-result__main">
				<div class="dcw-result__top">
					<p class="dcw-result__kicker"><?php esc_html_e( 'Your best match', 'dcw-guide-tools' ); ?></p>
					<h3 class="dcw-result__title"><?php echo esc_html( (string) ( $category['label'] ?? '' ) ); ?></h3>
					<p class="dcw-result__desc"><?php echo esc_html( (string) ( $category['result'] ?? '' ) ); ?></p>

					<p class="dcw-result__note" data-dcw-note hidden></p>

					<?php if ( $equipment ) : ?>
						<div class="dcw-result__equip">
							<span class="dcw-result__equip-label"><?php esc_html_e( 'Equipment to explore', 'dcw-guide-tools' ); ?></span>
							<?php foreach ( $equipment as $item ) : ?>
								<span class="dcw-result__chip">
									<span class="dcw-result__dot" aria-hidden="true"></span>
									<span class="dcw-result__chip-text"><?php echo esc_html( $item['title'] ); ?></span>
								</span>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<div class="dcw-result__actions">
						<a class="dcw-btn dcw-btn--primary dcw-result__explore" href="<?php echo esc_url( (string) ( $finder['explore_url'] ?? '#options' ) ); ?>">
							<?php
							printf(
								/* translators: %s: category name */
								esc_html__( 'Explore %s', 'dcw-guide-tools' ),
								esc_html( (string) ( $category['label'] ?? '' ) )
							);
							?>
						</a>
						<button type="button" class="dcw-result__restart" data-dcw-restart>
							<?php esc_html_e( 'Start over', 'dcw-guide-tools' ); ?> <span aria-hidden="true">&rsaquo;</span>
						</button>
					</div>
				</div>

				<div class="dcw-result__also" data-dcw-also hidden>
					<div class="dcw-result__also-row">
						<div class="dcw-result__also-head">
							<p class="dcw-result__kicker dcw-result__kicker--soft"><?php esc_html_e( 'Also consider', 'dcw-guide-tools' ); ?></p>
							<h4 class="dcw-result__also-title" data-dcw-also-title></h4>
						</div>
						<p class="dcw-result__also-desc" data-dcw-also-desc></p>
					</div>
					<a class="dcw-result__compare" href="<?php echo esc_url( (string) ( $finder['compare_url'] ?? '#compare' ) ); ?>" data-dcw-compare>
						<?php esc_html_e( 'Compare these two', 'dcw-guide-tools' ); ?> <span aria-hidden="true">&rsaquo;</span>
					</a>
				</div>
			</div>

			<div class="dcw-result__form">
				<h4 class="dcw-result__form-title"><?php esc_html_e( 'Get your full recommendation', 'dcw-guide-tools' ); ?></h4>
				<p class="dcw-result__form-sub"><?php esc_html_e( 'Your best match, the equipment behind it, and the questions to ask before you commit. We email it either way.', 'dcw-guide-tools' ); ?></p>

				<div class="dcw-result__form-body">
					<?php if ( $form_id && shortcode_exists( 'fluentform' ) ) : ?>
						<?php echo do_shortcode( '[fluentform id="' . $form_id . '"]' ); ?>
					<?php else : ?>
						<p class="dcw-result__form-missing">
							<?php esc_html_e( 'The report form has not been connected yet. Set the Fluent Forms form ID in Settings → DCW Guide Tools.', 'dcw-guide-tools' ); ?>
						</p>
					<?php endif; ?>
				</div>

				<a class="dcw-result__book" href="<?php echo esc_url( (string) ( $finder['contact_url'] ?? '/contact/' ) ); ?>">
					<?php esc_html_e( 'Also book a call', 'dcw-guide-tools' ); ?> <span aria-hidden="true">&rarr;</span>
				</a>

				<a class="dcw-result__more" href="<?php echo esc_url( (string) ( $finder['explore_url'] ?? '#options' ) ); ?>">
					<?php esc_html_e( 'Explore more options', 'dcw-guide-tools' ); ?> <span aria-hidden="true">&rarr;</span>
				</a>
			</div>
		</article>
		<?php
	}

	/**
	 * Shown when a finder is requested that has no content yet (water, ice).
	 * Editors see why; visitors see nothing.
	 */
	private static function render_unavailable( string $slug, ?array $finder ): string {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}

		$label = $finder['label'] ?? $slug;

		return sprintf(
			'<div class="dcw-finder__notice"><strong>%s</strong> %s</div>',
			esc_html__( 'DCW Finder:', 'dcw-guide-tools' ),
			esc_html(
				sprintf(
					/* translators: %s: finder label */
					__( 'the "%s" finder has no questions or categories yet, so nothing is rendered here. Only logged-in editors see this message.', 'dcw-guide-tools' ),
					$label
				)
			)
		);
	}

	/**
	 * The slice of config the browser needs: scoring, copy for the "also
	 * consider" block, and the labels used in the progress rail.
	 */
	private static function client_config( string $slug, array $finder ): array {
		$categories = [];

		foreach ( (array) $finder['categories'] as $key => $category ) {
			$categories[ $key ] = [
				'label'    => (string) ( $category['label'] ?? '' ),
				'consider' => (string) ( $category['consider'] ?? '' ),
			];
		}

		$questions = [];

		foreach ( (array) $finder['questions'] as $question ) {
			$answers = [];

			foreach ( (array) ( $question['answers'] ?? [] ) as $answer ) {
				$answers[] = [
					'id'        => (string) ( $answer['id'] ?? '' ),
					'label'     => (string) ( $answer['label'] ?? '' ),
					'points'    => (object) array_map( 'intval', (array) ( $answer['points'] ?? [] ) ),
					'eliminate' => array_values( (array) ( $answer['eliminate'] ?? [] ) ),
					'note'      => (string) ( $answer['note'] ?? '' ),
				];
			}

			$questions[] = [
				'id'      => (string) ( $question['id'] ?? '' ),
				'type'    => (string) ( $question['type'] ?? 'score' ),
				'nav'     => (string) ( $question['nav'] ?? '' ),
				'answers' => $answers,
			];
		}

		return [
			'slug'       => $slug,
			'window'     => (int) ( $finder['also_consider_window'] ?? 2 ),
			'tiebreak'   => array_values( (array) ( $finder['tiebreak'] ?? [] ) ),
			'compareUrl' => (string) ( $finder['compare_url'] ?? '#compare' ),
			'categories' => $categories,
			'questions'  => $questions,
		];
	}
}
