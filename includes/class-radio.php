<?php
/**
 * 📻 Radio in the Silk Road: English to listen to while playing, instead of the game's music, with captions.
 *
 * Podcasts (default): episodes from public RSS feeds, played from the publisher's own file like any podcast app.
 * Captions are made once per 30 seconds of an episode with speech-to-text and shared by every listener, so an
 * episode costs the same whether one student or a hundred listen ($0.003 a minute: a 6-minute episode ≈ 2 cents).
 * Live stations: only ones the admin marks as allowing it (most stations' terms don't allow use in other apps).
 * Their captions are made while someone listens: 15 seconds at a time, shared by all listeners of that station
 * (≈ $0.18 an hour per station). Everything stops at the monthly budget; the audio keeps playing without captions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sawa_Lens_Radio {

	const OPT      = 'sawa_lens_radio';
	const USAGE    = 'sawa_lens_radio_usage';
	const CHUNK    = 30;    // seconds of a podcast per caption piece
	const LIVE     = 15;    // seconds of a live stream per caption piece
	const PER_MIN  = 0.003; // USD per minute of speech-to-text (gpt-4o-mini-transcribe)

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function defaults() {
		return array(
			'on'       => 1,
			'captions' => 1,
			'budget'   => 5, // USD a month for captions
			'stations' => array(
				array( 'id' => 'bbc6', 'name' => 'BBC Learning English · 6 Minute English', 'kind' => 'podcast', 'url' => 'https://podcasts.files.bbci.co.uk/p02pc9tn.rss', 'desc' => 'Two presenters, one topic, six new words. Clear British English (A2–B1).', 'kbps' => 0, 'ok' => 1 ),
				array( 'id' => 'upfirst', 'name' => 'NPR · Up First', 'kind' => 'podcast', 'url' => 'https://feeds.npr.org/510318/podcast.xml', 'desc' => 'The day\'s news from the US in about 12 minutes (B1–B2).', 'kbps' => 0, 'ok' => 1 ),
				array( 'id' => 'ted', 'name' => 'NPR · TED Radio Hour', 'kind' => 'podcast', 'url' => 'https://feeds.npr.org/510298/podcast.xml', 'desc' => 'Big ideas from TED speakers, explained (B2).', 'kbps' => 0, 'ok' => 1 ),
			),
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
		foreach ( array( array( 'radio', 'GET', 'home' ), array( 'radio/episodes', 'GET', 'episodes' ), array( 'radio/caption', 'POST', 'caption' ), array( 'radio/live', 'POST', 'live' ), array( 'radio/heard', 'POST', 'heard' ) ) as $r ) {
			register_rest_route( Sawa_Lens_App::NS, '/app/' . $r[0], array( 'methods' => $r[1], 'callback' => array( __CLASS__, $r[2] ), 'permission_callback' => $can ) );
		}
	}

	private static function out( $data ) {
		$res = rest_ensure_response( $data );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	private static function station( $id ) {
		foreach ( self::settings()['stations'] as $s ) {
			if ( $s['id'] === $id && ! empty( $s['ok'] ) ) {
				return $s;
			}
		}
		return null;
	}

	/** Spent on captions this month (USD). */
	public static function spent() {
		$u = get_option( self::USAGE, array() );
		return (float) ( is_array( $u ) ? ( $u[ wp_date( 'Y-m' ) ] ?? 0 ) : 0 );
	}

	private static function charge( $secs ) {
		$u                       = get_option( self::USAGE, array() );
		$u                       = is_array( $u ) ? $u : array();
		$u[ wp_date( 'Y-m' ) ] = round( (float) ( $u[ wp_date( 'Y-m' ) ] ?? 0 ) + $secs / 60 * self::PER_MIN, 4 );
		update_option( self::USAGE, array_slice( $u, -12, null, true ), false );
	}

	/** Captions can be made now: switched on, a speech-to-text key in the settings, and budget left this month. */
	private static function can_caption() {
		$s = self::settings();
		return $s['captions'] && class_exists( 'Sawa_Lens_AI' ) && '' !== Sawa_Lens_AI::settings()['stt_key'] && self::spent() < (float) $s['budget'];
	}

	public static function home() {
		$s = self::settings();
		return self::out(
			array(
				'on'       => (bool) $s['on'],
				'captions' => self::can_caption(),
				'stations' => array_values( array_map( function ( $x ) { return array( 'id' => $x['id'], 'name' => $x['name'], 'kind' => $x['kind'], 'desc' => $x['desc'] ); }, array_filter( $s['stations'], function ( $x ) { return ! empty( $x['ok'] ); } ) ) ),
			)
		);
	}

	private static function secs( $d ) {
		$p = array_map( 'intval', explode( ':', (string) $d ) );
		$t = 0;
		foreach ( $p as $x ) {
			$t = $t * 60 + $x;
		}
		return $t;
	}

	/** A podcast's newest episodes (cached for 3 hours). Only these files can be captioned. */
	private static function eps( $st ) {
		$key = 'sawa_lens_radio_eps_' . $st['id'];
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$out = array();
		$res = wp_remote_get( $st['url'], array( 'timeout' => 12 ) );
		if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
			$prev = libxml_use_internal_errors( true );
			$xml  = simplexml_load_string( wp_remote_retrieve_body( $res ), 'SimpleXMLElement', LIBXML_NOCDATA );
			libxml_use_internal_errors( $prev );
			foreach ( $xml && isset( $xml->channel->item ) ? $xml->channel->item : array() as $it ) {
				$enc = $it->enclosure ? $it->enclosure->attributes() : null;
				if ( ! $enc || false === strpos( (string) $enc['type'], 'audio' ) ) {
					continue;
				}
				$it_ns = $it->children( 'http://www.itunes.com/dtds/podcast-1.0.dtd' );
				$dur   = self::secs( (string) $it_ns->duration );
				$len   = (int) $enc['length'];
				$audio = esc_url_raw( str_replace( '/proto/http/', '/proto/https/', preg_replace( '~^http://~', 'https://', (string) $enc['url'] ) ) ); // an https page can't play http audio
				$out[ md5( $audio ) ] = array( 'ep' => md5( $audio ), 'title' => mb_substr( trim( html_entity_decode( (string) $it->title, ENT_QUOTES ) ), 0, 160 ), 'audio' => $audio, 'dur' => $dur, 'bps' => $dur > 0 && $len > 0 ? (int) round( $len / $dur ) : 16000 );
				if ( count( $out ) >= 6 ) {
					break;
				}
			}
		}
		set_transient( $key, $out, $out ? 3 * HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
		return $out;
	}

	public static function episodes( WP_REST_Request $r ) {
		$st = self::station( sanitize_key( (string) $r->get_param( 'station' ) ) );
		if ( ! $st ) {
			return new WP_Error( 'station', 'This station is not on the radio.', array( 'status' => 404 ) );
		}
		if ( 'live' === $st['kind'] ) {
			return self::out( array( 'live' => true, 'audio' => $st['url'], 'name' => $st['name'], 'captions' => self::can_caption() ) );
		}
		return self::out( array( 'episodes' => array_values( array_map( function ( $e ) { return array_intersect_key( $e, array_flip( array( 'ep', 'title', 'audio', 'dur' ) ) ); }, self::eps( $st ) ) ), 'chunk' => self::CHUNK, 'captions' => self::can_caption() ) );
	}

	/** Bytes of a file or a stream → text. $range: "bytes=a-b" for a podcast piece; $limit: how many bytes of a live stream. */
	private static function hear( $url, $range, $limit, $secs ) {
		$tmp = trailingslashit( get_temp_dir() ) . 'sawa-radio-' . wp_generate_password( 12, false ) . '.mp3';
		$args = array( 'timeout' => 30, 'stream' => true, 'filename' => $tmp, 'limit_response_size' => $limit );
		if ( $range ) {
			$args['headers'] = array( 'Range' => $range );
		}
		$res = wp_remote_get( $url, $args );
		$txt = null; // null: try again later (the audio or the speech-to-text failed); '' : nothing said (music)
		if ( ! is_wp_error( $res ) && file_exists( $tmp ) && filesize( $tmp ) > 2000 ) {
			$t = Sawa_Lens_AI::transcribe( 0, $tmp, 'English radio: a podcast or a news programme. Write what is said, with normal punctuation.', 25, false );
			if ( ! is_wp_error( $t ) ) {
				self::charge( $secs );
				$txt = $t;
			}
		}
		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		return $txt;
	}

	/** The caption for piece $k (30 s) of an episode: made once, then shared by everyone. */
	public static function caption( WP_REST_Request $r ) {
		$st = self::station( sanitize_key( (string) $r->get_param( 'station' ) ) );
		$ep = sanitize_key( (string) $r->get_param( 'ep' ) );
		$k  = max( 0, min( 400, (int) $r->get_param( 'k' ) ) );
		$e  = $st ? ( self::eps( $st )[ $ep ] ?? null ) : null;
		if ( ! $e ) {
			return new WP_Error( 'ep', 'Episode not found.', array( 'status' => 404 ) );
		}
		$key = 'sawa_lens_radio_cap_' . $ep . '_' . $k;
		$hit = get_option( $key, null );
		if ( null !== $hit ) {
			return self::out( array( 'k' => $k, 'text' => $hit ) );
		}
		if ( ! self::can_caption() ) {
			return self::out( array( 'k' => $k, 'text' => '', 'off' => true ) );
		}
		if ( get_transient( $key . '_lock' ) ) {
			return self::out( array( 'k' => $k, 'text' => '', 'wait' => true ) );
		}
		set_transient( $key . '_lock', 1, 60 );
		$a    = $k * self::CHUNK * $e['bps'];
		$text = self::hear( $e['audio'], 'bytes=' . $a . '-' . ( $a + self::CHUNK * $e['bps'] - 1 ), self::CHUNK * $e['bps'] + 1024, self::CHUNK );
		delete_transient( $key . '_lock' );
		if ( null === $text ) {
			return self::out( array( 'k' => $k, 'text' => '', 'wait' => true ) );
		}
		add_option( $key, $text, '', 'no' );
		self::forget_old();
		return self::out( array( 'k' => $k, 'text' => $text ) );
	}

	/** Keep at most ~3000 caption pieces (the oldest go first). */
	private static function forget_old() {
		global $wpdb;
		$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'sawa\\_lens\\_radio\\_cap\\_%'" ); // phpcs:ignore
		if ( $n > 3000 ) {
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'sawa\\_lens\\_radio\\_cap\\_%' ORDER BY option_id LIMIT 500" ); // phpcs:ignore
		}
	}

	/** A live station: the last captions, and one more 15-second piece if nobody made one in the last 15 seconds. */
	public static function live( WP_REST_Request $r ) {
		$st = self::station( sanitize_key( (string) $r->get_param( 'station' ) ) );
		if ( ! $st || 'live' !== $st['kind'] ) {
			return new WP_Error( 'station', 'This station is not live.', array( 'status' => 404 ) );
		}
		$key   = 'sawa_lens_radio_live_' . $st['id'];
		$lines = get_transient( $key );
		$lines = is_array( $lines ) ? $lines : array();
		$last  = $lines ? end( $lines )[0] : 0;
		if ( time() - $last >= self::LIVE && self::can_caption() && ! get_transient( $key . '_lock' ) ) {
			set_transient( $key . '_lock', 1, 40 );
			$bps  = max( 4, (int) $st['kbps'] ?: 128 ) * 125;
			$text = self::hear( $st['url'], '', self::LIVE * $bps, self::LIVE );
			if ( null !== $text && '' !== $text ) {
				$lines[] = array( time(), $text );
				set_transient( $key, array_slice( $lines, -8 ), 10 * MINUTE_IN_SECONDS );
			}
			delete_transient( $key . '_lock' );
		}
		return self::out( array( 'lines' => $lines, 'captions' => self::can_caption() ) );
	}

	/** Listening counts as study (Passive Immersion), up to 2 minutes per call (the game calls every 2 minutes). */
	public static function heard( WP_REST_Request $r ) {
		$uid  = get_current_user_id();
		$mins = max( 0, min( 2, (float) $r->get_param( 'mins' ) ) );
		if ( $mins > 0 ) {
			Sawa_Lens_Activity::add( $uid, '', 1, 'Passive Immersion', $mins );
		}
		return self::out( array( 'ok' => true ) );
	}

	/* ------------------------------------------------------------------ admin (on the "Reels, reading & radio" page) */

	public static function admin_fields() {
		$s = self::settings();
		echo '<h2>📻 Radio</h2><p><label><input type="checkbox" name="radio_on" value="1"' . checked( $s['on'], 1, false ) . '> Students can play English radio in the game instead of its music.</label></p>';
		echo '<p><label><input type="checkbox" name="radio_captions" value="1"' . checked( $s['captions'], 1, false ) . '> Captions (speech-to-text with the OpenAI key in the Sawa Lens settings)</label> · Monthly budget $<input name="radio_budget" type="number" min="0" step="1" value="' . esc_attr( $s['budget'] ) . '" style="width:70px"> · <b>Spent this month: $' . esc_html( number_format( self::spent(), 2 ) ) . '</b></p>';
		echo '<p class="description">Costs: $0.003 per minute of speech. A podcast episode is captioned once for everyone (6 Minute English ≈ $0.02, a 12-minute news episode ≈ $0.04). A live station costs ≈ $0.18 an hour, only while someone listens. At the budget, captions stop for the month and the audio keeps playing.</p>';
		echo '<p class="description">Stations, one per line: <code>id | name | podcast or live | RSS feed or stream URL | short description | stream kbps | ok</code>. Podcasts: any public podcast RSS feed. <b>Live stations: only add one whose terms allow playing its stream in another app</b> (BBC and RNZ, for example, don\'t), and put <code>ok</code> at the end to switch it on.</p>';
		$rows = array_map( function ( $x ) { return implode( ' | ', array( $x['id'], $x['name'], $x['kind'], $x['url'], $x['desc'], (int) $x['kbps'], $x['ok'] ? 'ok' : '' ) ); }, $s['stations'] );
		echo '<textarea name="radio_stations" class="large-text code" rows="5">' . esc_textarea( implode( "\n", $rows ) ) . '</textarea>';
	}

	public static function save_admin() {
		$s             = self::settings();
		$s['on']       = empty( $_POST['radio_on'] ) ? 0 : 1; // phpcs:ignore
		$s['captions'] = empty( $_POST['radio_captions'] ) ? 0 : 1; // phpcs:ignore
		$s['budget']   = max( 0, (int) ( $_POST['radio_budget'] ?? 5 ) ); // phpcs:ignore
		$list          = array();
		foreach ( array_filter( array_map( 'trim', preg_split( '/\r\n|\n/', (string) wp_unslash( $_POST['radio_stations'] ?? '' ) ) ) ) as $l ) { // phpcs:ignore
			$c = array_map( 'trim', explode( '|', $l ) );
			if ( count( $c ) < 4 || ! wp_http_validate_url( $c[3] ) ) {
				continue;
			}
			$list[] = array( 'id' => substr( sanitize_key( $c[0] ), 0, 20 ), 'name' => sanitize_text_field( $c[1] ), 'kind' => 'live' === strtolower( $c[2] ) ? 'live' : 'podcast', 'url' => esc_url_raw( $c[3] ), 'desc' => sanitize_text_field( $c[4] ?? '' ), 'kbps' => (int) ( $c[5] ?? 0 ), 'ok' => 'live' === strtolower( $c[2] ) ? (int) ( 'ok' === strtolower( $c[6] ?? '' ) ) : 1 );
		}
		$s['stations'] = $list;
		update_option( self::OPT, $s, false );
		foreach ( $list as $x ) {
			delete_transient( 'sawa_lens_radio_eps_' . $x['id'] );
		}
	}
}
