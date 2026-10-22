<?php
/**
 * "Stories for you" (beta): short graded readers about what each learner loves (their interest-quiz picks),
 * written at their level following Krashen's i+1. Three formats: short stories, true-to-life explainers and
 * chapter series (one chapter a day). Readers are shared: one written for football at A2 is reused by every
 * A2 football fan, so the AI writes each one once. Beta testers can also get a personal reader a day, woven
 * around their own Learning words. Original characters only: never real people, never copied text.
 *
 * @package SawaLens
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sawa_Lens_Readers {

	const MAX_CHAPTERS = 6;
	const XP_READ      = 10; // finishing a reader
	const XP_CHECK     = 5;  // and getting the quick check right
	const CEFR         = array( 1 => 'A1', 2 => 'A2', 3 => 'B1', 4 => 'B2', 5 => 'C1' );
	const WORDS        = array( 1 => '150–250', 2 => '250–400', 3 => '400–600', 4 => '600–900', 5 => '700–1000' );
	// Broad topics for the "something different" pick (about 1 in 5), so it's not always the same topic.
	const FRESH = array( 'travel', 'food', 'animals', 'science', 'history', 'explainers', 'psychology', 'movies', 'family', 'tech' );

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function install( $c ) {
		dbDelta(
			'CREATE TABLE ' . self::t() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			kind varchar(8) NOT NULL DEFAULT 'pool',
			owner bigint(20) unsigned NOT NULL DEFAULT 0,
			cat varchar(40) NOT NULL DEFAULT '',
			level tinyint(3) unsigned NOT NULL DEFAULT 2,
			format varchar(12) NOT NULL DEFAULT 'story',
			series bigint(20) unsigned NOT NULL DEFAULT 0,
			chapter tinyint(3) unsigned NOT NULL DEFAULT 0,
			title varchar(160) NOT NULL DEFAULT '',
			body longtext,
			status varchar(10) NOT NULL DEFAULT 'ready',
			cost float NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY pool (kind,level,cat,format),
			KEY owner (owner),
			KEY series (series,chapter)
			) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::log_t() . " (
			user_id bigint(20) unsigned NOT NULL,
			reader_id bigint(20) unsigned NOT NULL,
			opened_at datetime NOT NULL,
			done_on date DEFAULT NULL,
			score tinyint(3) unsigned NOT NULL DEFAULT 0,
			total tinyint(3) unsigned NOT NULL DEFAULT 0,
			xp smallint(5) unsigned NOT NULL DEFAULT 0,
			fav tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (user_id,reader_id),
			KEY user_done (user_id,done_on)
			) $c;"
		);
	}

	private static function t() {
		return Sawa_Lens_Store::t( 'readers' );
	}

	private static function log_t() {
		return Sawa_Lens_Store::t( 'reader_log' );
	}

	/* ------------------------------------------------------------ settings & beta */

	/** Settings: beta testers (user IDs or emails, or "all"), monthly budget in USD, price per reader, personal readers on/off. */
	public static function settings() {
		$s = Sawa_Lens_AI::settings();
		return array(
			'beta'     => (string) ( $s['readers_beta'] ?? '' ),
			'budget'   => (float) ( $s['readers_budget'] ?? 10 ),
			'price'    => (float) ( $s['readers_price'] ?? 0.04 ),
			'personal' => ! empty( $s['readers_personal'] ),
		);
	}

	/** Is this learner in the beta? Admins always are. */
	public static function allowed( $uid ) {
		if ( ! $uid ) {
			return false;
		}
		if ( user_can( $uid, 'manage_options' ) ) {
			return true;
		}
		$list = array_filter( array_map( 'trim', preg_split( '/[\s,]+/', strtolower( self::settings()['beta'] ) ) ) );
		if ( in_array( 'all', $list, true ) ) {
			return true;
		}
		$u = get_userdata( $uid );
		return in_array( (string) $uid, $list, true ) || ( $u && in_array( strtolower( $u->user_email ), $list, true ) );
	}

	public static function spent_this_month() {
		global $wpdb;
		return (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(cost),0) FROM ' . self::t() . ' WHERE created_at >= %s', wp_date( 'Y-m-01 00:00:00' ) ) ); // phpcs:ignore
	}

	private static function budget_left() {
		$s = self::settings();
		return self::spent_this_month() + $s['price'] <= $s['budget'];
	}

	/* ------------------------------------------------------------ routes */

	public static function routes() {
		$can = function () {
			$uid = get_current_user_id();
			return $uid && Sawa_Lens_Auth::allowed( $uid ) && Sawa_Lens_App::is_open_for( $uid ) && self::allowed( $uid );
		};
		$r   = function ( $path, $method, $cb ) use ( $can ) {
			register_rest_route( Sawa_Lens_App::NS, '/app/' . $path, array( 'methods' => $method, 'callback' => $cb, 'permission_callback' => $can ) );
		};
		$r( 'readers', 'GET', array( __CLASS__, 'home' ) );
		$r( 'readers/next', 'POST', array( __CLASS__, 'next' ) );
		$r( 'readers/(?P<id>\d+)', 'GET', array( __CLASS__, 'one' ) );
		$r( 'readers/done', 'POST', array( __CLASS__, 'done' ) );
		$r( 'readers/fav', 'POST', array( __CLASS__, 'fav' ) );
		$r( 'readers/skip', 'POST', array( __CLASS__, 'skip' ) );
		$r( 'readers/hide', 'POST', array( __CLASS__, 'hide' ) );
	}

	private static function out( $data ) {
		$res = rest_ensure_response( $data );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	/* ------------------------------------------------------------ the learner */

	/** Reader level 1–5 (A1–C1) from the learner's level or placement test. */
	public static function level( $uid ) {
		$p = (array) get_user_meta( $uid, 'sawa_lens_placement', true );
		$l = Sawa_Lens_Store::level( $uid ) ?: ( (int) ( $p['levelNum'] ?? 0 ) ?: 2 );
		return max( 1, min( 5, $l ) );
	}

	/** Interest categories from data/interests.json, by id. */
	private static function cats() {
		static $cats = null;
		if ( null === $cats ) {
			$d    = json_decode( (string) file_get_contents( SAWA_LENS_DIR . 'data/interests.json' ), true ); // phpcs:ignore
			$cats = array();
			foreach ( (array) ( $d['categories'] ?? array() ) as $c ) {
				$cats[ $c['id'] ] = array( 'id' => $c['id'], 'name' => $c['name'], 'ar' => $c['ar'] ?? '', 'emoji' => $c['emoji'] ?? '' );
			}
		}
		return $cats;
	}

	/** The learner's quiz categories, minus the ones they said "not this topic" to in the last 14 days. */
	private static function my_cats( $uid ) {
		$p    = class_exists( 'Sawa_Lens_Interests' ) ? Sawa_Lens_Interests::picks( $uid ) : null;
		$all  = self::cats();
		$skip = (array) get_user_meta( $uid, 'sawa_lens_reader_skip', true );
		$out  = array();
		foreach ( (array) ( $p['cats'] ?? array() ) as $id ) {
			if ( isset( $all[ $id ] ) && ( empty( $skip[ $id ] ) || $skip[ $id ] < time() - 14 * DAY_IN_SECONDS ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	private static function learning( $uid, $n ) {
		global $wpdb;
		return (array) $wpdb->get_col( $wpdb->prepare( 'SELECT lemma FROM ' . Sawa_Lens_Store::t( 'words' ) . " WHERE user_id = %d AND status = 'learning' ORDER BY learning_on DESC, lemma LIMIT %d", $uid, $n ) ); // phpcs:ignore
	}

	/** Category read longest ago (never-read first); about 1 in 5 days, a fresh topic outside their picks. */
	private static function pick_cat( $uid ) {
		global $wpdb;
		$mine = self::my_cats( $uid );
		$seed = crc32( $uid . '|' . wp_date( 'Y-m-d' ) );
		if ( ! $mine || 0 === $seed % 5 ) {
			$fresh = array_values( array_diff( array_intersect( self::FRESH, array_keys( self::cats() ) ), $mine ) );
			if ( $fresh ) {
				return $fresh[ $seed % count( $fresh ) ];
			}
		}
		$last = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT r.cat, MAX(l.opened_at) AS t FROM ' . self::log_t() . ' l JOIN ' . self::t() . ' r ON r.id = l.reader_id WHERE l.user_id = %d GROUP BY r.cat', $uid ), ARRAY_A ); // phpcs:ignore
		$when = array();
		foreach ( $last as $x ) {
			$when[ $x['cat'] ] = $x['t'];
		}
		usort(
			$mine,
			function ( $a, $b ) use ( $when ) {
				return strcmp( $when[ $a ] ?? '', $when[ $b ] ?? '' );
			}
		);
		return $mine[0];
	}

	/** Mostly stories, some explainers, and now and then a new series (when none is going). */
	private static function pick_format( $uid ) {
		$n = crc32( 'f' . $uid . '|' . wp_date( 'Y-m-d' ) ) % 20;
		if ( $n < 3 && ! self::series_going( $uid ) ) {
			return 'series';
		}
		return $n < 8 ? 'explainer' : 'story';
	}

	/** The series the learner is following: [series id, chapters finished, finished today?, chapters written], or null. */
	private static function series_going( $uid ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT r.series, MAX(r.chapter) AS ch, MAX(l.done_on) AS last FROM ' . self::log_t() . ' l JOIN ' . self::t() . " r ON r.id = l.reader_id WHERE l.user_id = %d AND r.format = 'series' AND l.done_on IS NOT NULL GROUP BY r.series ORDER BY MAX(l.opened_at) DESC LIMIT 1", $uid ), ARRAY_A ); // phpcs:ignore
		if ( ! $row || (int) $row['ch'] >= self::MAX_CHAPTERS ) {
			return null;
		}
		return array( (int) $row['series'], (int) $row['ch'], $row['last'] === Sawa_Lens_Store::today() );
	}

	/** Days in a row with a finished reader (today, or up to yesterday if not read yet today). */
	public static function streak( $uid ) {
		global $wpdb;
		$days = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT done_on FROM ' . self::log_t() . ' WHERE user_id = %d AND done_on IS NOT NULL ORDER BY done_on DESC LIMIT 400', $uid ) ); // phpcs:ignore
		$set  = array_flip( $days );
		$d    = new DateTime( Sawa_Lens_Store::today() );
		if ( ! isset( $set[ $d->format( 'Y-m-d' ) ] ) ) {
			$d->modify( '-1 day' );
		}
		$n = 0;
		while ( isset( $set[ $d->format( 'Y-m-d' ) ] ) ) {
			$n++;
			$d->modify( '-1 day' );
		}
		return $n;
	}

	public static function read_today( $uid ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::log_t() . ' WHERE user_id = %d AND done_on = %s', $uid, Sawa_Lens_Store::today() ) ) > 0; // phpcs:ignore
	}

	/** Can this learner get a personal ("made for me") reader now? '' = yes, else why not. */
	private static function personal_why( $uid ) {
		global $wpdb;
		if ( ! self::settings()['personal'] ) {
			return 'off';
		}
		if ( ! Sawa_Lens_AI::ready() ) {
			return 'off';
		}
		$today = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t() . " WHERE kind = 'own' AND owner = %d AND created_at >= %s", $uid, Sawa_Lens_Store::today() . ' 00:00:00' ) ); // phpcs:ignore
		if ( $today ) {
			return 'today';
		}
		if ( count( self::learning( $uid, 3 ) ) < 3 ) {
			return 'words';
		}
		return self::budget_left() ? '' : 'budget';
	}

	/* ------------------------------------------------------------ endpoints */

	/** The Stories page: today's suggestion, the series going on, the library, streak and what's possible. */
	public static function home() {
		global $wpdb;
		$uid    = get_current_user_id();
		$level  = self::level( $uid );
		$mine   = self::my_cats( $uid );
		$cats   = self::cats();
		$going  = self::series_going( $uid );
		$series = null;
		if ( $going ) {
			$first  = $wpdb->get_row( $wpdb->prepare( 'SELECT id, title, cat FROM ' . self::t() . ' WHERE id = %d', $going[0] ), ARRAY_A ); // phpcs:ignore
			$series = $first ? array( 'id' => (int) $first['id'], 'title' => self::series_title( $first['title'] ), 'cat' => $first['cat'], 'next' => $going[1] + 1, 'of' => self::MAX_CHAPTERS, 'tomorrow' => $going[2] ) : null;
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT r.id, r.title, r.cat, r.format, r.chapter, r.kind, r.level, l.done_on, l.fav, l.score, l.total, l.opened_at FROM ' . self::log_t() . ' l JOIN ' . self::t() . ' r ON r.id = l.reader_id WHERE l.user_id = %d ORDER BY l.opened_at DESC LIMIT 100', $uid ), ARRAY_A ); // phpcs:ignore
		$lib  = array_map(
			function ( $x ) use ( $cats ) {
				return array( 'id' => (int) $x['id'], 'title' => $x['title'], 'cat' => $cats[ $x['cat'] ] ?? array( 'id' => $x['cat'], 'name' => $x['cat'], 'ar' => '', 'emoji' => '📖' ), 'format' => $x['format'], 'chapter' => (int) $x['chapter'], 'mine' => 'own' === $x['kind'], 'level' => self::CEFR[ (int) $x['level'] ] ?? '', 'done' => (bool) $x['done_on'], 'fav' => (bool) $x['fav'], 'score' => (int) $x['score'], 'total' => (int) $x['total'] );
			},
			$rows
		);
		$why  = self::personal_why( $uid );
		return self::out(
			array(
				'level'    => self::CEFR[ $level ],
				'quiz'     => (bool) ( class_exists( 'Sawa_Lens_Interests' ) && Sawa_Lens_Interests::picks( $uid ) ),
				'cats'     => array_values( array_map( function ( $id ) use ( $cats ) { return $cats[ $id ]; }, $mine ) ),
				'today'    => array( 'cat' => $cats[ self::pick_cat( $uid ) ] ?? null, 'format' => self::pick_format( $uid ) ),
				'series'   => $series,
				'library'  => $lib,
				'streak'   => self::streak( $uid ),
				'readToday' => self::read_today( $uid ),
				'personal' => array( 'can' => '' === $why, 'why' => $why ),
				'admin'    => current_user_can( 'manage_options' ),
			)
		);
	}

	/** Open the next reader: a series chapter, a personal one, or the best shared one (written now if there is none). */
	public static function next( WP_REST_Request $r ) {
		global $wpdb;
		$uid    = get_current_user_id();
		$level  = self::level( $uid );
		$series = (int) $r->get_param( 'series' );
		if ( $series ) {
			return self::chapter( $uid, $series );
		}
		$cats = self::cats();
		$cat  = sanitize_key( (string) $r->get_param( 'cat' ) );
		$cat  = isset( $cats[ $cat ] ) ? $cat : self::pick_cat( $uid );
		$fmt  = sanitize_key( (string) $r->get_param( 'format' ) );
		$fmt  = in_array( $fmt, array( 'story', 'explainer', 'series' ), true ) ? $fmt : self::pick_format( $uid );
		if ( $r->get_param( 'personal' ) ) {
			$why = self::personal_why( $uid );
			if ( '' !== $why ) {
				$msg = array(
					'off'    => 'Personal stories are not switched on yet.',
					'today'  => 'One personal story a day. Come back tomorrow!',
					'words'  => 'Mark at least 3 words as Learning first: your personal story is built around them.',
					'budget' => 'New stories are paused until next month. The shared stories are still here.',
				);
				return new WP_Error( $why, $msg[ $why ], array( 'status' => 400 ) );
			}
			$words = self::learning( $uid, 6 );
			$res   = self::write( $uid, 'own', $cat, $level, 'series' === $fmt ? 'story' : $fmt, $words );
			return is_wp_error( $res ) ? $res : self::open( $uid, $res );
		}
		// Shared readers this learner hasn't opened yet; the one with most of their Learning words wins.
		$cands = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t() . " r WHERE kind = 'pool' AND status = 'ready' AND level = %d AND cat = %s AND format = %s AND chapter <= 1 AND NOT EXISTS (SELECT 1 FROM " . self::log_t() . ' l WHERE l.user_id = %d AND l.reader_id = r.id) ORDER BY id DESC LIMIT 40', $level, $cat, $fmt, $uid ), ARRAY_A ); // phpcs:ignore
		if ( $cands ) {
			$learn = self::learning( $uid, 60 );
			usort(
				$cands,
				function ( $a, $b ) use ( $learn ) {
					return self::overlap( $b, $learn ) - self::overlap( $a, $learn );
				}
			);
			return self::open( $uid, $cands[0] );
		}
		if ( ! Sawa_Lens_AI::ready() ) {
			return new WP_Error( 'off', 'Stories are not switched on yet.', array( 'status' => 503 ) );
		}
		if ( ! self::budget_left() ) {
			// No budget for a new one: any unread shared reader at their level.
			$any = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . " r WHERE kind = 'pool' AND status = 'ready' AND level = %d AND chapter <= 1 AND NOT EXISTS (SELECT 1 FROM " . self::log_t() . ' l WHERE l.user_id = %d AND l.reader_id = r.id) ORDER BY RAND() LIMIT 1', $level, $uid ), ARRAY_A ); // phpcs:ignore
			return $any ? self::open( $uid, $any ) : new WP_Error( 'budget', 'New stories are paused until next month. Read one from your library again!', array( 'status' => 400 ) );
		}
		$res = self::write( $uid, 'pool', $cat, $level, $fmt, array() );
		return is_wp_error( $res ) ? $res : self::open( $uid, $res );
	}

	/** The next chapter of a series: written once for everyone following it, one a day per learner. */
	private static function chapter( $uid, $series ) {
		global $wpdb;
		$first = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . " WHERE id = %d AND format = 'series' AND chapter = 1", $series ), ARRAY_A ); // phpcs:ignore
		if ( ! $first ) {
			return new WP_Error( 'series', 'This series is not there anymore.', array( 'status' => 404 ) );
		}
		$done = $wpdb->get_row( $wpdb->prepare( 'SELECT MAX(r.chapter) AS ch, MAX(l.done_on) AS last FROM ' . self::log_t() . ' l JOIN ' . self::t() . ' r ON r.id = l.reader_id WHERE l.user_id = %d AND r.series = %d AND l.done_on IS NOT NULL', $uid, $series ), ARRAY_A ); // phpcs:ignore
		$n    = (int) ( $done['ch'] ?? 0 ) + 1;
		if ( $n > self::MAX_CHAPTERS ) {
			return new WP_Error( 'series', 'You finished this series! 🎉', array( 'status' => 400 ) );
		}
		if ( $n > 1 && ( $done['last'] ?? '' ) === Sawa_Lens_Store::today() && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'tomorrow', 'Chapter ' . $n . ' opens tomorrow. Come back for what happens next!', array( 'status' => 400 ) );
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . " WHERE series = %d AND chapter = %d AND status = 'ready'", $series, $n ), ARRAY_A ); // phpcs:ignore
		if ( $row ) {
			return self::open( $uid, $row );
		}
		if ( ! Sawa_Lens_AI::ready() || ! self::budget_left() ) {
			return new WP_Error( 'budget', 'The next chapter isn\'t written yet, and new chapters are paused until next month.', array( 'status' => 400 ) );
		}
		$prev  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT chapter, title, body FROM ' . self::t() . ' WHERE series = %d AND chapter < %d ORDER BY chapter', $series, $n ), ARRAY_A ); // phpcs:ignore
		$story = array();
		foreach ( $prev as $p ) {
			$b       = json_decode( (string) $p['body'], true );
			$story[] = 'Chapter ' . $p['chapter'] . ' (' . $p['title'] . '): ' . ( $b['recap'] ?? '' );
		}
		$res = self::write( $uid, 'pool', $first['cat'], (int) $first['level'], 'series', array(), array( 'series' => $series, 'chapter' => $n, 'story' => $story, 'name' => self::series_title( $first['title'] ) ) );
		return is_wp_error( $res ) ? $res : self::open( $uid, $res );
	}

	public static function one( WP_REST_Request $r ) {
		global $wpdb;
		$uid = get_current_user_id();
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE id = %d', (int) $r['id'] ), ARRAY_A ); // phpcs:ignore
		$was = $row ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::log_t() . ' WHERE user_id = %d AND reader_id = %d', $uid, (int) $row['id'] ) ) : 0; // phpcs:ignore
		if ( ! $row || ( 'own' === $row['kind'] && (int) $row['owner'] !== $uid ) || ( ! $was && 'ready' !== $row['status'] && ! current_user_can( 'manage_options' ) ) ) {
			return new WP_Error( 'none', 'This story is not there anymore.', array( 'status' => 404 ) );
		}
		return self::open( $uid, $row );
	}

	/** Finished reading (and the quick check): XP once per reader, and the reading streak. */
	public static function done( WP_REST_Request $r ) {
		global $wpdb;
		$uid   = get_current_user_id();
		$id    = (int) $r->get_param( 'id' );
		$total = max( 0, min( 10, (int) $r->get_param( 'total' ) ) );
		$right = max( 0, min( $total, (int) $r->get_param( 'right' ) ) );
		$log   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::log_t() . ' WHERE user_id = %d AND reader_id = %d', $uid, $id ), ARRAY_A ); // phpcs:ignore
		if ( ! $log ) {
			return new WP_Error( 'none', 'Open the story first.', array( 'status' => 400 ) );
		}
		$xp = 0;
		if ( ! $log['done_on'] ) {
			$xp = self::XP_READ + ( $total && $right / $total >= 0.8 ? self::XP_CHECK : 0 );
			if ( class_exists( 'Sawa_Tracker_XP' ) ) {
				$xp = (int) Sawa_Tracker_XP::award( $uid, $xp, 'reader' );
			}
			$wpdb->update( self::log_t(), array( 'done_on' => Sawa_Lens_Store::today(), 'score' => $right, 'total' => $total, 'xp' => $xp ), array( 'user_id' => $uid, 'reader_id' => $id ) ); // phpcs:ignore
			do_action( 'sawa_lens_reader_done', $uid, $id );
		} elseif ( $right > (int) $log['score'] ) {
			$wpdb->update( self::log_t(), array( 'score' => $right, 'total' => $total ), array( 'user_id' => $uid, 'reader_id' => $id ) ); // phpcs:ignore
		}
		$next = null;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT format, series, chapter FROM ' . self::t() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
		if ( $row && 'series' === $row['format'] && (int) $row['chapter'] < self::MAX_CHAPTERS ) {
			$next = (int) $row['chapter'] + 1;
		}
		return self::out( array( 'xp' => $xp, 'streak' => self::streak( $uid ), 'nextChapter' => $next ) );
	}

	public static function fav( WP_REST_Request $r ) {
		global $wpdb;
		$wpdb->update( self::log_t(), array( 'fav' => $r->get_param( 'on' ) ? 1 : 0 ), array( 'user_id' => get_current_user_id(), 'reader_id' => (int) $r->get_param( 'id' ) ) ); // phpcs:ignore
		return self::out( array( 'ok' => true ) );
	}

	/** "Not this topic": not suggested again for 14 days. */
	public static function skip( WP_REST_Request $r ) {
		$uid  = get_current_user_id();
		$cat  = sanitize_key( (string) $r->get_param( 'cat' ) );
		$skip = (array) get_user_meta( $uid, 'sawa_lens_reader_skip', true );
		if ( isset( self::cats()[ $cat ] ) ) {
			$skip[ $cat ] = time();
			update_user_meta( $uid, 'sawa_lens_reader_skip', $skip );
		}
		return self::out( array( 'ok' => true ) );
	}

	/** Admins: hide a shared reader that's wrong (it's never suggested again; people who read it keep it). */
	public static function hide( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'admin', 'Only admins can hide stories.', array( 'status' => 403 ) );
		}
		$wpdb->update( self::t(), array( 'status' => $r->get_param( 'show' ) ? 'ready' : 'hidden' ), array( 'id' => (int) $r->get_param( 'id' ) ) ); // phpcs:ignore
		return self::out( array( 'ok' => true ) );
	}

	/* ------------------------------------------------------------ helpers */

	/** How many of the learner's Learning words a reader uses. */
	private static function overlap( $row, $learn ) {
		$b    = json_decode( (string) $row['body'], true );
		$text = ' ' . strtolower( implode( ' ', (array) ( $b['paragraphs'] ?? array() ) ) ) . ' ';
		$n    = 0;
		foreach ( $learn as $w ) {
			if ( preg_match( '/\b' . preg_quote( strtolower( $w ), '/' ) . '(s|es|ed|d|ing)?\b/u', $text ) ) {
				$n++;
			}
		}
		return $n;
	}

	private static function series_title( $title ) {
		return trim( preg_replace( '/\s*[–:-]\s*Chapter\s+\d+.*$/iu', '', (string) $title ) );
	}

	/** Marks the reader as opened by this learner and returns it for the app. */
	private static function open( $uid, $row ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::log_t() . ' (user_id, reader_id, opened_at) VALUES (%d, %d, %s)', $uid, (int) $row['id'], current_time( 'mysql' ) ) ); // phpcs:ignore
		$log  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::log_t() . ' WHERE user_id = %d AND reader_id = %d', $uid, (int) $row['id'] ), ARRAY_A ); // phpcs:ignore
		$b    = json_decode( (string) $row['body'], true );
		$cats = self::cats();
		return self::out(
			array(
				'id'        => (int) $row['id'],
				'title'     => $row['title'],
				'cat'       => $cats[ $row['cat'] ] ?? array( 'id' => $row['cat'], 'name' => $row['cat'], 'ar' => '', 'emoji' => '📖' ),
				'format'    => $row['format'],
				'series'    => (int) $row['series'],
				'chapter'   => (int) $row['chapter'],
				'of'        => self::MAX_CHAPTERS,
				'mine'      => 'own' === $row['kind'],
				'hidden'    => 'hidden' === $row['status'],
				'level'     => self::CEFR[ (int) $row['level'] ] ?? 'A2',
				'levelNum'  => (int) $row['level'],
				'paragraphs' => (array) ( $b['paragraphs'] ?? array() ),
				'targets'   => (array) ( $b['targets'] ?? array() ),
				'used'      => (array) ( $b['used'] ?? array() ),
				'questions' => (array) ( $b['questions'] ?? array() ),
				'teaser'    => (string) ( $b['teaser'] ?? '' ),
				'minutes'   => max( 1, (int) round( str_word_count( implode( ' ', (array) ( $b['paragraphs'] ?? array() ) ) ) / 110 ) ),
				'done'      => (bool) ( $log && $log['done_on'] ),
				'fav'       => (bool) ( $log && $log['fav'] ),
				'score'     => $log ? (int) $log['score'] : 0,
				'admin'     => current_user_can( 'manage_options' ),
			)
		);
	}

	/**
	 * Has the AI write a reader and saves it. $kind 'pool' (shared) or 'own' (personal, around $words).
	 * $ser: for series chapters after the first: series id, chapter number, the story so far, its name.
	 */
	private static function write( $uid, $kind, $cat, $level, $format, $words, $ser = array() ) {
		global $wpdb;
		$c       = self::cats()[ $cat ] ?? array( 'name' => $cat, 'ar' => '' );
		$chapter = 'series' === $format ? (int) ( $ser['chapter'] ?? 1 ) : 0;
		// Shared readers in the same topic: titles to avoid, so each new one is different.
		$seen    = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT title FROM ' . self::t() . " WHERE kind = 'pool' AND cat = %s AND chapter <= 1 ORDER BY id DESC LIMIT 15", $cat ) ); // phpcs:ignore
		$res     = Sawa_Lens_AI::reader(
			$uid,
			array(
				'level'   => self::CEFR[ $level ],
				'next'    => self::CEFR[ min( 5, $level + 1 ) ] ?? 'C1',
				'words'   => self::WORDS[ $level ],
				'topic'   => $c['name'] . ( $c['ar'] ? ' (' . $c['ar'] . ')' : '' ),
				'format'  => $format,
				'chapter' => $chapter,
				'of'      => self::MAX_CHAPTERS,
				'story'   => (array) ( $ser['story'] ?? array() ),
				'name'    => (string) ( $ser['name'] ?? '' ),
				'avoid'   => $seen,
				'use'     => $words,
				'seed'    => wp_rand( 1, 9999 ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$title = mb_substr( (string) $res['title'], 0, 140 );
		if ( $chapter ) {
			$title = ( $chapter > 1 ? (string) $ser['name'] : $title ) . ' – Chapter ' . $chapter;
		}
		$wpdb->insert( // phpcs:ignore
			self::t(),
			array(
				'kind'       => $kind,
				'owner'      => 'own' === $kind ? $uid : 0,
				'cat'        => $cat,
				'level'      => $level,
				'format'     => $format,
				'series'     => (int) ( $ser['series'] ?? 0 ),
				'chapter'    => $chapter,
				'title'      => $title,
				'body'       => wp_json_encode( array_diff_key( $res, array( 'title' => 1, 'cached' => 1 ) ) ),
				'status'     => 'ready',
				'cost'       => self::settings()['price'],
				'created_at' => current_time( 'mysql' ),
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return new WP_Error( 'save', 'The story couldn\'t be saved. Try again.', array( 'status' => 500 ) );
		}
		if ( 1 === $chapter ) {
			$wpdb->update( self::t(), array( 'series' => $id ), array( 'id' => $id ) ); // phpcs:ignore -- a series is known by its first chapter
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
	}
}
