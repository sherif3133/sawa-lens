<?php
/**
 * Review cards (spaced repetition) and the learner's library.
 *
 * Cards are made automatically from what the learner already collects:
 *   w:<lemma>   words marked "learning" (extension, web app)
 *   s:<uuid>    saved sentences, phrases and grammar examples
 *   e:<id>      their own mistakes from the writing checker and chat feedback
 * Scheduling is FSRS-6 (see class-fsrs.php) with four buttons (Again / Hard / Good / Easy), in whole days, with
 * load balancing so reviews spread evenly over the days. Every answer is logged (revlog) for the learner's memory
 * stats and, later, per-learner weights.
 * A word card that reaches a 21-day interval counts as known, and syncs back to the extension.
 *
 * Card levels (progressive overload): new cards a day grow with consistency. Everyone starts small; a week with
 * reviews on 5+ days and 80%+ remembered unlocks the next level; a weak week steps back. When reviews pile up
 * (catch-up mode) no new cards come in until the backlog is cleared, so nobody drowns.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sawa_Lens_Review {

	const SESSION     = 40;
	const KNOWN_AFTER = 21;

	/** New cards a day at each card level (1 … 7). */
	const LEVELS = array( 5, 7, 10, 13, 16, 20, 25 );

	public static function install( $c ) {
		dbDelta(
			'CREATE TABLE ' . Sawa_Lens_Store::t( 'cards' ) . " (
			user_id bigint(20) unsigned NOT NULL,
			ckey varchar(80) NOT NULL,
			due date NOT NULL,
			ivl float NOT NULL DEFAULT 0,
			ease float NOT NULL DEFAULT 2.5,
			reps smallint(5) unsigned NOT NULL DEFAULT 0,
			lapses smallint(5) unsigned NOT NULL DEFAULT 0,
			last_review date DEFAULT NULL,
			stab float NOT NULL DEFAULT 0,
			diff float NOT NULL DEFAULT 0,
			suspended tinyint(1) NOT NULL DEFAULT 0,
			created_on date NOT NULL,
			PRIMARY KEY  (user_id,ckey),
			KEY due (user_id,suspended,due)
			) $c;"
		);
		// One row per answer: what was shown, how it went, and the memory state after it.
		dbDelta(
			'CREATE TABLE ' . Sawa_Lens_Store::t( 'revlog' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			ckey varchar(80) NOT NULL,
			day date NOT NULL,
			grade tinyint(3) unsigned NOT NULL,
			kind tinyint(3) unsigned NOT NULL DEFAULT 0,
			elapsed smallint(5) unsigned NOT NULL DEFAULT 0,
			ivl smallint(5) unsigned NOT NULL DEFAULT 0,
			stab float NOT NULL DEFAULT 0,
			diff float NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user_day (user_id,day),
			KEY user_card (user_id,ckey)
			) $c;"
		);
	}

	/** revlog kinds */
	const K_NEW = 0, K_REVIEW = 1, K_SAME_DAY = 2;

	private static function t() {
		return Sawa_Lens_Store::t( 'cards' );
	}

	/** Everything that can become a card right now, keyed by card key. */
	private static function sources( $uid ) {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT lemma FROM ' . Sawa_Lens_Store::t( 'words' ) . " WHERE user_id = %d AND status = 'learning' ORDER BY learning_on, lemma", $uid ) ) as $l ) { // phpcs:ignore
			$out[ 'w:' . $l ] = 1;
		}
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT uuid FROM ' . Sawa_Lens_Store::t( 'sentences' ) . ' WHERE user_id = %d AND deleted = 0 ORDER BY id', $uid ) ) as $u ) { // phpcs:ignore
			$out[ 's:' . $u ] = 1;
		}
		// One card per distinct mistaken sentence (latest correction wins).
		$seen = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, sentence FROM ' . Sawa_Lens_Store::t( 'errors' ) . " WHERE user_id = %d AND sentence <> '' AND fixed <> '' AND sentence <> fixed ORDER BY id DESC LIMIT 300", $uid ), ARRAY_A ) as $e ) { // phpcs:ignore
			$k = md5( strtolower( $e['sentence'] ) );
			if ( ! isset( $seen[ $k ] ) ) {
				$seen[ $k ]               = 1;
				$out[ 'e:' . $e['id'] ] = 1;
			}
		}
		return $out;
	}

	/** Adds cards for new sources, up to the daily new-card limit; suspends cards whose source is gone. */
	public static function sync_cards( $uid ) {
		global $wpdb;
		$t     = self::t();
		$today = Sawa_Lens_Store::today();
		$src   = self::sources( $uid );
		$have  = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT ckey, suspended FROM $t WHERE user_id = %d", $uid ), ARRAY_A ) as $r ) { // phpcs:ignore
			$have[ $r['ckey'] ] = (int) $r['suspended'];
		}
		foreach ( $have as $k => $sus ) {
			$gone = ! isset( $src[ $k ] );
			if ( $gone && ! $sus ) {
				$wpdb->update( $t, array( 'suspended' => 1 ), array( 'user_id' => $uid, 'ckey' => $k ) ); // phpcs:ignore
			} elseif ( ! $gone && 1 === $sus ) {
				$wpdb->update( $t, array( 'suspended' => 0 ), array( 'user_id' => $uid, 'ckey' => $k ) ); // phpcs:ignore
			}
		}
		$added_today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE user_id = %d AND created_on = %s", $uid, $today ) ); // phpcs:ignore
		$cap         = self::capacity( $uid );
		$room        = $cap['catchup'] ? 0 : max( 0, $cap['newPerDay'] - $added_today );
		$waiting     = 0;
		foreach ( $src as $k => $_ ) {
			if ( isset( $have[ $k ] ) ) {
				continue;
			}
			if ( $room <= 0 ) {
				$waiting++;
				continue;
			}
			$wpdb->insert( $t, array( 'user_id' => $uid, 'ckey' => $k, 'due' => $today, 'created_on' => $today ) ); // phpcs:ignore
			$room--;
		}
		return $waiting;
	}

	/** The learner's own choice (fewer new cards than their level allows), 0 = as many as the level allows. */
	public static function new_per_day( $uid ) {
		$n     = (int) get_user_meta( $uid, 'sawa_lens_new_per_day', true );
		$level = self::LEVELS[ self::level( $uid )['level'] - 1 ];
		return $n > 0 ? min( $n, $level ) : $level;
	}

	/** Due cards with everything needed to show them. */
	public static function due( $uid, $limit = self::SESSION ) {
		global $wpdb;
		$waiting = self::sync_cards( $uid );
		$t       = self::t();
		$today   = Sawa_Lens_Store::today();
		$rows    = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ckey, ivl, ease, reps, lapses, stab, diff, last_review FROM $t WHERE user_id = %d AND suspended = 0 AND due <= %s ORDER BY reps = 0, due, RAND() LIMIT %d", $uid, $today, $limit ), ARRAY_A ); // phpcs:ignore
		$cards   = array();
		foreach ( $rows as $r ) {
			$c = self::content( $uid, $r['ckey'] );
			if ( $c ) {
				$cards[] = $c + array(
					'key'   => $r['ckey'],
					'new'   => 0 === (int) $r['reps'],
					'ivl'   => (float) $r['ivl'],
					'next'  => self::preview( $r ),
				);
			}
		}
		return array(
			'cards'   => $cards,
			'waiting' => $waiting,
			'stats'   => self::stats( $uid ),
		);
	}

	private static function content( $uid, $key ) {
		global $wpdb;
		list( $type, $ref ) = explode( ':', $key, 2 ) + array( '', '' );
		if ( 'w' === $type ) {
			$w = $wpdb->get_row( $wpdb->prepare( 'SELECT ctx, ctx_url, ctx_title, ctx_vid, ctx_ms FROM ' . Sawa_Lens_Store::t( 'words' ) . ' WHERE user_id = %d AND lemma = %s', $uid, $ref ), ARRAY_A ); // phpcs:ignore
			return array(
				'type'    => 'word',
				'lemma'   => $ref,
				'text'    => $w ? $w['ctx'] : '',
				'url'     => $w ? $w['ctx_url'] : '',
				'title'   => $w ? $w['ctx_title'] : '',
				'videoId' => $w ? $w['ctx_vid'] : '',
				'startMs' => $w ? (int) $w['ctx_ms'] : 0,
			);
		}
		if ( 's' === $type ) {
			$s = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Sawa_Lens_Store::t( 'sentences' ) . ' WHERE user_id = %d AND uuid = %s AND deleted = 0', $uid, $ref ), ARRAY_A ); // phpcs:ignore
			return $s ? array( 'type' => $s['kind'] ) + Sawa_Lens_Store::sentence_out( $s ) : null;
		}
		if ( 'e' === $type ) {
			$e = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Sawa_Lens_Store::t( 'errors' ) . ' WHERE user_id = %d AND id = %d', $uid, (int) $ref ), ARRAY_A ); // phpcs:ignore
			if ( ! $e ) {
				return null;
			}
			$k = Sawa_Lens_Skills::get( $e['skill'] );
			return array(
				'type'      => 'mistake',
				'sentence'  => $e['sentence'],
				'fixed'     => $e['fixed'],
				'wrong'     => $e['wrong'],
				'fix'       => $e['fix'],
				'why'       => $e['why'],
				'why_ar'    => $e['why_ar'],
				'skill'     => $e['skill'],
				'skillName' => $k ? $k['name'] : '',
				'source'    => $e['source'],
			);
		}
		return null;
	}

	/** The card's FSRS memory state: [s, d, reps]. Cards from the SM-2 days are converted on their next review. */
	private static function memory( $r ) {
		$s = (float) ( $r['stab'] ?? 0 );
		$d = (float) ( $r['diff'] ?? 0 );
		if ( $s <= 0 && (int) $r['reps'] > 0 ) {
			list( $s, $d ) = Sawa_Lens_FSRS::from_sm2( $r['ivl'], $r['ease'] );
		}
		return array( $s, $d, (int) $r['reps'] );
	}

	private static function elapsed( $r ) {
		if ( empty( $r['last_review'] ) ) {
			return 0;
		}
		return max( 0, (int) round( ( strtotime( Sawa_Lens_Store::today() ) - strtotime( $r['last_review'] ) ) / DAY_IN_SECONDS ) );
	}

	/** Labels for the four buttons: "again", "1d", "3d", "2mo"… */
	private static function preview( $r ) {
		$iv = Sawa_Lens_FSRS::intervals( self::memory( $r ), self::elapsed( $r ) );
		return array( 'again', self::human( $iv[2] ), self::human( $iv[3] ), self::human( $iv[4] ) );
	}

	private static function human( $days ) {
		$d = (int) round( $days );
		if ( $d < 30 ) {
			return $d . 'd';
		}
		if ( $d < 365 ) {
			return round( $d / 30 ) . 'mo';
		}
		return round( $d / 365, 1 ) . 'y';
	}

	/**
	 * Load balancing: an interval of 3+ days can move a little (±5–15%, like Anki's fuzz) to the day in that
	 * range with the fewest reviews already due, so cards learned together don't all come back together.
	 */
	private static function balance( $uid, $ivl ) {
		global $wpdb;
		if ( $ivl < 3 ) {
			return $ivl;
		}
		$pct   = $ivl < 7 ? 0.15 : ( $ivl < 20 ? 0.1 : 0.05 );
		$delta = max( 1, (int) round( $ivl * $pct ) );
		$lo    = max( 2, $ivl - $delta );
		$hi    = min( Sawa_Lens_FSRS::MAX_IVL, $ivl + $delta );
		$base  = strtotime( Sawa_Lens_Store::today() );
		$t     = self::t();
		$load  = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT due, COUNT(*) AS n FROM $t WHERE user_id = %d AND suspended = 0 AND due BETWEEN %s AND %s GROUP BY due", $uid, wp_date( 'Y-m-d', $base + $lo * DAY_IN_SECONDS ), wp_date( 'Y-m-d', $base + $hi * DAY_IN_SECONDS ) ), ARRAY_A ) as $x ) { // phpcs:ignore
			$load[ $x['due'] ] = (int) $x['n'];
		}
		$best = array();
		$min  = PHP_INT_MAX;
		for ( $i = $lo; $i <= $hi; $i++ ) {
			$n = $load[ wp_date( 'Y-m-d', $base + $i * DAY_IN_SECONDS ) ] ?? 0;
			if ( $n < $min ) {
				$min  = $n;
				$best = array( $i );
			} elseif ( $n === $min ) {
				$best[] = $i;
			}
		}
		return $best[ array_rand( $best ) ];
	}

	public static function grade( $uid, $key, $grade ) {
		global $wpdb;
		$t   = self::t();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE user_id = %d AND ckey = %s", $uid, $key ), ARRAY_A ); // phpcs:ignore
		if ( ! $row ) {
			return new WP_Error( 'card', 'Card not found.', array( 'status' => 404 ) );
		}
		$grade   = max( 1, min( 4, (int) $grade ) );
		$mem     = self::memory( $row );
		$elapsed = self::elapsed( $row );
		$kind    = ! $mem[2] || $mem[0] <= 0 ? self::K_NEW : ( $elapsed < 1 ? self::K_SAME_DAY : self::K_REVIEW );
		list( $s, $d ) = Sawa_Lens_FSRS::next( $mem, $elapsed, $grade );
		if ( 1 === $grade ) {
			$ivl = 0; // "Again": it comes back later in this session (and stays due today)
		} else {
			$iv  = Sawa_Lens_FSRS::intervals( $mem, $elapsed );
			$ivl = self::balance( $uid, $iv[ $grade ] );
		}
		$today = Sawa_Lens_Store::today();
		$wpdb->update( // phpcs:ignore
			$t,
			array(
				'ivl'         => $ivl,
				'stab'        => $s,
				'diff'        => $d,
				'reps'        => (int) $row['reps'] + 1,
				'lapses'      => (int) $row['lapses'] + ( 1 === $grade && self::K_REVIEW === $kind ? 1 : 0 ),
				'due'         => wp_date( 'Y-m-d', strtotime( $today ) + $ivl * DAY_IN_SECONDS ),
				'last_review' => $today,
			),
			array( 'user_id' => $uid, 'ckey' => $key )
		);
		$wpdb->insert( // phpcs:ignore
			Sawa_Lens_Store::t( 'revlog' ),
			array( 'user_id' => $uid, 'ckey' => $key, 'day' => $today, 'grade' => $grade, 'kind' => $kind, 'elapsed' => min( 65535, $elapsed ), 'ivl' => $ivl, 'stab' => $s, 'diff' => $d, 'created_at' => current_time( 'mysql' ) )
		);
		$log = (array) get_user_meta( $uid, 'sawa_lens_reviews', true );
		$log[ $today ] = (int) ( $log[ $today ] ?? 0 ) + 1;
		ksort( $log );
		update_user_meta( $uid, 'sawa_lens_reviews', array_slice( $log, -60, null, true ) );
		$known = false;
		if ( 0 === strpos( $key, 'w:' ) && $ivl >= self::KNOWN_AFTER ) {
			Sawa_Lens_Store::put_words( $uid, array( array( 'lemma' => substr( $key, 2 ), 'status' => 'known', 't' => (int) round( microtime( true ) * 1000 ) ) ) );
			$known = true;
		}
		do_action( 'sawa_lens_card_reviewed', $uid, $key, $grade );
		Sawa_Lens_Activity::add( $uid, 'card' );
		return array(
			'ivl'   => $ivl,
			'known' => $known,
			'stats' => self::stats( $uid ),
		);
	}

	/* ------------------------------------------------------------------ card levels (progressive overload) */

	/**
	 * The learner's card level, checked once a week:
	 *   up   — reviews on 5+ of the last 7 days, 80%+ remembered, and not in catch-up mode
	 *   down — reviews on 2 or fewer days, or under 70% remembered (with enough reviews to tell)
	 * New learners start at level 1 (5 new cards a day); learners from before levels start where their habit puts them.
	 */
	public static function level( $uid ) {
		$lv    = get_user_meta( $uid, 'sawa_lens_card_level', true );
		$today = Sawa_Lens_Store::today();
		if ( ! is_array( $lv ) ) {
			$days = count( array_filter( array_slice( (array) get_user_meta( $uid, 'sawa_lens_reviews', true ), -14, null, true ) ) );
			$lv   = array( 'level' => $days >= 10 ? 4 : ( $days >= 6 ? 3 : ( $days >= 1 ? 2 : 1 ) ), 'checked' => $today, 'change' => '' );
			update_user_meta( $uid, 'sawa_lens_card_level', $lv );
			return $lv;
		}
		if ( strtotime( $today ) - strtotime( $lv['checked'] ) >= 7 * DAY_IN_SECONDS ) {
			$w    = self::week( $uid );
			$was  = (int) $lv['level'];
			$good = $w['days'] >= 5 && ( $w['n'] < 15 || $w['retention'] >= 80 ) && ! self::backlog( $uid )['catchup'];
			$weak = $w['days'] <= 2 || ( $w['n'] >= 15 && $w['retention'] < 70 );
			$lv['level']   = $good ? min( count( self::LEVELS ), $was + 1 ) : ( $weak ? max( 1, $was - 1 ) : $was );
			$lv['change']  = $lv['level'] > $was ? 'up' : ( $lv['level'] < $was ? 'down' : '' );
			$lv['checked'] = $today;
			$lv['seen']    = false;
			update_user_meta( $uid, 'sawa_lens_card_level', $lv );
		}
		return $lv;
	}

	/** The last 7 days: days with reviews, and how many review answers (not new cards) were remembered. */
	public static function week( $uid, $days = 7 ) {
		global $wpdb;
		$from = wp_date( 'Y-m-d', strtotime( Sawa_Lens_Store::today() ) - ( $days - 1 ) * DAY_IN_SECONDS );
		$l    = Sawa_Lens_Store::t( 'revlog' );
		$act  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT day) FROM $l WHERE user_id = %d AND day >= %s", $uid, $from ) ); // phpcs:ignore
		$r    = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, SUM(grade > 1) AS ok FROM $l WHERE user_id = %d AND day >= %s AND kind = %d", $uid, $from, self::K_REVIEW ), ARRAY_A ); // phpcs:ignore
		// Days before the review log existed still count, from the daily totals.
		if ( $act < $days ) {
			$old = 0;
			foreach ( (array) get_user_meta( $uid, 'sawa_lens_reviews', true ) as $k => $n ) {
				$old += ( $k >= $from && $n ) ? 1 : 0;
			}
			$act = max( $act, $old );
		}
		$n = (int) ( $r['n'] ?? 0 );
		return array( 'days' => $act, 'n' => $n, 'retention' => $n ? (int) round( 100 * $r['ok'] / $n ) : null );
	}

	/** Catch-up mode: more reviews due than about 3 days of the learner's usual work (and at least 30). */
	public static function backlog( $uid ) {
		global $wpdb;
		$t     = self::t();
		$due   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE user_id = %d AND suspended = 0 AND reps > 0 AND due <= %s", $uid, Sawa_Lens_Store::today() ) ); // phpcs:ignore
		$log   = array_slice( (array) get_user_meta( $uid, 'sawa_lens_reviews', true ), -14, null, true );
		$daily = array_sum( $log ) / 14;
		$limit = max( 30, (int) ceil( 3 * $daily ) );
		return array( 'due' => $due, 'limit' => $limit, 'catchup' => $due > $limit );
	}

	/**
	 * What the learner can take on: new cards a day, catch-up mode, and how many new Learning words the extension
	 * should allow today (the level's new cards, plus a little room, and none while the waiting line is long).
	 */
	public static function capacity( $uid ) {
		global $wpdb;
		$lv      = self::level( $uid );
		$per_day = self::new_per_day( $uid );
		$b       = self::backlog( $uid );
		$have    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t() . ' WHERE user_id = %d', $uid ) ); // phpcs:ignore
		$waiting = max( 0, count( self::sources( $uid ) ) - $have );
		$full    = $b['catchup'] || $waiting >= 3 * $per_day;
		return array(
			'level'      => (int) $lv['level'],
			'levels'     => count( self::LEVELS ),
			'newPerDay'  => $per_day,
			'levelMax'   => self::LEVELS[ $lv['level'] - 1 ],
			'nextPerDay' => self::LEVELS[ min( count( self::LEVELS ), $lv['level'] + 1 ) - 1 ],
			'catchup'    => $b['catchup'],
			'dueReviews' => $b['due'],
			'waiting'    => $waiting,
			'learnPerDay' => $full ? 0 : $per_day + 5,
			'full'       => $full,
		);
	}

	public static function stats( $uid ) {
		global $wpdb;
		$t     = self::t();
		$today = Sawa_Lens_Store::today();
		$log   = (array) get_user_meta( $uid, 'sawa_lens_reviews', true );
		$streak = 0;
		for ( $d = 0; $d < 60; $d++ ) {
			$k = wp_date( 'Y-m-d', time() - $d * DAY_IN_SECONDS );
			if ( ! empty( $log[ $k ] ) ) {
				$streak++;
			} elseif ( $d > 0 ) {
				break;
			}
		}
		$cap  = self::capacity( $uid );
		$lv   = self::level( $uid );
		$week = self::week( $uid );
		$m30  = self::week( $uid, 30 );
		$change = '';
		if ( ! empty( $lv['change'] ) && empty( $lv['seen'] ) ) {
			$change      = $lv['change'];
			$lv['seen']  = true;
			update_user_meta( $uid, 'sawa_lens_card_level', $lv );
		}
		$days_left = max( 0, 7 - (int) round( ( strtotime( $today ) - strtotime( $lv['checked'] ) ) / DAY_IN_SECONDS ) );
		return array(
			'due'        => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE user_id = %d AND suspended = 0 AND due <= %s", $uid, $today ) ), // phpcs:ignore
			'total'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE user_id = %d AND suspended = 0", $uid ) ), // phpcs:ignore
			'mature'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE user_id = %d AND suspended = 0 AND ivl >= 21", $uid ) ), // phpcs:ignore
			'today'      => (int) ( $log[ $today ] ?? 0 ),
			'streak'     => $streak,
			'newPerDay'  => $cap['newPerDay'],
			'level'      => $cap + array( 'change' => $change, 'checkIn' => $days_left, 'weekDays' => $week['days'] ),
			'retention'  => $week['retention'],
			'retention30' => $m30['retention'],
		);
	}

	/**
	 * The learner's progress over time, for the charts on the home page: reviews a day (12 weeks), words known
	 * (60 days), % remembered each week (8 weeks), reviews coming up (14 days), and English met each day (14 days).
	 */
	public static function history( $uid ) {
		global $wpdb;
		$today = strtotime( Sawa_Lens_Store::today() );
		$day   = function ( $n ) use ( $today ) {
			return wp_date( 'Y-m-d', $today + $n * DAY_IN_SECONDS );
		};
		$l = Sawa_Lens_Store::t( 'revlog' );
		// reviews a day: the log of answers, and the older daily totals before it existed
		$heat = array();
		foreach ( (array) get_user_meta( $uid, 'sawa_lens_reviews', true ) as $k => $n ) {
			$heat[ $k ] = (int) $n;
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT day, COUNT(*) AS n FROM $l WHERE user_id = %d AND day >= %s GROUP BY day", $uid, $day( -83 ) ), ARRAY_A ) as $r ) { // phpcs:ignore
			$heat[ $r['day'] ] = max( $heat[ $r['day'] ] ?? 0, (int) $r['n'] );
		}
		$reviews = array();
		for ( $i = -83; $i <= 0; $i++ ) {
			$reviews[] = array( $day( $i ), (int) ( $heat[ $day( $i ) ] ?? 0 ) );
		}
		// words known, day by day (words known before the window, e.g. from the level test, are the starting point)
		$w     = Sawa_Lens_Store::t( 'words' );
		$start = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $w WHERE user_id = %d AND status = 'known' AND ( known_on IS NULL OR known_on < %s )", $uid, $day( -59 ) ) ); // phpcs:ignore
		$per   = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT known_on, COUNT(*) AS n FROM $w WHERE user_id = %d AND status = 'known' AND known_on >= %s GROUP BY known_on", $uid, $day( -59 ) ), ARRAY_A ) as $r ) { // phpcs:ignore
			$per[ $r['known_on'] ] = (int) $r['n'];
		}
		$known = array();
		$sum   = $start;
		for ( $i = -59; $i <= 0; $i++ ) {
			$sum    += $per[ $day( $i ) ] ?? 0;
			$known[] = array( $day( $i ), $sum );
		}
		// % remembered each week (review answers only, not new cards)
		$weeks = array();
		for ( $k = 7; $k >= 0; $k-- ) {
			$r = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, SUM(grade > 1) AS ok FROM $l WHERE user_id = %d AND kind = %d AND day BETWEEN %s AND %s", $uid, self::K_REVIEW, $day( -7 * $k - 6 ), $day( -7 * $k ) ), ARRAY_A ); // phpcs:ignore
			$n       = (int) $r['n'];
			$weeks[] = array( $day( -7 * $k - 6 ), $n ? (int) round( 100 * $r['ok'] / $n ) : null, $n );
		}
		// reviews coming up (overdue cards count for today)
		$t    = self::t();
		$fc   = array_fill( 0, 14, 0 );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT due, COUNT(*) AS n FROM $t WHERE user_id = %d AND suspended = 0 AND due <= %s GROUP BY due", $uid, $day( 13 ) ), ARRAY_A ) as $r ) { // phpcs:ignore
			$i        = max( 0, (int) round( ( strtotime( $r['due'] ) - $today ) / DAY_IN_SECONDS ) );
			$fc[ $i ] += (int) $r['n'];
		}
		$forecast = array();
		foreach ( $fc as $i => $n ) {
			$forecast[] = array( $day( $i ), $n );
		}
		// cards by how well they're learned
		$stages = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(reps = 0) AS fresh, SUM(reps > 0 AND ivl < 7) AS learning, SUM(ivl >= 7 AND ivl < 21) AS young, SUM(ivl >= 21) AS mature FROM $t WHERE user_id = %d AND suspended = 0", $uid ), ARRAY_A ); // phpcs:ignore
		// English met each day (Language Lens)
		$met = array();
		$ds  = class_exists( 'Sawa_Lens_Daily' ) ? Sawa_Lens_Daily::series( $uid ) : array();
		for ( $i = -13; $i <= 0; $i++ ) {
			$x     = $ds[ $day( $i ) ] ?? array();
			$met[] = array( $day( $i ), (int) ( $x['read'] ?? 0 ), (int) ( $x['heard'] ?? 0 ), (int) ( $x['curious'] ?? 0 ) );
		}
		return array(
			'reviews'  => $reviews,
			'known'    => $known,
			'weeks'    => $weeks,
			'forecast' => $forecast,
			'stages'   => array_map( 'intval', (array) $stages ),
			'met'      => $met,
		);
	}

	/** Everything the learner has collected, for the library page. */
	public static function library( $uid ) {
		global $wpdb;
		$words = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT lemma, learning_on, ctx, ctx_vid, ctx_ms, ctx_url, ctx_title FROM ' . Sawa_Lens_Store::t( 'words' ) . " WHERE user_id = %d AND status = 'learning' ORDER BY learning_on DESC, lemma", $uid ), ARRAY_A ); // phpcs:ignore
		$known = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Sawa_Lens_Store::t( 'words' ) . " WHERE user_id = %d AND status = 'known'", $uid ) ); // phpcs:ignore
		$recent_known = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT lemma FROM ' . Sawa_Lens_Store::t( 'words' ) . " WHERE user_id = %d AND status = 'known' AND known_on IS NOT NULL ORDER BY known_on DESC LIMIT 60", $uid ) ); // phpcs:ignore
		$sents = array_map(
			array( 'Sawa_Lens_Store', 'sentence_out' ),
			(array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Sawa_Lens_Store::t( 'sentences' ) . ' WHERE user_id = %d AND deleted = 0 ORDER BY id DESC LIMIT 2000', $uid ), ARRAY_A ) // phpcs:ignore
		);
		$ivl = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT ckey, ivl, due FROM ' . self::t() . ' WHERE user_id = %d', $uid ), ARRAY_A ) as $c ) { // phpcs:ignore
			$ivl[ $c['ckey'] ] = array( (float) $c['ivl'], $c['due'] );
		}
		return array(
			'learning'    => array_map(
				function ( $w ) use ( $ivl ) {
					$c = $ivl[ 'w:' . $w['lemma'] ] ?? null;
					return array( 'lemma' => $w['lemma'], 'since' => $w['learning_on'], 'ivl' => $c ? $c[0] : null, 'due' => $c ? $c[1] : null, 'text' => $w['ctx'], 'videoId' => $w['ctx_vid'], 'startMs' => (int) $w['ctx_ms'], 'url' => $w['ctx_url'], 'title' => $w['ctx_title'] );
				},
				$words
			),
			'known'       => $known,
			'recentKnown' => $recent_known,
			'sentences'   => $sents,
			'mistakes'    => Sawa_Lens_Skills::recent_mistakes( $uid, 100 ),
		);
	}
}
