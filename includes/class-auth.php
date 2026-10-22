<?php
/**
 * Sign-in for the extension (and later the phone app).
 * The extension opens https://site/?sawa_lens=connect&redirect_uri=https://<id>.chromiumapp.org/&state=…
 * The learner signs in to WordPress as usual; if they're an approved tracker member, we create a device
 * token, keep only its SHA-256, and send the token back in the redirect's #fragment.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sawa_Lens_Auth {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'connect' ), 1 );
	}

	/** Approved members of the tracker (or anyone who can manage the site). */
	public static function allowed( $uid ) {
		if ( user_can( $uid, 'manage_options' ) ) {
			return true;
		}
		if ( class_exists( 'Sawa_Tracker_Game' ) ) {
			return Sawa_Tracker_Game::is_member( $uid );
		}
		return false;
	}

	private static function redirect_ok( $uri ) {
		// Chrome/Edge extension redirect (chrome.identity), or the Sawa web app later.
		// Phones (Firefox for Android, Safari): the extension signs in in a normal tab and reads the token on our own "connected" page.
		return (bool) preg_match( '~^https://[a-p]{32}\.chromiumapp\.org/[\w/-]*$~', $uri )
			|| (bool) preg_match( '~^https://[a-z0-9-]+\.allizom\.org/~', $uri )
			|| 0 === strpos( $uri, home_url( '/lens/' ) )
			|| home_url( '/?sawa_lens=connected' ) === $uri;
	}

	public static function connect() {
		if ( isset( $_GET['sawa_lens'] ) && 'connected' === $_GET['sawa_lens'] ) { // phpcs:ignore
			self::connected();
		}
		if ( ! isset( $_GET['sawa_lens'] ) || 'connect' !== $_GET['sawa_lens'] ) { // phpcs:ignore
			return;
		}
		nocache_headers();
		$redirect = isset( $_GET['redirect_uri'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_uri'] ) ) : ''; // phpcs:ignore
		$state    = isset( $_GET['state'] ) ? preg_replace( '/[^\w-]/', '', wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore
		if ( ! self::redirect_ok( $redirect ) ) {
			self::page( 'This sign-in link is not valid', 'Open Language Lens in your browser and press "Sign in" again.' );
		}
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		$uid = get_current_user_id();
		if ( ! self::allowed( $uid ) ) {
			self::page( 'Language Lens is for Sawa English members', 'Your account is not approved in the Sawa English tracker yet. Once Sherif approves your request, come back and sign in again.' );
		}
		$token = bin2hex( random_bytes( 32 ) );
		global $wpdb;
		$wpdb->insert( // phpcs:ignore
			Sawa_Lens_Store::t( 'tokens' ),
			array(
				'user_id'    => $uid,
				'hash'       => hash( 'sha256', $token ),
				'label'      => mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 80 ), // phpcs:ignore
				'created_at' => current_time( 'mysql' ),
			)
		);
		wp_redirect( $redirect . '#token=' . $token . '&state=' . rawurlencode( $state ) ); // phpcs:ignore
		exit;
	}

	/** Where phone sign-in lands. The token is in the #fragment, so it never reaches the server; the extension reads it here. */
	private static function connected() {
		nocache_headers();
		echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>Language Lens</title>'
			. '<style>body{font:18px/1.7 "IBM Plex Sans Arabic",system-ui,sans-serif;background:#F6F5F3;color:#0F0F10;display:grid;place-items:center;min-height:100vh;margin:0;padding:24px}div{max-width:440px;background:#fff;border:1px solid #E6E3DF;border-radius:14px;padding:28px}h1{font-size:22px;margin:0 0 10px}p{margin:0;color:#4A4847}.ok{color:#15803D;font-weight:600}.bad{color:#E3262B;font-weight:600}</style>'
			. '<body><div><h1>Language Lens · Sawa English</h1><p id="sawa-lens-status">جارٍ الربط… · Connecting…</p></div>'
			. '<script>setTimeout(function(){var e=document.getElementById("sawa-lens-status");if(!e.className){e.className="bad";e.textContent="لم يتم الربط. افتح هذه الصفحة في المتصفح الذي ثبّتّ فيه Language Lens، ثم اضغط Sign in مرة أخرى. · Not connected: sign in again from the Language Lens icon in the browser where it is installed.";}},5000);</script></body></html>';
		exit;
	}

	public static function page( $title, $text ) {
		status_header( 403 );
		echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $title ) . '</title>'
			. '<body style="font:18px/1.7 system-ui,sans-serif;background:#F6F5F3;color:#0F0F10;display:grid;place-items:center;min-height:100vh;margin:0;padding:24px">'
			. '<div style="max-width:440px;background:#fff;border:1px solid #E6E3DF;border-radius:14px;padding:28px"><h1 style="font-size:22px;margin:0 0 10px">' . esc_html( $title ) . '</h1><p style="margin:0;color:#4A4847">' . esc_html( $text ) . '</p></div></body></html>';
		exit;
	}

	/** The user behind a request's device token, or 0. */
	public static function user_from_request( WP_REST_Request $r ) {
		$h = (string) $r->get_header( 'authorization' );
		if ( preg_match( '/^Bearer\s+([a-f0-9]{64})$/i', $h, $m ) ) {
			$token = $m[1];
		} else {
			$token = (string) $r->get_header( 'x_sawa_token' ); // some hosts strip Authorization
		}
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return 0;
		}
		global $wpdb;
		$t   = Sawa_Lens_Store::t( 'tokens' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id, last_used FROM $t WHERE hash = %s", hash( 'sha256', $token ) ) ); // phpcs:ignore
		if ( ! $row ) {
			return 0;
		}
		if ( ! $row->last_used || strtotime( $row->last_used ) < time() - HOUR_IN_SECONDS ) {
			$wpdb->update( $t, array( 'last_used' => current_time( 'mysql' ) ), array( 'id' => $row->id ) ); // phpcs:ignore
		}
		$GLOBALS['sawa_lens_token_id'] = (int) $row->id;
		return (int) $row->user_id;
	}

	public static function revoke_current() {
		if ( empty( $GLOBALS['sawa_lens_token_id'] ) ) {
			return;
		}
		global $wpdb;
		$wpdb->delete( Sawa_Lens_Store::t( 'tokens' ), array( 'id' => (int) $GLOBALS['sawa_lens_token_id'] ) ); // phpcs:ignore
	}
}
