<?php
/**
 * "Find English content you'll love": the interests quiz in the Sawa App.
 * Students pick what they enjoy in Arabic (categories, then creators) and get English
 * creators, music, podcasts and books that match. The dataset is data/interests.json;
 * the app reads it as a static file and saves the student's choices here.
 *
 * @package SawaLens
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sawa_Lens_Interests {

	const META = 'sawa_lens_interests';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'journey_flag' ), 10, 3 );
	}

	public static function routes() {
		$can = function () {
			$uid = get_current_user_id();
			return $uid && Sawa_Lens_Auth::allowed( $uid ) && Sawa_Lens_App::is_open_for( $uid );
		};
		register_rest_route( Sawa_Lens_App::NS, '/app/interests', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get' ), 'permission_callback' => $can ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save' ), 'permission_callback' => $can ),
		) );
	}

	/** The student's saved picks, or null before the first quiz. */
	public static function picks( $uid ) {
		$p = get_user_meta( $uid, self::META, true );
		return is_array( $p ) && ! empty( $p['cats'] ) ? $p : null;
	}

	private static function out( $data ) {
		$res = rest_ensure_response( $data );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	public static function get() {
		return self::out( array(
			'picks' => self::picks( get_current_user_id() ),
			'data'  => esc_url_raw( SAWA_LENS_URL . 'data/interests.json?v=' . SAWA_LENS_VER ),
		) );
	}

	/** Known category and creator ids, so only real ids are stored. */
	private static function known() {
		$d = json_decode( (string) file_get_contents( SAWA_LENS_DIR . 'data/interests.json' ), true ); // phpcs:ignore
		$cats = array();
		$en   = array();
		foreach ( (array) ( $d['categories'] ?? array() ) as $c ) {
			$cats[ $c['id'] ] = 1;
			foreach ( (array) $c['en'] as $e ) {
				$en[ $e['id'] ] = 1;
			}
		}
		return array( $cats, (array) ( $d['creators'] ?? array() ), $en );
	}

	public static function save( WP_REST_Request $r ) {
		list( $cats, $creators, $en ) = self::known();
		$clean = function ( $list, $ok, $max ) {
			$out = array();
			foreach ( (array) $list as $id ) {
				$id = sanitize_title( (string) $id );
				if ( isset( $ok[ $id ] ) && ! in_array( $id, $out, true ) ) {
					$out[] = $id;
				}
			}
			return array_slice( $out, 0, $max );
		};
		$p = array(
			'cats'  => $clean( $r->get_param( 'cats' ), $cats, 60 ),
			'liked' => $clean( $r->get_param( 'liked' ), $creators, 200 ),
			'saved' => $clean( $r->get_param( 'saved' ), $en, 100 ), // English picks the student starred
			'at'    => time(),
		);
		if ( ! $p['cats'] ) {
			return new WP_Error( 'interests', 'Pick at least one thing you like.', array( 'status' => 400 ) );
		}
		update_user_meta( get_current_user_id(), self::META, $p );
		return self::out( array( 'picks' => $p ) );
	}

	/** Adds "interests" (quiz done) to the app's journey, for the Start-here step. */
	public static function journey_flag( $res, $server, $req ) {
		if ( $res instanceof WP_REST_Response && '/' . Sawa_Lens_App::NS . '/app/journey' === $req->get_route() && is_array( $res->get_data() ) ) {
			$d              = $res->get_data();
			$d['interests'] = (bool) self::picks( get_current_user_id() );
			$res->set_data( $d );
		}
		return $res;
	}
}
