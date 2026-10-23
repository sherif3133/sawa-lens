<?php
/**
 * Placement test: an adaptive English test (about 25 minutes) that gives a CEFR level (A1–C2), a 0–100 score
 * and a vocabulary size, like EF SET or the Duolingo English Test.
 *
 * Sections:
 *   1. Vocabulary – yes/no checklist of real and made-up words in 1,000-word frequency bands, corrected for
 *      guessing, then a meaning check on a sample of the "yes" words (catches over-claiming).
 *   2. Grammar     – adaptive multiple choice.
 *   3. Reading     – C-tests: short texts with the second half of every second word missing.
 *   4. Listening   – listen and type (dictation).
 *   5. Writing     – optional, members only: a short text graded by AI against the CEFR.
 *
 * Scoring is a Rasch model: every item has a difficulty b (logits); ability θ is the EAP estimate over all
 * answers, and the next item is the one whose difficulty is closest to the current θ. Items start with a
 * difficulty from their CEFR level and are recalibrated from real answers (admin → Placement test).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sawa_Lens_Placement {

	const LEVELS = array( 1 => 'A1', 2 => 'A2', 3 => 'B1', 4 => 'B2', 5 => 'C1', 6 => 'C2' );
	const PSEUDO = array( 'brastic', 'plinder', 'morvish', 'tralent', 'glimber', 'spaunt', 'crendle', 'fruskle', 'dorpish', 'quillate', 'sneavy', 'mallish', 'pronter', 'gaspen', 'flurdish', 'cobblet', 'harpel', 'vorine', 'starnish', 'plosent', 'trindle', 'bleckish', 'grommage', 'snurtle', 'pelfrey', 'contrapt', 'mundial', 'skelving', 'abrance', 'perlance' );
	const GRAMMAR_N = 14;
	const READING_N = 2;
	const LISTEN_N  = 5;
	const PRIOR_SD  = 1.5;  // θ prior: mean 0, SD 1.5 (education/placement-test-spec.md 2d)
	const VOCAB_W   = 4;    // the vocabulary result counts once, as much as 4 grammar questions
	const CUTS      = array( -2, -1, 0, 1, 2 ); // where A2, B1, B2, C1, C2 start
	const BORDER    = 0.25; // θ this close to a cut: borderline, placed in the lower level
	const UP_SE     = 0.4;  // a level goes up on one test only when θ is this far above the cut
	const EXPOSURE_DAYS = 90; // a learner never sees an item again within this many days

	public static function install( $c ) {
		dbDelta(
			'CREATE TABLE ' . Sawa_Lens_Store::t( 'pt_items' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(10) NOT NULL,
			level tinyint(3) unsigned NOT NULL,
			b float NOT NULL DEFAULT 0,
			body longtext NOT NULL,
			active tinyint(1) NOT NULL DEFAULT 1,
			n int(10) unsigned NOT NULL DEFAULT 0,
			score float NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY pick (type,active,b)
			) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . Sawa_Lens_Store::t( 'pt_sessions' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			skey char(32) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ip_hash char(16) NOT NULL DEFAULT '',
			state longtext NOT NULL,
			result longtext,
			started_at datetime NOT NULL,
			finished_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY skey (skey),
			KEY user (user_id)
			) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . Sawa_Lens_Store::t( 'pt_responses' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL,
			item_id bigint(20) unsigned NOT NULL,
			score float NOT NULL,
			theta float NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY item (item_id),
			KEY session (session_id)
			) $c;"
		);
	}

	private static function t( $n ) {
		return Sawa_Lens_Store::t( $n );
	}

	/* ------------------------------------------------------------------ scoring (Rasch) */

	/** Difficulty a CEFR level starts with: A1 −2.5 … C2 2.5. */
	public static function level_b( $level ) {
		return (float) $level - 3.5;
	}

	/** EAP ability over a grid. $resp: [[b, score 0–1, weight]]. */
	public static function eap( $resp, $mean = 0.0, $sd = 1.5 ) {
		$num = 0;
		$den = 0;
		$sq  = 0;
		for ( $th = -5.0; $th <= 5.0001; $th += 0.1 ) {
			$lp = -0.5 * pow( ( $th - $mean ) / $sd, 2 );
			foreach ( $resp as $r ) {
				$p   = 1 / ( 1 + exp( -( $th - $r[0] ) ) );
				$w   = $r[2] ?? 1;
				$lp += $w * ( $r[1] * log( max( 1e-9, $p ) ) + ( 1 - $r[1] ) * log( max( 1e-9, 1 - $p ) ) );
			}
			$l    = exp( $lp );
			$num += $th * $l;
			$sq  += $th * $th * $l;
			$den += $l;
		}
		$m = $den > 0 ? $num / $den : $mean;
		return array( 'theta' => $m, 'se' => $den > 0 ? sqrt( max( 0, $sq / $den - $m * $m ) ) : $sd );
	}

	/** θ → CEFR level 1–6 (cut points halfway between level difficulties). */
	public static function cefr( $th ) {
		$lv = 1;
		foreach ( self::CUTS as $c ) {
			if ( $th >= $c ) {
				$lv++;
			}
		}
		return $lv;
	}

	/** θ → placed level and borderline: within 0.25 of a cut, the lower level ("A2-B1"). */
	public static function place( $th ) {
		foreach ( self::CUTS as $i => $c ) {
			if ( abs( $th - $c ) < self::BORDER ) {
				return array( 'level' => $i + 1, 'borderline' => self::LEVELS[ $i + 1 ] . '-' . self::LEVELS[ $i + 2 ] );
			}
		}
		return array( 'level' => self::cefr( $th ), 'borderline' => '' );
	}

	/**
	 * The level to keep after a test. Levels go up, never down on one test:
	 * up when θ is clearly above the new level's cut (UP_SE) or the last test was above too; down only after two lower tests in a row.
	 * $prev = the last counted result {levelNum (kept), raw (tested)} (or null), $raw = this test's level, $th = its θ. Returns [level, note].
	 */
	public static function decide( $prev, $raw, $th ) {
		if ( ! is_array( $prev ) || empty( $prev['levelNum'] ) ) {
			return array( $raw, '' );
		}
		$p_lv  = (int) $prev['levelNum'];
		$p_raw = (int) ( $prev['raw'] ?? $prev['levelNum'] );
		if ( $raw > $p_lv ) {
			if ( $th - self::CUTS[ $raw - 2 ] > self::UP_SE ) {
				return array( $raw, 'up' );
			}
			return $p_raw > $p_lv ? array( min( $raw, $p_raw ), 'up' ) : array( $p_lv, 'up_next' );
		}
		if ( $raw < $p_lv ) {
			return $p_raw < $p_lv ? array( max( $raw, $p_raw ), 'down' ) : array( $p_lv, 'kept' );
		}
		return array( $p_lv, '' );
	}

	/** All scored answers for θ: grammar, reading, listening, and the vocabulary result once, as its own skill. */
	public static function responses( $s ) {
		$all = array();
		foreach ( array( 'grammar', 'reading', 'listening' ) as $k ) {
			foreach ( (array) ( $s['skills'][ $k ] ?? array() ) as $r ) {
				$all[] = $r;
			}
		}
		if ( isset( $s['vocabTheta'] ) ) {
			$all[] = array( (float) $s['vocabTheta'], 0.5, self::VOCAB_W );
		}
		return $all;
	}

	/** θ → 0–100 score on the same bands as EF SET (A1 1–30, A2 31–40, B1 41–50, B2 51–60, C1 61–70, C2 71–100). */
	public static function score100( $th ) {
		$pts = array( array( -5, 1 ), array( -2, 30.5 ), array( -1, 40.5 ), array( 0, 50.5 ), array( 1, 60.5 ), array( 2, 70.5 ), array( 4, 100 ) );
		if ( $th <= $pts[0][0] ) {
			return 1;
		}
		for ( $i = 1; $i < count( $pts ); $i++ ) {
			if ( $th <= $pts[ $i ][0] ) {
				$a = $pts[ $i - 1 ];
				$b = $pts[ $i ];
				return (int) max( 1, min( 100, round( $a[1] + ( $th - $a[0] ) / ( $b[0] - $a[0] ) * ( $b[1] - $a[1] ) ) ) );
			}
		}
		return 100;
	}

	/* ------------------------------------------------------------------ items */

	private static function vocab_bank() {
		static $v = null;
		if ( null === $v ) {
			$v = json_decode( (string) file_get_contents( SAWA_LENS_DIR . 'assets/placement-vocab.json' ), true ); // phpcs:ignore
		}
		return $v;
	}

	/** The next unused active item of a type, closest in difficulty to θ (a little random among the closest). */
	private static function pick( $type, $theta, $used, $seen = array() ) {
		global $wpdb;
		$rows = array();
		// Items seen in the last 90 days are skipped first; if that leaves nothing of this type, only this test's items are.
		foreach ( $seen ? array( array_merge( $used, $seen ), $used ) : array( $used ) as $skip ) {
			$not  = $skip ? ' AND id NOT IN (' . implode( ',', array_map( 'intval', $skip ) ) . ')' : '';
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, type, level, b, body FROM ' . self::t( 'pt_items' ) . " WHERE type = %s AND active = 1 $not ORDER BY ABS(b - %f) LIMIT 5", $type, $theta ), ARRAY_A ); // phpcs:ignore
			if ( $rows ) {
				break;
			}
		}
		if ( ! $rows ) {
			return null;
		}
		$r         = $rows[ array_rand( $rows ) ];
		$r['body'] = json_decode( $r['body'], true );
		return $r;
	}

	/** Items this learner (or, for visitors, this connection) answered in the last 90 days. */
	public static function seen_items( $uid, $ip ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - self::EXPOSURE_DAYS * DAY_IN_SECONDS );
		$who   = $uid ? $wpdb->prepare( 's.user_id = %d', $uid ) : $wpdb->prepare( 's.user_id = 0 AND s.ip_hash = %s', $ip );
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT r.item_id FROM ' . self::t( 'pt_responses' ) . ' r JOIN ' . self::t( 'pt_sessions' ) . " s ON s.id = r.session_id WHERE $who AND r.created_at > %s", $since ) ) ); // phpcs:ignore
	}

	/** C-test: first sentence whole, then the second half of every second word is removed (max 20 gaps). */
	public static function ctest( $text ) {
		$text  = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
		$first = preg_match( '/^.+?[.!?](\s|$)/u', $text, $m ) ? $m[0] : '';
		$rest  = substr( $text, strlen( $first ) );
		$parts = preg_split( '/(\s+)/', $rest, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out   = array( array( 't' => $first ) );
		$k     = 0;
		$gaps  = 0;
		foreach ( $parts as $p ) {
			if ( '' === $p || ctype_space( $p ) ) {
				$out[] = array( 't' => $p );
				continue;
			}
			preg_match( "/^([A-Za-z']+)(.*)$/", $p, $w );
			if ( $w && strlen( $w[1] ) >= 2 && $gaps < 20 && 1 === $k++ % 2 ) {
				$keep  = substr( $w[1], 0, intdiv( strlen( $w[1] ), 2 ) );
				$out[] = array( 'keep' => $keep, 'full' => $w[1], 'n' => strlen( $w[1] ) - strlen( $keep ) );
				if ( '' !== $w[2] ) {
					$out[] = array( 't' => $w[2] );
				}
				$gaps++;
			} else {
				$out[] = array( 't' => $p );
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ sessions */

	private static function ip_hash() {
		return substr( hash( 'sha256', ( $_SERVER['REMOTE_ADDR'] ?? '' ) . wp_salt() ), 0, 16 ); // phpcs:ignore
	}

	public static function start( $uid, $quick = false ) {
		global $wpdb;
		$ip = self::ip_hash();
		if ( ! $uid ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'pt_sessions' ) . ' WHERE ip_hash = %s AND started_at > %s', $ip, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ); // phpcs:ignore
			if ( $n >= 5 ) {
				return new WP_Error( 'limit', 'You started 5 tests today. Try again tomorrow.', array( 'status' => 429 ) );
			}
		}
		// Vocabulary checklist: 5 words from each band + made-up words.
		$bank  = self::vocab_bank();
		$items = array();
		foreach ( $bank['bands'] as $bi => $band ) {
			$pool = $band['items'];
			shuffle( $pool );
			foreach ( array_slice( $pool, 0, 5 ) as $it ) {
				$items[] = array( 'w' => $it['w'], 'band' => $bi );
			}
		}
		$fakes = self::PSEUDO;
		shuffle( $fakes );
		foreach ( array_slice( $fakes, 0, 12 ) as $f ) {
			$items[] = array( 'w' => $f, 'band' => -1 );
		}
		shuffle( $items );
		$state = array(
			'section' => 'vocab',
			'vocab'   => $items,
			'resp'    => array(),
			'used'    => array(),
			'skills'  => array(),
			'theta'   => 0,
			'quick'   => (bool) $quick,
			'seen'    => self::seen_items( $uid, $ip ),
		);
		$key = wp_generate_password( 32, false );
		$wpdb->insert( self::t( 'pt_sessions' ), array( 'skey' => $key, 'user_id' => (int) $uid, 'ip_hash' => $ip, 'state' => wp_json_encode( $state ), 'started_at' => current_time( 'mysql' ) ) ); // phpcs:ignore
		return array( 'key' => $key ) + self::step( $state );
	}

	private static function load( $key ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'pt_sessions' ) . ' WHERE skey = %s', (string) $key ), ARRAY_A ); // phpcs:ignore
		if ( ! $row ) {
			return null;
		}
		$row['state'] = json_decode( $row['state'], true );
		return $row;
	}

	private static function save( $row ) {
		global $wpdb;
		$wpdb->update( self::t( 'pt_sessions' ), array( 'state' => wp_json_encode( $row['state'] ) ), array( 'id' => $row['id'] ) ); // phpcs:ignore
	}

	/** What the learner sees next (no answers included). */
	private static function step( $s ) {
		$sec = $s['section'];
		$n   = array( 'vocab' => 1, 'meaning' => 1, 'grammar' => 2, 'reading' => 3, 'listening' => 4, 'writing' => 5 );
		$base = array( 'section' => $sec, 'part' => $n[ $sec ] ?? 5, 'parts' => 5, 'quick' => ! empty( $s['quick'] ) );
		if ( 'vocab' === $sec ) {
			return $base + array( 'words' => array_map( function ( $i ) { return $i['w']; }, $s['vocab'] ) );
		}
		if ( 'meaning' === $sec ) {
			return $base + array( 'items' => array_map( function ( $m ) { return array( 'w' => $m['w'], 'options' => $m['options'] ); }, $s['meaning'] ) );
		}
		$cur = $s['cur'] ?? null;
		if ( ! $cur ) {
			return $base;
		}
		$body = $cur['body'];
		if ( 'grammar' === $sec ) {
			return $base + array( 'q' => $body['q'], 'options' => $body['options'], 'i' => count( $s['skills']['grammar'] ?? array() ) + 1, 'of' => ! empty( $s['quick'] ) ? 10 : self::GRAMMAR_N );
		}
		if ( 'reading' === $sec ) {
			return $base + array( 'title' => $body['title'] ?? '', 'parts_text' => array_map( function ( $p ) { return isset( $p['keep'] ) ? array( 'keep' => $p['keep'], 'n' => $p['n'] ) : array( 't' => $p['t'] ); }, $body['ctest'] ), 'i' => count( $s['skills']['reading'] ?? array() ) + 1, 'of' => self::READING_N );
		}
		if ( 'listening' === $sec ) {
			return $base + array( 'say' => $body['text'], 'i' => count( $s['skills']['listening'] ?? array() ) + 1, 'of' => self::LISTEN_N );
		}
		if ( 'writing' === $sec ) {
			return $base + array( 'prompt' => $cur['prompt'] );
		}
		return $base;
	}

	private static function next_item( &$s, $type ) {
		$it = self::pick( $type, $s['theta'], $s['used'], (array) ( $s['seen'] ?? array() ) );
		if ( ! $it ) {
			return false;
		}
		$s['used'][] = (int) $it['id'];
		$s['cur']    = $it;
		return true;
	}

	private static function log_resp( $row, $item_id, $score, $theta ) {
		global $wpdb;
		$wpdb->insert( self::t( 'pt_responses' ), array( 'session_id' => $row['id'], 'item_id' => $item_id, 'score' => $score, 'theta' => $theta, 'created_at' => current_time( 'mysql' ) ) ); // phpcs:ignore
	}

	private static function norm( $s ) {
		return strtolower( preg_replace( "/[^a-z0-9' ]+/i", '', str_replace( '’', "'", (string) $s ) ) );
	}

	/** Word-level accuracy of a dictation (longest common subsequence / words in the sentence). */
	private static function dictation_score( $want, $got ) {
		$a = preg_split( '/\s+/', trim( self::norm( $want ) ), -1, PREG_SPLIT_NO_EMPTY );
		$b = preg_split( '/\s+/', trim( self::norm( $got ) ), -1, PREG_SPLIT_NO_EMPTY );
		$n = count( $a );
		$m = count( $b );
		if ( ! $n ) {
			return 0;
		}
		$dp = array_fill( 0, $n + 1, array_fill( 0, $m + 1, 0 ) );
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			for ( $j = $m - 1; $j >= 0; $j-- ) {
				$dp[ $i ][ $j ] = $a[ $i ] === $b[ $j ] ? $dp[ $i + 1 ][ $j + 1 ] + 1 : max( $dp[ $i + 1 ][ $j ], $dp[ $i ][ $j + 1 ] );
			}
		}
		return $dp[0][0] / max( $n, $m );
	}

	/** Takes an answer for the current step and returns the next step, or the result. */
	public static function answer( $key, $answer, $uid ) {
		$row = self::load( $key );
		if ( ! $row ) {
			return new WP_Error( 'session', 'This test session has ended. Start again.', array( 'status' => 404 ) );
		}
		if ( $row['finished_at'] ) {
			return array( 'done' => true, 'result' => json_decode( $row['result'], true ) );
		}
		$s   = &$row['state'];
		$sec = $s['section'];
		if ( null === $answer ) {
			return self::step( $s ); // resuming: just show where they are
		}

		if ( 'vocab' === $sec ) {
			$yes  = (array) $answer;
			$bank = self::vocab_bank();
			$fa_n = 0;
			$fa_y = 0;
			$band = array();
			foreach ( $s['vocab'] as $i => $it ) {
				$y = ! empty( $yes[ $i ] );
				if ( $it['band'] < 0 ) {
					$fa_n++;
					$fa_y += $y ? 1 : 0;
				} else {
					$band[ $it['band'] ][] = $y ? 1 : 0;
				}
			}
			$fa         = $fa_n ? $fa_y / $fa_n : 0;
			$s['fa']    = $fa;
			$s['bandH'] = array();
			foreach ( $band as $bi => $hits ) {
				$h                 = array_sum( $hits ) / count( $hits );
				$s['bandH'][ $bi ] = $fa >= 1 ? 0 : max( 0, min( 1, ( $h - $fa ) / ( 1 - $fa ) ) );
			}
			// Meaning check: up to 8 "yes" words across the bands, 4 Arabic meanings each.
			$yes_words = array();
			foreach ( $s['vocab'] as $i => $it ) {
				if ( $it['band'] >= 0 && ! empty( $yes[ $i ] ) ) {
					$yes_words[] = $it;
				}
			}
			usort( $yes_words, function ( $a, $b ) { return $b['band'] - $a['band']; } );
			$pick = array();
			$step = max( 1, count( $yes_words ) / 8 );
			for ( $k = 0; $k < count( $yes_words ) && count( $pick ) < 8; $k += $step ) {
				$pick[] = $yes_words[ (int) floor( $k ) ];
			}
			$s['meaning'] = array();
			foreach ( $pick as $it ) {
				$items = $bank['bands'][ $it['band'] ]['items'];
				$right = null;
				foreach ( $items as $x ) {
					if ( $x['w'] === $it['w'] ) {
						$right = $x;
					}
				}
				if ( ! $right ) {
					continue;
				}
				$others = array_values( array_filter( $items, function ( $x ) use ( $right ) { return $x['w'] !== $right['w'] && $x['ar'] !== $right['ar']; } ) );
				shuffle( $others );
				$opts = array_merge( array( $right['ar'] ), array_map( function ( $x ) { return $x['ar']; }, array_slice( $others, 0, 3 ) ) );
				shuffle( $opts );
				$s['meaning'][] = array( 'w' => $it['w'], 'band' => $it['band'], 'options' => $opts, 'answer' => array_search( $right['ar'], $opts, true ) );
			}
			$s['section'] = $s['meaning'] ? 'meaning' : 'grammar';
			if ( ! $s['meaning'] ) {
				self::finish_vocab( $s, 1.0 );
				self::next_item( $s, 'mc' );
			}
		} elseif ( 'meaning' === $sec ) {
			$ans = (array) $answer;
			$ok  = 0;
			foreach ( $s['meaning'] as $i => $m ) {
				$ok += isset( $ans[ $i ] ) && (int) $ans[ $i ] === (int) $m['answer'] ? 1 : 0;
			}
			$acc = $ok / count( $s['meaning'] );
			self::finish_vocab( $s, max( 0, min( 1, ( $acc - 0.25 ) / 0.75 ) ) );
			$s['section'] = 'grammar';
			if ( ! self::next_item( $s, 'mc' ) ) {
				$s['section'] = 'reading';
				self::next_item( $s, 'ctest' );
			}
		} elseif ( 'grammar' === $sec ) {
			$cur = $s['cur'];
			$ok  = (int) $answer === (int) $cur['body']['answer'] ? 1 : 0;
			self::add( $s, 'grammar', (float) $cur['b'], $ok, 1 );
			self::log_resp( $row, $cur['id'], $ok, $s['theta'] );
			if ( ! empty( $s['quick'] ) && count( $s['skills']['grammar'] ) >= 10 ) {
				return self::finish( $row ); // quick check: vocabulary + 10 grammar questions
			}
			if ( count( $s['skills']['grammar'] ) >= self::GRAMMAR_N || ! self::next_item( $s, 'mc' ) ) {
				$s['section'] = 'reading';
				if ( ! self::next_item( $s, 'ctest' ) ) {
					$s['section'] = 'listening';
					if ( ! self::next_item( $s, 'dictation' ) ) {
						$s['section'] = 'writing'; // no reading or listening items: straight to writing
					}
				}
			}
		} elseif ( 'reading' === $sec ) {
			$cur  = $s['cur'];
			$ans  = array_values( (array) $answer );
			$gaps = array_values( array_filter( $cur['body']['ctest'], function ( $p ) { return isset( $p['keep'] ); } ) );
			$ok   = 0;
			foreach ( $gaps as $i => $g ) {
				$ok += strtolower( $g['keep'] . trim( (string) ( $ans[ $i ] ?? '' ) ) ) === strtolower( $g['full'] ) ? 1 : 0;
			}
			$frac = $gaps ? $ok / count( $gaps ) : 0;
			// Each gap counts as a small item; together they weigh about as much as 4 grammar questions.
			self::add( $s, 'reading', (float) $cur['b'], $frac, 4 );
			self::log_resp( $row, $cur['id'], $frac, $s['theta'] );
			if ( count( $s['skills']['reading'] ) >= self::READING_N || ! self::next_item( $s, 'ctest' ) ) {
				$s['section'] = 'listening';
				if ( ! self::next_item( $s, 'dictation' ) ) {
					$s['section'] = 'writing';
				}
			}
		} elseif ( 'listening' === $sec ) {
			$cur   = $s['cur'];
			$score = self::dictation_score( $cur['body']['text'], (string) $answer );
			self::add( $s, 'listening', (float) $cur['b'], $score >= 0.85 ? 1 : ( $score >= 0.6 ? 0.5 : 0 ), 1.5 );
			self::log_resp( $row, $cur['id'], $score, $s['theta'] );
			if ( count( $s['skills']['listening'] ) >= self::LISTEN_N || ! self::next_item( $s, 'dictation' ) ) {
				$s['section'] = 'writing';
			}
		} elseif ( 'writing' === $sec ) {
			if ( is_array( $answer ) && ! empty( $answer['text'] ) && $uid && Sawa_Lens_AI::ready() ) {
				$w = Sawa_Lens_AI::grade_writing( $uid, (string) $answer['text'], (string) ( $s['cur']['prompt'] ?? '' ) );
				if ( ! is_wp_error( $w ) ) {
					$s['writing'] = $w;
				}
			}
			return self::finish( $row );
		}
		if ( 'writing' === $s['section'] && empty( $s['cur']['prompt'] ) ) {
			if ( ! $uid ) {
				return self::finish( $row ); // writing is graded by AI: members only
			}
			$lv          = self::cefr( $s['theta'] );
			$prompts     = array( 1 => 'Write about your family and where you live (40–60 words).', 2 => 'Write an email to a friend about your last weekend (60–80 words).', 3 => 'What do you like and dislike about your city? Give reasons (80–120 words).', 4 => 'Some people say social media makes us lonely. Do you agree? Explain your opinion (120–160 words).', 5 => 'Describe a decision that changed your life and what you learned from it (150–200 words).', 6 => 'Should universities be free for everyone? Discuss both sides and give your view (180–220 words).' );
			$s['cur']    = array( 'prompt' => $prompts[ $lv ], 'level' => $lv );
		}
		self::save( $row );
		return self::step( $s );
	}

	/** Adds a scored answer to a skill and updates θ (the vocabulary result counts once, inside responses()). */
	private static function add( &$s, $skill, $b, $score, $w ) {
		$s['skills'][ $skill ][] = array( $b, $score, $w );
		$s['theta'] = self::eap( self::responses( $s ), 0, self::PRIOR_SD )['theta'];
	}

	private static function finish_vocab( &$s, $verify ) {
		$bank = self::vocab_bank();
		$size = 0;
		foreach ( $bank['bands'] as $bi => $band ) {
			$size += ( $band['to'] - $band['from'] + 1 ) * ( $s['bandH'][ $bi ] ?? 0 ) * ( 0.4 + 0.6 * $verify );
		}
		$s['vocabSize']   = (int) round( $size / 50 ) * 50;
		$s['vocabVerify'] = $verify;
		// Vocabulary size → starting θ (Milton 2010: ~1,500 A1/A2, ~2,750 B1, ~3,250 B2, ~3,750 C1, ~4,500 C2 of the top 5,000).
		$cuts = array( array( 0, -3 ), array( 1500, -2 ), array( 2500, -1 ), array( 3250, 0 ), array( 4000, 1 ), array( 5000, 2 ), array( 7000, 3 ) );
		$th   = -3;
		for ( $i = 1; $i < count( $cuts ); $i++ ) {
			if ( $size >= $cuts[ $i - 1 ][0] ) {
				$a  = $cuts[ $i - 1 ];
				$b  = $cuts[ $i ];
				$th = $a[1] + min( 1, ( $size - $a[0] ) / ( $b[0] - $a[0] ) ) * ( $b[1] - $a[1] );
			}
		}
		$s['vocabTheta'] = $th;
		$s['theta']      = $th;
	}

	/** The result of a finished test from its answers only (no storage), so the same answers always give the same θ. */
	public static function score( $s ) {
		$skills = array();
		foreach ( array( 'grammar', 'reading', 'listening' ) as $k ) {
			$r = (array) ( $s['skills'][ $k ] ?? array() );
			if ( $r ) {
				$e            = self::eap( $r, 0, self::PRIOR_SD );
				$skills[ $k ] = array( 'level' => self::LEVELS[ self::cefr( $e['theta'] ) ], 'score' => self::score100( $e['theta'] ) );
			}
		}
		$skills['vocabulary'] = array( 'level' => self::LEVELS[ self::cefr( $s['vocabTheta'] ?? -3 ) ], 'score' => self::score100( $s['vocabTheta'] ?? -3 ), 'size' => (int) ( $s['vocabSize'] ?? 0 ) );
		$e  = self::eap( self::responses( $s ), 0, self::PRIOR_SD );
		$pl = self::place( $e['theta'] );
		return array(
			'level'      => self::LEVELS[ $pl['level'] ],
			'levelNum'   => $pl['level'],
			'raw'        => $pl['level'],
			'borderline' => $pl['borderline'],
			'score'      => self::score100( $e['theta'] ),
			'theta'      => round( $e['theta'], 2 ),
			'se'         => round( $e['se'], 2 ),
			'margin'     => (int) round( 10 * $e['se'] ) + ( ! empty( $s['quick'] ) ? 4 : 0 ),
			'skills'     => $skills,
			'vocab'      => (int) ( $s['vocabSize'] ?? 0 ),
		);
	}

	/** The vocabulary bands this test found (top 1k … 6k), scaled by the meaning check, for Language Lens's profile. */
	public static function vocab_bands( $s ) {
		$bank  = self::vocab_bank();
		$bands = array();
		foreach ( array_slice( $bank['bands'], 0, 6 ) as $bi => $b ) {
			$bands[] = round( ( $s['bandH'][ $bi ] ?? 0 ) * ( 0.4 + 0.6 * (float) ( $s['vocabVerify'] ?? 1 ) ), 3 );
		}
		return Sawa_Lens_Store::smooth_bands( $bands );
	}

	private static function finish( $row ) {
		global $wpdb;
		$s      = $row['state'];
		$result = self::score( $s );
		if ( ! empty( $s['writing'] ) ) {
			$result['skills']['writing'] = $s['writing'];
		}
		$result += array(
			'tested' => $result['level'], // this test's own level; 'level' is the one kept (see decide())
			'note'   => '',
			'date'   => wp_date( 'j F Y' ),
			'beta'   => self::calibrated_share() < 0.6,
			'quick'  => ! empty( $s['quick'] ),
			'saved'  => false,
		);
		$t = (int) round( microtime( true ) * 1000 );
		if ( $row['user_id'] ) {
			$uid  = (int) $row['user_id'];
			$prev = get_user_meta( $uid, 'sawa_lens_placement', true );
			// A quick check never replaces a full test. Between tests of the same kind, levels go up but never drop on one test.
			if ( empty( $s['quick'] ) || ! is_array( $prev ) || ! empty( $prev['quick'] ) ) {
				$same              = is_array( $prev ) && ! empty( $prev['quick'] ) === ! empty( $s['quick'] );
				list( $lv, $note ) = self::decide( $same ? $prev : null, (int) $result['raw'], (float) $result['theta'] );
				$result['level']    = self::LEVELS[ $lv ];
				$result['levelNum'] = $lv;
				$result['note']     = $note;
				$result['saved']    = true;
				Sawa_Lens_Store::set_level( $uid, $lv, $t );
				update_user_meta( $uid, 'sawa_lens_placement', $result );
			}
			Sawa_Lens_Activity::add( $uid, '', 1, 'Others', ! empty( $s['quick'] ) ? 8 : 20 );
			$bands = self::vocab_bands( $s );
			Sawa_Lens_Store::put_vocab( $uid, array( 'date' => wp_date( 'Y-m-d' ), 'estimate' => min( 6000, (int) ( $s['vocabSize'] ?? 0 ) ), 'bands' => $bands, 'falseAlarms' => (float) ( $s['fa'] ?? 0 ), 't' => $t, 'src' => ! empty( $s['quick'] ) ? 'quick' : 'placement' ) );
			// Every test keeps its own record, so the app can show the before and after.
			$hist   = (array) get_user_meta( $uid, 'sawa_lens_placement_history', true );
			$hist[] = array(
				'date'   => wp_date( 'Y-m-d' ),
				't'      => $t,
				'form'   => ! empty( $s['quick'] ) ? 'quick' : 'full',
				'theta'  => $result['theta'],
				'se'     => $result['se'],
				'level'  => $result['tested'],
				'placed' => $result['level'],
				'border' => $result['borderline'],
				'skills' => $result['skills'],
				'vocab'  => array( 'size' => $result['vocab'], 'bands' => $bands ),
			);
			update_user_meta( $uid, 'sawa_lens_placement_history', array_slice( array_values( array_filter( $hist, 'is_array' ) ), -50 ) );
		}
		$wpdb->update( self::t( 'pt_sessions' ), array( 'state' => wp_json_encode( $s ), 'result' => wp_json_encode( $result ), 'finished_at' => current_time( 'mysql' ) ), array( 'id' => $row['id'] ) ); // phpcs:ignore
		return array( 'done' => true, 'result' => $result );
	}

	/** Share of items with at least 30 answers (when most are calibrated, the "beta" label goes away). */
	public static function calibrated_share() {
		global $wpdb;
		$t   = self::t( 'pt_items' );
		$all = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE active = 1" ); // phpcs:ignore
		$cal = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE active = 1 AND n >= 30" ); // phpcs:ignore
		return $all ? $cal / $all : 0;
	}

	/**
	 * Recalibrates item difficulties from real answers (Rasch, one pass): for items with 30+ answers,
	 * b = mean θ of the people who took it − logit(share correct). Returns how many items changed.
	 */
	public static function recalibrate() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT item_id, COUNT(*) n, AVG(score) p, AVG(theta) th FROM ' . self::t( 'pt_responses' ) . ' GROUP BY item_id', ARRAY_A ); // phpcs:ignore
		$n    = 0;
		foreach ( (array) $rows as $r ) {
			$p   = max( 0.03, min( 0.97, (float) $r['p'] ) );
			$upd = array( 'n' => (int) $r['n'], 'score' => (float) $r['p'] );
			if ( (int) $r['n'] >= 30 ) {
				$upd['b'] = round( (float) $r['th'] - log( $p / ( 1 - $p ) ), 2 );
				$n++;
			}
			$wpdb->update( self::t( 'pt_items' ), $upd, array( 'id' => (int) $r['item_id'] ) ); // phpcs:ignore
		}
		return $n;
	}

	/* ------------------------------------------------------------------ item bank */

	public static function add_item( $type, $level, $body ) {
		global $wpdb;
		if ( 'ctest' === $type ) {
			$body['ctest'] = self::ctest( $body['text'] ?? '' );
		}
		$wpdb->insert( self::t( 'pt_items' ), array( 'type' => $type, 'level' => $level, 'b' => self::level_b( $level ), 'body' => wp_json_encode( $body ), 'created_at' => current_time( 'mysql' ) ) ); // phpcs:ignore
	}

	public static function counts() {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT type, level, COUNT(*) n FROM ' . self::t( 'pt_items' ) . ' WHERE active = 1 GROUP BY type, level', ARRAY_A ) as $r ) { // phpcs:ignore
			$out[ $r['type'] ][ (int) $r['level'] ] = (int) $r['n'];
		}
		return $out;
	}
}
