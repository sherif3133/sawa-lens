<?php
/**
 * Short videos and "read the internet" for the Silk Road's bonus camps.
 *
 * 📱 Reels: English YouTube Shorts and TikToks in their official embedded players, picked by the admin or found by
 * topic (the tracker's YouTube search: the YouTube Data API when a key is set), English titles only, the student's
 * interest-quiz topics first, and memes as official Reddit embeds (curated by the admin, never copied).
 * 📰 Reading: posts from newsletters' public RSS feeds (title, a short preview, a difficulty for this student),
 * Reddit and Quora discussions as links. Full articles are never copied: the student opens the post and reads it
 * with Language Lens, or pastes it into "Adapt to my level".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sawa_Lens_Feeds {

	const OPT        = 'sawa_lens_feeds';
	const HEARD_GOAL = 1000; // words heard with Language Lens today ≈ 10 minutes of English Shorts
	const CHECK_XP   = 3;
	const READ_XP    = 5;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_post_sawa_lens_feeds', array( __CLASS__, 'save' ) );
		add_filter( 'sawa_tracker_road_reels', '__return_true' );
		add_filter( 'sawa_tracker_road_web', function () { return (bool) array_filter( self::settings()['feeds'], function ( $f ) { return ! empty( $f['on'] ); } ) || (bool) self::settings()['discuss']; } );
	}

	public static function defaults() {
		return array(
			'auto'    => 1, // find Shorts by topic
			'reels'   => array(), // [{url, platform, id, title, cat, q, options[], answer}]
			'memes'   => array(), // [{url, title}] Reddit posts
			'feeds'   => array(
				array( 'url' => 'https://www.garbageday.email/feed', 'name' => 'Garbage Day', 'cat' => 'reactions', 'on' => 1 ),
				array( 'url' => 'https://www.gamefile.news/feed', 'name' => 'Game File', 'cat' => 'gaming', 'on' => 1 ),
				array( 'url' => 'https://www.readtrung.com/feed', 'name' => 'SatPost by Trung Phan', 'cat' => 'business', 'on' => 1 ),
				array( 'url' => 'https://www.oneusefulthing.org/feed', 'name' => 'One Useful Thing', 'cat' => 'ai', 'on' => 1 ),
			),
			'discuss' => array(), // [{url, title, cat}] Reddit threads or Quora questions (links)
		);
	}

	public static function settings() {
		$s = get_option( self::OPT, array() );
		return wp_parse_args( is_array( $s ) ? $s : array(), self::defaults() );
	}

	public static function routes() {
		$can = function () {
			$uid = get_current_user_id();
			return $uid && Sawa_Lens_Auth::allowed( $uid ) && Sawa_Lens_App::is_open_for( $uid );
		};
		foreach ( array( array( 'reels', 'GET', 'reels' ), array( 'reels/check', 'POST', 'check' ), array( 'reading', 'GET', 'reading' ), array( 'reading/done', 'POST', 'read_done' ) ) as $r ) {
			register_rest_route( Sawa_Lens_App::NS, '/app/' . $r[0], array( 'methods' => $r[1], 'callback' => array( __CLASS__, $r[2] ), 'permission_callback' => $can ) );
		}
	}

	private static function out( $data ) {
		$res = rest_ensure_response( $data );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	/** The student's interest-quiz topics (ids like football, business). */
	private static function cats( $uid ) {
		$p = class_exists( 'Sawa_Lens_Interests' ) ? Sawa_Lens_Interests::picks( $uid ) : null;
		return $p ? array_values( array_map( 'sanitize_key', array_map( function ( $c ) { return is_array( $c ) ? ( $c['id'] ?? '' ) : $c; }, (array) $p['cats'] ) ) ) : array();
	}

	/** A link → [platform, id] for the official embeds (YouTube / Shorts, TikTok, Reddit). */
	public static function parse( $url ) {
		$url = trim( (string) $url );
		if ( preg_match( '~(?:youtube\.com/(?:shorts/|watch\?(?:.*&)?v=|embed/)|youtu\.be/)([\w-]{11})~', $url, $m ) ) {
			return array( 'yt', $m[1] );
		}
		if ( preg_match( '~tiktok\.com/@[\w.-]+/video/(\d{8,25})~', $url, $m ) ) {
			return array( 'tiktok', $m[1] );
		}
		if ( preg_match( '~^https://(?:www\.|old\.)?reddit\.com/(r/\w+/comments/\w+(?:/[\w-]*)?)~', $url, $m ) ) {
			return array( 'reddit', $m[1] );
		}
		return null;
	}

	/** English only: no other scripts, and most words are in the English dictionary. */
	public static function english( $text ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES );
		if ( preg_match( '/[\p{Arabic}\p{Cyrillic}\p{Han}\p{Hangul}\p{Devanagari}\p{Thai}\p{Hiragana}\p{Katakana}\p{Hebrew}\p{Greek}]/u', $text ) ) {
			return false;
		}
		preg_match_all( '/[a-z]+/', strtolower( $text ), $m );
		$words = array_filter( $m[0], function ( $w ) { return strlen( $w ) > 2; } );
		if ( count( $words ) < 2 ) {
			return false;
		}
		$lv = self::levels();
		$in = count( array_filter( $words, function ( $w ) use ( $lv ) { return isset( $lv[ $w ] ) || isset( $lv[ preg_replace( '/(ing|ed|es|s)$/', '', $w ) ] ); } ) );
		return $in / count( $words ) >= 0.6;
	}

	/** lemma → CEFR level (1–6), from the app's dictionary; built once and kept. */
	public static function levels() {
		static $lv = null;
		if ( null !== $lv ) {
			return $lv;
		}
		$key = 'sawa_lens_levels_' . SAWA_LENS_VER;
		$lv  = get_option( $key );
		if ( is_array( $lv ) ) {
			return $lv;
		}
		$lv = array();
		$j  = json_decode( (string) file_get_contents( SAWA_LENS_DIR . 'assets/lexicon.json' ), true ); // phpcs:ignore
		foreach ( (array) ( $j['entries'] ?? array() ) as $e ) {
			foreach ( explode( '/', strtolower( (string) $e[0] ) ) as $w ) {
				if ( preg_match( '/^[a-z]+$/', $w ) && ( ! isset( $lv[ $w ] ) || $lv[ $w ] > (int) $e[2] ) ) {
					$lv[ $w ] = max( 1, (int) $e[2] );
				}
			}
		}
		foreach ( (array) wp_load_alloptions() as $k => $v ) {
			if ( 0 === strpos( $k, 'sawa_lens_levels_' ) ) {
				delete_option( $k );
			}
		}
		add_option( $key, $lv, '', 'no' );
		return $lv;
	}

	/** How hard a text is for a student at $level: the share of its words above their level → easy / good / hard. */
	public static function difficulty( $text, $level ) {
		preg_match_all( '/[a-z]+/', strtolower( (string) $text ), $m );
		$lv    = self::levels();
		$n     = 0;
		$above = 0;
		$top   = array();
		foreach ( $m[0] as $w ) {
			$l = $lv[ $w ] ?? ( $lv[ preg_replace( '/(ing|ed|es|s)$/', '', $w ) ] ?? ( strlen( $w ) > 3 ? 6 : 1 ) );
			++$n;
			$top[] = $l;
			if ( $l > $level ) {
				++$above;
			}
		}
		if ( ! $n ) {
			return array( 'label' => 'good', 'pct' => 100, 'cefr' => 'A2' );
		}
		sort( $top );
		$cefr = array( '', 'A1', 'A2', 'B1', 'B2', 'C1', 'C2' )[ max( 1, min( 6, $top[ (int) floor( 0.9 * ( count( $top ) - 1 ) ) ] ) ) ];
		$pct  = (int) round( 100 * ( $n - $above ) / $n );
		return array( 'label' => $pct >= 92 ? 'easy' : ( $pct >= 80 ? 'good' : 'hard' ), 'pct' => $pct, 'cefr' => $cefr );
	}

	private static function level( $uid ) {
		$l = class_exists( 'Sawa_Lens_Store' ) ? (int) Sawa_Lens_Store::level( $uid ) : 0;
		return $l ?: 2;
	}

	/* ------------------------------------------------------------------ 📱 reels */

	public static function reels() {
		$uid  = get_current_user_id();
		$s    = self::settings();
		$cats = self::cats( $uid );
		$mine = function ( $it ) use ( $cats ) { return in_array( $it['cat'] ?? '', $cats, true ) ? 0 : 1; };
		$list = array();
		foreach ( $s['reels'] as $i => $r ) {
			$list[] = array(
				'key'      => 'a' . $i,
				'platform' => $r['platform'],
				'id'       => $r['id'],
				'url'      => $r['url'],
				'title'    => $r['title'],
				'cat'      => $r['cat'],
				'check'    => '' !== ( $r['q'] ?? '' ) ? array( 'q' => $r['q'], 'options' => $r['options'] ) : null,
			);
		}
		usort( $list, function ( $a, $b ) use ( $mine ) { return $mine( $a ) <=> $mine( $b ); } );
		// Found by topic: the student's topics first (the search results are cached for a day).
		if ( $s['auto'] && class_exists( 'Sawa_Tracker_Media' ) ) {
			$seen = wp_list_pluck( $list, 'id' );
			foreach ( array_slice( $cats ? $cats : array( 'comedy', 'football', 'science' ), 0, 4 ) as $cat ) {
				$found = Sawa_Tracker_Media::search( $uid, self::topic( $cat ) . ' #shorts english' );
				foreach ( is_array( $found ) ? array_slice( $found, 0, 6 ) : array() as $v ) {
					if ( in_array( $v['vid'], $seen, true ) || ! self::english( $v['title'] ) || self::too_long( $v['duration'] ?? '' ) ) {
						continue;
					}
					$seen[] = $v['vid'];
					$list[] = array( 'key' => 'y' . $v['vid'], 'platform' => 'yt', 'id' => $v['vid'], 'url' => 'https://www.youtube.com/shorts/' . $v['vid'], 'title' => $v['title'], 'cat' => $cat, 'check' => null );
				}
			}
		}
		$memes = array_map( function ( $m ) { return array( 'url' => $m['url'], 'path' => $m['id'], 'title' => $m['title'] ); }, array_slice( $s['memes'], 0, 12 ) );
		$days  = class_exists( 'Sawa_Lens_Daily' ) ? Sawa_Lens_Daily::series( $uid ) : array();
		return self::out(
			array(
				'items' => array_slice( $list, 0, 30 ),
				'memes' => $memes,
				'heard' => (int) ( $days[ wp_date( 'Y-m-d' ) ]['heard'] ?? 0 ),
				'goal'  => self::HEARD_GOAL,
				'done'  => self::checks_today( $uid ),
			)
		);
	}

	/** Shorts only: longer than 90 seconds is not a reel (no duration known: keep it). */
	private static function too_long( $d ) {
		$t = 0;
		foreach ( array_map( 'intval', explode( ':', (string) $d ) ) as $x ) {
			$t = $t * 60 + $x;
		}
		return '' !== (string) $d && $t > 90;
	}

	private static function topic( $cat ) {
		$names = array( 'standup' => 'stand up comedy', 'reactions' => 'reaction', 'pranks' => 'prank', 'rap' => 'rap', 'pop' => 'pop music', 'fight' => 'boxing', 'fitness' => 'gym', 'tech' => 'tech', 'ai' => 'AI', 'explainers' => 'explained', 'documentaries' => 'nature', 'truecrime' => 'mystery', 'movies' => 'movie', 'series' => 'tv series', 'study' => 'study tips', 'languages' => 'learn english', 'books' => 'books', 'animals' => 'funny animals', 'diy' => 'diy', 'food' => 'street food', 'travel' => 'travel', 'cooking' => 'cooking', 'football' => 'football', 'business' => 'business', 'money' => 'money tips', 'self' => 'motivation', 'comedy' => 'funny', 'science' => 'science', 'history' => 'history', 'gaming' => 'gaming', 'cars' => 'cars' );
		return $names[ $cat ] ?? str_replace( '-', ' ', $cat );
	}

	private static function checks_today( $uid ) {
		$c = get_user_meta( $uid, 'sawa_lens_reel_checks', true );
		return is_array( $c ) && ( $c['day'] ?? '' ) === wp_date( 'Y-m-d' ) ? (array) $c['keys'] : array();
	}

	/** "What did they say?": the admin's question, or one thing you heard, in English. XP once per reel a day. */
	public static function check( WP_REST_Request $r ) {
		$uid  = get_current_user_id();
		$key  = substr( sanitize_key( (string) $r->get_param( 'key' ) ), 0, 20 );
		$s    = self::settings();
		$ok   = false;
		$why  = '';
		$item = 'a' === substr( $key, 0, 1 ) ? ( $s['reels'][ (int) substr( $key, 1 ) ] ?? null ) : null;
		if ( $item && '' !== ( $item['q'] ?? '' ) ) {
			$ok  = (int) $r->get_param( 'a' ) === (int) $item['answer'];
			$why = $item['options'][ (int) $item['answer'] ] ?? '';
		} else {
			$text = trim( wp_strip_all_tags( (string) $r->get_param( 'text' ) ) );
			if ( preg_match( '/\p{Arabic}/u', $text ) || count( preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY ) ) < 3 ) {
				return new WP_Error( 'short', 'Write at least three English words you heard.', array( 'status' => 400 ) );
			}
			$ok = true;
		}
		$done = self::checks_today( $uid );
		$xp   = 0;
		if ( $ok && ! in_array( $key, $done, true ) ) {
			$done[] = $key;
			update_user_meta( $uid, 'sawa_lens_reel_checks', array( 'day' => wp_date( 'Y-m-d' ), 'keys' => $done ) );
			$xp = class_exists( 'Sawa_Tracker_XP' ) ? (int) Sawa_Tracker_XP::award( $uid, self::CHECK_XP, 'reel' ) : 0;
			Sawa_Lens_Activity::add( $uid, '', 1, 'Active Immersion', 1 );
		}
		return self::out( array( 'ok' => $ok, 'right' => $why, 'xp' => $xp, 'done' => count( $done ) ) );
	}

	/* ------------------------------------------------------------------ 📰 reading */

	/** The newest posts of one feed (title, link, a short preview): cached for 3 hours. */
	private static function feed( $f ) {
		$key = 'sawa_lens_rss_' . md5( $f['url'] );
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$out = array();
		$res = wp_remote_get( $f['url'], array( 'timeout' => 10, 'user-agent' => 'Mozilla/5.0 (compatible; SawaEnglish/1.0; +' . home_url() . ')' ) );
		if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
			$prev = libxml_use_internal_errors( true );
			$xml  = simplexml_load_string( wp_remote_retrieve_body( $res ), 'SimpleXMLElement', LIBXML_NOCDATA );
			libxml_use_internal_errors( $prev );
			foreach ( $xml && isset( $xml->channel->item ) ? $xml->channel->item : array() as $it ) {
				$title = trim( html_entity_decode( (string) $it->title, ENT_QUOTES ) );
				$text  = trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( (string) $it->description ), ENT_QUOTES ) ) );
				if ( '' === $title || ! self::english( $title . ' ' . $text ) ) {
					continue;
				}
				$out[] = array( 'title' => mb_substr( $title, 0, 160 ), 'link' => esc_url_raw( (string) $it->link ), 'preview' => wp_trim_words( $text, 40, '…' ), 'date' => (string) $it->pubDate ); // a short preview only
				if ( count( $out ) >= 6 ) {
					break;
				}
			}
		}
		set_transient( $key, $out, $out ? 3 * HOUR_IN_SECONDS : 20 * MINUTE_IN_SECONDS );
		return $out;
	}

	public static function reading() {
		$uid   = get_current_user_id();
		$s     = self::settings();
		$cats  = self::cats( $uid );
		$level = self::level( $uid );
		$read  = self::read_today( $uid );
		$items = array();
		foreach ( $s['feeds'] as $f ) {
			if ( empty( $f['on'] ) ) {
				continue;
			}
			foreach ( self::feed( $f ) as $p ) {
				$items[] = $p + array( 'source' => $f['name'], 'cat' => $f['cat'], 'kind' => 'post', 'mine' => in_array( $f['cat'], $cats, true ), 'level' => self::difficulty( $p['title'] . ' ' . $p['preview'], $level ), 'read' => in_array( md5( $p['link'] ), $read, true ) );
			}
		}
		$rank = array( 'good' => 0, 'easy' => 1, 'hard' => 2 );
		usort( $items, function ( $a, $b ) use ( $rank ) { return array( $a['read'], ! $a['mine'], $rank[ $a['level']['label'] ] ) <=> array( $b['read'], ! $b['mine'], $rank[ $b['level']['label'] ] ); } );
		$discuss = array_map( function ( $d ) use ( $cats ) { return array( 'title' => $d['title'], 'link' => $d['url'], 'kind' => false !== strpos( $d['url'], 'quora.com' ) ? 'quora' : 'reddit', 'mine' => in_array( $d['cat'], $cats, true ) ); }, $s['discuss'] );
		usort( $discuss, function ( $a, $b ) { return (int) $b['mine'] - (int) $a['mine']; } );
		$days = class_exists( 'Sawa_Lens_Daily' ) ? Sawa_Lens_Daily::series( $uid ) : array();
		return self::out( array( 'items' => array_slice( $items, 0, 16 ), 'discuss' => array_slice( $discuss, 0, 10 ), 'level' => array( '', 'A1', 'A2', 'B1', 'B2', 'C1', 'C2' )[ $level ], 'readWords' => (int) ( $days[ wp_date( 'Y-m-d' ) ]['read'] ?? 0 ) ) );
	}

	private static function read_today( $uid ) {
		$c = get_user_meta( $uid, 'sawa_lens_web_read', true );
		return is_array( $c ) && ( $c['day'] ?? '' ) === wp_date( 'Y-m-d' ) ? (array) $c['keys'] : array();
	}

	/** Read a post outside the app: one thing you learned, in English → XP and study minutes, once per post. */
	public static function read_done( WP_REST_Request $r ) {
		$uid  = get_current_user_id();
		$link = esc_url_raw( (string) $r->get_param( 'link' ) );
		$note = trim( wp_strip_all_tags( (string) $r->get_param( 'note' ) ) );
		if ( preg_match( '/\p{Arabic}/u', $note ) || count( preg_split( '/\s+/', $note, -1, PREG_SPLIT_NO_EMPTY ) ) < 5 ) {
			return new WP_Error( 'short', 'Write one thing you learned, in English (5 words or more).', array( 'status' => 400 ) );
		}
		$done = self::read_today( $uid );
		$xp   = 0;
		if ( ! in_array( md5( $link ), $done, true ) ) {
			$done[] = md5( $link );
			update_user_meta( $uid, 'sawa_lens_web_read', array( 'day' => wp_date( 'Y-m-d' ), 'keys' => $done ) );
			$xp = class_exists( 'Sawa_Tracker_XP' ) ? (int) Sawa_Tracker_XP::award( $uid, self::READ_XP, 'web' ) : 0;
			Sawa_Lens_Activity::add( $uid, '', 1, 'Active Immersion', 8 );
		}
		return self::out( array( 'ok' => true, 'xp' => $xp ) );
	}

	/* ------------------------------------------------------------------ admin */

	public static function menu() {
		add_submenu_page( 'sawa-lens-quizzes', 'Reels, reading & radio', 'Reels, reading & radio', 'manage_options', 'sawa-lens-feeds', array( __CLASS__, 'page' ) );
	}

	/** One line per item: "url | title | topic" (reels may add "| question | option A ; option B ; option C | right number"). */
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sawa_lens_feeds' ) ) {
			wp_die( 'Not allowed.' );
		}
		$lines = function ( $k ) { return array_filter( array_map( 'trim', preg_split( '/\r\n|\n/', (string) wp_unslash( $_POST[ $k ] ?? '' ) ) ) ); }; // phpcs:ignore
		$cells = function ( $l ) { return array_map( 'trim', explode( '|', $l ) ); };
		$s     = self::settings();
		$bad   = 0;
		$s['auto']  = empty( $_POST['auto'] ) ? 0 : 1; // phpcs:ignore
		$s['reels'] = array();
		foreach ( $lines( 'reels' ) as $l ) {
			$c = $cells( $l );
			$p = self::parse( $c[0] );
			if ( ! $p || 'reddit' === $p[0] ) {
				++$bad;
				continue;
			}
			$opts         = array_values( array_filter( array_map( 'trim', explode( ';', $c[4] ?? '' ) ), 'strlen' ) );
			$s['reels'][] = array( 'url' => esc_url_raw( $c[0] ), 'platform' => $p[0], 'id' => $p[1], 'title' => sanitize_text_field( $c[1] ?? '' ), 'cat' => sanitize_key( $c[2] ?? '' ), 'q' => count( $opts ) >= 2 ? sanitize_text_field( $c[3] ?? '' ) : '', 'options' => array_map( 'sanitize_text_field', $opts ), 'answer' => max( 0, (int) ( $c[5] ?? 1 ) - 1 ) );
		}
		$s['memes'] = array();
		foreach ( $lines( 'memes' ) as $l ) {
			$c = $cells( $l );
			$p = self::parse( $c[0] );
			if ( ! $p || 'reddit' !== $p[0] ) {
				++$bad;
				continue;
			}
			$s['memes'][] = array( 'url' => esc_url_raw( $c[0] ), 'id' => $p[1], 'title' => sanitize_text_field( $c[1] ?? '' ) );
		}
		$s['feeds'] = array();
		foreach ( $lines( 'feeds' ) as $l ) {
			$c = $cells( $l );
			if ( ! wp_http_validate_url( $c[0] ) ) {
				++$bad;
				continue;
			}
			$s['feeds'][] = array( 'url' => esc_url_raw( $c[0] ), 'name' => sanitize_text_field( $c[1] ?? '' ), 'cat' => sanitize_key( $c[2] ?? '' ), 'on' => 1 );
		}
		$s['discuss'] = array();
		foreach ( $lines( 'discuss' ) as $l ) {
			$c = $cells( $l );
			if ( ! preg_match( '~^https://(www\.)?(reddit\.com|[\w-]+\.quora\.com|quora\.com)/~', $c[0] ) ) {
				++$bad;
				continue;
			}
			$s['discuss'][] = array( 'url' => esc_url_raw( $c[0] ), 'title' => sanitize_text_field( $c[1] ?? '' ), 'cat' => sanitize_key( $c[2] ?? '' ) );
		}
		update_option( self::OPT, $s, false );
		if ( class_exists( 'Sawa_Lens_Radio' ) ) {
			Sawa_Lens_Radio::save_admin();
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'sawa-lens-feeds', 'saved' => 1, 'bad' => $bad ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function page() {
		$s    = self::settings();
		$join = function ( $rows, $keys ) { return implode( "\n", array_map( function ( $r ) use ( $keys ) { return implode( ' | ', array_map( function ( $k ) use ( $r ) { return is_array( $r[ $k ] ?? '' ) ? implode( ' ; ', $r[ $k ] ) : ( 'answer' === $k ? (int) $r[ $k ] + 1 : (string) ( $r[ $k ] ?? '' ) ); }, $keys ) ); }, $rows ) ); };
		echo '<div class="wrap"><h1>Reels, reading &amp; radio</h1>';
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore
			echo '<div class="notice notice-success"><p>Saved.' . ( (int) $_GET['bad'] ? ' ' . (int) $_GET['bad'] . ' line(s) were skipped: check the links.' : '' ) . '</p></div>'; // phpcs:ignore
		}
		echo '<p>These fill the Silk Road\'s bonus camps: 📱 Reels and 📰 Read the internet, and the 📻 radio. Students see official embeds and links only: nothing is copied.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sawa_lens_feeds">';
		wp_nonce_field( 'sawa_lens_feeds' );
		echo '<h2>📱 Reels</h2><p><label><input type="checkbox" name="auto" value="1"' . checked( $s['auto'], 1, false ) . '> Also find English YouTube Shorts by each student\'s topics (interest quiz). Uses the tracker\'s YouTube search: add a YouTube Data API key in the tracker settings to use the official API.</label></p>';
		echo '<p class="description">Your picks, one per line: <code>link | title | topic | question | option A ; option B ; option C | right option number</code>. The question part is optional (without it, students write one thing they heard). Links: YouTube Shorts or videos, TikTok videos.</p><textarea name="reels" class="large-text code" rows="6">' . esc_textarea( $join( $s['reels'], array( 'url', 'title', 'cat', 'q', 'options', 'answer' ) ) ) . '</textarea>';
		echo '<h3>Memes (Reddit posts, shown as official Reddit embeds)</h3><p class="description">Keep it small and curated: <code>https://www.reddit.com/r/…/comments/… | title</code>. Pick clean ones; Reddit charges for heavy commercial use, so don\'t add hundreds.</p><textarea name="memes" class="large-text code" rows="4">' . esc_textarea( $join( $s['memes'], array( 'url', 'title' ) ) ) . '</textarea>';
		echo '<h2>📰 Read the internet</h2><p class="description">Newsletters (Substack or any blog with an RSS feed): <code>feed URL | name | topic</code>. Students see the title, a short preview and how hard it is for them, and open the post itself to read it.</p><textarea name="feeds" class="large-text code" rows="5">' . esc_textarea( $join( $s['feeds'], array( 'url', 'name', 'cat' ) ) ) . '</textarea>';
		echo '<h3>Discussions (Reddit threads, Quora questions: links only)</h3><textarea name="discuss" class="large-text code" rows="4">' . esc_textarea( $join( $s['discuss'], array( 'url', 'title', 'cat' ) ) ) . '</textarea>';
		echo '<p class="description">Topics: business, money, self, comedy, football, fitness, tech, ai, science, history, news, cooking, travel, movies, gaming, music (pop, rap)… (the interest-quiz topics).</p>';
		if ( class_exists( 'Sawa_Lens_Radio' ) ) {
			Sawa_Lens_Radio::admin_fields();
		}
		submit_button( 'Save' );
		echo '</form></div>';
	}
}
