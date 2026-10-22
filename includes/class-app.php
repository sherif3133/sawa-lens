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
		add_action( 'template_redirect', array( __CLASS__, 'render' ), 1 ); // before WordPress adds a trailing slash to /app/sw.js
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function url() {
		return home_url( '/app/' );
	}

	public static function rewrite() {
		add_rewrite_rule( '^lens/?$', 'index.php?sawa_lens_app=1', 'top' );
		add_rewrite_rule( '^app/?$', 'index.php?sawa_lens_app=2', 'top' );
		add_rewrite_rule( '^app/manifest.json$', 'index.php?sawa_lens_app=5', 'top' );
		add_rewrite_rule( '^app/sw.js$', 'index.php?sawa_lens_app=4', 'top' );
		// Flush only when one of our addresses (/app, /lens, /placement…) is missing from the saved rules.
		global $wp_rewrite;
		$saved = (array) get_option( 'rewrite_rules', array() );
		foreach ( array_keys( (array) $wp_rewrite->extra_rules_top ) as $k ) {
			if ( preg_match( '#^\^(app|lens|placement)#', $k ) && ! isset( $saved[ $k ] ) ) {
				flush_rewrite_rules( false );
				break;
			}
		}
	}

	public static function render() {
		// 1 = old /lens/, 2 = /app, 4/5 = the app's service worker / manifest (3 = /placement/, its own class).
		if ( ! in_array( (string) get_query_var( 'sawa_lens_app' ), array( '1', '2', '4', '5' ), true ) ) {
			return;
		}
		if ( in_array( (string) get_query_var( 'sawa_lens_app' ), array( '4', '5' ), true ) ) {
			self::pwa_file( (string) get_query_var( 'sawa_lens_app' ) );
		}
		nocache_headers();
		// Personal pages: never from a page cache (LiteSpeed and others).
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		// The old Language Lens address now opens the one app (the #part of the link is kept by the browser).
		if ( '1' === (string) get_query_var( 'sawa_lens_app' ) && class_exists( 'Sawa_Tracker_Front' ) ) {
			wp_safe_redirect( home_url( '/app/' ), 302 );
			exit;
		}
		$app_mode = '2' === (string) get_query_var( 'sawa_lens_app' ) && class_exists( 'Sawa_Tracker_Front' );
		if ( $app_mode && ( ! is_user_logged_in() || ! Sawa_Lens_Auth::allowed( get_current_user_id() ) ) ) {
			self::join_page(); // guests and people waiting for approval get the tracker's own sign-in / join screens
		}
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		$uid = get_current_user_id();
		if ( ! Sawa_Lens_Auth::allowed( $uid ) ) {
			Sawa_Lens_Auth::page( 'Language Lens is for Sawa English members', 'Your account is not approved in the Sawa English tracker yet. Once Sherif approves your request, come back to this page.' );
		}
		if ( ! self::is_open_for( $uid ) ) {
			self::waiting_page();
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
			'stt'     => '' !== Sawa_Lens_AI::settings()['stt_key'],
			'tracker' => esc_url_raw( home_url( '/tracker/' ) ),
			'lessons' => $lessons,
			'lexicon' => esc_url_raw( SAWA_LENS_URL . 'assets/lexicon.json?v=' . SAWA_LENS_VER ),
			'songs'   => class_exists( 'Sawa_Lens_Songs' ) && Sawa_Lens_Songs::allowed( get_current_user_id() ), // My songs beta
			'appMode' => $app_mode,
			'progress' => esc_url_raw( rest_url( self::NS . '/web/progress' ) ),
			'explainLang' => Sawa_Lens_AI::explain_lang( $u->ID ), // same setting as in Language Lens
		);
		$trk = $app_mode ? do_shortcode( '[sawa_tracker embedded="1" view="today"]' ) : '';
		$v = SAWA_LENS_VER;
		header( 'Content-Type: text/html; charset=utf-8' );
		?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0F0F10">
<meta name="robots" content="noindex">
<?php if ( $app_mode ) : ?>
<link rel="manifest" href="<?php echo esc_url( home_url( '/app/manifest.json' ) ); ?>">
<link rel="apple-touch-icon" href="<?php echo esc_url( SAWA_LENS_URL . 'assets/icon-192.png' ); ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Sawa English">
<?php endif; ?>
<title><?php echo $app_mode ? 'Sawa English' : 'Language Lens – Sawa English'; ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Changa:wght@600;800&family=IBM+Plex+Sans+Arabic:wght@400;600&display=swap">
<link rel="stylesheet" href="<?php echo esc_url( SAWA_LENS_URL . 'assets/app.css?v=' . $v ); ?>">
</head>
<body>
<div id="app" class="app<?php echo $app_mode ? ' one' : ''; ?>"><p class="loading">Loading…</p></div>
<?php if ( $app_mode ) : ?>
<div id="trk" class="trk" hidden><?php echo $trk; // phpcs:ignore -- the tracker's own markup ?></div>
<nav id="nav" class="nav"></nav>
<?php endif; ?>
<script>window.SAWA_LENS = <?php echo wp_json_encode( $boot ); ?>;</script>
<script src="<?php echo esc_url( SAWA_LENS_URL . 'assets/app.js?v=' . $v ); ?>"></script>
<?php if ( $app_mode ) : ?>
<?php // The app's menus are English: use the tracker's English build when it's there. ?>
<script src="<?php echo esc_url( SAWA_TR_URL . ( file_exists( SAWA_TR_DIR . 'assets/app-en.js' ) ? 'assets/app-en.js' : 'assets/app.js' ) . '?ver=' . SAWA_TR_VER ); ?>"></script>
<?php endif; ?>
</body>
</html>
		<?php
		exit;
	}

	/** The app's web manifest (5) and service worker (4), so students can add it to their home screen. */
	private static function pwa_file( $which ) {
		if ( '5' === $which ) {
			header( 'Content-Type: application/manifest+json; charset=utf-8' );
			echo wp_json_encode(
				array(
					'name'             => 'Sawa English',
					'short_name'       => 'Sawa English',
					'start_url'        => '/app/#today',
					'scope'            => '/app/',
					'display'          => 'standalone',
					'background_color' => '#F6F5F3',
					'theme_color'      => '#0F0F10',
					'icons'            => array(
						array( 'src' => SAWA_LENS_URL . 'assets/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable' ),
						array( 'src' => SAWA_LENS_URL . 'assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable' ),
					),
				),
				JSON_UNESCAPED_SLASHES
			);
			exit;
		}
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: /app/' );
		header( 'Cache-Control: no-cache' );
		// Online only (the app needs fresh data); the fetch handler makes the app installable.
		echo "self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });
self.addEventListener('fetch', function () {});
";
		exit;
	}

	/** /app for guests and people not approved yet: the tracker's sign-in, join and waiting screens. */
	/** The app is open to students (Settings → Language Lens). Until then only admins get in; approved students wait. */
	public static function is_open() {
		return (bool) get_option( 'sawa_lens_app_open', 0 );
	}

	public static function is_open_for( $uid ) {
		return self::is_open() || user_can( $uid, 'manage_options' );
	}

	/** What approved students see while the app is closed. */
	private static function waiting_page() {
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#0F0F10"><title>Sawa App · You\'re on the list</title>'
			. '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Changa:wght@800&family=IBM+Plex+Sans:wght@400;600&display=swap">'
			. '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;box-sizing:border-box;background:#F6F5F3;color:#2B2A29;font:17px/1.7 "IBM Plex Sans",system-ui,sans-serif}'
			. '.box{max-width:480px;background:#fff;border:1px solid #E6E3DF;border-radius:16px;padding:36px 30px;text-align:center}'
			. '.tick{width:64px;height:64px;border-radius:50%;background:#0F0F10;color:#fff;display:grid;place-items:center;font-size:30px;margin:0 auto 18px}'
			. 'h1{font:800 30px/1.2 Changa,"IBM Plex Sans",sans-serif;color:#0F0F10;margin:0 0 12px}p{margin:0 0 8px}.muted{color:#8A8584;font-size:15px}a{color:#0F0F10}</style></head><body>'
			. '<div class="box"><div class="tick" aria-hidden="true">✓</div><h1>You\'re on the list!</h1>'
			. '<p>Please wait for Sherif to publish the Sawa App.</p>'
			. '<p class="muted">Your account is ready. When the app opens, come back to this page and sign in.</p>'
			. '<p class="muted" style="margin-top:18px"><a href="' . esc_url( home_url( '/' ) ) . '">Back to Sawa English</a></p></div></body></html>';
		exit;
	}

	private static function join_page() {
		$html = do_shortcode( '[sawa_tracker]' );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#0F0F10"><title>Sawa English</title>'
			. '<style>body{margin:0;background:#F6F5F3}.sawa-tracker-app{max-width:1100px;margin:0 auto;padding:16px}</style></head><body>'
			. $html // phpcs:ignore -- the tracker's own markup
			. '<script src="' . esc_url( SAWA_TR_URL . 'assets/app.js?ver=' . SAWA_TR_VER ) . '"></script></body></html>';
		exit;
	}

	public static function routes() {
		$can = function () {
			$uid = get_current_user_id();
			return $uid && Sawa_Lens_Auth::allowed( $uid ) && self::is_open_for( $uid );
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
		$r( 'review', 'GET', array( __CLASS__, 'review' ) );
		$r( 'review', 'POST', array( __CLASS__, 'review_grade' ) );
		$r( 'review/settings', 'POST', array( __CLASS__, 'review_settings' ) );
		$r( 'prefs', 'POST', array( __CLASS__, 'prefs' ) );
		$r( 'review/history', 'GET', array( __CLASS__, 'review_history' ) );
		$r( 'library', 'GET', array( __CLASS__, 'library' ) );
		$r( 'library/word', 'POST', array( __CLASS__, 'library_word' ) );
		$r( 'library/sentence', 'POST', array( __CLASS__, 'library_sentence' ) );
		$r( 'library/translate', 'POST', array( __CLASS__, 'library_translate' ) );
		$r( 'library/explain', 'POST', array( __CLASS__, 'library_explain' ) );
		$r( 'library/save', 'POST', array( __CLASS__, 'library_save' ) );
		$r( 'scan', 'POST', array( __CLASS__, 'scan' ) );
		$r( 'adapt', 'POST', array( __CLASS__, 'adapt' ) );
		$r( 'songs', 'GET', array( __CLASS__, 'songs' ) );
		$r( 'songs', 'POST', array( __CLASS__, 'song_make' ) );
		$r( 'songs/played', 'POST', array( __CLASS__, 'song_played' ) );
		$r( 'readaloud', 'GET', array( __CLASS__, 'readaloud' ) );
		$r( 'speak', 'POST', array( __CLASS__, 'speak' ) );
		$r( 'journey', 'GET', array( __CLASS__, 'journey' ) );
		$r( 'today', 'GET', array( __CLASS__, 'today_plan' ) );
		$r( 'image', 'GET', array( 'Sawa_Lens_Rest', 'image' ) );
		$r( 'image/ai', 'POST', array( 'Sawa_Lens_Rest', 'image_ai' ) );
		$r( 'videos', 'GET', array( __CLASS__, 'videos' ) );
		$r( 'video', 'GET', array( __CLASS__, 'video' ) );
		$r( 'video/answer', 'POST', array( __CLASS__, 'video_answer' ) );
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
		Sawa_Lens_Activity::add( $uid, 'practice' );
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
		$xp             = Sawa_Lens_Skills::xp( $uid, min( 50, intdiv( $words, 2 ) ), 'write' );
		Sawa_Lens_Activity::add( $uid, '', 1, 'Pronunciation/Grammar', max( 2, $words / 15 ) );
		$out            = self::tree_out( $uid );
		$out['xp']      = $xp;
		$out['changed'] = self::changes( $before, $out['tree'] );
		$out['result']  = $res;
		$out['text']    = $text;
		$out['id']      = $wid;
		$out['words']   = $words;
		return self::out( $out );
	}

	public static function review() {
		return self::out( Sawa_Lens_Review::due( get_current_user_id() ) );
	}

	public static function review_grade( WP_REST_Request $r ) {
		$res = Sawa_Lens_Review::grade( get_current_user_id(), (string) $r->get_param( 'key' ), (int) $r->get_param( 'grade' ) );
		return is_wp_error( $res ) ? $res : self::out( $res );
	}

	public static function review_settings( WP_REST_Request $r ) {
		$n = (int) $r->get_param( 'newPerDay' );
		if ( $n >= 1 && $n <= 100 ) {
			update_user_meta( get_current_user_id(), 'sawa_lens_new_per_day', $n );
		}
		return self::out( Sawa_Lens_Review::stats( get_current_user_id() ) );
	}

	/** "Explanations: English / العربية" (shared with Language Lens). */
	public static function prefs( WP_REST_Request $r ) {
		$uid  = get_current_user_id();
		$expl = (string) $r->get_param( 'explainLang' );
		if ( in_array( $expl, array( 'en', 'ar' ), true ) ) {
			update_user_meta( $uid, 'sawa_lens_explain', $expl );
		}
		return self::out( array( 'explainLang' => Sawa_Lens_AI::explain_lang( $uid ) ) );
	}

	/** Progress over time, for the charts on the home page. */
	public static function review_history() {
		return self::out( Sawa_Lens_Review::history( get_current_user_id() ) );
	}

	/** My songs (beta): the learner's songs and whether a new one is unlocked. */
	public static function songs() {
		$uid = get_current_user_id();
		if ( ! Sawa_Lens_Songs::allowed( $uid ) ) {
			return new WP_Error( 'beta', 'Songs are in a small beta for now.', array( 'status' => 403 ) );
		}
		return self::out( array( 'songs' => Sawa_Lens_Songs::songs( $uid ), 'state' => Sawa_Lens_Songs::state( $uid ) ) );
	}

	public static function song_make( WP_REST_Request $r ) {
		$res = Sawa_Lens_Songs::request( get_current_user_id(), sanitize_key( (string) $r->get_param( 'style' ) ), (bool) $r->get_param( 'slow' ) );
		return is_wp_error( $res ) ? $res : self::out( $res );
	}

	public static function song_played( WP_REST_Request $r ) {
		return self::out( Sawa_Lens_Songs::played( get_current_user_id(), (int) $r->get_param( 'id' ), (int) $r->get_param( 'right' ), (int) $r->get_param( 'total' ) ) );
	}

	/** Graded reading: one chunk of a text rewritten at the learner's level (i+1), with their Learning words. */
	public static function adapt( WP_REST_Request $r ) {
		global $wpdb;
		$uid   = get_current_user_id();
		$level = (int) $r->get_param( 'level' );
		if ( $level < 1 ) {
			$p     = (array) get_user_meta( $uid, 'sawa_lens_placement', true );
			$level = Sawa_Lens_Store::level( $uid ) ?: ( (int) ( $p['levelNum'] ?? 0 ) ?: 2 );
		}
		$learning = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT lemma FROM ' . Sawa_Lens_Store::t( 'words' ) . " WHERE user_id = %d AND status = 'learning' ORDER BY learning_on DESC LIMIT 40", $uid ) ); // phpcs:ignore
		$res      = Sawa_Lens_AI::adapt( $uid, (string) $r->get_param( 'text' ), $level, $learning );
		return is_wp_error( $res ) ? $res : self::out( $res + array( 'levelNum' => $level ) );
	}

	public static function library() {
		return self::out( Sawa_Lens_Review::library( get_current_user_id() ) );
	}

	/** Mark a word known / learning / remove it (syncs to the extension like a mark made there). */
	public static function library_word( WP_REST_Request $r ) {
		$status = (string) $r->get_param( 'status' );
		if ( ! in_array( $status, array( 'known', 'learning', '' ), true ) ) {
			return new WP_Error( 'status', 'Bad status.', array( 'status' => 400 ) );
		}
		$w = array( 'lemma' => (string) $r->get_param( 'lemma' ), 'status' => $status, 't' => (int) round( microtime( true ) * 1000 ) );
		if ( '' !== trim( (string) $r->get_param( 'sentence' ) ) ) { // the sentence where the word was met (in the app or the Silk Road game)
			$w['ctx'] = array( 'sentence' => (string) $r->get_param( 'sentence' ), 'title' => (string) $r->get_param( 'title' ) );
		}
		Sawa_Lens_Store::put_words( get_current_user_id(), array( $w ) );
		return self::out( array( 'ok' => true ) );
	}

	/** Delete a saved sentence / phrase (syncs to the extension). */
	public static function library_sentence( WP_REST_Request $r ) {
		Sawa_Lens_Store::put_sentences( get_current_user_id(), array( array( 'uuid' => (string) $r->get_param( 'uuid' ), 'deleted' => 1, 't' => (int) round( microtime( true ) * 1000 ) ) ) );
		return self::out( array( 'ok' => true ) );
	}

	/** Arabic translation of a saved sentence that doesn't have one yet; kept on the sentence. */
	public static function library_translate( WP_REST_Request $r ) {
		global $wpdb;
		$uid = get_current_user_id();
		$t   = Sawa_Lens_Store::t( 'sentences' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, body, ar FROM $t WHERE user_id = %d AND uuid = %s AND deleted = 0", $uid, (string) $r->get_param( 'uuid' ) ), ARRAY_A ); // phpcs:ignore
		if ( ! $row ) {
			return new WP_Error( 'missing', 'Not found.', array( 'status' => 404 ) );
		}
		if ( '' !== $row['ar'] ) {
			return self::out( array( 'ar' => $row['ar'] ) );
		}
		$res = Sawa_Lens_AI::translate( $uid, $row['body'] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$wpdb->update( $t, array( 'ar' => $res['sentence'], 'change_id' => Sawa_Lens_Store::next_change() ), array( 'id' => $row['id'] ) ); // phpcs:ignore
		return self::out( array( 'ar' => $res['sentence'] ) );
	}

	/**
	 * The learner's journey: what they've done so far, so the app can unlock features step by step
	 * instead of showing everything on day one.
	 */
	public static function journey() {
		global $wpdb;
		$uid = get_current_user_id();
		$c   = function ( $sql ) use ( $wpdb, $uid ) {
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $uid ) ); // phpcs:ignore
		};
		$reviews = (array) get_user_meta( $uid, 'sawa_lens_reviews', true );
		$sets    = 0;
		foreach ( (array) get_user_meta( $uid, 'sawa_lens_skills', true ) as $s ) {
			$sets += is_array( $s ) ? (int) ( $s['sets'] ?? 0 ) : 0;
		}
		$chat_voice = 0;
		if ( class_exists( 'Sawa_Tracker_Game' ) ) {
			$chat_voice = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Sawa_Tracker_Game::table( 'messages' ) . " WHERE user_id = %d AND audio <> ''", $uid ) ); // phpcs:ignore
		}
		return self::out(
			array(
				'admin'     => current_user_can( 'manage_options' ),
				'extension' => $c( 'SELECT COUNT(*) FROM ' . Sawa_Lens_Store::t( 'tokens' ) . ' WHERE user_id = %d' ) > 0,
				'vocabTest' => is_array( get_user_meta( $uid, 'sawa_lens_vocab', true ) ),
				// Words ever marked Learning (still learning, or learned since).
				'learning'  => $c( 'SELECT COUNT(*) FROM ' . Sawa_Lens_Store::t( 'words' ) . ' WHERE user_id = %d AND learning_on IS NOT NULL' ),
				'known'     => $c( 'SELECT COUNT(*) FROM ' . Sawa_Lens_Store::t( 'words' ) . " WHERE user_id = %d AND status = 'known'" ),
				'saved'     => $c( 'SELECT COUNT(*) FROM ' . Sawa_Lens_Store::t( 'sentences' ) . ' WHERE user_id = %d AND deleted = 0' ),
				'reviews'   => array_sum( array_map( 'intval', $reviews ) ),
				'reviewDays' => count( array_filter( $reviews ) ),
				'writings'  => $c( 'SELECT COUNT(*) FROM ' . Sawa_Lens_Store::t( 'writing' ) . " WHERE user_id = %d AND source = 'write'" ),
				'speaks'    => $c( 'SELECT COUNT(*) FROM ' . Sawa_Lens_Store::t( 'writing' ) . " WHERE user_id = %d AND source = 'voice'" ) + $chat_voice,
				'sets'      => $sets,
				'quizzes'   => $c( 'SELECT COUNT(*) FROM ' . Sawa_Lens_Store::t( 'lesson_results' ) . ' WHERE user_id = %d' ),
				'store'     => (string) Sawa_Lens_AI::settings()['store_url'],
				'tracker'   => home_url( '/tracker/' ),
				'guide'     => home_url( '/guide/' ),
				'placement' => ( $pl = get_user_meta( $uid, 'sawa_lens_placement', true ) ) && is_array( $pl ) ? array( 'level' => $pl['level'], 'score' => $pl['score'] ) : null,
			)
		);
	}

	/**
	 * Today's plan: 4 small steps, worked out on the server. Each step done pays +5 XP once a day,
	 * and the whole plan pays a +30 XP "perfect day" bonus.
	 */
	public static function today_plan() {
		global $wpdb;
		$uid   = get_current_user_id();
		$today = Sawa_Lens_Store::today();
		$rv    = Sawa_Lens_Review::stats( $uid );
		$c     = function ( $sql ) use ( $wpdb, $uid, $today ) {
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $uid, $today ) ); // phpcs:ignore
		};
		$new_words = $c( 'SELECT COUNT(*) FROM ' . Sawa_Lens_Store::t( 'words' ) . ' WHERE user_id = %d AND learning_on = %s' );
		$new_sents = $c( 'SELECT COUNT(*) FROM ' . Sawa_Lens_Store::t( 'sentences' ) . ' WHERE user_id = %d AND created_on = %s AND deleted = 0' );
		$quizzes   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Sawa_Lens_Store::t( 'lesson_results' ) . ' WHERE user_id = %d AND updated_at >= %s', $uid, $today . ' 00:00:00' ) ); // phpcs:ignore
		$why       = array();
		$studied   = false;
		if ( class_exists( 'Sawa_Tracker_XP' ) ) {
			$why     = (array) Sawa_Tracker_XP::summary( $uid )['why'];
			$studied = (bool) Sawa_Tracker_Game::live( $uid )['studiedToday'];
		}
		$lesson = null;
		foreach ( Sawa_Lens_Lessons::list_for( $uid ) as $l ) {
			if ( $l['answered'] < $l['total'] ) {
				$lesson = $l;
				break;
			}
		}
		$items = array();
		if ( $rv['total'] > 0 ) {
			$items[] = array( 'k' => 'review', 'done' => 0 === $rv['due'] && $rv['today'] > 0, 't' => $rv['due'] ? 'Review ' . min( 40, $rv['due'] ) . ' card' . ( 1 === $rv['due'] ? '' : 's' ) : ( $rv['today'] ? 'Cards reviewed' : 'No cards due today' ), 'sub' => '5 minutes · the most important step', 'go' => 'review' );
			if ( 0 === $rv['due'] && 0 === $rv['today'] ) {
				$items[0]['done'] = true; // nothing to do is fine
			}
		} else {
			$items[] = array( 'k' => 'collect', 'done' => $new_words + $new_sents >= 3, 't' => 'Save 3 new words', 'sub' => 'From a video, a page you scan, or a quiz · they become review cards', 'go' => 'scan' );
		}
		$items[] = array( 'k' => 'watch', 'done' => $quizzes > 0 || $new_words + $new_sents > 0, 't' => 'Read something in English and save new words', 'sub' => 'Learn → Read a text (paste any text) or Scan a page · or mark words with Language Lens', 'go' => 'read' );
		$items[] = array( 'k' => 'use', 'done' => ! empty( $why['chat'] ) || ! empty( $why['voice'] ) || ! empty( $why['write'] ) || ! empty( $why['speak'] ), 't' => 'Write or say something in English', 'sub' => 'A message in the chat, or a few sentences in Write', 'go' => 'chat' );
		$items[] = array( 'k' => 'log', 'done' => $studied, 't' => $studied ? 'Study day counted' : 'Count today as a study day', 'sub' => 'Anything above counts automatically · or log other study', 'go' => 'log' );

		if ( class_exists( 'Sawa_Tracker_Replies' ) ) { // the chat's reply goal: an extra step that doesn't decide a perfect day
			$g       = Sawa_Tracker_Replies::goal( $uid );
			$items[] = array( 'k' => 'reply', 'optional' => true, 'done' => (bool) $g['done'], 't' => 'Reply to ' . $g['goal'] . ' messages in the chat', 'sub' => min( $g['n'], $g['goal'] ) . ' / ' . $g['goal'] . ' today · ' . $g['min'] . '+ English words each · optional, +' . $g['bonus'] . ' XP bonus', 'go' => 'chat' );
		}

		// Rewards, once per day.
		$paid = get_user_meta( $uid, 'sawa_lens_plan', true );
		if ( ! is_array( $paid ) || ( $paid['day'] ?? '' ) !== $today ) {
			$paid = array( 'day' => $today, 'items' => array(), 'bonus' => false );
		}
		$gained = 0;
		foreach ( $items as $it ) {
			if ( $it['done'] && empty( $paid['items'][ $it['k'] ] ) ) {
				$paid['items'][ $it['k'] ] = 1;
				$gained += Sawa_Lens_Skills::xp( $uid, 5, 'plan', false );
				Sawa_Lens_Skills::coins( $uid, 5 );
				do_action( 'sawa_lens_plan_step', $uid, $it['k'] );
			}
		}
		$all = ! array_filter( $items, function ( $it ) { return ! $it['done'] && empty( $it['optional'] ); } );
		if ( $all && ! $paid['bonus'] ) {
			$paid['bonus'] = true;
			$gained       += Sawa_Lens_Skills::xp( $uid, 30, 'plan', false );
			Sawa_Lens_Skills::coins( $uid, 20 );
			if ( class_exists( 'Sawa_Tracker_RPG' ) ) {
				Sawa_Tracker_RPG::add_elixirs( $uid, 1 );
			}
			do_action( 'sawa_lens_plan_step', $uid, 'perfect' );
		}
		update_user_meta( $uid, 'sawa_lens_plan', $paid );
		return self::out(
			array(
				'items'   => $items,
				'perfect' => $all,
				'gained'  => $gained,
				'due'     => $rv['due'],
				'minutes' => Sawa_Lens_Activity::today( $uid ),
			)
		);
	}

	public static function videos() {
		// Sherif's free YouTube courses (Sawa Site plugin), for when there are no quizzes yet.
		$free = array();
		foreach ( get_posts( array( 'post_type' => 'sawa_resource', 'post_status' => 'publish', 'posts_per_page' => 12 ) ) as $p ) {
			if ( preg_match( '~(?:v=|youtu\.be/)([\w-]{11})~', (string) get_post_meta( $p->ID, '_sr_url', true ), $m ) ) {
				$free[] = array( 'videoId' => $m[1], 'title' => get_the_title( $p ) );
			}
		}
		return self::out( array( 'lessons' => Sawa_Lens_Lessons::list_for( get_current_user_id() ), 'free' => $free ) );
	}

	public static function video( WP_REST_Request $r ) {
		$l = Sawa_Lens_Lessons::get( (int) $r->get_param( 'id' ) );
		if ( ! $l || ! $l['published'] ) {
			return new WP_Error( 'lesson', 'This quiz is not available.', array( 'status' => 404 ) );
		}
		return self::out( Sawa_Lens_Lessons::for_student( $l, get_current_user_id() ) );
	}

	public static function video_answer( WP_REST_Request $r ) {
		$res = Sawa_Lens_Lessons::answer( get_current_user_id(), (int) $r->get_param( 'id' ), sanitize_key( (string) $r->get_param( 'qid' ) ), $r->get_param( 'a' ) );
		return is_wp_error( $res ) ? $res : self::out( $res );
	}

	/** Save a sentence or phrase from the web app (scan, review…): same as saving it in the extension. */
	public static function library_save( WP_REST_Request $r ) {
		$uuid = wp_generate_uuid4();
		Sawa_Lens_Store::put_sentences(
			get_current_user_id(),
			array(
				array(
					'uuid'  => $uuid,
					'text'  => (string) $r->get_param( 'text' ),
					'focus' => (string) $r->get_param( 'focus' ),
					'kind'  => (string) $r->get_param( 'kind' ),
					'title' => (string) $r->get_param( 'title' ),
					't'     => (int) round( microtime( true ) * 1000 ),
				),
			)
		);
		return self::out( array( 'uuid' => $uuid ) );
	}

	/** Photo of a page → text (Claude vision). Body: { image: base64, mime }. */
	public static function scan( WP_REST_Request $r ) {
		$res = Sawa_Lens_AI::scan( get_current_user_id(), preg_replace( '/\s+/', '', (string) $r->get_param( 'image' ) ), (string) $r->get_param( 'mime' ) );
		if ( ! is_wp_error( $res ) ) {
			Sawa_Lens_Activity::add( get_current_user_id(), 'scan' );
		}
		return is_wp_error( $res ) ? $res : self::out( $res );
	}

	public static function readaloud( WP_REST_Request $r ) {
		$id = sanitize_key( (string) $r->get_param( 'id' ) );
		$k  = Sawa_Lens_Skills::get( $id );
		if ( ! $k || 'pron' !== $k['track'] ) {
			return new WP_Error( 'skill', 'Unknown pronunciation point.', array( 'status' => 404 ) );
		}
		$res = Sawa_Lens_AI::readaloud( get_current_user_id(), $id, (int) $r->get_param( 'v' ) );
		return is_wp_error( $res ) ? $res : self::out( $res );
	}

	/** Words of a sentence, lower-case, for comparing what was said with what was meant. */
	private static function words( $s ) {
		preg_match_all( "/[a-z0-9]+(?:'[a-z]+)?/", strtolower( str_replace( array( '’', '‘' ), "'", (string) $s ) ), $m );
		return $m[0];
	}

	/**
	 * Speaking: an uploaded recording (max 2 minutes) is transcribed and deleted at once.
	 *   With "target" (read aloud): every target word is marked heard / not heard; pronunciation practice is recorded.
	 *   Without (free speaking): the transcript is checked like writing, and mistakes feed the skill tree.
	 */
	public static function speak( WP_REST_Request $r ) {
		$uid   = get_current_user_id();
		$files = $r->get_file_params();
		$f     = $files['audio'] ?? null;
		if ( ! $f || ! empty( $f['error'] ) || (int) $f['size'] > 6 * MB_IN_BYTES || ! preg_match( '~^(audio|video)/~', (string) $f['type'] ) ) {
			return new WP_Error( 'audio', 'The recording didn\'t arrive. Try again (max 2 minutes).', array( 'status' => 400 ) );
		}
		$ext = preg_match( '~mp4|m4a|aac~', $f['type'] ) ? 'm4a' : ( false !== strpos( $f['type'], 'ogg' ) ? 'ogg' : 'webm' );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = wp_tempnam( 'sawa-speak' ) . '.' . $ext;
		if ( ! move_uploaded_file( $f['tmp_name'], $tmp ) && ! copy( $f['tmp_name'], $tmp ) ) {
			return new WP_Error( 'audio', 'Could not read the recording.', array( 'status' => 500 ) );
		}
		$text = Sawa_Lens_AI::transcribe( $uid, $tmp );
		wp_delete_file( $tmp );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$target = trim( (string) $r->get_param( 'target' ) );
		$secs   = max( 1, min( 120, (int) $r->get_param( 'seconds' ) ) );
		if ( '' !== $target ) {
			$want  = self::words( $target );
			$heard = self::words( $text );
			// Longest common subsequence: which target words were heard, in order.
			$n  = count( $want );
			$m  = count( $heard );
			$dp = array_fill( 0, $n + 1, array_fill( 0, $m + 1, 0 ) );
			for ( $i = $n - 1; $i >= 0; $i-- ) {
				for ( $j = $m - 1; $j >= 0; $j-- ) {
					$dp[ $i ][ $j ] = $want[ $i ] === $heard[ $j ] ? $dp[ $i + 1 ][ $j + 1 ] + 1 : max( $dp[ $i + 1 ][ $j ], $dp[ $i ][ $j + 1 ] );
				}
			}
			$ok = array_fill( 0, $n, false );
			for ( $i = 0, $j = 0; $i < $n && $j < $m; ) {
				if ( $want[ $i ] === $heard[ $j ] ) {
					$ok[ $i ] = true;
					$i++;
					$j++;
				} elseif ( $dp[ $i + 1 ][ $j ] >= $dp[ $i ][ $j + 1 ] ) {
					$i++;
				} else {
					$j++;
				}
			}
			$words = array();
			foreach ( $want as $i => $w ) {
				$words[] = array( 'w' => $w, 'ok' => $ok[ $i ] );
			}
			$targets = array_map( 'strtolower', (array) $r->get_param( 'targets' ) );
			$res     = array();
			foreach ( $words as $w ) {
				if ( ! $targets || in_array( $w['w'], $targets, true ) ) {
					$res[] = $w['ok'] ? 1 : 0;
				}
			}
			$skill = sanitize_key( (string) $r->get_param( 'skill' ) );
			if ( $skill && Sawa_Lens_Skills::get( $skill ) ) {
				Sawa_Lens_Skills::record_practice( $uid, $skill, array( $res && array_sum( $res ) / count( $res ) >= 0.8 ? 1 : 0 ), 0 );
			}
			$xp = Sawa_Lens_Skills::xp( $uid, 3, 'speak' );
			Sawa_Lens_Activity::add( $uid, '', 1, 'Pronunciation/Grammar', 1 );
			return self::out(
				array(
					'transcript' => $text,
					'words'      => $words,
					'score'      => $n ? (int) round( 100 * array_sum( $ok ) / $n ) : 0,
					'xp'         => $xp,
				)
			);
		}
		if ( count( self::words( $text ) ) < 3 ) {
			return self::out( array( 'transcript' => $text, 'short' => true ) );
		}
		$before = Sawa_Lens_Skills::tree( $uid );
		$res    = Sawa_Lens_AI::check_writing( $uid, $text, 'voice' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		global $wpdb;
		$words = count( self::words( $text ) );
		$wpdb->insert( Sawa_Lens_Store::t( 'writing' ), array( 'user_id' => $uid, 'source' => 'voice', 'body' => $text, 'result' => wp_json_encode( $res ), 'words' => $words, 'errors' => count( $res['errors'] ), 'created_at' => current_time( 'mysql' ) ) ); // phpcs:ignore
		Sawa_Lens_Skills::log_errors( $uid, $res['errors'], 'voice', (int) $wpdb->insert_id );
		Sawa_Lens_Skills::record_uses( $uid, $res['good'] );
		$out            = self::tree_out( $uid );
		$out['changed'] = self::changes( $before, $out['tree'] );
		$out['result']  = $res;
		$out['text']    = $text;
		$out['words']   = $words;
		$out['xp']      = Sawa_Lens_Skills::xp( $uid, 5 + intdiv( $secs, 10 ), 'voice' );
		Sawa_Lens_Activity::add( $uid, '', 1, 'Pronunciation/Grammar', max( 1, $secs / 30 ) );
		return self::out( $out );
	}

	/** AI meaning for a word or phrase that isn't in the dictionary, using a sentence the learner saved with it. */
	public static function library_explain( WP_REST_Request $r ) {
		global $wpdb;
		$uid  = get_current_user_id();
		$word = mb_substr( trim( sanitize_text_field( (string) $r->get_param( 'word' ) ) ), 0, 60 );
		$given = trim( (string) $r->get_param( 'sentence' ) );
		$sent = '' !== $given ? mb_substr( $given, 0, 600 ) : (string) $wpdb->get_var( $wpdb->prepare( 'SELECT body FROM ' . Sawa_Lens_Store::t( 'sentences' ) . ' WHERE user_id = %d AND deleted = 0 AND body LIKE %s ORDER BY id DESC LIMIT 1', $uid, '%' . $wpdb->esc_like( $word ) . '%' ) ); // phpcs:ignore
		$lang = Sawa_Lens_AI::explain_lang( $uid ); // English by default, Arabic when the student chose it
		$res  = Sawa_Lens_AI::explain( $uid, $word, '' !== $sent ? $sent : $word, $lang, $lang );
		return is_wp_error( $res ) ? $res : self::out( $res + array( 'example' => $sent ) );
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
