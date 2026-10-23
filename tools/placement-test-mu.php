<?php
/**
 * Test helper for tools/placement-test.js: LOCAL PLAYGROUND ONLY (mounted as a mu-plugin, never uploaded).
 * Routes under /sawa-pt-test/v1 run placement tests and /sync as made-up users, with automatic answers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'rest_api_init',
	function () {
		$open = function () { return true; };
		$r    = function ( $path, $cb ) use ( $open ) {
			register_rest_route( 'sawa-pt-test/v1', $path, array( 'methods' => 'POST', 'callback' => $cb, 'permission_callback' => $open ) );
		};
		$r( '/reset', 'sawa_pt_test_reset' );
		$r( '/score', function ( $q ) { return Sawa_Lens_Placement::score( (array) $q->get_param( 'state' ) ); } );
		$r( '/decide', function ( $q ) { return Sawa_Lens_Placement::decide( $q->get_param( 'prev' ), (int) $q->get_param( 'raw' ), (float) $q->get_param( 'th' ) ); } );
		$r( '/place', function ( $q ) { return Sawa_Lens_Placement::place( (float) $q->get_param( 'th' ) ); } );
		$r( '/run', 'sawa_pt_test_run' );
		$r( '/age', function ( $q ) {
			global $wpdb;
			$uid = (int) $q->get_param( 'uid' );
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Sawa_Lens_Store::t( 'pt_sessions' ) . ' WHERE user_id = %d', $uid ) ); // phpcs:ignore
			foreach ( $ids as $id ) {
				$wpdb->update( Sawa_Lens_Store::t( 'pt_responses' ), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - (int) $q->get_param( 'days' ) * DAY_IN_SECONDS ) ), array( 'session_id' => (int) $id ) ); // phpcs:ignore
			}
			return count( $ids );
		} );
		$r( '/sync', function ( $q ) {
			wp_set_current_user( (int) $q->get_param( 'uid' ) );
			$req = new WP_REST_Request( 'POST', '/sawa-lens/v1/sync' );
			foreach ( (array) $q->get_param( 'body' ) as $k => $v ) {
				$req->set_param( $k, $v );
			}
			return Sawa_Lens_Rest::sync( $req );
		} );
		$r( '/meta', function ( $q ) {
			$uid = (int) $q->get_param( 'uid' );
			return array( 'level' => Sawa_Lens_Store::level( $uid ), 'placement' => get_user_meta( $uid, 'sawa_lens_placement', true ), 'history' => get_user_meta( $uid, 'sawa_lens_placement_history', true ), 'profile' => Sawa_Lens_Store::vocab_profile( $uid ) );
		} );
	}
);

/** Makes (or clears) the test users and a small grammar bank: 10 items per level. */
function sawa_pt_test_reset() {
	global $wpdb;
	$t = Sawa_Lens_Store::t( 'pt_items' );
	if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE type = 'mc'" ) < 60 ) { // phpcs:ignore
		for ( $lv = 1; $lv <= 6; $lv++ ) {
			for ( $k = 0; $k < 10; $k++ ) {
				Sawa_Lens_Placement::add_item( 'mc', $lv, array( 'q' => "Test L$lv #$k ___", 'options' => array( 'right', 'wrong1', 'wrong2', 'wrong3' ), 'answer' => 0 ) );
			}
		}
	}
	$out = array();
	foreach ( array( 'pt_a', 'pt_b', 'pt_c' ) as $login ) {
		$u   = get_user_by( 'login', $login );
		$uid = $u ? $u->ID : wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login . '@example.test' ) );
		foreach ( array( 'sawa_lens_level', 'sawa_lens_level_t', 'sawa_lens_vocab', 'sawa_lens_placement', 'sawa_lens_placement_history' ) as $m ) {
			delete_user_meta( $uid, $m );
		}
		$sids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Sawa_Lens_Store::t( 'pt_sessions' ) . ' WHERE user_id = %d', $uid ) ); // phpcs:ignore
		foreach ( $sids as $sid ) {
			$wpdb->delete( Sawa_Lens_Store::t( 'pt_responses' ), array( 'session_id' => (int) $sid ) ); // phpcs:ignore
		}
		$wpdb->delete( Sawa_Lens_Store::t( 'pt_sessions' ), array( 'user_id' => $uid ) ); // phpcs:ignore
		$out[ $login ] = $uid;
	}
	return $out;
}

/** Runs a whole test as $uid with "strong" or "weak" answers; returns the result, the items used and the items it skipped as seen. */
function sawa_pt_test_run( $q ) {
	global $wpdb;
	$uid    = (int) $q->get_param( 'uid' );
	$strong = 'strong' === $q->get_param( 'kind' );
	wp_set_current_user( $uid );
	$res = Sawa_Lens_Placement::start( $uid, (bool) $q->get_param( 'quick' ) );
	$key = $res['key'];
	for ( $guard = 0; $guard < 80 && empty( $res['done'] ); $guard++ ) {
		$s = json_decode( $wpdb->get_var( $wpdb->prepare( 'SELECT state FROM ' . Sawa_Lens_Store::t( 'pt_sessions' ) . ' WHERE skey = %s', $key ) ), true ); // phpcs:ignore
		$sec = $s['section'];
		if ( 'vocab' === $sec ) {
			$a = array_map( function ( $it ) use ( $strong ) { return $it['band'] < 0 ? 0 : ( $strong || $it['band'] <= 1 ? 1 : 0 ); }, $s['vocab'] );
		} elseif ( 'meaning' === $sec ) {
			$a = array_map( function ( $m ) use ( $strong ) { return $strong ? $m['answer'] : ( $m['answer'] + 1 ) % 4; }, $s['meaning'] );
		} elseif ( 'grammar' === $sec ) {
			$a = $strong ? (int) $s['cur']['body']['answer'] : ( (int) $s['cur']['body']['answer'] + 1 ) % 4;
		} elseif ( 'reading' === $sec ) {
			$a = array_map( function ( $p ) use ( $strong ) { return $strong ? substr( $p['full'], strlen( $p['keep'] ) ) : ''; }, array_values( array_filter( $s['cur']['body']['ctest'], function ( $p ) { return isset( $p['keep'] ); } ) ) );
		} elseif ( 'listening' === $sec ) {
			$a = $strong ? $s['cur']['body']['text'] : '';
		} else {
			$a = array();
		}
		$res = Sawa_Lens_Placement::answer( $key, $a, $uid );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
	}
	$s = json_decode( $wpdb->get_var( $wpdb->prepare( 'SELECT state FROM ' . Sawa_Lens_Store::t( 'pt_sessions' ) . ' WHERE skey = %s', $key ) ), true ); // phpcs:ignore
	return array( 'result' => $res['result'] ?? null, 'used' => $s['used'], 'seen' => $s['seen'] ?? array(), 'state' => $s );
}
