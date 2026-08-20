<?php
/**
 * Finder definitions.
 *
 * One "finder" per product line (coffee, water, ice). The engine is generic:
 * it knows about questions, answers, per-category points and a gate. All the
 * domain knowledge lives in the arrays below, so adding water or ice is a
 * content job, not a code job.
 *
 * Every finder is passed through the `dcw_guide_tools_finders` filter, so the
 * settings screen (or a future admin UI) can override any of it without
 * touching this file.
 *
 * @package DCW\GuideTools
 */

namespace DCW\GuideTools;

defined( 'ABSPATH' ) || exit;

class Config {

	/**
	 * Option key holding admin-side overrides, merged over the defaults below.
	 */
	public const OPTION_KEY = 'dcw_guide_tools_finders';

	/**
	 * Cached, filtered finder definitions.
	 *
	 * @var array<string, array>|null
	 */
	private static ?array $cache = null;

	/**
	 * Get every finder definition.
	 *
	 * @return array<string, array>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$defaults  = self::defaults();
		$overrides = get_option( self::OPTION_KEY, [] );

		if ( is_array( $overrides ) && $overrides ) {
			foreach ( $overrides as $key => $override ) {
				if ( isset( $defaults[ $key ] ) && is_array( $override ) ) {
					$defaults[ $key ] = array_replace_recursive( $defaults[ $key ], $override );
				}
			}
		}

		/**
		 * Filter the full set of finder definitions.
		 *
		 * @param array<string, array> $defaults Finder definitions keyed by slug.
		 */
		self::$cache = (array) apply_filters( 'dcw_guide_tools_finders', $defaults );

		return self::$cache;
	}

	/**
	 * Get one finder definition by slug.
	 *
	 * @param string $slug Finder slug, e.g. "coffee".
	 */
	public static function get( string $slug ): ?array {
		$all = self::all();

		return $all[ $slug ] ?? null;
	}

	/**
	 * Slugs of every finder that has at least one question defined.
	 *
	 * Water and ice ship as empty shells so the engine is provably
	 * multi-category, but they must not render a broken quiz.
	 *
	 * @return string[]
	 */
	public static function available(): array {
		$out = [];

		foreach ( self::all() as $slug => $finder ) {
			if ( ! empty( $finder['questions'] ) && ! empty( $finder['categories'] ) ) {
				$out[] = $slug;
			}
		}

		return $out;
	}

	/**
	 * Reset the in-request cache. Used after saving settings.
	 */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Built-in finder definitions.
	 *
	 * Coffee scoring comes from Notes/Coffee Finder Logic.md — a draft the team
	 * is expected to correct. Every number is editable, which is the point.
	 *
	 * @return array<string, array>
	 */
	private static function defaults(): array {
		return [
			'coffee' => [
				'label'                => __( 'Coffee', 'dcw-guide-tools' ),
				'taxonomy'             => 'equipment_category',
				'intro_kicker'         => __( 'Question %1$d of %2$d', 'dcw-guide-tools' ),
				'tiebreak'             => [ 'bean-to-cup', 'traditional', 'single-cup', 'liquid' ],
				'explore_url'          => '#options',
				'compare_url'          => '#compare',
				'contact_url'          => '/contact/',
				'form_id'              => 0,

				'categories' => [
					'bean-to-cup' => [
						'label'    => __( 'Bean-to-Cup', 'dcw-guide-tools' ),
						'term'     => 'bean-to-cup',
						'color'    => 'var(--dcw-coffee, #C25D02)',
						'tagline'  => __( 'Freshly ground coffee, one cup at a time.', 'dcw-guide-tools' ),
						'result'   => __( 'Based on your workplace needs, a bean-to-cup system may be your strongest fit. These systems grind whole beans fresh for each cup while offering individual drink selections and specialty beverage options.', 'dcw-guide-tools' ),
						'consider' => __( 'Fresh whole-bean coffee with greater drink customization, if quality matters more than raw speed.', 'dcw-guide-tools' ),
					],
					'traditional' => [
						'label'    => __( 'Traditional Brewers', 'dcw-guide-tools' ),
						'term'     => 'traditional',
						'color'    => 'var(--dcw-sys-traditional, #8C4A12)',
						'tagline'  => __( 'Familiar brewed coffee made for groups.', 'dcw-guide-tools' ),
						'result'   => __( 'Based on your workplace needs, a traditional brewer may be your strongest fit. These systems brew ground coffee into pots or thermal servers, making coffee readily available for several people at once.', 'dcw-guide-tools' ),
						'consider' => __( 'Simple, dependable batch brewing. Worth a look if predictable group demand matters more than individual choice.', 'dcw-guide-tools' ),
					],
					'single-cup'  => [
						'label'    => __( 'Single-Cup', 'dcw-guide-tools' ),
						'term'     => 'single-cup',
						'color'    => 'var(--dcw-sys-single, #E28B3E)',
						'tagline'  => __( 'More choices, one drink at a time.', 'dcw-guide-tools' ),
						'result'   => __( 'Based on your workplace needs, a single-cup system may be your strongest fit. These systems let employees select and prepare individual beverages using K-Cups or freshpacks.', 'dcw-guide-tools' ),
						'consider' => __( 'More choices, one drink at a time. Worth a look if individual variety ends up mattering more than freshness.', 'dcw-guide-tools' ),
					],
					'liquid'      => [
						'label'    => __( 'Liquid Coffee', 'dcw-guide-tools' ),
						'term'     => 'liquid',
						'color'    => 'var(--dcw-sys-liquid, #5A2E0E)',
						'tagline'  => __( 'Fast, consistent coffee for serious volume.', 'dcw-guide-tools' ),
						'result'   => __( 'Based on your workplace needs, a liquid coffee system may be your strongest fit. These systems use concentrated coffee to dispense drinks quickly and consistently at high volume.', 'dcw-guide-tools' ),
						'consider' => __( 'Fast service during periods of heavy demand. Worth a look if throughput is the real constraint.', 'dcw-guide-tools' ),
					],
				],

				'questions' => [
					[
						'id'      => 'team-size',
						'nav'     => __( 'Team size', 'dcw-guide-tools' ),
						'title'   => __( 'How many people are you serving?', 'dcw-guide-tools' ),
						'type'    => 'score',
						'answers' => [
							[
								'id'     => 'under-25',
								'label'  => __( 'Under 25 people', 'dcw-guide-tools' ),
								'points' => [ 'bean-to-cup' => 1, 'traditional' => 1, 'single-cup' => 3, 'liquid' => 0 ],
							],
							[
								'id'     => '25-75',
								'label'  => __( '25 to 75 people', 'dcw-guide-tools' ),
								'points' => [ 'bean-to-cup' => 2, 'traditional' => 2, 'single-cup' => 2, 'liquid' => 0 ],
							],
							[
								'id'     => '75-150',
								'label'  => __( '75 to 150 people', 'dcw-guide-tools' ),
								'points' => [ 'bean-to-cup' => 3, 'traditional' => 2, 'single-cup' => 1, 'liquid' => 1 ],
							],
							[
								'id'     => 'over-150',
								'label'  => __( 'More than 150 people', 'dcw-guide-tools' ),
								'points' => [ 'bean-to-cup' => 2, 'traditional' => 2, 'single-cup' => 0, 'liquid' => 3 ],
							],
						],
					],
					[
						'id'      => 'coffee-habits',
						'nav'     => __( 'Coffee habits', 'dcw-guide-tools' ),
						'title'   => __( 'How is coffee typically used?', 'dcw-guide-tools' ),
						'type'    => 'score',
						'answers' => [
							[
								'id'     => 'mornings',
								'label'  => __( 'Mostly mornings', 'dcw-guide-tools' ),
								'points' => [ 'bean-to-cup' => 1, 'traditional' => 2, 'single-cup' => 1, 'liquid' => 1 ],
							],
							[
								'id'     => 'rushes',
								'label'  => __( 'Heavy morning or break-time rushes', 'dcw-guide-tools' ),
								'points' => [ 'bean-to-cup' => 1, 'traditional' => 3, 'single-cup' => 0, 'liquid' => 3 ],
							],
							[
								'id'     => 'all-day',
								'label'  => __( 'High demand throughout the day', 'dcw-guide-tools' ),
								'points' => [ 'bean-to-cup' => 2, 'traditional' => 1, 'single-cup' => 1, 'liquid' => 3 ],
							],
						],
					],
					[
						'id'      => 'team-wants',
						'nav'     => __( 'What your team wants', 'dcw-guide-tools' ),
						'title'   => __( 'What does your team want?', 'dcw-guide-tools' ),
						'type'    => 'score',
						'answers' => [
							[
								'id'     => 'traditional-coffee',
								'label'  => __( 'Mostly traditional coffee', 'dcw-guide-tools' ),
								'points' => [ 'bean-to-cup' => 0, 'traditional' => 3, 'single-cup' => 1, 'liquid' => 1 ],
							],
							[
								'id'     => 'freshly-ground',
								'label'  => __( 'Freshly ground coffee', 'dcw-guide-tools' ),
								'points' => [ 'bean-to-cup' => 3, 'traditional' => 0, 'single-cup' => 0, 'liquid' => 0 ],
							],
							[
								'id'     => 'individual-choice',
								'label'  => __( 'Lots of individual choices', 'dcw-guide-tools' ),
								'points' => [ 'bean-to-cup' => 1, 'traditional' => 0, 'single-cup' => 3, 'liquid' => 0 ],
							],
							[
								'id'     => 'specialty',
								'label'  => __( 'Coffee and specialty drinks', 'dcw-guide-tools' ),
								'points' => [ 'bean-to-cup' => 2, 'traditional' => 0, 'single-cup' => 2, 'liquid' => 1 ],
							],
						],
					],
					[
						'id'      => 'matters-most',
						'nav'     => __( 'What matters most', 'dcw-guide-tools' ),
						'title'   => __( 'What matters most?', 'dcw-guide-tools' ),
						'type'    => 'score',
						'answers' => [
							[
								'id'     => 'speed',
								'label'  => __( 'Speed and volume', 'dcw-guide-tools' ),
								'points' => [ 'bean-to-cup' => 0, 'traditional' => 2, 'single-cup' => 0, 'liquid' => 3 ],
							],
							[
								'id'     => 'quality',
								'label'  => __( 'Fresh coffee quality', 'dcw-guide-tools' ),
								'points' => [ 'bean-to-cup' => 3, 'traditional' => 1, 'single-cup' => 0, 'liquid' => 0 ],
							],
							[
								'id'     => 'variety',
								'label'  => __( 'Variety and customization', 'dcw-guide-tools' ),
								'points' => [ 'bean-to-cup' => 2, 'traditional' => 0, 'single-cup' => 3, 'liquid' => 0 ],
							],
							[
								'id'     => 'simple',
								'label'  => __( 'Simple operation', 'dcw-guide-tools' ),
								'points' => [ 'bean-to-cup' => 1, 'traditional' => 3, 'single-cup' => 2, 'liquid' => 1 ],
							],
						],
					],
					[
						'id'      => 'water-line',
						'nav'     => __( 'Water line', 'dcw-guide-tools' ),
						'title'   => __( 'Is a water line available where the machine will go?', 'dcw-guide-tools' ),
						'type'    => 'gate',
						'answers' => [
							[
								'id'        => 'yes',
								'label'     => __( 'Yes, there is a water line', 'dcw-guide-tools' ),
								'eliminate' => [],
								'note'      => '',
							],
							[
								'id'        => 'no',
								'label'     => __( 'No water line available', 'dcw-guide-tools' ),
								'eliminate' => [ 'bean-to-cup', 'traditional', 'liquid' ],
								'note'      => __( 'Without a water line, a pour-over single-cup machine is usually the only workable option. The Flavia Creation 600 runs either way.', 'dcw-guide-tools' ),
							],
							[
								'id'        => 'unsure',
								'label'     => __( 'Not sure yet', 'dcw-guide-tools' ),
								'eliminate' => [],
								'note'      => __( 'Most systems need a water line. We can check your site before anything is ordered.', 'dcw-guide-tools' ),
							],
						],
					],
				],
			],

			// Shells. The engine treats these exactly like coffee the moment
			// they have categories and questions; nothing here is special-cased.
			'water' => [
				'label'                => __( 'Water', 'dcw-guide-tools' ),
				'taxonomy'             => 'equipment_category',
				'tiebreak'             => [],
				'explore_url'          => '#options',
				'compare_url'          => '#compare',
				'contact_url'          => '/contact/',
				'form_id'              => 0,
				'categories'           => [],
				'questions'            => [],
			],
			'ice'   => [
				'label'                => __( 'Ice', 'dcw-guide-tools' ),
				'taxonomy'             => 'equipment_category',
				'tiebreak'             => [],
				'explore_url'          => '#options',
				'compare_url'          => '#compare',
				'contact_url'          => '/contact/',
				'form_id'              => 0,
				'categories'           => [],
				'questions'            => [],
			],
		];
	}
}
