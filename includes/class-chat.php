<?php
/**
 * Bridge to the Sawa Tracker chat: transcripts of voice messages and private feedback on a learner's own
 * messages. Feedback errors go into the same error log as the writing checker (source "chat" or "voice"),
 * so the skill tree and the "are my mistakes going down" trends include what learners say and write in chat.
 *
 * Chat English is also checked automatically, without the learner asking: English messages queue up per learner,
 * and once a day an hourly job checks them in one batch (one AI call per learner per day, with a daily cap for the
 * whole site). Errors go to the error log (source "chat"), right uses pay the improvement bonus, and the learner gets
 * a private notification. Nothing is shown in the room.
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
		add_filter( 'sawa_tracker_words_since', function ( $n, $uid, $from ) { global $wpdb; return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Sawa_Lens_Store::t( 'words' ) . ' WHERE user_id = %d AND learning_on >= %s', $uid, $from ) ); }, 10, 3 ); // phpcs:ignore
		add_action( 'sawa_tracker_missed_card', array( __CLASS__, 'missed_card' ), 10, 2 );
		add_filter( 'sawa_tracker_mastery', function ( $m, $uid ) { return Sawa_Lens_Skills::mastery_counts( $uid ); }, 10, 2 );
		add_action( 'sawa_tracker_message_sent', array( __CLASS__, 'queue' ), 30, 5 );
		add_filter( 'sawa_tracker_feedback_summary', function ( $out, $notes ) { return Sawa_Lens_AI::ready() ? Sawa_Lens_AI::feedback_summary( $notes ) : new WP_Error( 'off', 'Add the Claude API key in Sawa Lens settings first.' ); }, 10, 2 );
		add_action( 'sawa_lens_chat_review', array( __CLASS__, 'review_all' ) );
		if ( ! wp_next_scheduled( 'sawa_lens_chat_review' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'sawa_lens_chat_review' );
		}
	}

	const QUEUE_MAX  = 30;   // messages kept per learner until the next check
	const BATCH_MIN  = 3;    // a check needs at least this many messages (or one day of waiting)
	const BATCH_CHARS = 900; // text sent in one check
	const SITE_DAY   = 150;  // checks a day for the whole site

	/** A chat message in English (5+ words) waits for the daily check. */
	public static function queue( $uid, $room_id, $msg_id, $body, $audio ) {
		$text = trim( wp_strip_all_tags( (string) $body ) );
		if ( '' === $text || '0' === (string) get_option( 'sawa_lens_chat_auto', '1' ) || ! Sawa_Lens_Auth::allowed( $uid ) ) {
			return;
		}
		if ( count( preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) ) < 5 || preg_match( '/[\x{0600}-\x{06FF}]/u', $text ) || ! preg_match( '/[a-z]{2}/i', $text ) ) {
			return;
		}
		$q = get_user_meta( $uid, 'sawa_lens_chatq', true );
		$q = is_array( $q ) ? $q : array( 'since' => time(), 'msgs' => array() );
		if ( in_array( $text, $q['msgs'], true ) ) {
			return; // the same text twice is checked once
		}
		$q['msgs'][] = mb_substr( $text, 0, 400 );
		$q['msgs']   = array_slice( $q['msgs'], -self::QUEUE_MAX );
		update_user_meta( $uid, 'sawa_lens_chatq', $q );
	}

	/** The hourly job: learners with enough waiting messages, not checked today, until the site's daily cap. */
	public static function review_all() {
		global $wpdb;
		if ( ! Sawa_Lens_AI::ready() || '0' === (string) get_option( 'sawa_lens_chat_auto', '1' ) ) {
			return 0;
		}
		$day  = Sawa_Lens_Store::today();
		$used = get_option( 'sawa_lens_chatq_day', array() );
		$used = is_array( $used ) && ( $used['day'] ?? '' ) === $day ? $used : array( 'day' => $day, 'n' => 0 );
		$done = 0;
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT 200", 'sawa_lens_chatq' ) ) as $uid ) { // phpcs:ignore
			if ( $used['n'] >= self::SITE_DAY ) {
				break;
			}
			if ( self::review( (int) $uid ) ) {
				++$used['n'];
				++$done;
			}
		}
		update_option( 'sawa_lens_chatq_day', $used, false );
		return $done;
	}

	/** One learner's waiting chat English, checked in one call. Returns true when a check ran. */
	public static function review( $uid, $force = false ) {
		global $wpdb;
		$q = get_user_meta( $uid, 'sawa_lens_chatq', true );
		if ( ! is_array( $q ) || ! $q['msgs'] ) {
			return false;
		}
		$day = Sawa_Lens_Store::today();
		if ( ! $force && ( get_user_meta( $uid, 'sawa_lens_chatq_last', true ) === $day || ( count( $q['msgs'] ) < self::BATCH_MIN && time() - (int) $q['since'] < DAY_IN_SECONDS ) ) ) {
			return false;
		}
		$text = '';
		foreach ( $q['msgs'] as $m ) {
			if ( strlen( $text ) + strlen( $m ) > self::BATCH_CHARS ) {
				break;
			}
			$text .= ( '' === $text ? '' : "\n" ) . $m;
		}
		$res = Sawa_Lens_AI::check_writing( $uid, $text, 'chat' );
		if ( is_wp_error( $res ) ) {
			return false;
		}
		delete_user_meta( $uid, 'sawa_lens_chatq' );
		update_user_meta( $uid, 'sawa_lens_chatq_last', $day );
		$wpdb->insert( Sawa_Lens_Store::t( 'writing' ), array( 'user_id' => $uid, 'source' => 'chat', 'body' => $text, 'result' => wp_json_encode( $res ), 'words' => count( preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) ), 'errors' => count( $res['errors'] ), 'created_at' => current_time( 'mysql' ) ) ); // phpcs:ignore
		Sawa_Lens_Skills::log_errors( $uid, $res['errors'], 'chat', (int) $wpdb->insert_id );
		Sawa_Lens_Skills::record_uses( $uid, $res['good'] ); // right uses of points you got wrong before pay a bonus
		if ( class_exists( 'Sawa_Tracker_Game' ) ) {
			$names = array();
			foreach ( Sawa_Lens_Skills::tree( $uid ) as $n ) {
				$names[ $n['id'] ] = $n['name'];
			}
			$top = array_count_values( array_map( function ( $e ) { return $e['skill']; }, $res['errors'] ) );
			arsort( $top );
			Sawa_Tracker_Game::event( 0, 'chat_review', $uid, array( 'msgs' => count( $q['msgs'] ), 'errors' => count( $res['errors'] ), 'good' => array_sum( (array) $res['good'] ), 'focus' => $top ? ( $names[ key( $top ) ] ?? key( $top ) ) : '' ) );
		}
		return true;
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
				return self::unique( $qs );
			}
		}
		$pl  = get_user_meta( $uid, 'sawa_lens_placement', true );
		$lv  = ! empty( $opt['level'] ) ? (float) $opt['level'] : ( is_array( $pl ) ? (int) $pl['levelNum'] : max( 2, Sawa_Lens_Store::level( $uid ) ) );
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
		return self::unique( $qs );
	}

	/** No question twice in one fight (the same sentence can come from two sources). */
	private static function unique( $qs ) {
		$seen = array();
		$out  = array();
		foreach ( $qs as $q ) {
			$k = strtolower( preg_replace( '/\W+/', '', (string) $q['q'] ) ) . '|' . implode( '|', (array) ( $q['options'] ?? array() ) );
			if ( 'Which sentence is correct?' !== $q['q'] && isset( $seen[ $k ] ) ) {
				continue;
			}
			$seen[ $k ] = 1;
			$out[]      = $q;
		}
		shuffle( $out );
		return $out;
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
			foreach ( self::phrase_gaps( $uid ) as $q ) { // saved phrases and sentences make questions too
				$words[] = $q;
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

	/**
	 * Saved phrases and sentences with a focus ("give up", "on the other hand"): the phrase becomes the gap, and the
	 * student's other saved phrases are the wrong options. Words due for review come first elsewhere; these add variety.
	 */
	private static function phrase_gaps( $uid ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT uuid, body, focus, source_title FROM ' . Sawa_Lens_Store::t( 'sentences' ) . " WHERE user_id = %d AND deleted = 0 AND focus <> '' AND CHAR_LENGTH(body) < 220 ORDER BY RAND() LIMIT 6", $uid ), ARRAY_A ); // phpcs:ignore
		$all  = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT focus FROM ' . Sawa_Lens_Store::t( 'sentences' ) . " WHERE user_id = %d AND deleted = 0 AND focus <> '' ORDER BY RAND() LIMIT 30", $uid ) ); // phpcs:ignore
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$focus = trim( (string) $r['focus'] );
			$pos   = stripos( $r['body'], $focus );
			if ( false === $pos || str_word_count( $focus ) > 5 ) {
				continue;
			}
			$wrong = array_slice( array_values( array_filter( (array) $all, function ( $x ) use ( $focus ) { return strtolower( trim( $x ) ) !== strtolower( $focus ); } ) ), 0, 2 );
			if ( count( $wrong ) < 2 ) {
				continue; // not enough saved phrases yet for good wrong options
			}
			$opts  = array_merge( array( substr( $r['body'], $pos, strlen( $focus ) ) ), $wrong );
			shuffle( $opts );
			$out[] = array( 'q' => trim( substr( $r['body'], 0, $pos ) . '___' . substr( $r['body'], $pos + strlen( $focus ) ) ), 'options' => $opts, 'answer' => array_search( substr( $r['body'], $pos, strlen( $focus ) ), $opts, true ), 'point' => 'Your phrase', 'src' => mb_substr( (string) $r['source_title'], 0, 80 ), 'card' => 's:' . $r['uuid'], 'why' => '"' . $focus . '" — a phrase you saved.', 'user' => $uid );
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
			if ( 'ar' !== Sawa_Lens_AI::explain_lang( $uid ) ) {
				$e['why_ar'] = ''; // English by default; Arabic only for students who chose العربية
			}
		}
		unset( $e );
		$res['app'] = Sawa_Lens_App::url();
		return $res;
	}
}
