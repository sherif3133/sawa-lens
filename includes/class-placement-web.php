<?php
/**
 * The placement test page (https://site/placement/) and its API. Open to everyone: members get their level
 * saved to their account; visitors see their result and an invitation to join.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sawa_Lens_Placement_Web {

	const NS = 'sawa-lens/v1';

	public static function init() {
		// Before Sawa_Lens_App::rewrite() (priority 10), which flushes the rules after an update.
		add_action( 'init', function () { add_rewrite_rule( '^placement/?$', 'index.php?sawa_lens_app=3', 'top' ); }, 5 );
		add_action( 'template_redirect', array( __CLASS__, 'render' ), 5 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		// Item difficulties follow real answers: recalibrated once a week (the admin button still works any time).
		add_action( 'sawa_lens_pt_recalibrate', array( 'Sawa_Lens_Placement', 'recalibrate' ) );
		if ( ! wp_next_scheduled( 'sawa_lens_pt_recalibrate' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', 'sawa_lens_pt_recalibrate' );
		}
	}

	public static function routes() {
		$open = function () { return true; };
		register_rest_route( self::NS, '/pt/start', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'start' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/pt/answer', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'answer' ), 'permission_callback' => $open ) );
	}

	private static function out( $d ) {
		$r = rest_ensure_response( $d );
		$r->header( 'Cache-Control', 'no-store' );
		return $r;
	}

	public static function start( WP_REST_Request $r ) {
		$res = Sawa_Lens_Placement::start( get_current_user_id(), 'quick' === $r->get_param( 'mode' ) );
		return is_wp_error( $res ) ? $res : self::out( $res );
	}

	public static function answer( WP_REST_Request $r ) {
		$res = Sawa_Lens_Placement::answer( (string) $r->get_param( 'key' ), $r->get_param( 'answer' ), get_current_user_id() );
		return is_wp_error( $res ) ? $res : self::out( $res );
	}

	public static function render() {
		if ( '3' !== (string) get_query_var( 'sawa_lens_app' ) ) {
			return;
		}
		nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		$uid  = get_current_user_id();
		$prev = $uid ? get_user_meta( $uid, 'sawa_lens_placement', true ) : null;
		$boot = array(
			'root'     => esc_url_raw( rest_url( self::NS . '/pt/' ) ),
			'nonce'    => $uid ? wp_create_nonce( 'wp_rest' ) : '',
			'member'   => $uid && Sawa_Lens_Auth::allowed( $uid ),
			'name'     => $uid ? wp_get_current_user()->first_name : '',
			'app'      => esc_url_raw( home_url( '/app/' ) ),
			'join'     => esc_url_raw( home_url( '/join/' ) ),
			'previous' => is_array( $prev ) ? $prev : null,
			'ready'    => Sawa_Lens_Placement::counts() ? true : false,
		);
		$v  = SAWA_LENS_VER;
		$ar = Sawa_Lang::is_ar(); // the test itself is in English; the instructions follow the site language
		header( 'Content-Type: text/html; charset=utf-8' );
		?><!doctype html>
<html lang="<?php echo $ar ? 'ar' : 'en'; ?>" dir="<?php echo $ar ? 'rtl' : 'ltr'; ?>" data-lang="<?php echo $ar ? 'ar' : 'en'; ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0F0F10">
<?php echo Sawa_Lang::boot( true ); // phpcs:ignore ?>
<title>Free English Level Test – Sawa English</title>
<meta name="description" content="Find your English level (A1–C2) in about 25 minutes: vocabulary, grammar, reading, listening and writing. Free.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Changa:wght@600;800&family=IBM+Plex+Sans+Arabic:wght@400;600&display=swap">
<link rel="stylesheet" href="<?php echo esc_url( SAWA_LENS_URL . 'assets/app.css?v=' . $v ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( SAWA_LENS_URL . 'assets/placement.css?v=' . $v ); ?>">
</head>
<body>
<style>html[data-lang=ar] #pt p.ar{display:none}</style><?php // the Arabic page already says it in Arabic ?>
<div class="pt-lang" style="max-width:760px;margin:0 auto;padding:12px 16px 0;display:flex;justify-content:flex-end;align-items:center"><?php echo Sawa_Lang::toggle(); // phpcs:ignore ?></div>
<div id="pt" class="app pt"><p class="loading"><?php echo $ar ? 'بنجهز الاختبار…' : 'Loading…'; ?></p></div>
<script>window.SAWA_PT = <?php echo wp_json_encode( $boot ); ?>;</script>
<script src="<?php echo esc_url( SAWA_LENS_URL . ( $ar && file_exists( SAWA_LENS_DIR . 'assets/placement-ar.js' ) ? 'assets/placement-ar.js' : 'assets/placement.js' ) . '?v=' . $v ); ?>"></script>
</body>
</html>
		<?php
		exit;
	}
}
