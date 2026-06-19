<?php
/**
 * Wbcom Family Kit — bundled registry (no network).
 *
 * `wporg_slug` is non-null ONLY for members genuinely installable from
 * wordpress.org; null members (premium / pre-release) render a learn-more
 * link instead of an install button. CONFIRM each wporg_slug against the
 * live wp.org listing before relying on one-click install for that member.
 *
 * @package Wbcom\Family
 */

namespace Wbcom\Family;

defined( 'ABSPATH' ) || exit;

/**
 * @return array{members:array<string,array>,outcomes:array<string,array>,third_party:array<int,array>}
 */
function registry(): array {
	return array(
		'members'     => array(
			'buddynext'       => array(
				'name'       => 'BuddyNext',
				'tagline'    => 'The community engine — profiles, activity feeds and spaces.',
				'icon'       => 'users',
				'category'   => 'engine',
				'slug_free'  => 'buddynext/buddynext.php',
				'slug_pro'   => 'buddynext-pro/buddynext-pro.php',
				'wporg_slug' => null,
				'learn_url'  => 'https://wbcomdesigns.com/downloads/buddynext/',
				'pro_url'    => 'https://wbcomdesigns.com/downloads/buddynext/',
				'is_engine'  => true,
			),
			'wb-gamification' => array(
				'name'       => 'Gamification',
				'tagline'    => 'Points, badges and levels that reward real engagement.',
				'icon'       => 'trophy',
				'category'   => 'engagement',
				'slug_free'  => 'wb-gamification/wb-gamification.php',
				'slug_pro'   => null,
				'wporg_slug' => null,
				'learn_url'  => 'https://wbcomdesigns.com/downloads/wb-gamification/',
				'pro_url'    => null,
				'is_engine'  => false,
			),
			'learnomy'        => array(
				'name'       => 'Learnomy',
				'tagline'    => 'Lessons, quizzes and certificates inside your community.',
				'icon'       => 'graduation-cap',
				'category'   => 'learning',
				'slug_free'  => 'learnomy/learnomy.php',
				'slug_pro'   => 'learnomy-pro/learnomy-pro.php',
				'wporg_slug' => null,
				'learn_url'  => 'https://wbcomdesigns.com/downloads/learnomy/',
				'pro_url'    => 'https://wbcomdesigns.com/downloads/learnomy/',
				'is_engine'  => false,
			),
			'wpmediaverse'    => array(
				'name'       => 'MediaVerse',
				'tagline'    => 'Direct messages and a media library for members.',
				'icon'       => 'image',
				'category'   => 'media',
				'slug_free'  => 'wpmediaverse/wpmediaverse.php',
				'slug_pro'   => 'wpmediaverse-pro/wpmediaverse-pro.php',
				'wporg_slug' => null,
				'learn_url'  => 'https://wbcomdesigns.com/downloads/wpmediaverse/',
				'pro_url'    => 'https://wbcomdesigns.com/downloads/wpmediaverse/',
				'is_engine'  => false,
			),
			'jetonomy'        => array(
				'name'       => 'Jetonomy',
				'tagline'    => 'Threaded discussions and forums for your members.',
				'icon'       => 'messages-square',
				'category'   => 'engagement',
				'slug_free'  => 'jetonomy/jetonomy.php',
				'slug_pro'   => 'jetonomy-pro/jetonomy-pro.php',
				'wporg_slug' => null,
				'learn_url'  => 'https://wbcomdesigns.com/downloads/jetonomy/',
				'pro_url'    => 'https://wbcomdesigns.com/downloads/jetonomy/',
				'is_engine'  => false,
			),
			'wp-career-board' => array(
				'name'       => 'Career Board',
				'tagline'    => 'A jobs board with applications inside the community.',
				'icon'       => 'briefcase',
				'category'   => 'careers',
				'slug_free'  => 'wp-career-board/wp-career-board.php',
				'slug_pro'   => 'wp-career-board-pro/wp-career-board-pro.php',
				'wporg_slug' => null,
				'learn_url'  => 'https://wbcomdesigns.com/downloads/wp-career-board/',
				'pro_url'    => 'https://wbcomdesigns.com/downloads/wp-career-board/',
				'is_engine'  => false,
			),
			'wb-listora'      => array(
				'name'       => 'Listora',
				'tagline'    => 'Member-submitted listings and directories.',
				'icon'       => 'list',
				'category'   => 'commerce',
				'slug_free'  => 'wb-listora/wb-listora.php',
				'slug_pro'   => 'wb-listora-pro/wb-listora-pro.php',
				'wporg_slug' => null,
				'learn_url'  => 'https://wbcomdesigns.com/downloads/wb-listora/',
				'pro_url'    => 'https://wbcomdesigns.com/downloads/wb-listora/',
				'is_engine'  => false,
			),
		),
		'outcomes'    => array(
			'reward_engagement' => array(
				'title'       => 'Reward engagement',
				'description' => 'Award points, badges and levels for posting, courses and milestones.',
				'requires'    => array( 'wb-gamification' ),
			),
			'build_community'   => array(
				'title'       => 'Build the community',
				'description' => 'Profiles, activity feeds and spaces — the foundation everything rewards.',
				'requires'    => array( 'buddynext' ),
			),
			'run_courses'       => array(
				'title'       => 'Run courses',
				'description' => 'Reward lessons completed and courses passed with badges and points.',
				'requires'    => array( 'learnomy' ),
			),
			'messaging_media'   => array(
				'title'       => 'Add messaging & media',
				'description' => 'Direct messages and a media library members can earn around.',
				'requires'    => array( 'wpmediaverse' ),
			),
			'discussions'       => array(
				'title'       => 'Add forums & discussions',
				'description' => 'Reward answers and participation in threaded discussions.',
				'requires'    => array( 'jetonomy' ),
			),
			'jobs_board'        => array(
				'title'       => 'Add a jobs board',
				'description' => 'Reward hiring milestones and applications with points.',
				'requires'    => array( 'wp-career-board' ),
			),
			'listings'          => array(
				'title'       => 'Add listings & a directory',
				'description' => 'Member-submitted listings and directories your community can browse.',
				'requires'    => array( 'wb-listora' ),
			),
		),
		'third_party' => array(
			array( 'name' => 'BuddyPress', 'note' => 'Activity and member events can feed rewards if you already run BuddyPress.' ),
			array( 'name' => 'LearnDash', 'note' => 'Course completions can trigger points.' ),
			array( 'name' => 'WooCommerce', 'note' => 'Purchases can award points.' ),
		),
	);
}
