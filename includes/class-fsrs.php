<?php
/**
 * FSRS-6 (Free Spaced Repetition Scheduler, 2025): the scheduler in modern Anki, and the best open-source one in
 * the public srs-benchmark. Instead of SM-2's fixed "ease" multiplier it models each card's memory:
 *   stability S  = days until the chance of remembering drops to 90%
 *   difficulty D = 1 (easy) … 10 (hard)
 *   retrievability R = chance of remembering it now, which falls as days pass
 * After each answer it updates S and D, then schedules the next review for the day R reaches the desired retention.
 * Late reviews count for more (the card survived a longer gap), and "Hard" no longer traps cards in short gaps.
 * The formulas and default weights follow the reference implementations (py-fsrs 6 / ts-fsrs 5).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sawa_Lens_FSRS {

	/** Default FSRS-6 weights (trained on hundreds of millions of Anki reviews). */
	const W = array( 0.212, 1.2931, 2.3065, 8.2956, 6.4133, 0.8334, 3.0194, 0.001, 1.8722, 0.1666, 0.796, 1.4835, 0.0614, 0.2629, 1.6483, 0.6014, 1.8729, 0.5425, 0.0912, 0.0658, 0.1542 );

	/** Chance of remembering a card on its review day. 90% is the usual sweet spot of effort vs. forgetting. */
	const RETENTION = 0.9;

	const MAX_IVL = 3650;

	private static function decay() {
		return -self::W[20];
	}

	private static function factor() {
		return pow( 0.9, 1 / self::decay() ) - 1;
	}

	/** Chance of remembering after $t days with stability $s. */
	public static function retrievability( $t, $s ) {
		return pow( 1 + self::factor() * max( 0, $t ) / max( 0.01, $s ), self::decay() );
	}

	/** Days until retrievability falls to the desired retention. */
	public static function interval( $s, $retention = self::RETENTION ) {
		$i = $s / self::factor() * ( pow( $retention, 1 / self::decay() ) - 1 );
		return (int) min( self::MAX_IVL, max( 1, round( $i ) ) );
	}

	private static function init_s( $g ) {
		return max( 0.001, self::W[ $g - 1 ] );
	}

	private static function init_d( $g ) {
		return self::clamp_d( self::W[4] - exp( self::W[5] * ( $g - 1 ) ) + 1 );
	}

	private static function clamp_d( $d ) {
		return min( 10, max( 1, $d ) );
	}

	private static function next_d( $d, $g ) {
		$delta = -self::W[6] * ( $g - 3 );
		$d2    = $d + $delta * ( 10 - $d ) / 9; // linear damping: changes shrink near the top
		return self::clamp_d( self::W[7] * self::init_d( 4 ) + ( 1 - self::W[7] ) * $d2 ); // mean reversion
	}

	private static function recall_s( $d, $s, $r, $g ) {
		$hard = 2 === $g ? self::W[15] : 1;
		$easy = 4 === $g ? self::W[16] : 1;
		return $s * ( 1 + exp( self::W[8] ) * ( 11 - $d ) * pow( $s, -self::W[9] ) * ( exp( self::W[10] * ( 1 - $r ) ) - 1 ) * $hard * $easy );
	}

	private static function forget_s( $d, $s, $r ) {
		$f = self::W[11] * pow( $d, -self::W[12] ) * ( pow( $s + 1, self::W[13] ) - 1 ) * exp( self::W[14] * ( 1 - $r ) );
		return min( $f, $s );
	}

	/** A second answer on the same day (after "Again"): short-term stability. */
	private static function same_day_s( $s, $g ) {
		$inc = exp( self::W[17] * ( $g - 3 + self::W[18] ) ) * pow( $s, -self::W[19] );
		if ( $g >= 3 ) {
			$inc = max( 1, $inc );
		}
		return $s * $inc;
	}

	/**
	 * The memory state after answering. $card: [s, d, reps] (s = 0 for a new card); $elapsed: days since the last review.
	 * Returns [s, d].
	 */
	public static function next( $card, $elapsed, $g ) {
		list( $s, $d, $reps ) = $card;
		if ( $s <= 0 || ! $reps ) {
			return array( self::init_s( $g ), self::init_d( $g ) );
		}
		$d2 = self::next_d( $d, $g );
		if ( $elapsed < 1 ) {
			return array( max( 0.01, self::same_day_s( $s, $g ) ), $d2 );
		}
		$r  = self::retrievability( $elapsed, $s );
		$s2 = 1 === $g ? self::forget_s( $d, $s, $r ) : self::recall_s( $d, $s, $r, $g );
		return array( max( 0.01, $s2 ), $d2 );
	}

	/** Intervals for Hard / Good / Easy, kept in order (Hard < Good < Easy), as the reference scheduler does. */
	public static function intervals( $card, $elapsed ) {
		$out = array();
		foreach ( array( 2, 3, 4 ) as $g ) {
			$out[ $g ] = self::interval( self::next( $card, $elapsed, $g )[0] );
		}
		$out[3] = max( $out[3], $out[2] + 1 );
		$out[4] = max( $out[4], $out[3] + 1 );
		return $out;
	}

	/** A card scheduled by the old SM-2 code: its FSRS memory state, from its interval and ease. */
	public static function from_sm2( $ivl, $ease ) {
		$s = max( 0.5, (float) $ivl );
		$d = 11 - ( ( (float) $ease - 1.3 ) / ( 3.5 - 1.3 ) ) * 9; // ease 1.3 → 11 (hard) … 3.5 → 2 (easy)
		return array( $s, self::clamp_d( $d ) );
	}
}
