<?php
/**
 * Scoring engine.
 *
 * Pure logic: takes a finder definition plus a set of answers and returns a
 * result. No WordPress calls, no output — so it can be reasoned about (and
 * later unit tested) on its own.
 *
 * The same algorithm runs twice: here in PHP (authoritative, used for the
 * no-JS fallback and for anything server-side) and in assets/finder.js for the
 * instant client-side result. Both read the same config, so they agree.
 *
 * @package DCW\GuideTools
 */

namespace DCW\GuideTools;

defined( 'ABSPATH' ) || exit;

class Scorer {

	/**
	 * Score a set of answers against a finder.
	 *
	 * @param array                 $finder  Finder definition from Config.
	 * @param array<string, string> $answers Map of question id => answer id.
	 *
	 * @return array{
	 *     complete: bool,
	 *     scores: array<string, int>,
	 *     eliminated: string[],
	 *     notes: string[],
	 *     best: ?string,
	 *     also: ?string,
	 *     ranked: string[]
	 * }
	 */
	public static function score( array $finder, array $answers ): array {
		$categories = array_keys( $finder['categories'] ?? [] );
		$questions  = $finder['questions'] ?? [];

		$scores     = array_fill_keys( $categories, 0 );
		$eliminated = [];
		$notes      = [];
		$answered   = 0;

		foreach ( $questions as $question ) {
			$qid = $question['id'] ?? '';

			if ( '' === $qid || ! isset( $answers[ $qid ] ) ) {
				continue;
			}

			$answer = self::find_answer( $question, (string) $answers[ $qid ] );

			if ( null === $answer ) {
				continue;
			}

			++$answered;

			// Scored questions add points. Gate questions remove candidates.
			foreach ( (array) ( $answer['points'] ?? [] ) as $category => $points ) {
				if ( isset( $scores[ $category ] ) ) {
					$scores[ $category ] += (int) $points;
				}
			}

			foreach ( (array) ( $answer['eliminate'] ?? [] ) as $category ) {
				if ( isset( $scores[ $category ] ) ) {
					$eliminated[ $category ] = true;
				}
			}

			if ( ! empty( $answer['note'] ) ) {
				$notes[] = (string) $answer['note'];
			}
		}

		$complete   = $answered === count( $questions ) && $questions;
		$eliminated = array_keys( $eliminated );

		$ranked = self::rank( $scores, $finder['tiebreak'] ?? [] );

		// Survivors are the ranked list minus anything the gate removed. If the
		// gate somehow removed everything, fall back to the full ranking rather
		// than showing the user nothing — a recommendation with a caveat beats
		// a dead end.
		$survivors = array_values( array_diff( $ranked, $eliminated ) );

		if ( ! $survivors ) {
			$survivors = $ranked;
		}

		$best = $survivors[0] ?? null;
		$also = null;

		if ( $best && isset( $survivors[1] ) ) {
			$window    = (int) ( $finder['also_consider_window'] ?? 2 );
			$runner_up = $survivors[1];
			$gap       = ( $scores[ $best ] ?? 0 ) - ( $scores[ $runner_up ] ?? 0 );

			// Shown only when it is genuinely close and actually scored. A
			// zero-point runner-up is not a real second choice.
			if ( $gap <= $window && ( $scores[ $runner_up ] ?? 0 ) > 0 ) {
				$also = $runner_up;
			}
		}

		return [
			'complete'   => (bool) $complete,
			'scores'     => $scores,
			'eliminated' => $eliminated,
			'notes'      => $notes,
			'best'       => $best,
			'also'       => $also,
			'ranked'     => $ranked,
		];
	}

	/**
	 * Rank categories by score, breaking ties with the finder's declared order.
	 *
	 * @param array<string, int> $scores   Category => points.
	 * @param string[]           $tiebreak Category slugs, most-preferred first.
	 *
	 * @return string[] Category slugs, best first.
	 */
	private static function rank( array $scores, array $tiebreak ): array {
		$order = array_flip( $tiebreak );
		$keys  = array_keys( $scores );

		usort(
			$keys,
			static function ( $a, $b ) use ( $scores, $order ) {
				if ( $scores[ $a ] !== $scores[ $b ] ) {
					return $scores[ $b ] <=> $scores[ $a ];
				}

				$pa = $order[ $a ] ?? PHP_INT_MAX;
				$pb = $order[ $b ] ?? PHP_INT_MAX;

				return $pa <=> $pb;
			}
		);

		return $keys;
	}

	/**
	 * Find an answer definition inside a question.
	 */
	private static function find_answer( array $question, string $answer_id ): ?array {
		foreach ( (array) ( $question['answers'] ?? [] ) as $answer ) {
			if ( ( $answer['id'] ?? null ) === $answer_id ) {
				return $answer;
			}
		}

		return null;
	}

	/**
	 * Equipment posts assigned to a category, for the "Equipment to explore" chips.
	 *
	 * @param array  $finder   Finder definition.
	 * @param string $category Category slug.
	 *
	 * @return array<int, array{id:int, title:string, url:string}>
	 */
	public static function equipment_for( array $finder, string $category ): array {
		$term = $finder['categories'][ $category ]['term'] ?? '';

		if ( '' === $term || ! taxonomy_exists( $finder['taxonomy'] ?? '' ) ) {
			return [];
		}

		$posts = get_posts(
			[
				'post_type'        => 'equipment',
				'post_status'      => 'publish',
				'numberposts'      => 12,
				'orderby'          => 'menu_order title',
				'order'            => 'ASC',
				'suppress_filters' => false,
				'tax_query'        => [
					[
						'taxonomy' => $finder['taxonomy'],
						'field'    => 'slug',
						'terms'    => $term,
					],
				],
			]
		);

		return array_map(
			static fn( $post ) => [
				'id'    => (int) $post->ID,
				'title' => get_the_title( $post ),
				'url'   => (string) get_permalink( $post ),
			],
			$posts
		);
	}
}
