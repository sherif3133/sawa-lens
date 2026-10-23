<?php
/**
 * One language setting for every Sawa page and app screen: English by default, Arabic (RTL) on request.
 *
 * The same file ships in every Sawa plugin (sawa-site, sawa-lens, sawa-tracker, sawa-testimonials); the first
 * one loaded wins, so keep the copies identical (bump VER when you change it and copy it to the others).
 *
 * - Stored in localStorage + cookie `sawa_lang` ('en' | 'ar') and, for signed-in students, user meta `sawa_lang`.
 * - Priority: `?lang=ar|en` in the link > the student's saved choice > localStorage > cookie > English.
 * - Cached public pages carry both languages: English in `.t-en`, Arabic in `.t-ar`; CSS shows one.
 *   Attributes: data-tattr='{"placeholder":["Search","دوّر"]}'. Switching needs no reload there.
 * - App screens that build their text in JS read SawaLang.get() and pass reload=true to boot().
 * - The toggle: Sawa_Lang::toggle(). Any `.sawa-lang button[data-l]` works.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Sawa_Lang' ) ) {

	final class Sawa_Lang {

		const VER = '2';
		const KEY = 'sawa_lang';

		public static function init() {
			add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
			// A ?lang= link is saved before any redirect (e.g. /tracker/ → /app/) can drop it.
			add_action(
				'init',
				function () {
					if ( isset( $_GET['lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
						self::current();
					}
				},
				20
			);
		}

		public static function norm( $l ) {
			return 'ar' === $l ? 'ar' : 'en';
		}

		/** The language for a page that is not cached (apps, signed-in pages). */
		public static function current() {
			static $l = null;
			if ( null !== $l ) {
				return $l;
			}
			$uid = get_current_user_id();
			$q   = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			if ( 'en' === $q || 'ar' === $q ) {
				if ( $uid ) {
					update_user_meta( $uid, self::KEY, $q );
				}
				if ( ! headers_sent() ) {
					setcookie( self::KEY, $q, time() + YEAR_IN_SECONDS, '/', '', is_ssl(), false );
				}
				return $l = $q;
			}
			if ( $uid ) {
				$m = (string) get_user_meta( $uid, self::KEY, true );
				if ( '' !== $m ) {
					return $l = self::norm( $m );
				}
			}
			if ( isset( $_COOKIE[ self::KEY ] ) ) {
				return $l = self::norm( sanitize_key( wp_unslash( $_COOKIE[ self::KEY ] ) ) );
			}
			return $l = 'en';
		}

		public static function is_ar() {
			return 'ar' === self::current();
		}

		/** Pick the text for the current language (uncached pages only). */
		public static function pick( $en, $ar ) {
			return self::is_ar() ? $ar : $en;
		}

		public static function routes() {
			register_rest_route(
				'sawa/v1',
				'/lang',
				array(
					'methods'             => 'POST',
					'permission_callback' => 'is_user_logged_in',
					'callback'            => function ( WP_REST_Request $r ) {
						$l = self::norm( (string) $r->get_param( 'lang' ) );
						update_user_meta( get_current_user_id(), self::KEY, $l );
						return array( 'lang' => $l );
					},
				)
			);
		}

		/** Both languages, escaped, for cached pages: <span class="t-en">…</span><span class="t-ar">…</span>. */
		public static function t( $en, $ar, $tag = 'span' ) {
			return self::th( esc_html( $en ), esc_html( $ar ), $tag );
		}

		/** Same as t() for HTML you already escaped. */
		public static function th( $en_html, $ar_html, $tag = 'span' ) {
			return '<' . $tag . ' class="t-en">' . $en_html . '</' . $tag . '><' . $tag . ' class="t-ar" lang="ar">' . $ar_html . '</' . $tag . '>';
		}

		/** data-tattr for attributes that change with the language: array( 'aria-label' => array( en, ar ) ). */
		public static function attr( $pairs ) {
			return ' data-tattr="' . esc_attr( wp_json_encode( $pairs, JSON_UNESCAPED_UNICODE ) ) . '"';
		}

		/** The switch. $class adds a modifier (e.g. "sawa-lang-dark" on a dark header). */
		public static function toggle( $class = '' ) {
			return '<div class="sawa-lang ' . esc_attr( $class ) . '" role="group" aria-label="Language / اللغة">'
				. '<button type="button" data-l="en" lang="en" aria-pressed="false">English</button>'
				. '<button type="button" data-l="ar" lang="ar" aria-pressed="false">العربية</button></div>';
		}

		/**
		 * Inline <style> + <script> for <head>, before the page's own CSS. Sets <html lang dir data-lang>
		 * before anything paints. $reload: switching reloads the page (screens whose text comes from JS).
		 */
		public static function boot( $reload = false ) {
			$uid  = get_current_user_id();
			$cfg  = array(
				'user'   => $uid ? ( (string) get_user_meta( $uid, self::KEY, true ) ) : '',
				'rest'   => $uid ? esc_url_raw( rest_url( 'sawa/v1/lang' ) ) : '',
				'nonce'  => $uid ? wp_create_nonce( 'wp_rest' ) : '',
				'reload' => (bool) $reload,
			);
			$css = 'html[data-lang=ar] .t-en,html:not([data-lang=ar]) .t-ar{display:none!important}'
				. '.sawa-lang{display:inline-flex;align-items:stretch;gap:2px;padding:3px;border-radius:999px;background:rgba(15,15,16,.06);border:1px solid rgba(15,15,16,.14);direction:ltr;flex:none;vertical-align:middle}'
				. '.sawa-lang button{min-height:40px;min-width:44px;padding:0 14px;border:0;border-radius:999px;background:transparent;color:inherit;font:600 15px/1 "IBM Plex Sans Arabic","IBM Plex Sans","Segoe UI",Tahoma,sans-serif;cursor:pointer;-webkit-tap-highlight-color:transparent}'
				. '.sawa-lang button[aria-pressed=true]{background:#0F0F10;color:#fff}'
				. '.sawa-lang.sawa-lang-dark{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.25)}'
				. '.sawa-lang.sawa-lang-dark button[aria-pressed=true]{background:#fff;color:#0F0F10}'
				. '.sawa-lang button:focus-visible{outline:2px solid #E3262B;outline-offset:2px}';
			$js = <<<'JS'
(function(c){var K='sawa_lang',d=document,h=d.documentElement,l,q=/[?&]lang=(en|ar)(?=&|#|$)/.exec(location.search);
function ls(v){try{if(v===undefined)return localStorage.getItem(K);localStorage.setItem(K,v)}catch(e){}return null}
function ck(){var m=/(?:^|;\s*)sawa_lang=(en|ar)/.exec(d.cookie);return m?m[1]:null}
function apply(x){l=x==='ar'?'ar':'en';h.lang=l;h.dir=l==='ar'?'rtl':'ltr';h.setAttribute('data-lang',l);ls(l);d.cookie=K+'='+l+';path=/;max-age=31536000;samesite=lax'}
function save(){if(!c.rest||l===c.user)return;c.user=l;try{fetch(c.rest,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':c.nonce},body:JSON.stringify({lang:l}),keepalive:true})}catch(e){}}
function paint(){var i,b=d.querySelectorAll('.sawa-lang button[data-l]');for(i=0;i<b.length;i++)b[i].setAttribute('aria-pressed',String(b[i].getAttribute('data-l')===l));
var a=d.querySelectorAll('[data-tattr]');for(i=0;i<a.length;i++){try{var o=JSON.parse(a[i].getAttribute('data-tattr'));for(var k in o)a[i].setAttribute(k,o[k][l==='ar'?1:0])}catch(e){}}}
apply(q?q[1]:(c.user||ls()||ck()||'en'));
if(q&&history.replaceState){try{history.replaceState(null,'',location.href.replace(/([?&])lang=(en|ar)&?/,'$1').replace(/[?&](#|$)/,'$1'))}catch(e){}}
save();
window.SawaLang={get:function(){return l},set:function(x){x=x==='ar'?'ar':'en';if(x===l)return;apply(x);save();paint();try{d.dispatchEvent(new CustomEvent('sawa-lang',{detail:{lang:l}}))}catch(e){}if(c.reload)setTimeout(function(){location.reload()},350)},paint:paint,t:function(en,ar){return l==='ar'?ar:en}};
d.addEventListener('click',function(e){var b=e.target.closest&&e.target.closest('.sawa-lang button[data-l]');if(b){e.preventDefault();window.SawaLang.set(b.getAttribute('data-l'))}});
if(d.readyState==='loading')d.addEventListener('DOMContentLoaded',paint);else paint()})(
JS;
			return '<style id="sawa-lang-css">' . $css . '</style>'
				. '<script id="sawa-lang-js">' . $js . wp_json_encode( $cfg ) . ');</script>';
		}
	}

	Sawa_Lang::init();
}
