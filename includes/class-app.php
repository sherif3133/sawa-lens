<?php
/**
 * The Language Lens web app at https://site/lens/ – works on phones and computers.
 * Skill tree, practice sets and the writing checker. Uses the normal WordPress login (cookie + REST nonce).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sawa_Lens_App {

	const NS = 'sawa-lens/v1';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'rewrite' ) );
		add_filter( 'query_vars', function ( $v ) { $v[] = 'sawa_lens_app'; return $v; } );
		add_action( 'template_redirect', array( __CLASS__, 'render' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function url() {
		return home_url( '/lens/' );
	}

	public static function rewrite() {
		add_rewrite_rule( '^lens/?$', 'index.php?sawa_lens_app=1', 'top' );
		if ( get_option( 'sawa_lens_rw' ) !== SAWA_LENS_VER ) {
			flush_rewrite_rules( false );
			update_option( 'sawa_lens_rw', SAWA_LENS_VER );
		}
	}

	public static function render() {
		if ( ! get_query_var( 'sawa_lens_app' ) ) {
			return;
		}
		nocache_headers();
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		$uid = get_current_user_id();
		if ( ! Sawa_Lens_Auth::allowed( $uid ) ) {
			Sawa_Lens_Auth::page( 'Language Lens is for Sawa English members', 'Your account is not approved in the Sawa English tracker yet. Once Sherif approves your request, come back to this page.' );
		}
		$lessons = array();
		foreach ( array_keys( Sawa_Lens_Skills::all() ) as $id ) {
			$lessons[ $id ] = Sawa_Lens_Skills::lesson( $id );
		}
		$u    = wp_get_current_user();
		$boot = array(
			'root'    => esc_url_raw( rest_url( self::NS . '/app/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'name'    => $u->first_name ? $u->first_name : $u->display_name,
			'ai'      => Sawa_Lens_AI::ready(),
			'tracker' => esc_url_raw( home_url( '/tracker/' ) ),
			'lessons' => $lessons,
		);
		$v = SAWA_LENS_VER;
		header( 'Content-Type: text/html; charset=utf-8' );
		?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0F0F10">
<meta name="robots" content="noindex">
<title>Language Lens – Sawa English</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Changa:wght@600;800&family=IBM+Plex+Sans+Arabic:wght@400;600&display=swap">
<link rel="stylesheet" href="<?php echo esc_url( SAWA_LENS_URL . 'assets/app.css?v=' . $v ); ?>">
</head>
<body>
<div id="app" class="app"><p class="loading">Loading…</p></div>
<script>window.SAWA_LENS = <?php echo wp_json_encode( $boot ); ?>;</script>
<script src="<?php echo esc_url( SAWA_LENS_URL . 'assets/app.js?v=' . $v ); ?>"></script>
</body>
</html>
		<?php
		exit;
	}

	public static function routes() {
		$can = function () {
			return is_user_logged_in() && Sawa_Lens_Auth::allowed( get_current_user_id() );
		};
		$r   = function ( $path, $method, $cb ) use ( $can ) {
			register_rest_route(
				self::NS,
				'/app/' . $path,
				array(
					'methods'             => $method,
					'callback'            => $cb,
					'permission_callback' => $can,
				)
			);
		};
		$r( 'tree', 'GET', array( __CLASS__, 'tree' ) );
		$r( 'skill', 'GET', array( __CLASS__, 'skill' ) );
		$r( 'practice', 'GET', array( __CLASS__, 'practice' ) );
		$r( 'practice', 'POST', array( __CLASS__, 'practice_done' ) );
		$r( 'write', 'POST', array( __CLASS__, 'write' ) );
		$r( 'history', 'GET', array( __CLASS__, 'history' ) );
		$r( 'writing', 'GET', array( __CLASS__, 'writing' ) );
	}

	private static function out( $data ) {
		$res = rest_ensure_response( $data );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	private static function status_map( $tree ) {
		$m = array();
		foreach ( $tree as $n ) {
			$m[ $n['id'] ] = $n['status'];
		}
		return $m;
	}

	/** Points whose status changed between two trees: "unlocked", "mastered", "needs work". */
	private static function changes( $before, $after ) {
		$a   = self::status_map( $after );
		$out = array();
		foreach ( self::status_map( $before ) as $id => $s ) {
			if ( isset( $a[ $id ] ) && $a[ $id ] !== $s ) {
				$out[] = array(
					'id'   => $id,
					'from' => $s,
					'to'   => $a[ $id ],
				);
			}
		}
		return $out;
	}

	private static function tree_out( $uid ) {
		$tree = Sawa_Lens_Skills::tree( $uid );
		return array(
			'tree' => $tree,
			'next' => Sawa_Lens_Skills::next_up( $tree ),
		);
	}

	public static function tree() {
		return self::out( self::tree_out( get_current_user_id() ) );
	}

	public static function skill( WP_REST_Request $r ) {
		$id = sanitize_key( (string) $r->get_param( 'id' ) );
		if ( ! Sawa_Lens_Skills::get( $id ) ) {
			return new WP_Error( 'skill', 'Unknown skill.', array( 'status' => 404 ) );
		}
		return self::out( array( 'mine' => Sawa_Lens_Skills::own_errors( get_current_user_id(), $id, 6 ) ) );
	}

	/** A practice set: up to 3 items from the learner's own mistakes first, then the AI set for this round. */
	public static function practice( WP_REST_Request $r ) {
		$uid = get_current_user_id();
		$id  = sanitize_key( (string) $r->get_param( 'id' ) );
		$k   = Sawa_Lens_Skills::get( $id );
		if ( ! $k ) {
			return new WP_Error( 'skill', 'Unknown skill.', array( 'status' => 404 ) );
		}
		$node = null;
		foreach ( Sawa_Lens_Skills::tree( $uid ) as $n ) {
			if ( $n['id'] === $id ) {
				$node = $n;
			}
		}
		if ( ! $node || 'locked' === $node['status'] ) {
			return new WP_Error( 'locked', 'This point is still locked.', array( 'status' => 403 ) );
		}
		$set = Sawa_Lens_AI::practice_set( $uid, $id, $node['sets'] );
		if ( is_wp_error( $set ) ) {
			return $set;
		}
		$own  = array();
		$seen = array();
		foreach ( Sawa_Lens_Skills::own_errors( $uid, $id, 12 ) as $e ) {
			if ( '' === $e['sentence'] || '' === $e['fixed'] || $e['sentence'] === $e['fixed'] || isset( $seen[ $e['sentence'] ] ) ) {
				continue;
			}
			$seen[ $e['sentence'] ] = 1;
			$own[]                  = array(
				'type'   => 'fix',
				'own'    => true,
				'q'      => $e['sentence'],
				'answer' => array( $e['fixed'] ),
				'hint'   => $e['wrong'],
				'why'    => $e['why'],
				'why_ar' => $e['why_ar'],
			);
			if ( count( $own ) >= 3 ) {
				break;
			}
		}
		return self::out(
			array(
				'items'   => array_merge( $own, $set['items'] ),
				'variant' => (int) $node['sets'] % 6,
			)
		);
	}

	public static function practice_done( WP_REST_Request $r ) {
		$uid = get_current_user_id();
		$id  = sanitize_key( (string) $r->get_param( 'id' ) );
		if ( ! Sawa_Lens_Skills::get( $id ) ) {
			return new WP_Error( 'skill', 'Unknown skill.', array( 'status' => 404 ) );
		}
		$before = Sawa_Lens_Skills::tree( $uid );
		Sawa_Lens_Skills::record_practice( $uid, $id, array_map( 'intval', (array) $r->get_param( 'results' ) ), (int) $r->get_param( 'found' ) );
		$out            = self::tree_out( $uid );
		$out['changed'] = self::changes( $before, $out['tree'] );
		return self::out( $out );
	}

	public static function write( WP_REST_Request $r ) {
		$uid  = get_current_user_id();
		$text = trim( str_replace( "\r", '', wp_strip_all_tags( (string) $r->get_param( 'text' ) ) ) );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		if ( mb_strlen( $text ) < 15 ) {
			return new WP_Error( 'short', 'Write at least one full sentence.', array( 'status' => 400 ) );
		}
		if ( mb_strlen( $text ) > 3000 ) {
			return new WP_Error( 'long', 'That\'s a lot! Check up to about 500 words at a time.', array( 'status' => 400 ) );
		}
		$before = Sawa_Lens_Skills::tree( $uid );
		$res    = Sawa_Lens_AI::check_writing( $uid, $text );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$words = count( preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) );
		global $wpdb;
		$wpdb->insert( // phpcs:ignore
			Sawa_Lens_Store::t( 'writing' ),
			array(
				'user_id'    => $uid,
				'source'     => 'write',
				'body'       => $text,
				'result'     => wp_json_encode( $res ),
				'words'      => $words,
				'errors'     => count( $res['errors'] ),
				'created_at' => current_time( 'mysql' ),
			)
		);
		$wid = (int) $wpdb->insert_id;
		Sawa_Lens_Skills::log_errors( $uid, $res['errors'], 'write', $wid );
		Sawa_Lens_Skills::record_uses( $uid, $res['good'] );
		$out            = self::tree_out( $uid );
		$out['changed'] = self::changes( $before, $out['tree'] );
		$out['result']  = $res;
		$out['text']    = $text;
		$out['id']      = $wid;
		$out['words']   = $words;
		return self::out( $out );
	}

	public static function history() {
		$uid = get_current_user_id();
		global $wpdb;
		$t    = Sawa_Lens_Store::t( 'writing' );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, body, words, errors, created_at FROM $t WHERE user_id = %d ORDER BY id DESC LIMIT 15", $uid ), ARRAY_A ); // phpcs:ignore
		$list = array();
		foreach ( $rows as $w ) {
			$list[] = array(
				'id'      => (int) $w['id'],
				'excerpt' => mb_substr( $w['body'], 0, 90 ),
				'words'   => (int) $w['words'],
				'errors'  => (int) $w['errors'],
				'date'    => mysql2date( 'j M', $w['created_at'] ),
			);
		}
		return self::out(
			array(
				'writings' => $list,
				'trends'   => Sawa_Lens_Skills::trends( $uid ),
			)
		);
	}

	public static function writing( WP_REST_Request $r ) {
		global $wpdb;
		$t   = Sawa_Lens_Store::t( 'writing' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, body, result, words FROM $t WHERE id = %d AND user_id = %d", (int) $r->get_param( 'id' ), get_current_user_id() ), ARRAY_A ); // phpcs:ignore
		if ( ! $row ) {
			return new WP_Error( 'missing', 'Not found.', array( 'status' => 404 ) );
		}
		return self::out(
			array(
				'id'     => (int) $row['id'],
				'text'   => $row['body'],
				'words'  => (int) $row['words'],
				'result' => json_decode( $row['result'], true ),
			)
		);
	}
}
