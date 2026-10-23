<?php
/**
 * "Send feedback" inside Language Lens. The note goes to the same inbox as the Sawa app's Beta feedback
 * (wp-admin → Sawa Tracker → Beta feedback), under the screen "Language Lens", so there is one place to read it.
 * Works signed in (device token) and signed out (optional email). 5 notes an hour per student or address.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sawa_Lens_Feedback {

	const AREA  = 'Language Lens';
	const LIMIT = 5;
	// Language Lens type -> the tracker's reaction kind
	const KINDS = array( 'bug' => 'bug', 'idea' => 'idea', 'confusing' => 'confused', 'like' => 'like' );

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route( Sawa_Lens_Rest::NS, '/feedback', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'add' ), 'permission_callback' => '__return_true' ) );
	}

	public static function add( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! class_exists( 'Sawa_Tracker_Feedback' ) ) {
			return new WP_Error( 'feedback_off', 'Feedback is not available right now. Please try again later.', array( 'status' => 503 ) );
		}
		$type = sanitize_key( (string) $r->get_param( 'type' ) );
		if ( ! isset( self::KINDS[ $type ] ) ) {
			return new WP_Error( 'feedback', 'Choose a type.', array( 'status' => 400 ) );
		}
		$body = mb_substr( trim( sanitize_textarea_field( (string) $r->get_param( 'text' ) ) ), 0, 2000 );
		if ( mb_strlen( $body ) < 3 ) {
			return new WP_Error( 'feedback', 'Please write a few words.', array( 'status' => 400 ) );
		}
		$uid = (int) Sawa_Lens_Auth::user_from_request( $r ); // 0 when signed out or the token is old
		$key = 'sawa_lfb_' . ( $uid ? 'u' . $uid : 'i' . substr( md5( Sawa_Lens_Tiers::client_ip() . wp_salt() ), 0, 12 ) );
		$n   = (int) get_transient( $key );
		if ( $n >= self::LIMIT ) {
			return new WP_Error( 'feedback_limit', 'Thank you! You sent a lot of feedback. Please try again in an hour.', array( 'status' => 429 ) );
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );

		$page = esc_url_raw( (string) $r->get_param( 'page' ) );
		$mail = sanitize_email( (string) $r->get_param( 'email' ) );
		$ctx  = array(
			'source'  => 'language-lens',
			'lensVer' => mb_substr( sanitize_text_field( (string) $r->get_param( 'version' ) ), 0, 20 ),
			'browser' => mb_substr( sanitize_text_field( (string) $r->get_param( 'browser' ) ), 0, 40 ),
			'device'  => 'phone' === $r->get_param( 'device' ) ? 'phone' : 'computer',
			'lang'    => 'ar' === $r->get_param( 'lang' ) ? 'ar' : 'en',
			'from'    => mb_substr( sanitize_key( (string) $r->get_param( 'from' ) ), 0, 20 ), // popup or card
			'signedIn' => $uid > 0,
		);
		if ( $page ) {
			$ctx['page'] = mb_substr( $page, 0, 300 );
		}
		if ( ! $uid && is_email( $mail ) ) {
			$ctx['email'] = $mail;
		}
		$wpdb->insert( // phpcs:ignore
			Sawa_Tracker_Feedback::t(),
			array(
				'user_id'    => $uid,
				'kind'       => self::KINDS[ $type ],
				'body'       => $body,
				'area'       => self::AREA,
				'target'     => $page ? mb_substr( preg_replace( '#^https?://#', '', $page ), 0, 160 ) : '',
				'ctx'        => mb_substr( wp_json_encode( $ctx, JSON_UNESCAPED_UNICODE ), 0, 4000 ),
				'version'    => 'lens ' . $ctx['lensVer'] . ' / ' . SAWA_LENS_VER,
				'created_at' => current_time( 'mysql' ),
			)
		);
		if ( $wpdb->last_error ) {
			return new WP_Error( 'feedback', 'Could not save your note. Please try again.', array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}
}
