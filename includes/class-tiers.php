<?php
/**
 * Free and Member (paid) accounts, shared by every Sawa app.
 *
 * - Anyone can sign up and use their account at once as "free". Sherif ticks "Member (paid)" in wp-admin → Users.
 * - Settings → Free vs Member: a checkbox per feature per plan. Default: free = Review only.
 * - Every REST call of Language Lens, the app and the tracker is checked here (rest_dispatch_request, after sign-in),
 *   so a locked feature is locked on the server, not only hidden in the app.
 * - User meta sawa_tier = free | member. No meta + an approved account = member (everyone approved before 0.25.0,
 *   and the review-gift accounts), so nobody loses anything.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sawa_Lens_Tiers {

	const META = 'sawa_tier';
	const OPT  = 'sawa_lens_tiers';

	public static function init() {
		add_filter( 'rest_dispatch_request', array( __CLASS__, 'gate' ), 5, 4 );
		add_filter( 'sawa_tracker_front_cfg', array( __CLASS__, 'front_cfg' ), 10, 2 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'migrate' ) );
			add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
			add_action( 'admin_post_sawa_lens_tiers_save', array( __CLASS__, 'save' ) );
			add_action( 'admin_post_sawa_lens_tier', array( __CLASS__, 'toggle' ) );
			add_filter( 'manage_users_columns', array( __CLASS__, 'column' ) );
			add_filter( 'manage_users_custom_column', array( __CLASS__, 'cell' ), 10, 3 );
			add_filter( 'views_users', array( __CLASS__, 'views' ) );
			add_action( 'pre_get_users', array( __CLASS__, 'filter_users' ) );
			add_filter( 'bulk_actions-users', array( __CLASS__, 'bulk_actions' ) );
			add_filter( 'handle_bulk_actions-users', array( __CLASS__, 'bulk' ), 10, 3 );
			add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		}
	}

	/* ------------------------------------------------------------------ features */

	/** key => [English, Arabic]. Add a feature here and to rules() to make it lockable. */
	public static function features() {
		return array(
			'review'        => array( 'Review cards and saved words (Language Lens + the app\'s Review tab)', 'مراجعة الكروت والكلمات المحفوظة' ),
			'lens_meanings' => array( 'Language Lens: word meanings on YouTube and websites', 'Language Lens: معاني الكلمات على يوتيوب والمواقع' ),
			'lens_ai'       => array( 'Language Lens: AI explanations, translations and sounds', 'Language Lens: شرح وترجمة ونطق بالذكاء الاصطناعي' ),
			'lens_proof'    => array( 'Language Lens: proofreader', 'Language Lens: تصحيح الكتابة' ),
			'home'          => array( 'Sawa App: Home (today\'s plan and progress)', 'تطبيق سوا: الرئيسية' ),
			'writing'       => array( 'Sawa App: Write & speak (writing checker)', 'تطبيق سوا: اكتب واتكلم' ),
			'learn'         => array( 'Sawa App: skill tree, lessons, Read a text and Scan a page', 'تطبيق سوا: المهارات والدروس والقراءة' ),
			'stories'       => array( 'Stories, songs, radio and reels', 'القصص والأغاني والراديو والريلز' ),
			'tracker'       => array( 'Study tracker and leaderboards', 'تراكر المذاكرة والترتيب' ),
			'game'          => array( 'Silk Road game, raids and caravans', 'لعبة طريق الحرير والغارات والقوافل' ),
			'community'     => array( 'Chat, clans and groups', 'الشات والقبائل والجروبات' ),
		);
	}

	/** Free during the Language Lens beta (decided 2026-10-23): all of Language Lens + Review in the app. */
	const FREE_DEFAULT = array( 'review', 'lens_meanings', 'lens_ai', 'lens_proof' );

	/**
	 * Which plan gets which feature (Settings → Free vs Member). A feature added after the settings were saved
	 * ("known" doesn't list it) gets its default: on for members, FREE_DEFAULT for free.
	 */
	public static function map() {
		$all   = array_keys( self::features() );
		$m     = get_option( self::OPT );
		$m     = is_array( $m ) ? $m : array();
		$known = isset( $m['known'] ) ? (array) $m['known'] : array( 'review', 'lens_meanings', 'lens_ai', 'writing', 'learn', 'stories', 'tracker', 'game', 'community' );
		$out   = array();
		foreach ( array( 'free' => self::FREE_DEFAULT, 'member' => $all ) as $t => $def ) {
			$saved     = isset( $m[ $t ] ) ? (array) $m[ $t ] : $def;
			$new       = array_intersect( array_diff( $all, $known ), $def );
			$out[ $t ] = array_values( array_intersect( $all, array_merge( $saved, $new ) ) );
		}
		return $out;
	}

	/** Sign-up opens a free account at once (no approval). Off = the old "ask to join" queue. */
	public static function instant() {
		$m = get_option( self::OPT );
		return ! is_array( $m ) || ! isset( $m['instant'] ) || ! empty( $m['instant'] );
	}

	/* ------------------------------------------------------------------ accounts */

	/** The account can sign in to the apps (not declined, not waiting). */
	public static function active( $uid ) {
		return $uid && ( user_can( $uid, 'manage_options' ) || 'approved' === get_user_meta( $uid, 'sawa_status', true ) );
	}

	/** 'member', 'free', or '' (no usable account). */
	public static function tier( $uid ) {
		if ( ! self::active( $uid ) ) {
			return '';
		}
		if ( user_can( $uid, 'manage_options' ) ) {
			return 'member';
		}
		$t = get_user_meta( $uid, self::META, true );
		return 'free' === $t ? 'free' : 'member'; // no meta = approved before plans existed = member
	}

	public static function can( $uid, $feature ) {
		if ( $uid && user_can( $uid, 'manage_options' ) ) {
			return true;
		}
		$t = self::tier( $uid );
		return $t && in_array( $feature, self::map()[ $t ], true );
	}

	/** The features this student can use, for the apps. */
	public static function allowed( $uid ) {
		$t = self::tier( $uid );
		return $t ? ( user_can( $uid, 'manage_options' ) ? array_keys( self::features() ) : self::map()[ $t ] ) : array();
	}

	public static function set_tier( $uid, $tier ) {
		update_user_meta( $uid, self::META, 'member' === $tier ? 'member' : 'free' );
		do_action( 'sawa_tier_changed', (int) $uid, $tier );
	}

	/** Where "Join Sawa English" goes: the website's join page, else Skool. */
	public static function join_url() {
		if ( class_exists( 'Sawa_Site' ) ) {
			$u = Sawa_Site::url( 'sawa-join' );
			return $u ? $u : Sawa_Site::s( 'join_url' );
		}
		return 'https://www.skool.com/sawa-english-7655';
	}

	/** What every app gets about the student's plan (Language Lens /me, the app, the tracker). */
	public static function info( $uid ) {
		return array(
			'tier'     => self::tier( $uid ),
			'features' => self::allowed( $uid ),
			'locked'   => array_values( array_diff( array_keys( self::features() ), self::allowed( $uid ) ) ),
			'join'     => self::join_url(),
		);
	}

	public static function locked_error( $feature ) {
		return new WP_Error(
			'locked',
			'Members only — join Sawa English to use this.',
			array(
				'status'    => 403,
				'feature'   => $feature,
				'join'      => self::join_url(),
				'message_ar' => 'للأعضاء بس — اشترك في سوا انجليش عشان تستخدم دي.',
			)
		);
	}

	/* ------------------------------------------------------------------ settings */

	public static function opt( $key, $def ) {
		$m = get_option( self::OPT );
		return is_array( $m ) && isset( $m[ $key ] ) ? $m[ $key ] : $def;
	}

	/** AI answers a day for a free account (members: Settings → Language Lens). */
	public static function free_ai() {
		return max( 0, (int) self::opt( 'free_ai', 30 ) );
	}

	/** The Google sign-in client ID (Google Cloud → Credentials → OAuth client, type Web). Empty = no Google button. */
	public static function google_id() {
		return (string) self::opt( 'google_id', '' );
	}

	/* ------------------------------------------------------------------ sign-up limits */

	const CF = array( '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22', '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32' );

	/**
	 * The visitor's address for limits. The site is behind Cloudflare: its CF-Connecting-IP header is trusted only
	 * when the request really comes from a Cloudflare address (anyone reaching the server directly could fake it).
	 */
	public static function client_ip() {
		$remote = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore
		$cf     = (string) ( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '' ); // phpcs:ignore
		if ( $cf && filter_var( $cf, FILTER_VALIDATE_IP ) && self::from_cloudflare( $remote ) ) {
			return $cf;
		}
		return $remote;
	}

	private static function from_cloudflare( $ip ) {
		$bin = @inet_pton( $ip ); // phpcs:ignore
		if ( false === $bin ) {
			return false;
		}
		foreach ( self::CF as $range ) {
			list( $net, $bits ) = explode( '/', $range );
			$n = inet_pton( $net );
			if ( strlen( $n ) !== strlen( $bin ) ) {
				continue;
			}
			$bytes = intdiv( (int) $bits, 8 );
			$rest  = (int) $bits % 8;
			if ( substr( $bin, 0, $bytes ) !== substr( $n, 0, $bytes ) ) {
				continue;
			}
			if ( ! $rest || ( ( ord( $bin[ $bytes ] ) ^ ord( $n[ $bytes ] ) ) & ( 0xFF << ( 8 - $rest ) ) & 0xFF ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	const THROWAWAY = array( 'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'sharklasers.com', '10minutemail.com', 'tempmail.com', 'temp-mail.org', 'yopmail.com', 'trashmail.com', 'getnada.com', 'dispostable.com', 'maildrop.cc', 'throwawaymail.com', 'fakeinbox.com', 'mintemail.com', 'mohmal.com', 'emailondeck.com', 'tempail.com', 'moakt.com', 'spambox.us' );

	/**
	 * Instant sign-up is open to everyone, so it has limits: per connection (3 an hour, 6 a day), for the whole site
	 * (40 an hour), no throwaway email services, and the email's domain must accept mail.
	 * Returns '' when the sign-up may go ahead, or a message [English, Arabic]. Call count_signup() after it worked.
	 */
	public static function signup_check( $email, $ip, $check_domain = true ) {
		$k = md5( $ip );
		if ( (int) get_transient( 'sawa_su_h_' . $k ) >= 3 || (int) get_transient( 'sawa_su_d_' . $k ) >= 6 ) {
			return array( 'Too many sign-ups from this connection. Please try again later.', 'تسجيلات كتير من نفس الاتصال. جرب تاني بعد شوية.' );
		}
		if ( (int) get_transient( 'sawa_su_all' ) >= 40 ) {
			return array( 'Sign-up is busy right now. Please try again in an hour.', 'التسجيل زحمة دلوقتي. جرب تاني بعد ساعة.' );
		}
		$domain = strtolower( substr( strrchr( $email, '@' ), 1 ) );
		if ( in_array( $domain, self::THROWAWAY, true ) ) {
			return array( 'Please use your real email address.', 'اكتب إيميلك الحقيقي.' );
		}
		$common = array( 'gmail.com', 'hotmail.com', 'outlook.com', 'yahoo.com', 'icloud.com', 'live.com' );
		if ( $check_domain && ! in_array( $domain, $common, true ) && self::dns_works() && ! checkdnsrr( $domain, 'MX' ) && ! checkdnsrr( $domain, 'A' ) ) {
			return array( 'This email address cannot get mail. Please check it.', 'الإيميل ده مش بيستقبل رسايل. راجعه تاني.' );
		}
		return '';
	}

	/** Only judge a domain when the server can look domains up at all (never block everyone over a DNS hiccup). */
	private static function dns_works() {
		if ( ! function_exists( 'checkdnsrr' ) ) {
			return false;
		}
		$v = get_transient( 'sawa_su_dns' );
		if ( false === $v ) {
			$v = checkdnsrr( 'gmail.com', 'MX' ) ? 1 : 0;
			set_transient( 'sawa_su_dns', $v, HOUR_IN_SECONDS );
		}
		return (bool) $v;
	}

	public static function count_signup( $ip ) {
		$k = md5( $ip );
		foreach ( array( array( 'sawa_su_h_' . $k, HOUR_IN_SECONDS ), array( 'sawa_su_d_' . $k, DAY_IN_SECONDS ), array( 'sawa_su_all', HOUR_IN_SECONDS ) ) as $t ) {
			set_transient( $t[0], (int) get_transient( $t[0] ) + 1, $t[1] );
		}
	}

	/* ------------------------------------------------------------------ the server check */

	/** Route (without the namespace) → feature. null = open to every account (sign-in, settings, level test, feedback). */
	public static function feature_for( $route ) {
		$rules = array(
			'#^/sawa-lens/v1/(me|settings|logout|lang|daily|web/progress|pt/|app/(prefs|interests|band|journey|daily)$)#' => null,
			'#^/sawa-lens/v1/app/today#' => 'home',
			'#^/sawa-lens/v1/(sync|curious|image$|app/review|app/library$|app/library/(word|sentence|save)$|app/image$)#' => 'review',
			'#^/sawa-lens/v1/(explain|translate|to-english|sound|lyric|song-lines|image/ai|app/library/(translate|explain)|app/image/ai)#' => 'lens_ai',
			'#^/sawa-lens/v1/app/(readaloud|speak)#' => 'lens_meanings', // Read a text: listen to it
			'#^/sawa-lens/v1/app/scan#' => 'learn', // Scan a page (photo → text)
			'#^/sawa-lens/v1/proof#' => 'lens_proof',
			'#^/sawa-lens/v1/app/(write|history|writing)#' => 'writing',
			'#^/sawa-lens/v1/(video/|lesson/|lessons/|app/(tree|skill|practice|videos?|video/))#' => 'learn',
			'#^/sawa-lens/v1/app/(readers|songs|radio|reels|reading|adapt)#' => 'stories',
			'#^/sawa-tracker/v1/(me|request|feedback|ideas|roadmap|lang)#' => null,
			'#^/sawa-tracker/v1/(world|play|raids|party|coop|quest|clan/(heal|revive))#' => 'game',
			'#^/sawa-tracker/v1/(chat|clan|members|reminder)#' => 'community',
			'#^/sawa-tracker/v1/#' => 'tracker',
		);
		foreach ( $rules as $re => $f ) {
			if ( preg_match( $re, $route ) ) {
				return $f;
			}
		}
		return null;
	}

	/** Runs after the route's own sign-in check, so the student is known (cookie or Language Lens token). */
	public static function gate( $result, $request, $route, $handler ) {
		if ( null !== $result || ( 0 !== strpos( $route, '/sawa-lens/' ) && 0 !== strpos( $route, '/sawa-tracker/' ) ) ) {
			return $result;
		}
		$f   = self::feature_for( $route );
		$uid = get_current_user_id();
		if ( $f && $uid && ! self::can( $uid, $f ) ) {
			return self::locked_error( $f );
		}
		return $result;
	}

	public static function front_cfg( $cfg, $uid ) {
		$cfg['plan']    = $uid ? self::info( $uid ) : null;
		$cfg['instant'] = self::instant(); // the join form says "free account, opens at once"
		if ( ! $uid && self::google_id() ) {
			$cfg['google']    = self::google_id(); // "Continue with Google" on the sign-in and join screens
			$cfg['googleUrl'] = esc_url_raw( rest_url( 'sawa-lens/v1/google' ) );
		}
		if ( ! $uid && isset( $_GET['login_failed'] ) ) { // phpcs:ignore -- a wrong email or password comes back here, not to wp-login.php
			$cfg['loginFailed'] = true;
		}
		return $cfg;
	}

	/* ------------------------------------------------------------------ Google sign-in */

	public static function routes() {
		register_rest_route( 'sawa-lens/v1', '/google', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'google' ), 'permission_callback' => '__return_true' ) );
	}

	/**
	 * "Continue with Google": asks Google to check its signed ID token, then signs the student in, or makes a new
	 * free account (same limits as the sign-up form). Admin accounts can't sign in this way.
	 */
	public static function google( WP_REST_Request $r ) {
		$no = function ( $en, $ar, $code = 400 ) {
			return new WP_Error( 'google', class_exists( 'Sawa_Lang' ) && Sawa_Lang::is_ar() ? $ar : $en, array( 'status' => $code ) );
		};
		$cid = self::google_id();
		$jwt = (string) $r->get_param( 'credential' );
		if ( ! $cid || ! preg_match( '/^[\w-]+\.[\w-]+\.[\w-]+$/', $jwt ) ) {
			return $no( 'Google sign-in is not available.', 'الدخول بجوجل مش متاح.' );
		}
		$res = wp_remote_get( 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode( $jwt ), array( 'timeout' => 10 ) );
		$t   = is_wp_error( $res ) ? null : json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $t ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) || ( $t['aud'] ?? '' ) !== $cid
			|| ! in_array( $t['iss'] ?? '', array( 'accounts.google.com', 'https://accounts.google.com' ), true )
			|| (int) ( $t['exp'] ?? 0 ) < time() || 'true' !== (string) ( $t['email_verified'] ?? '' ) || ! is_email( $t['email'] ?? '' ) ) {
			return $no( 'Google sign-in did not work. Please try again.', 'الدخول بجوجل منفعش. جرب تاني.', 401 );
		}
		$email = sanitize_email( $t['email'] );
		$uid   = (int) email_exists( $email );
		if ( $uid && user_can( $uid, 'manage_options' ) ) {
			return $no( 'Please sign in with your password.', 'سجّل دخول بالباسورد.', 403 );
		}
		if ( ! $uid ) {
			$ip = self::client_ip();
			$x  = self::signup_check( $email, $ip, false );
			if ( $x ) {
				return $no( $x[0], $x[1], 429 );
			}
			$name  = sanitize_text_field( (string) ( $t['name'] ?? '' ) );
			$login = sanitize_user( strtolower( strtok( $email, '@' ) ), true );
			$base  = $login ? $login : 'student';
			$i     = 1;
			while ( '' === $login || username_exists( $login ) ) {
				$login = $base . ( ++$i );
			}
			$uid = wp_insert_user( array( 'user_login' => $login, 'user_email' => $email, 'user_pass' => wp_generate_password( 24 ), 'display_name' => $name ? $name : $login, 'first_name' => sanitize_text_field( (string) ( $t['given_name'] ?? '' ) ), 'role' => 'subscriber' ) );
			if ( is_wp_error( $uid ) ) {
				return $no( 'Could not make your account. Please try again.', 'معرفناش نعمل حسابك. جرب تاني.' );
			}
			if ( self::instant() ) {
				update_user_meta( $uid, self::META, 'free' );
				update_user_meta( $uid, 'sawa_status', 'approved' );
			} else {
				update_user_meta( $uid, 'sawa_status', 'pending' );
			}
			update_user_meta( $uid, 'sawa_requested', current_time( 'mysql' ) );
			update_user_meta( $uid, 'sawa_google', 1 );
			self::count_signup( $ip );
		}
		wp_set_current_user( $uid );
		wp_set_auth_cookie( $uid, true, is_ssl() );
		return array( 'ok' => true );
	}

	/* ------------------------------------------------------------------ one-time switch */

	/** 0.25.0: people still waiting for approval get a free account now. Review-gift requests stay for Sherif. */
	public static function migrate() {
		$v = (int) get_option( 'sawa_lens_tiers_v' );
		if ( $v < 2 ) {
			// 0.26.1: the Language Lens beta decision. Saved settings get Language Lens opened for free accounts.
			update_option( 'sawa_lens_tiers_v', 2, false );
			$m = get_option( self::OPT );
			if ( is_array( $m ) && isset( $m['free'] ) ) {
				$m['free'] = array_values( array_unique( array_merge( (array) $m['free'], self::FREE_DEFAULT ) ) );
				update_option( self::OPT, $m, true );
			}
		}
		if ( $v ) {
			return;
		}
		$ids = get_users( array( 'meta_key' => 'sawa_status', 'meta_value' => 'pending', 'fields' => 'ID', 'number' => 2000 ) ); // phpcs:ignore
		foreach ( $ids as $id ) {
			if ( get_user_meta( $id, '_sawa_gift', true ) ) {
				continue;
			}
			update_user_meta( $id, self::META, 'free' );
			update_user_meta( $id, 'sawa_status', 'approved' );
		}
	}

	/* ------------------------------------------------------------------ Settings → Free vs Member */

	public static function menu() {
		add_options_page( 'Free vs Member', 'Free vs Member', 'manage_options', 'sawa-tiers', array( __CLASS__, 'page' ) );
	}

	public static function page() {
		$map = self::map();
		$cnt = self::counts();
		?>
		<div class="wrap">
			<h1>Free vs Member</h1>
			<p>Anyone can sign up for the Sawa App and Language Lens. A new account is <b>Free</b> and can use only what is ticked under Free.
				To make someone a paying <b>Member</b>, tick "Member (paid)" next to their name in <a href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>">Users</a>.
				Members today: <a href="<?php echo esc_url( admin_url( 'users.php?sawa_tier=member' ) ); ?>"><?php echo (int) $cnt['member']; ?></a> ·
				Free: <a href="<?php echo esc_url( admin_url( 'users.php?sawa_tier=free' ) ); ?>"><?php echo (int) $cnt['free']; ?></a></p>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore ?>
				<div class="notice notice-success"><p>Saved. Students see the change the next time they open the app.</p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sawa_lens_tiers_save">
				<?php wp_nonce_field( 'sawa_lens_tiers_save' ); ?>
				<table class="widefat striped" style="max-width:760px">
					<thead><tr><th>Feature</th><th style="text-align:center">Free</th><th style="text-align:center">Member (paid)</th></tr></thead>
					<tbody>
					<?php foreach ( self::features() as $k => $l ) : ?>
						<tr>
							<td><?php echo esc_html( $l[0] ); ?></td>
							<?php foreach ( array( 'free', 'member' ) as $t ) : ?>
								<td style="text-align:center"><input type="checkbox" name="<?php echo esc_attr( $t ); ?>[]" value="<?php echo esc_attr( $k ); ?>" aria-label="<?php echo esc_attr( $l[0] . ' — ' . $t ); ?>" <?php checked( in_array( $k, $map[ $t ], true ) ); ?>></td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><label><input type="checkbox" name="instant" value="1" <?php checked( self::instant() ); ?>> New sign-ups can use their free account right away (no approval).</label></p>
				<p><label>AI answers a day for a free account (if you tick an AI feature for Free): <input type="number" name="free_ai" min="0" style="width:80px" value="<?php echo (int) self::free_ai(); ?>"></label></p>
				<p><label>Google sign-in: client ID <input name="google_id" style="width:420px" placeholder="1234-abc.apps.googleusercontent.com" value="<?php echo esc_attr( self::google_id() ); ?>"></label><br>
					<span class="description">Empty = no Google button. Google Cloud → APIs &amp; Services → Credentials → Create OAuth client ID (Web). Authorised JavaScript origin: <code><?php echo esc_html( home_url() ); ?></code>.</span></p>
				<p class="description">Locked features show 🔒 "Members only — join Sawa English" with a link to <a href="<?php echo esc_url( self::join_url() ); ?>" target="_blank"><?php echo esc_html( self::join_url() ); ?></a>. Admins can always use everything.</p>
				<?php submit_button( 'Save' ); ?>
			</form>
		</div>
		<?php
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sawa_lens_tiers_save' ) ) {
			wp_die( 'Not allowed.' );
		}
		$all = array_keys( self::features() );
		update_option(
			self::OPT,
			array(
				'free'    => array_values( array_intersect( $all, array_map( 'sanitize_key', (array) wp_unslash( $_POST['free'] ?? array() ) ) ) ),
				'member'  => array_values( array_intersect( $all, array_map( 'sanitize_key', (array) wp_unslash( $_POST['member'] ?? array() ) ) ) ),
				'known'     => $all,
				'instant'   => ! empty( $_POST['instant'] ),
				'free_ai'   => max( 0, (int) ( $_POST['free_ai'] ?? 30 ) ),
				'google_id' => preg_match( '/^[\w.-]+\.apps\.googleusercontent\.com$/', trim( (string) wp_unslash( $_POST['google_id'] ?? '' ) ) ) ? trim( (string) wp_unslash( $_POST['google_id'] ) ) : '',
			),
			true
		);
		wp_safe_redirect( admin_url( 'options-general.php?page=sawa-tiers&saved=1' ) );
		exit;
	}

	/* ------------------------------------------------------------------ wp-admin → Users */

	private static function member_query() {
		return array(
			'relation' => 'OR',
			array( 'key' => self::META, 'value' => 'member' ),
			array(
				'relation' => 'AND',
				array( 'key' => self::META, 'compare' => 'NOT EXISTS' ),
				array( 'key' => 'sawa_status', 'value' => 'approved' ),
			),
		);
	}

	private static function counts() {
		$n = function ( $mq ) {
			return (int) ( new WP_User_Query( array( 'meta_query' => $mq, 'fields' => 'ID', 'number' => 1, 'count_total' => true ) ) )->get_total(); // phpcs:ignore
		};
		return array(
			'member' => $n( self::member_query() ),
			'free'   => $n( array( array( 'key' => self::META, 'value' => 'free' ), array( 'key' => 'sawa_status', 'value' => 'approved' ) ) ),
		);
	}

	public static function column( $c ) {
		$c['sawa_tier'] = 'Member (paid)';
		return $c;
	}

	public static function cell( $out, $col, $uid ) {
		if ( 'sawa_tier' !== $col ) {
			return $out;
		}
		if ( user_can( $uid, 'manage_options' ) ) {
			return '<span title="Admins can use everything">Admin</span>';
		}
		$t = self::tier( $uid );
		if ( ! $t ) {
			$s = get_user_meta( $uid, 'sawa_status', true );
			return '<span class="description">' . ( 'denied' === $s ? 'Declined' : ( 'pending' === $s ? 'Waiting (review gift)' : '—' ) ) . '</span>';
		}
		$to  = 'member' === $t ? 'free' : 'member';
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=sawa_lens_tier&user=' . (int) $uid . '&to=' . $to ), 'sawa_lens_tier_' . $uid );
		return '<a href="' . esc_url( $url ) . '" style="text-decoration:none" title="' . esc_attr( 'member' === $t ? 'Paid member. Click to make Free.' : 'Free. Click to make a paid Member.' ) . '">'
			. ( 'member' === $t ? '<span style="color:#15803D;font-weight:600">☑ Member</span>' : '<span style="color:#8A8584">☐ Free</span>' ) . '</a>';
	}

	public static function toggle() {
		$uid = (int) ( $_GET['user'] ?? 0 ); // phpcs:ignore
		if ( ! current_user_can( 'edit_users' ) || ! $uid || ! check_admin_referer( 'sawa_lens_tier_' . $uid ) ) {
			wp_die( 'Not allowed.' );
		}
		if ( 'approved' !== get_user_meta( $uid, 'sawa_status', true ) ) {
			update_user_meta( $uid, 'sawa_status', 'approved' ); // ticking a declined/waiting person opens their account too
		}
		self::set_tier( $uid, 'member' === ( $_GET['to'] ?? '' ) ? 'member' : 'free' ); // phpcs:ignore
		wp_safe_redirect( add_query_arg( 'sawa_tiered', 1, wp_get_referer() ? wp_get_referer() : admin_url( 'users.php' ) ) );
		exit;
	}

	public static function views( $v ) {
		$cnt = self::counts();
		$cur = sanitize_key( $_GET['sawa_tier'] ?? '' ); // phpcs:ignore
		foreach ( array( 'member' => 'Members (paid)', 'free' => 'Free' ) as $k => $l ) {
			$v[ 'sawa_' . $k ] = '<a href="' . esc_url( admin_url( 'users.php?sawa_tier=' . $k ) ) . '"' . ( $cur === $k ? ' class="current" aria-current="page"' : '' ) . '>' . $l . ' <span class="count">(' . (int) $cnt[ $k ] . ')</span></a>';
		}
		return $v;
	}

	public static function filter_users( $q ) {
		global $pagenow;
		if ( 'users.php' !== $pagenow || ! is_admin() ) {
			return;
		}
		$t = sanitize_key( $_GET['sawa_tier'] ?? '' ); // phpcs:ignore
		if ( 'member' === $t ) {
			$q->set( 'meta_query', self::member_query() );
		} elseif ( 'free' === $t ) {
			$q->set( 'meta_query', array( array( 'key' => self::META, 'value' => 'free' ) ) );
		}
	}

	public static function bulk_actions( $a ) {
		$a['sawa_member'] = 'Make Member (paid)';
		$a['sawa_free']   = 'Make Free';
		return $a;
	}

	public static function bulk( $redirect, $action, $ids ) {
		if ( ! in_array( $action, array( 'sawa_member', 'sawa_free' ), true ) || ! current_user_can( 'edit_users' ) ) {
			return $redirect;
		}
		foreach ( $ids as $id ) {
			if ( 'approved' !== get_user_meta( $id, 'sawa_status', true ) ) {
				update_user_meta( $id, 'sawa_status', 'approved' );
			}
			self::set_tier( (int) $id, 'sawa_member' === $action ? 'member' : 'free' );
		}
		return add_query_arg( 'sawa_tiered', count( $ids ), $redirect );
	}

	public static function notice() {
		if ( isset( $_GET['sawa_tiered'] ) && 'users' === get_current_screen()->id ) { // phpcs:ignore
			echo '<div class="notice notice-success is-dismissible"><p>Plan updated. It works in the app and Language Lens right away.</p></div>';
		}
	}
}
