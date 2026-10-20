<?php
/**
 * REST API for the extension: /wp-json/sawa-lens/v1/…
 * Auth is a device token (Authorization: Bearer … or X-Sawa-Token), never cookies,
 * so there are no nonces and no CSRF surface.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sawa_Lens_Rest {

	const NS = 'sawa-lens/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter(
			'sawa_tracker_front_cfg',
			function ( $cfg, $uid ) {
				if ( $uid && Sawa_Lens_Auth::allowed( $uid ) ) {
					$cfg['lens'] = array(
						'progress' => esc_url_raw( rest_url( self::NS . '/web/progress' ) ),
						'install'  => (string) Sawa_Lens_AI::settings()['store_url'],
					);
				}
				return $cfg;
			},
			10,
			2
		);
		add_filter(
			'rest_allowed_cors_headers',
			function ( $h ) {
				$h[] = 'X-Sawa-Token';
				return $h;
			}
		);
	}

	public static function routes() {
		$auth = array( __CLASS__, 'auth' );
		$r    = function ( $path, $method, $cb ) use ( $auth ) {
			register_rest_route(
				self::NS,
				$path,
				array(
					'methods'             => $method,
					'callback'            => $cb,
					'permission_callback' => $auth,
				)
			);
		};
		$r( '/me', 'GET', array( __CLASS__, 'me' ) );
		$r( '/settings', 'POST', array( __CLASS__, 'save_settings' ) );
		$r( '/sync', 'POST', array( __CLASS__, 'sync' ) );
		$r( '/explain', 'POST', array( __CLASS__, 'explain' ) );
		$r( '/translate', 'POST', array( __CLASS__, 'translate' ) );
		$r( '/logout', 'POST', array( __CLASS__, 'logout' ) );
		// For the tracker page (normal WordPress login + nonce, not a device token).
		register_rest_route(
			self::NS,
			'/web/progress',
			array(
				'methods'             => 'GET',
				'callback'            => function () {
					return self::out( Sawa_Lens_Store::progress( get_current_user_id(), 14 ) );
				},
				'permission_callback' => function () {
					return is_user_logged_in() && Sawa_Lens_Auth::allowed( get_current_user_id() );
				},
			)
		);
	}

	public static function auth( WP_REST_Request $r ) {
		$uid = Sawa_Lens_Auth::user_from_request( $r );
		if ( ! $uid ) {
			return new WP_Error( 'signed_out', 'سجّل الدخول مرة أخرى من Language Lens.', array( 'status' => 401 ) );
		}
		if ( ! Sawa_Lens_Auth::allowed( $uid ) ) {
			return new WP_Error( 'not_member', 'Language Lens لأعضاء سوا انجليش المفعّلين.', array( 'status' => 403 ) );
		}
		wp_set_current_user( $uid );
		return true;
	}

	private static function out( $data ) {
		$res = rest_ensure_response( $data );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	public static function me() {
		$uid = get_current_user_id();
		$u   = wp_get_current_user();
		return self::out(
			array(
				'id'       => $uid,
				'name'     => $u->display_name,
				'firstName' => $u->first_name ? $u->first_name : $u->display_name,
				'avatar'   => get_avatar_url( $uid, array( 'size' => 96 ) ),
				'ai'       => Sawa_Lens_AI::ready(),
				'tracker'  => self::tracker_url(),
				'progress' => Sawa_Lens_Store::progress( $uid, 14 ),
			)
		);
	}

	private static function tracker_url() {
		$p = get_option( 'sawa_tr_page', '' );
		if ( is_numeric( $p ) ) {
			$p = get_permalink( (int) $p );
		}
		return $p ? $p : home_url( '/tracker/' );
	}

	public static function save_settings( WP_REST_Request $r ) {
		$level = (int) $r->get_param( 'level' );
		if ( $level >= 1 && $level <= 6 ) {
			update_user_meta( get_current_user_id(), 'sawa_lens_level', $level );
		}
		return self::out( array( 'level' => Sawa_Lens_Store::level( get_current_user_id() ) ) );
	}

	/**
	 * Push local changes, pull everything newer than the device's cursor.
	 * Body: { since: 0, words: [...], sentences: [...] }
	 */
	public static function sync( WP_REST_Request $r ) {
		$uid   = get_current_user_id();
		$since = max( 0, (int) $r->get_param( 'since' ) );
		$pushW = (array) $r->get_param( 'words' );
		$pushS = (array) $r->get_param( 'sentences' );
		$level = (int) $r->get_param( 'level' );
		if ( $level >= 1 && $level <= 6 ) {
			update_user_meta( $uid, 'sawa_lens_level', $level );
		}
		Sawa_Lens_Store::put_words( $uid, $pushW );
		Sawa_Lens_Store::put_sentences( $uid, $pushS );
		$words     = Sawa_Lens_Store::words_since( $uid, $since );
		$sentences = Sawa_Lens_Store::sentences_since( $uid, $since );
		$cursor    = $since;
		foreach ( $words as $w ) {
			$cursor = max( $cursor, (int) $w['change_id'] );
		}
		foreach ( $sentences as $s ) {
			$cursor = max( $cursor, (int) $s['change_id'] );
		}
		return self::out(
			array(
				'cursor'    => $cursor,
				'more'      => count( $words ) >= 5000 || count( $sentences ) >= 2000,
				'level'     => Sawa_Lens_Store::level( $uid ),
				'words'     => array_map(
					function ( $w ) {
						return array(
							'lemma'  => $w['lemma'],
							'status' => $w['status'],
							't'      => (int) $w['t'],
						);
					},
					$words
				),
				'sentences' => $sentences,
				'progress'  => Sawa_Lens_Store::progress( $uid, 14 ),
			)
		);
	}

	public static function explain( WP_REST_Request $r ) {
		$res = Sawa_Lens_AI::explain( get_current_user_id(), $r->get_param( 'word' ), $r->get_param( 'sentence' ), (string) $r->get_param( 'lang' ) );
		return is_wp_error( $res ) ? $res : self::out( $res );
	}

	public static function translate( WP_REST_Request $r ) {
		$res = Sawa_Lens_AI::translate( get_current_user_id(), $r->get_param( 'sentence' ) );
		return is_wp_error( $res ) ? $res : self::out( $res );
	}

	public static function logout() {
		Sawa_Lens_Auth::revoke_current();
		return self::out( array( 'ok' => true ) );
	}
}
