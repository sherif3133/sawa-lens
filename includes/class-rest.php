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
		$r( '/to-english', 'POST', array( __CLASS__, 'to_english' ) );
		$r( '/sound', 'POST', array( __CLASS__, 'sound' ) );
		$r( '/proof', 'POST', array( __CLASS__, 'proof' ) );
		$r( '/logout', 'POST', array( __CLASS__, 'logout' ) );
		$r( '/video/(?P<vid>[\w-]{11})', 'GET', array( __CLASS__, 'video' ) );
		$r( '/lesson/(?P<id>\d+)/answer', 'POST', array( __CLASS__, 'lesson_answer' ) );
		$r( '/lessons/draft', 'POST', array( __CLASS__, 'lesson_draft' ) );
		$r( '/image', 'GET', array( __CLASS__, 'image' ) );
		$r( '/curious', 'POST', array( __CLASS__, 'curious' ) );
		$r( '/daily', 'POST', array( __CLASS__, 'daily_add' ) );
		$r( '/daily', 'GET', array( __CLASS__, 'daily' ) );
		$r( '/image/ai', 'POST', array( __CLASS__, 'image_ai' ) );
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
			return new WP_Error( 'signed_out', 'Please sign in again from Language Lens.', array( 'status' => 401 ) );
		}
		if ( ! Sawa_Lens_Auth::allowed( $uid ) ) {
			return new WP_Error( 'not_member', 'Language Lens is for approved Sawa English members.', array( 'status' => 403 ) );
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
				'app'      => Sawa_Lens_App::url(),
				'progress' => Sawa_Lens_Store::progress( $uid, 14 ),
				'admin'    => user_can( $uid, 'manage_options' ),
			)
		);
	}

	public static function image( WP_REST_Request $r ) {
		return self::out( Sawa_Lens_Images::lookup( (string) $r->get_param( 'word' ), sanitize_key( (string) $r->get_param( 'pos' ) ) ) + array( 'canAi' => '' !== Sawa_Lens_AI::settings()['stt_key'] ) );
	}

	public static function image_ai( WP_REST_Request $r ) {
		$res = Sawa_Lens_Images::ai( get_current_user_id(), (string) $r->get_param( 'word' ), (string) $r->get_param( 'sentence' ) );
		return is_wp_error( $res ) ? $res : self::out( $res );
	}

	/** The published quiz for a YouTube video (or null), with this student's earlier answers. */
	public static function video( WP_REST_Request $r ) {
		$l = Sawa_Lens_Lessons::by_video( (string) $r['vid'] );
		return self::out( array( 'lesson' => $l ? Sawa_Lens_Lessons::for_student( $l, get_current_user_id() ) : null ) );
	}

	public static function lesson_answer( WP_REST_Request $r ) {
		$res = Sawa_Lens_Lessons::answer( get_current_user_id(), (int) $r['id'], sanitize_key( (string) $r->get_param( 'qid' ) ), $r->get_param( 'a' ) );
		return is_wp_error( $res ) ? $res : self::out( $res );
	}

	/** Admin only: a draft quiz from the captions the extension already has. */
	public static function lesson_draft( WP_REST_Request $r ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Only Sherif can make quizzes.', array( 'status' => 403 ) );
		}
		$vid = (string) $r->get_param( 'videoId' );
		if ( ! preg_match( '/^[\w-]{11}$/', $vid ) ) {
			return new WP_Error( 'video', 'No video.', array( 'status' => 400 ) );
		}
		$lines = Sawa_Lens_Lessons::clean_lines( (array) $r->get_param( 'lines' ) );
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Sawa_Lens_Store::t( 'lessons' ) . ' WHERE video_id = %s ORDER BY id DESC LIMIT 1', $vid ) ); // phpcs:ignore
		$id = Sawa_Lens_Lessons::save( $id, array( 'videoId' => $vid, 'title' => (string) $r->get_param( 'title' ), 'transcript' => $lines ) );
		return self::out( array( 'id' => $id, 'edit' => admin_url( 'admin.php?page=sawa-lens-quizzes&edit=' . $id ) ) );
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
		// Words and sentences saved while watching or reading count as study time.
		$saved = count( array_filter( (array) $pushS, function ( $x ) { return is_array( $x ) && empty( $x['deleted'] ); } ) ) + count( array_filter( (array) $pushW, function ( $x ) { return is_array( $x ) && 'learning' === ( $x['status'] ?? '' ) && empty( $x['base'] ); } ) );
		if ( $saved ) {
			Sawa_Lens_Activity::add( $uid, 'saved', min( 40, $saved ) );
		}
		// Vocabulary test result: the newest one wins.
		$vocab = $r->get_param( 'vocab' );
		$saved = get_user_meta( $uid, 'sawa_lens_vocab', true );
		if ( is_array( $vocab ) && isset( $vocab['estimate'], $vocab['t'] ) && ( ! is_array( $saved ) || (int) $vocab['t'] > (int) ( $saved['t'] ?? 0 ) ) ) {
			$saved = array(
				'date'        => sanitize_text_field( (string) ( $vocab['date'] ?? '' ) ),
				'estimate'    => max( 0, min( 6000, (int) $vocab['estimate'] ) ),
				'bands'       => array_map( 'floatval', array_slice( (array) ( $vocab['bands'] ?? array() ), 0, 6 ) ),
				'falseAlarms' => (float) ( $vocab['falseAlarms'] ?? 0 ),
				't'           => (int) $vocab['t'],
			);
			update_user_meta( $uid, 'sawa_lens_vocab', $saved );
		}
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
				'vocab'     => is_array( $saved ) ? $saved : null,
				'progress'  => Sawa_Lens_Store::progress( $uid, 14 ),
			)
		);
	}

	public static function explain( WP_REST_Request $r ) {
		$res = Sawa_Lens_AI::explain( get_current_user_id(), $r->get_param( 'word' ), $r->get_param( 'sentence' ), (string) $r->get_param( 'lang' ) );
		return is_wp_error( $res ) ? $res : self::out( $res );
	}

	/** Proofreading what the learner types on a website: grammar, word choice and tone (spelling is checked in the extension). */
	public static function proof( WP_REST_Request $r ) {
		$text = trim( mb_substr( (string) $r->get_param( 'text' ), 0, 2000 ) );
		if ( mb_strlen( $text ) < 15 || preg_match_all( '/[\x{0600}-\x{06FF}]/u', $text ) > mb_strlen( $text ) * 0.3 ) {
			return self::out( array( 'errors' => array() ) );
		}
		$key = 'sawa_lens_proof_' . md5( $text );
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) {
			return self::out( $hit );
		}
		$res = Sawa_Lens_AI::check_writing( get_current_user_id(), $text, 'chat' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$out = array( 'errors' => array_map( function ( $e ) { return array_intersect_key( $e, array_flip( array( 'wrong', 'right', 'why', 'why_ar', 'skill' ) ) ); }, $res['errors'] ) );
		set_transient( $key, $out, DAY_IN_SECONDS );
		return self::out( $out );
	}

	public static function sound( WP_REST_Request $r ) {
		$res = Sawa_Lens_AI::sound( get_current_user_id(), $r->get_param( 'text' ) );
		return is_wp_error( $res ) ? $res : self::out( $res );
	}

	public static function translate( WP_REST_Request $r ) {
		$res = Sawa_Lens_AI::translate( get_current_user_id(), $r->get_param( 'sentence' ) );
		return is_wp_error( $res ) ? $res : self::out( $res );
	}

	/** Arabic → English: {text, word?} for one text, or {texts: [...]} for posts and comments on a page. */
	public static function to_english( WP_REST_Request $r ) {
		$uid = get_current_user_id();
		$res = is_array( $r->get_param( 'texts' ) )
			? Sawa_Lens_AI::to_english_many( $uid, $r->get_param( 'texts' ) )
			: Sawa_Lens_AI::to_english( $uid, (string) $r->get_param( 'text' ), (string) $r->get_param( 'word' ) );
		return is_wp_error( $res ) ? $res : self::out( $res );
	}

	/** A word the learner was curious about: XP, tracked but not added to reviews. */
	public static function curious( WP_REST_Request $r ) {
		$res = Sawa_Lens_Daily::curious( get_current_user_id(), (string) $r->get_param( 'lemma' ) );
		return is_wp_error( $res ) ? $res : self::out( $res );
	}

	/** Words read on websites and heard in videos since the last report. */
	public static function daily_add( WP_REST_Request $r ) {
		return self::out( Sawa_Lens_Daily::add( get_current_user_id(), (int) $r->get_param( 'read' ), (int) $r->get_param( 'heard' ) ) );
	}

	public static function daily() {
		return self::out( Sawa_Lens_Daily::summary( get_current_user_id() ) );
	}

	public static function logout() {
		Sawa_Lens_Auth::revoke_current();
		return self::out( array( 'ok' => true ) );
	}
}
