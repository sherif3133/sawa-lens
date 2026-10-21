<?php
/**
 * Automatic study time: what learners do in the app and the extension counts as study in the tracker,
 * so they don't have to log it by hand (streak, habit health, the weekly boss and SP all move).
 *
 * Each action adds an estimated number of minutes to today's tally for one tracker activity; every full
 * 5 minutes becomes a real tracker log entry. Capped per day so nothing can be farmed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sawa_Lens_Activity {

	const META      = 'sawa_lens_auto';
	const DAILY_CAP = 180;

	/** Estimated minutes for each kind of action, and the tracker activity it counts as. */
	const RULES = array(
		'card'     => array( 'Flashcards', 0.25 ),
		'quiz'     => array( 'Active Immersion', 1 ),
		'practice' => array( 'Pronunciation/Grammar', 3 ),
		'saved'    => array( 'Active Immersion', 0.5 ),
		'scan'     => array( 'Active Immersion', 2 ),
	);

	/**
	 * Adds study time. $kind is a key of RULES, or pass $activity + $minutes directly.
	 * Returns the minutes that became tracker log entries (0 most of the time).
	 */
	public static function add( $uid, $kind, $times = 1, $activity = '', $minutes = 0.0 ) {
		if ( ! $uid || ! class_exists( 'Sawa_Tracker_Game' ) || ! Sawa_Tracker_Game::is_member( $uid ) ) {
			return 0;
		}
		do_action( 'sawa_lens_activity', $uid, $kind ? $kind : 'use', $times );
		if ( isset( self::RULES[ $kind ] ) ) {
			$activity = self::RULES[ $kind ][0];
			$minutes  = self::RULES[ $kind ][1] * max( 0, (float) $times );
		}
		if ( ! $activity || $minutes <= 0 ) {
			return 0;
		}
		$day = Sawa_Tracker_Game::today();
		$m   = get_user_meta( $uid, self::META, true );
		if ( ! is_array( $m ) || ( $m['day'] ?? '' ) !== $day ) {
			$m = array( 'day' => $day, 'acc' => array(), 'logged' => array(), 'cards' => 0 );
		}
		$total_logged = array_sum( $m['logged'] );
		if ( $total_logged >= self::DAILY_CAP ) {
			return 0;
		}
		$m['acc'][ $activity ] = (float) ( $m['acc'][ $activity ] ?? 0 ) + $minutes;
		if ( 'card' === $kind ) {
			$m['cards'] += (int) $times;
		}
		$due = (int) ( floor( ( $m['acc'][ $activity ] - (int) ( $m['logged'][ $activity ] ?? 0 ) ) / 5 ) * 5 );
		$due = min( $due, self::DAILY_CAP - $total_logged );
		$done = 0;
		if ( $due >= 5 ) {
			$cards = 'Flashcards' === $activity ? (int) $m['cards'] : 0;
			$res   = Sawa_Tracker_Game::add_log( $uid, $activity, $due, $cards, 'Auto · Sawa English app', $day, true );
			if ( ! is_wp_error( $res ) ) {
				$m['logged'][ $activity ] = (int) ( $m['logged'][ $activity ] ?? 0 ) + $due;
				if ( 'Flashcards' === $activity ) {
					$m['cards'] = 0;
				}
				$done = $due;
			}
		}
		update_user_meta( $uid, self::META, $m );
		return $done;
	}

	/** Minutes counted today (logged + still adding up), for the app's Today tab. */
	public static function today( $uid ) {
		$m = get_user_meta( $uid, self::META, true );
		if ( ! is_array( $m ) || ! class_exists( 'Sawa_Tracker_Game' ) || ( $m['day'] ?? '' ) !== Sawa_Tracker_Game::today() ) {
			return 0;
		}
		return (int) round( array_sum( $m['acc'] ) );
	}
}
