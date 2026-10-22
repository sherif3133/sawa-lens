<?php
/**
 * Daily learning numbers that don't fill the review queue: words a learner was curious about (looked up and
 * understood, without saving them), words read on websites and words heard in videos. Curiosity earns XP, so
 * exploring feels rewarding without marking too many words as Learning (which is capped each day).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sawa_Lens_Daily {

	/**
	 * XP for each new word a learner is curious about. No daily limit: each word pays once, ever, so curiosity
	 * can't be farmed. (New Learning words are what's capped, in the extension, so reviews don't pile up.)
	 */
	const CURIOUS_XP = 2;

	private static function today() {
		return wp_date( 'Y-m-d' );
	}

	/** [day => {read, heard, curious}] for the last 60 days. */
	private static function days( $uid ) {
		$d = get_user_meta( $uid, 'sawa_lens_days', true );
		return is_array( $d ) ? $d : array();
	}

	private static function save_days( $uid, $d ) {
		ksort( $d );
		update_user_meta( $uid, 'sawa_lens_days', array_slice( $d, -60, null, true ) );
	}

	private static function blank() {
		return array( 'read' => 0, 'heard' => 0, 'curious' => 0 );
	}

	/** "I was curious about this word": tracked (hidden from reviews), and XP the first time. */
	public static function curious( $uid, $lemma ) {
		$lemma = mb_substr( strtolower( trim( preg_replace( '/[^\p{L}\' -]+/u', '', (string) $lemma ) ) ), 0, 60 );
		if ( '' === $lemma ) {
			return new WP_Error( 'empty', 'No word.', array( 'status' => 400 ) );
		}
		$seen = get_user_meta( $uid, 'sawa_lens_curious', true );
		$seen = is_array( $seen ) ? $seen : array();
		$day  = self::today();
		$d    = self::days( $uid );
		$t    = ( $d[ $day ] ?? array() ) + self::blank();
		$xp   = 0;
		$new  = ! isset( $seen[ $lemma ] );
		if ( $new ) {
			$seen[ $lemma ] = $day;
			if ( count( $seen ) > 30000 ) {
				$seen = array_slice( $seen, -30000, null, true );
			}
			update_user_meta( $uid, 'sawa_lens_curious', $seen );
			++$t['curious'];
			if ( class_exists( 'Sawa_Tracker_XP' ) ) {
				$xp = Sawa_Tracker_XP::award( $uid, self::CURIOUS_XP, 'curious', false ); // outside the tracker's daily XP cap
			}
			$d[ $day ] = $t;
			self::save_days( $uid, $d );
		}
		return array( 'new' => $new, 'xp' => (int) $xp, 'today' => (int) $t['curious'], 'total' => count( $seen ) );
	}

	/** Words read and heard since the last report (the extension sends small additions). */
	public static function add( $uid, $read, $heard ) {
		$day = self::today();
		$d   = self::days( $uid );
		$t   = ( $d[ $day ] ?? array() ) + self::blank();
		$t['read']  += max( 0, min( 50000, (int) $read ) );
		$t['heard'] += max( 0, min( 50000, (int) $heard ) );
		$d[ $day ]   = $t;
		self::save_days( $uid, $d );
		return self::summary( $uid );
	}

	/** Today, the last 7 days, and all time. */
	public static function summary( $uid ) {
		$d    = self::days( $uid );
		$day  = self::today();
		$week = self::blank();
		$all  = self::blank();
		$from = wp_date( 'Y-m-d', strtotime( '-6 days' ) );
		foreach ( $d as $k => $v ) {
			foreach ( $all as $f => $n ) {
				$all[ $f ] += (int) ( $v[ $f ] ?? 0 );
				if ( $k >= $from ) {
					$week[ $f ] += (int) ( $v[ $f ] ?? 0 );
				}
			}
		}
		$seen = get_user_meta( $uid, 'sawa_lens_curious', true );
		return array(
			'today'        => ( $d[ $day ] ?? array() ) + self::blank(),
			'week'         => $week,
			'all'          => $all,
			'curiousTotal' => is_array( $seen ) ? count( $seen ) : 0,
		);
	}
}
