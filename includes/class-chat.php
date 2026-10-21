<?php
/**
 * Bridge to the Sawa Tracker chat: transcripts of voice messages and private feedback on a learner's own
 * messages. Feedback errors go into the same error log as the writing checker (source "chat" or "voice"),
 * so the skill tree and the "are my mistakes going down" trends include what learners say and write in chat.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sawa_Lens_Chat {

	public static function init() {
		add_filter( 'sawa_tracker_chat_ai', array( __CLASS__, 'features' ), 10, 2 );
		add_filter( 'sawa_tracker_transcribe', array( __CLASS__, 'transcribe' ), 10, 3 );
		add_filter( 'sawa_tracker_feedback', array( __CLASS__, 'feedback' ), 10, 5 );
		add_filter( 'sawa_tracker_translate', array( __CLASS__, 'translate' ), 10, 3 );
		add_filter( 'sawa_tracker_battle_ready', function () { return array_sum( (array) ( Sawa_Lens_Placement::counts()['mc'] ?? array() ) ) >= 10; } );
		add_filter( 'sawa_tracker_battle_questions', array( __CLASS__, 'battle_questions' ), 10, 4 );
		add_filter( 'sawa_tracker_word_count', function ( $n, $uid ) { global $wpdb; return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Sawa_Lens_Store::t( 'words' ) . " WHERE user_id = %d AND status = 'learning'", $uid ) ); }, 10, 2 ); // phpcs:ignore
		add_action( 'sawa_tracker_missed_card', array( __CLASS__, 'missed_card' ), 10, 2 );
		add_filter( 'sawa_tracker_mastery', function ( $m, $uid ) { return Sawa_Lens_Skills::mastery_counts( $uid ); }, 10, 2 );
	}

	public static function features( $f, $uid ) {
		$s = Sawa_Lens_AI::settings();
		return array(
			'transcribe' => '' !== $s['stt_key'],
			'feedback'   => Sawa_Lens_AI::ready() && Sawa_Lens_Auth::allowed( $uid ),
			'translate'  => Sawa_Lens_AI::ready() && Sawa_Lens_Auth::allowed( $uid ),
		);
	}

	/**
	 * Battle questions: grammar questions from the level test's bank, around the student's level.
	 * $opt['level'] (1–6) asks for a fixed level (town bosses); $opt['weak'] mixes in questions on the student's
	 * weakest open skill points, taken from practice sets that were already made (no AI call).
	 */
	public static function battle_questions( $qs, $uid, $n, $opt = array() ) {
		global $wpdb;
		$opt    = (array) $opt;
		$target = count( $qs ) + (int) $n;
		if ( ! empty( $opt['own'] ) ) {
			foreach ( self::own_questions( is_array( $opt['own'] ) ? $opt['own'] : array( $uid ), (int) ceil( $n * 0.5 ) ) as $q ) {
				$qs[] = $q;
			}
		}
		if ( ! empty( $opt['weak'] ) ) {
			$nodes = array_filter( Sawa_Lens_Skills::tree( $uid ), function ( $k ) { return in_array( $k['status'] ?? '', array( 'open', 'review' ), true ); } );
			usort( $nodes, function ( $a, $b ) { return $a['score'] <=> $b['score']; } );
			$pool = array();
			foreach ( array_slice( $nodes, 0, 4 ) as $k ) {
				for ( $v = 0; $v < 6; $v++ ) {
					$set = Sawa_Lens_AI::cached_set( $k['id'], $v );
					foreach ( (array) ( $set['items'] ?? array() ) as $it ) {
						if ( 'choose' === ( $it['type'] ?? '' ) && ! empty( $it['options'] ) && isset( $it['answer'] ) ) {
							$pool[] = array( 'q' => $it['q'], 'options' => array_values( $it['options'] ), 'answer' => (int) $it['answer'], 'point' => $k['name'], 'why' => $it['why'] ?? '' );
						}
					}
				}
			}
			shuffle( $pool );
			foreach ( array_slice( $pool, 0, max( 0, (int) ceil( $n * ( empty( $opt['own'] ) ? 0.6 : 0.8 ) ) - count( $qs ) ) ) as $q ) {
				$qs[] = $q;
			}
			if ( count( $qs ) >= $target ) {
				return $qs;
			}
		}
		$pl  = get_user_meta( $uid, 'sawa_lens_placement', true );
		$lv  = ! empty( $opt['level'] ) ? (int) $opt['level'] : ( is_array( $pl ) ? (int) $pl['levelNum'] : max( 2, Sawa_Lens_Store::level( $uid ) ) );
		$b   = Sawa_Lens_Placement::level_b( $lv );
		$lo  = ! empty( $opt['level'] ) ? $b - 0.5 : $b - 1.2;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT body FROM ' . Sawa_Lens_Store::t( 'pt_items' ) . " WHERE type = 'mc' AND active = 1 AND b BETWEEN %f AND %f ORDER BY RAND() LIMIT %d", $lo, $b + 0.6, $target - count( $qs ) ), ARRAY_A ); // phpcs:ignore
		foreach ( (array) $rows as $r ) {
			$q    = json_decode( $r['body'], true );
			$qs[] = array( 'q' => $q['q'], 'options' => $q['options'], 'answer' => (int) $q['answer'] );
		}
		if ( count( $qs ) < $target && ! empty( $opt['level'] ) ) {
			return self::battle_questions( $qs, $uid, $target - count( $qs ) ); // not enough at that level yet: fill around the student's level
		}
		shuffle( $qs );
		return $qs;
	}

	/**
	 * Questions from what the students are learning themselves, no AI needed:
	 * - their learning words, as a gap in the sentence they met the word in (YouTube, an article…), with their
	 *   other words as the wrong options — words that are due for review come first;
	 * - their own past mistakes from writing, chat and speaking: "Which sentence is correct?".
	 * Each question carries its review card key, so a missed word can come back in today's review.
	 */
	public static function own_questions( $uids, $n ) {
		global $wpdb;
		$words = array();
		$mist  = array();
		foreach ( array_map( 'intval', (array) $uids ) as $uid ) {
			$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT w.lemma, w.ctx, w.ctx_title FROM ' . Sawa_Lens_Store::t( 'words' ) . ' w LEFT JOIN ' . Sawa_Lens_Store::t( 'cards' ) . " c ON c.user_id = w.user_id AND c.ckey = CONCAT('w:', w.lemma) WHERE w.user_id = %d AND w.status = 'learning' AND w.ctx <> '' ORDER BY COALESCE(c.due, '2000-01-01'), RAND() LIMIT 25", $uid ), ARRAY_A ); // phpcs:ignore
			$other = $wpdb->get_col( $wpdb->prepare( 'SELECT lemma FROM ' . Sawa_Lens_Store::t( 'words' ) . " WHERE user_id = %d AND status IN ('learning','known') ORDER BY RAND() LIMIT 60", $uid ) ); // phpcs:ignore
			foreach ( (array) $rows as $r ) {
				$q = self::word_gap( $r, $other );
				if ( $q ) {
					$q['user'] = $uid;
					if ( count( (array) $uids ) > 1 ) {
						$q['point'] = 'A word your party is learning';
					}
					$words[]   = $q;
				}
			}
			foreach ( count( (array) $uids ) > 1 ? array() : (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, sentence, fixed, why FROM ' . Sawa_Lens_Store::t( 'errors' ) . " WHERE user_id = %d AND sentence <> '' AND fixed <> '' AND sentence <> fixed AND CHAR_LENGTH(sentence) < 160 ORDER BY id DESC LIMIT 12", $uid ), ARRAY_A ) as $e ) { // phpcs:ignore
				$opts   = array( $e['fixed'], $e['sentence'] );
				shuffle( $opts );
				$mist[] = array( 'q' => 'Which sentence is correct?', 'options' => $opts, 'answer' => array_search( $e['fixed'], $opts, true ), 'point' => 'Your own mistake', 'why' => $e['why'], 'user' => $uid );
			}
		}
		// Mostly words, some mistakes, a little random so every fight is different.
		$out = array();
		$mw  = array_slice( $words, 0, 12 );
		shuffle( $mw );
		shuffle( $mist );
		while ( count( $out ) < $n && ( $mw || $mist ) ) {
			$out[] = $mist && ( ! $mw || 2 === count( $out ) % 3 ) ? array_shift( $mist ) : array_shift( $mw );
		}
		return $out;
	}

	/** A gap in the sentence where the student met the word: "She ___ the bus." [missed / word / …]. */
	private static function word_gap( $r, $other ) {
		$lemma = (string) $r['lemma'];
		$stem  = strlen( $lemma ) > 4 && 'e' === substr( $lemma, -1 ) ? substr( $lemma, 0, -1 ) : $lemma;
		if ( ! preg_match( '/\b(' . preg_quote( $stem, '/' ) . '[a-z]{0,4})\b/i', $r['ctx'], $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$form = $m[1][0];
		$pos  = $m[1][1];
		$ctx  = $r['ctx'];
		$a    = max( 0, $pos - 110 );
		$text = ( $a ? '…' : '' ) . substr( $ctx, $a, $pos - $a ) . '___' . substr( $ctx, $pos + strlen( $form ), 110 ) . ( strlen( $ctx ) > $pos + strlen( $form ) + 110 ? '…' : '' );
		$wrong = array();
		foreach ( (array) $other as $o ) {
			if ( strtolower( $o ) !== strtolower( $lemma ) && strtolower( $o ) !== strtolower( $form ) && ! in_array( $o, $wrong, true ) ) {
				$wrong[] = $o;
			}
			if ( count( $wrong ) >= 2 ) {
				break;
			}
		}
		if ( count( $wrong ) < 2 ) {
			return null; // not enough words yet to make good wrong options
		}
		$opts = array_merge( array( strtolower( $form ) ), $wrong );
		shuffle( $opts );
		return array( 'q' => trim( preg_replace( '/\s+/', ' ', $text ) ), 'options' => $opts, 'answer' => array_search( strtolower( $form ), $opts, true ), 'point' => 'Your word', 'src' => mb_substr( (string) $r['ctx_title'], 0, 80 ), 'card' => 'w:' . $lemma, 'why' => '"' . $form . '" — a word you are learning.' );
	}

	/** A word missed in a battle comes back in today's review. */
	public static function missed_card( $uid, $ckey ) {
		global $wpdb;
		$wpdb->update( Sawa_Lens_Store::t( 'cards' ), array( 'due' => Sawa_Lens_Store::today() ), array( 'user_id' => (int) $uid, 'ckey' => (string) $ckey ) ); // phpcs:ignore
	}

	public static function translate( $default, $text, $uid ) {
		if ( ! Sawa_Lens_AI::ready() || ! Sawa_Lens_Auth::allowed( $uid ) ) {
			return $default;
		}
		return Sawa_Lens_AI::chat_translate( $uid, $text );
	}

	public static function transcribe( $default, $path, $uid ) {
		return Sawa_Lens_AI::transcribe( $uid, $path );
	}

	public static function feedback( $default, $text, $uid, $source, $msg_id ) {
		if ( ! Sawa_Lens_AI::ready() || ! Sawa_Lens_Auth::allowed( $uid ) ) {
			return $default;
		}
		$text = trim( wp_strip_all_tags( (string) $text ) );
		if ( count( preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) ) < 3 || ! preg_match( '/[a-z]{2}/i', $text ) ) {
			return new WP_Error( 'short', 'الرسالة قصيرة أوي. الرأي بيشتغل على جملة إنجليزي كاملة على الأقل.' );
		}
		// One analysis per message: asking again shows the same feedback and doesn't log the errors twice.
		$key  = 'sawa_lens_chatfb_' . (int) $msg_id . '_' . substr( md5( $text ), 0, 8 );
		$res  = get_user_meta( $uid, $key, true );
		if ( ! is_array( $res ) ) {
			$res = Sawa_Lens_AI::check_writing( $uid, $text, 'voice' === $source ? 'voice' : 'chat' );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$src = 'voice' === $source ? 'voice' : 'chat';
			global $wpdb;
			$wpdb->insert( // phpcs:ignore
				Sawa_Lens_Store::t( 'writing' ),
				array(
					'user_id'    => $uid,
					'source'     => $src,
					'body'       => $text,
					'result'     => wp_json_encode( $res ),
					'words'      => count( preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) ),
					'errors'     => count( $res['errors'] ),
					'created_at' => current_time( 'mysql' ),
				)
			);
			Sawa_Lens_Skills::log_errors( $uid, $res['errors'], $src, (int) $wpdb->insert_id );
			Sawa_Lens_Skills::record_uses( $uid, $res['good'] );
			update_user_meta( $uid, $key, $res );
		}
		// Explanations only for points the learner has open (same rule as the writing checker).
		$status = array();
		foreach ( Sawa_Lens_Skills::tree( $uid ) as $n ) {
			$status[ $n['id'] ] = $n;
		}
		foreach ( $res['errors'] as &$e ) {
			$n              = $status[ $e['skill'] ] ?? null;
			$e['locked']    = $n && 'locked' === $n['status'];
			$e['skillName'] = $n ? $n['name'] : '';
			if ( $e['locked'] ) {
				$e['why']    = '';
				$e['why_ar'] = '';
			}
		}
		unset( $e );
		$res['app'] = Sawa_Lens_App::url();
		return $res;
	}
}
