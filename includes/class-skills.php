<?php
/**
 * The skill tree: what's open, mastered or needs work for a learner, from three signals:
 *   practice  – AI-made exercises for the point (last 20 answers),
 *   writing   – correct uses and errors the writing checker found (and later the chat),
 *   noticing  – targets found in "spot it" exercises + sentences saved with that grammar tag in the extension.
 * No teacher approval: everything is computed from what the learner does.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sawa_Lens_Skills {

	const META = 'sawa_lens_skills';

	private static $data = null;

	public static function all() {
		if ( null === self::$data ) {
			self::$data = require SAWA_LENS_DIR . 'includes/skills-data.php';
		}
		return self::$data;
	}

	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/** Raw per-skill progress from user meta: { id: { r:[1,0…], sets:n, notice:n, uses:{Y-m-d:n}, ever:0|1 } } */
	private static function raw( $uid ) {
		$m = get_user_meta( $uid, self::META, true );
		return is_array( $m ) ? $m : array();
	}

	private static function save_raw( $uid, $raw ) {
		update_user_meta( $uid, self::META, $raw );
	}

	/** Records a finished practice set. $results: list of 0/1; $found: noticing targets found. */
	public static function record_practice( $uid, $id, $results, $found ) {
		if ( ! self::get( $id ) ) {
			return;
		}
		$raw  = self::raw( $uid );
		$s    = isset( $raw[ $id ] ) ? $raw[ $id ] : array();
		$r    = isset( $s['r'] ) ? (array) $s['r'] : array();
		foreach ( array_slice( (array) $results, 0, 30 ) as $x ) {
			$r[] = $x ? 1 : 0;
		}
		$s['r']      = array_slice( $r, -20 );
		$s['sets']   = (int) ( $s['sets'] ?? 0 ) + 1;
		$s['notice'] = (int) ( $s['notice'] ?? 0 ) + max( 0, min( 20, (int) $found ) );
		$raw[ $id ]  = $s;
		self::save_raw( $uid, $raw );
	}

	/** English XP in the tracker (when it's installed). */
	public static function xp( $uid, $n, $reason, $capped = true ) {
		return class_exists( 'Sawa_Tracker_XP' ) ? Sawa_Tracker_XP::award( $uid, $n, $reason, $capped ) : 0;
	}

	/** Raid coins in the tracker (earned by learning, used as loot). */
	public static function coins( $uid, $n ) {
		return class_exists( 'Sawa_Tracker_Raids' ) ? Sawa_Tracker_Raids::add_coins( $uid, $n, 'learn' ) : 0;
	}

	/** Mastered points per track (grammar / pron / tone), counting points mastered at least once. */
	public static function mastery_counts( $uid ) {
		$out = array( 'grammar' => 0, 'pron' => 0, 'tone' => 0 );
		foreach ( self::raw( $uid ) as $id => $s ) {
			$k = self::get( $id );
			if ( $k && ! empty( $s['ever'] ) ) {
				$out[ $k['track'] ]++;
			}
		}
		return $out;
	}

	/** Correct uses seen in free writing / chat: { skill: n }. */
	public static function record_uses( $uid, $good ) {
		$raw = self::raw( $uid );
		$day = wp_date( 'Y-m-d' );
		// Bonus XP: a correct use of a point the learner got wrong in the last 30 days (max 30 a point a day).
		$recent = self::error_counts( $uid, 30 );
		foreach ( (array) $good as $id => $n ) {
			if ( ! empty( $recent[ $id ] ) && $n > 0 ) {
				$given = (int) ( $raw[ $id ]['bonus'][ $day ] ?? 0 );
				$add   = min( 30 - $given, 5 * (int) $n );
				if ( $add > 0 ) {
					self::xp( $uid, $add, 'improve:' . $id, false );
					$raw[ $id ]['bonus'] = array( $day => $given + $add );
				}
			}
		}
		foreach ( (array) $good as $id => $n ) {
			if ( ! self::get( $id ) || $n <= 0 ) {
				continue;
			}
			$s                = isset( $raw[ $id ] ) ? $raw[ $id ] : array();
			$u                = isset( $s['uses'] ) ? (array) $s['uses'] : array();
			$u[ $day ]        = (int) ( $u[ $day ] ?? 0 ) + min( 20, (int) $n );
			ksort( $u );
			$s['uses']        = array_slice( $u, -60, null, true );
			$raw[ $id ]       = $s;
		}
		self::save_raw( $uid, $raw );
	}

	/** Logs errors from the writing checker (source "write") or, later, the chat (source "chat"). */
	public static function log_errors( $uid, $errors, $source, $writing_id ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		foreach ( (array) $errors as $e ) {
			$wpdb->insert( // phpcs:ignore
				Sawa_Lens_Store::t( 'errors' ),
				array(
					'user_id'    => $uid,
					'skill'      => $e['skill'],
					'source'     => $source,
					'writing_id' => (int) $writing_id,
					'wrong'      => $e['wrong'],
					'fix'        => $e['right'],
					'why'        => $e['why'],
					'why_ar'     => $e['why_ar'],
					'sentence'   => $e['sentence'],
					'fixed'      => $e['fixed'],
					'created_at' => $now,
				)
			);
		}
	}

	/** Error counts per skill in the last N days. */
	private static function error_counts( $uid, $days ) {
		global $wpdb;
		$t    = Sawa_Lens_Store::t( 'errors' );
		$from = wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT skill, COUNT(*) n FROM $t WHERE user_id = %d AND created_at >= %s GROUP BY skill", $uid, $from ), ARRAY_A ); // phpcs:ignore
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['skill'] ] = (int) $row['n'];
		}
		return $out;
	}

	/** Sentences saved in the extension with a grammar tag count as noticing. */
	private static function saved_tags( $uid ) {
		global $wpdb;
		$t    = Sawa_Lens_Store::t( 'sentences' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT tag, COUNT(*) n FROM $t WHERE user_id = %d AND kind = 'grammar' AND deleted = 0 GROUP BY tag", $uid ), ARRAY_A ); // phpcs:ignore
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['tag'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * The whole tree for a learner, in order, with status and score.
	 * status: locked | open | review (was mastered, errors came back) | mastered
	 */
	public static function tree( $uid ) {
		$raw    = self::raw( $uid );
		$err14  = self::error_counts( $uid, 14 );
		$err30  = self::error_counts( $uid, 30 );
		$tags   = self::saved_tags( $uid );
		$cutoff = wp_date( 'Y-m-d', time() - 60 * DAY_IN_SECONDS );
		$out    = array();
		$state  = array();
		$dirty  = false;
		foreach ( self::all() as $id => $k ) {
			$s      = isset( $raw[ $id ] ) ? $raw[ $id ] : array();
			$r      = isset( $s['r'] ) ? (array) $s['r'] : array();
			$items  = count( $r );
			$acc    = $items ? array_sum( $r ) / $items : 0;
			$notice = (int) ( $s['notice'] ?? 0 );
			foreach ( $k['notice'] as $tag ) {
				$notice += (int) ( $tags[ $tag ] ?? 0 );
			}
			$ok   = 0;
			$days = 0;
			foreach ( (array) ( $s['uses'] ?? array() ) as $d => $n ) {
				if ( $d >= $cutoff && $n > 0 ) {
					$ok += (int) $n;
					$days++;
				}
			}
			$e14   = (int) ( $err14[ $id ] ?? 0 );
			$e30   = (int) ( $err30[ $id ] ?? 0 );
			$pPart = $acc * min( 1, $items / 8 );
			$nPart = min( $notice, 5 ) / 5;
			if ( $k['write'] ) {
				$wPart = max( 0, min( 1, ( min( $ok, 5 ) / 5 ) * ( $days >= 2 ? 1 : 0.6 ) - 0.2 * $e14 ) );
				$score = 60 * $pPart + 25 * $wPart + 15 * $nPart;
			} else {
				$wPart = null;
				$score = 75 * $pPart + 25 * $nPart;
			}
			$score    = (int) round( $score );
			$mastered = $score >= 80 && $e14 < 2;
			$ever     = ! empty( $s['ever'] );
			if ( $mastered && ! $ever ) {
				$raw[ $id ]['ever'] = 1;
				$ever               = true;
				$dirty              = true;
				self::xp( $uid, 100, 'mastered:' . $id, false );
				self::coins( $uid, 50 );
				if ( class_exists( 'Sawa_Tracker_RPG' ) ) {
					Sawa_Tracker_RPG::add_elixirs( $uid, 2 );
				}
				if ( class_exists( 'Sawa_Tracker_Game' ) ) {
					Sawa_Tracker_Game::event( $uid, 'mastered', null, array( 'name' => $k['name'] ) );
				}
				do_action( 'sawa_lens_mastered', $uid, $id );
			}
			$passed = $mastered || ( $items >= 8 && $acc >= 0.8 );
			$state[ $id ] = array( 'passed' => $passed || $ever );
			$out[ $id ]   = array(
				'id'       => $id,
				'track'    => $k['track'],
				'name'     => $k['name'],
				'need'     => $k['need'],
				'write'    => $k['write'],
				'score'    => $score,
				'parts'    => array(
					'practice' => (int) round( $pPart * 100 ),
					'writing'  => null === $wPart ? null : (int) round( $wPart * 100 ),
					'notice'   => (int) round( $nPart * 100 ),
				),
				'items'    => $items,
				'accuracy' => (int) round( $acc * 100 ),
				'uses'     => $ok,
				'noticed'  => $notice,
				'errors14' => $e14,
				'errors30' => $e30,
				'sets'     => (int) ( $s['sets'] ?? 0 ),
				'mastered' => $mastered,
				'ever'     => $ever,
				'passed'   => $passed,
			);
		}
		if ( $dirty ) {
			self::save_raw( $uid, $raw );
		}
		// Status needs every point's "passed" first.
		foreach ( $out as $id => &$n ) {
			$needsOk = true;
			$missing = array();
			foreach ( $n['need'] as $req ) {
				if ( empty( $state[ $req ]['passed'] ) ) {
					$needsOk   = false;
					$missing[] = $req;
				}
			}
			$n['missing'] = $missing;
			$n['byErrors'] = false;
			if ( $n['mastered'] ) {
				$n['status'] = 'mastered';
			} elseif ( $n['ever'] && $n['errors14'] >= 2 ) {
				$n['status'] = 'review';
			} elseif ( $needsOk || $n['ever'] ) {
				$n['status'] = 'open';
			} elseif ( $n['errors30'] >= 3 ) {
				// Your writing shows you need it now: open it early.
				$n['status']   = 'open';
				$n['byErrors'] = true;
			} else {
				$n['status'] = 'locked';
			}
		}
		unset( $n );
		return array_values( $out );
	}

	/** What to work on next: needs-work first, then open points with recent errors, then the first open point per track. */
	public static function next_up( $tree ) {
		$review = array_filter( $tree, function ( $n ) { return 'review' === $n['status']; } );
		$errs   = array_filter( $tree, function ( $n ) { return 'open' === $n['status'] && $n['errors30'] > 0; } );
		usort( $errs, function ( $a, $b ) { return $b['errors30'] - $a['errors30']; } );
		$ids = array();
		foreach ( array_merge( array_values( $review ), $errs ) as $n ) {
			$ids[] = $n['id'];
		}
		foreach ( array( 'grammar', 'tone', 'pron' ) as $track ) {
			foreach ( $tree as $n ) {
				if ( $n['track'] === $track && 'open' === $n['status'] ) {
					$ids[] = $n['id'];
					break;
				}
			}
		}
		return array_values( array_slice( array_unique( $ids ), 0, 3 ) );
	}

	/** The learner's own mistakes for a point (newest first), to show and to turn into exercises. */
	public static function own_errors( $uid, $id, $limit = 5 ) {
		global $wpdb;
		$t = Sawa_Lens_Store::t( 'errors' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT wrong, fix, why, why_ar, sentence, fixed, source, created_at FROM $t WHERE user_id = %d AND skill = %s ORDER BY id DESC LIMIT %d", $uid, $id, $limit ), ARRAY_A ); // phpcs:ignore
	}

	/** Latest mistakes (all points), for the library. */
	public static function recent_mistakes( $uid, $limit = 100 ) {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, skill, source, wrong, fix, why, why_ar, sentence, fixed, created_at FROM ' . Sawa_Lens_Store::t( 'errors' ) . ' WHERE user_id = %d ORDER BY id DESC LIMIT %d', $uid, $limit ), ARRAY_A ); // phpcs:ignore
		foreach ( $rows as &$r ) {
			$k               = self::get( $r['skill'] );
			$r['skillName']  = $k ? $k['name'] : 'Other';
			$r['date']       = mysql2date( 'j M', $r['created_at'] );
			unset( $r['created_at'] );
		}
		unset( $r );
		return $rows;
	}

	/**
	 * Is each error type going down? Errors per 100 words, per skill, for the last 8 weeks
	 * (writing and chat together), oldest week first.
	 */
	public static function trends( $uid ) {
		global $wpdb;
		$from  = wp_date( 'Y-m-d H:i:s', time() - 56 * DAY_IN_SECONDS );
		$tw    = Sawa_Lens_Store::t( 'writing' );
		$te    = Sawa_Lens_Store::t( 'errors' );
		$now   = time();
		$week  = function ( $date ) use ( $now ) {
			return max( 0, min( 7, 7 - (int) floor( ( $now - strtotime( $date ) ) / WEEK_IN_SECONDS ) ) );
		};
		$words = array_fill( 0, 8, 0 );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT words, created_at FROM $tw WHERE user_id = %d AND created_at >= %s", $uid, $from ), ARRAY_A ) as $w ) { // phpcs:ignore
			$words[ $week( $w['created_at'] ) ] += (int) $w['words'];
		}
		$per = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT skill, created_at FROM $te WHERE user_id = %d AND created_at >= %s", $uid, $from ), ARRAY_A ) as $e ) { // phpcs:ignore
			if ( ! isset( $per[ $e['skill'] ] ) ) {
				$per[ $e['skill'] ] = array_fill( 0, 8, 0 );
			}
			$per[ $e['skill'] ][ $week( $e['created_at'] ) ]++;
		}
		$out = array();
		foreach ( $per as $skill => $counts ) {
			$rates = array();
			foreach ( $counts as $i => $c ) {
				$rates[] = $words[ $i ] > 0 ? round( $c * 100 / $words[ $i ], 1 ) : null;
			}
			$k     = self::get( $skill );
			$out[] = array(
				'skill' => $skill,
				'name'  => $k ? $k['name'] : 'Other',
				'total' => array_sum( $counts ),
				'rates' => $rates,
			);
		}
		usort( $out, function ( $a, $b ) { return $b['total'] - $a['total']; } );
		return array(
			'words'  => $words,
			'skills' => $out,
		);
	}

	/** Static content of a point for the app (rule, Arabic rule, examples). */
	public static function lesson( $id ) {
		$k = self::get( $id );
		if ( ! $k ) {
			return null;
		}
		return array(
			'id'      => $id,
			'track'   => $k['track'],
			'name'    => $k['name'],
			'rule'    => $k['rule'],
			'rule_ar' => $k['rule_ar'],
			'ex'      => $k['ex'],
		);
	}
}
