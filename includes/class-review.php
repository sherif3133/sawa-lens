<?php
/**
 * Review cards (spaced repetition) and the learner's library.
 *
 * Cards are made automatically from what the learner already collects:
 *   w:<lemma>   words marked "learning" (extension, web app)
 *   s:<uuid>    saved sentences, phrases and grammar examples
 *   e:<id>      their own mistakes from the writing checker and chat feedback
 * Scheduling is SM-2 style with four buttons (Again / Hard / Good / Easy), in whole days.
 * A word card that reaches a 21-day interval counts as known, and syncs back to the extension.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sawa_Lens_Review {

	const NEW_PER_DAY = 10;
	const SESSION     = 40;
	const KNOWN_AFTER = 21;

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
			suspended tinyint(1) NOT NULL DEFAULT 0,
			created_on date NOT NULL,
			PRIMARY KEY  (user_id,ckey),
			KEY due (user_id,suspended,due)
			) $c;"
		);
	}

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
		$room        = max( 0, self::new_per_day( $uid ) - $added_today );
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

	public static function new_per_day( $uid ) {
		$n = (int) get_user_meta( $uid, 'sawa_lens_new_per_day', true );
		return $n > 0 ? min( 100, $n ) : self::NEW_PER_DAY;
	}

	/** Due cards with everything needed to show them. */
	public static function due( $uid, $limit = self::SESSION ) {
		global $wpdb;
		$waiting = self::sync_cards( $uid );
		$t       = self::t();
		$today   = Sawa_Lens_Store::today();
		$rows    = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ckey, ivl, ease, reps, lapses FROM $t WHERE user_id = %d AND suspended = 0 AND due <= %s ORDER BY reps = 0, due, RAND() LIMIT %d", $uid, $today, $limit ), ARRAY_A ); // phpcs:ignore
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

	/** SM-2 style schedule. Returns [ivl, ease, reps, lapses]. */
	private static function schedule( $ivl, $ease, $reps, $lapses, $grade ) {
		$ivl  = (float) $ivl;
		$ease = (float) $ease;
		$reps = (int) $reps;
		if ( 1 === $grade ) {
			return array( 0, max( 1.3, $ease - 0.2 ), 0, $lapses + 1 );
		}
		if ( 2 === $grade ) {
			$n = $reps ? max( 1, $ivl * 1.2 ) : 1;
			return array( $n, max( 1.3, $ease - 0.15 ), $reps + 1, $lapses );
		}
		if ( 3 === $grade ) {
			$n = 0 === $reps ? 1 : ( 1 === $reps ? 3 : max( $ivl + 1, $ivl * $ease ) );
			return array( $n, $ease, $reps + 1, $lapses );
		}
		$n = 0 === $reps ? 4 : max( $ivl + 2, $ivl * $ease * 1.3 );
		return array( $n, min( 3.5, $ease + 0.15 ), $reps + 1, $lapses );
	}

	/** Labels for the four buttons: "<1d", "1d", "3d", "8d"… */
	private static function preview( $r ) {
		$out  = array();
		$ease = isset( $r['ease'] ) ? (float) $r['ease'] : 2.5;
		foreach ( array( 1, 2, 3, 4 ) as $g ) {
			$s     = self::schedule( $r['ivl'], $ease, $r['reps'], $r['lapses'], $g );
			$out[] = $s[0] < 1 ? 'again' : self::human( $s[0] );
		}
		return $out;
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

	public static function grade( $uid, $key, $grade ) {
		global $wpdb;
		$t   = self::t();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE user_id = %d AND ckey = %s", $uid, $key ), ARRAY_A ); // phpcs:ignore
		if ( ! $row ) {
			return new WP_Error( 'card', 'Card not found.', array( 'status' => 404 ) );
		}
		$grade                         = max( 1, min( 4, (int) $grade ) );
		list( $ivl, $ease, $reps, $lp ) = self::schedule( $row['ivl'], $row['ease'], $row['reps'], $row['lapses'], $grade );
		$wpdb->update( // phpcs:ignore
			$t,
			array(
				'ivl'         => $ivl,
				'ease'        => $ease,
				'reps'        => $reps,
				'lapses'      => $lp,
				'due'         => wp_date( 'Y-m-d', time() + (int) round( $ivl ) * DAY_IN_SECONDS ),
				'last_review' => Sawa_Lens_Store::today(),
			),
			array( 'user_id' => $uid, 'ckey' => $key )
		);
		$log = (array) get_user_meta( $uid, 'sawa_lens_reviews', true );
		$day = Sawa_Lens_Store::today();
		$log[ $day ] = (int) ( $log[ $day ] ?? 0 ) + 1;
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
		return array(
			'due'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE user_id = %d AND suspended = 0 AND due <= %s", $uid, $today ) ), // phpcs:ignore
			'total'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE user_id = %d AND suspended = 0", $uid ) ), // phpcs:ignore
			'mature'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE user_id = %d AND suspended = 0 AND ivl >= 21", $uid ) ), // phpcs:ignore
			'today'    => (int) ( $log[ $today ] ?? 0 ),
			'streak'   => $streak,
			'newPerDay' => self::new_per_day( $uid ),
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
