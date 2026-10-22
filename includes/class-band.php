<?php
/**
 * The word storyline, band 1: the 500 most frequent English words.
 * Progress is the student's real vocabulary: words marked known, and review cards held for 21+ days (FSRS stability).
 * Every day the road offers 10 band words they don't have yet; they tap to add them to their cards (nothing is added by itself).
 * Later bands (501–1000 …) come when they are needed: the beta changes often, so nothing is planned further.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sawa_Lens_Band {

	const LONG_TERM = 21; // days of stability: a card that is in long-term memory
	const DAILY     = 10;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			Sawa_Lens_App::NS,
			'/app/band',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get' ),
				'permission_callback' => function () {
					$uid = get_current_user_id();
					return $uid && Sawa_Lens_Auth::allowed( $uid ) && Sawa_Lens_App::is_open_for( $uid );
				},
			)
		);
	}

	public static function words() {
		static $w = null;
		if ( null === $w ) {
			$j = json_decode( (string) file_get_contents( SAWA_LENS_DIR . 'data/band-1.json' ), true ); // phpcs:ignore
			$w = array_values( (array) ( $j['words'] ?? array() ) );
		}
		return $w;
	}

	/** [lemma => 'known' | 'learning'] for this student's band words. */
	public static function mine( $uid ) {
		global $wpdb;
		$words = self::words();
		if ( ! $words ) {
			return array();
		}
		$in  = implode( ',', array_fill( 0, count( $words ), '%s' ) );
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT lemma, status FROM ' . Sawa_Lens_Store::t( 'words' ) . " WHERE user_id = %d AND status IN ('known','learning') AND lemma IN ($in)", array_merge( array( $uid ), $words ) ), ARRAY_A ) as $r ) { // phpcs:ignore
			$out[ $r['lemma'] ] = $r['status'];
		}
		$keys = array_map( function ( $w ) { return 'w:' . $w; }, $words );
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT ckey FROM ' . Sawa_Lens_Store::t( 'cards' ) . " WHERE user_id = %d AND stab >= %d AND ckey IN ($in)", array_merge( array( $uid, self::LONG_TERM ), $keys ) ) ) as $k ) { // phpcs:ignore
			$out[ substr( $k, 2 ) ] = 'known';
		}
		return $out;
	}

	public static function get() {
		$uid   = get_current_user_id();
		$words = self::words();
		$mine  = self::mine( $uid );
		$level = (int) Sawa_Lens_Store::level( $uid ) ?: 2;
		$lv    = class_exists( 'Sawa_Lens_Feeds' ) ? Sawa_Lens_Feeds::levels() : array();
		// Words below the student's level (A1 words for an A2 student) count as known: nobody marks "the" or "be".
		$easy  = array_filter( $words, function ( $w ) use ( $mine, $lv, $level ) { return ! isset( $mine[ $w ] ) && isset( $lv[ $w ] ) && $lv[ $w ] < $level; } );
		$marked = count( array_filter( $mine, function ( $s ) { return 'known' === $s; } ) );
		$known  = $marked + count( $easy );
		$open  = array_values( array_filter( $words, function ( $w ) use ( $mine, $easy ) { return ! isset( $mine[ $w ] ) && ! in_array( $w, $easy, true ); } ) );
		$today = array_slice( $open, 0, self::DAILY ); // the most frequent ones first
		return rest_ensure_response(
			array(
				'band'     => 1,
				'from'     => 1,
				'to'       => count( $words ),
				'known'    => $known,
				'fromLevel' => count( $easy ),
				'level'    => array( '', 'A1', 'A2', 'B1', 'B2', 'C1', 'C2' )[ max( 1, min( 6, $level ) ) ],
				'learning' => count( $mine ) - $marked,
				'today'    => $today,
			)
		);
	}
}
