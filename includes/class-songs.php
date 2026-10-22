<?php
/**
 * "My songs" (beta): a short song made from the words a learner is studying, so new words stick through music.
 * Claude writes the lyrics (new words + words they know, at their level, the hardest words in the chorus);
 * Google's Lyria (Gemini API, model lyria-3.5) sings them. Unlocks after 10 new Learning words or saved sentences
 * since the last song, at most one a day, only for beta testers, within a monthly budget. Songs are saved, so
 * replaying is free.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sawa_Lens_Songs {

	const UNLOCK = 10;   // new Learning words / saved sentences since the last song
	const MODEL  = 'lyria-3.5';
	const STYLES = array(
		'acapella' => 'A cappella: one clear solo human voice with soft backing harmonies, no instruments, no beat, no percussion, voice only',
		'pop'      => 'Bright, catchy modern pop with a clear lead vocal',
		'rap'      => 'Laid-back hip-hop with a clear, unhurried rap flow over a simple beat',
		'acoustic' => 'Warm acoustic guitar ballad with a gentle, clear vocal',
	);

	public static function init() {
		add_action( 'sawa_lens_make_song', array( __CLASS__, 'make' ) );
		// The Silk Road's song camp: only when there is a song to sing (or one can be made now).
		add_filter( 'sawa_tracker_road_song', function ( $on, $uid ) { return ( $on && self::road_ready( $uid ) ) || ( class_exists( 'Sawa_Lens_Lessons' ) && Sawa_Lens_Lessons::count_kind( 'song' ) > 0 ); }, 10, 2 );
		// …and the video camp only when there is a published video (not a song).
		add_filter( 'sawa_tracker_road_video', function ( $on ) { return class_exists( 'Sawa_Lens_Lessons' ) && Sawa_Lens_Lessons::count_kind( 'video' ) > 0; } );
	}

	/** A ready song, one being made, or one that can be made now. */
	public static function road_ready( $uid ) {
		global $wpdb;
		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t() . " WHERE user_id = %d AND status IN ('ready', 'queued', 'writing', 'singing')", $uid ) ) ) { // phpcs:ignore
			return true;
		}
		return self::state( $uid )['can'];
	}

	public static function install( $c ) {
		dbDelta(
			'CREATE TABLE ' . Sawa_Lens_Store::t( 'songs' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			status varchar(12) NOT NULL DEFAULT 'queued',
			style varchar(12) NOT NULL DEFAULT 'pop',
			slow tinyint(1) NOT NULL DEFAULT 0,
			title varchar(120) NOT NULL DEFAULT '',
			lyrics text,
			words text,
			file varchar(200) NOT NULL DEFAULT '',
			error varchar(255) NOT NULL DEFAULT '',
			cost float NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user_time (user_id,created_at)
			) $c;"
		);
	}

	private static function t() {
		return Sawa_Lens_Store::t( 'songs' );
	}

	/** Settings: beta testers (user IDs or emails, or "all"), monthly budget in USD, price per song. */
	public static function settings() {
		$s = Sawa_Lens_AI::settings();
		return array(
			'key'    => (string) ( $s['gemini_key'] ?? '' ),
			'beta'   => (string) ( $s['songs_beta'] ?? '' ),
			'budget' => (float) ( $s['songs_budget'] ?? 10 ),
			'price'  => (float) ( $s['songs_price'] ?? 0.08 ),
		);
	}

	/** Is this learner in the beta? Admins always are. */
	public static function allowed( $uid ) {
		if ( ! $uid ) {
			return false;
		}
		if ( user_can( $uid, 'manage_options' ) ) {
			return true;
		}
		$list = array_filter( array_map( 'trim', preg_split( '/[\s,]+/', strtolower( self::settings()['beta'] ) ) ) );
		if ( in_array( 'all', $list, true ) ) {
			return true;
		}
		$u = get_userdata( $uid );
		return in_array( (string) $uid, $list, true ) || ( $u && in_array( strtolower( $u->user_email ), $list, true ) );
	}

	private static function spent_this_month() {
		global $wpdb;
		return (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(cost),0) FROM ' . self::t() . ' WHERE created_at >= %s', wp_date( 'Y-m-01 00:00:00' ) ) ); // phpcs:ignore
	}

	/** What the learner can do now: new items since the last song, whether a song is unlocked, and why not. */
	public static function state( $uid ) {
		global $wpdb;
		$last  = $wpdb->get_row( $wpdb->prepare( 'SELECT created_at, status FROM ' . self::t() . " WHERE user_id = %d AND status <> 'failed' ORDER BY id DESC LIMIT 1", $uid ), ARRAY_A ); // phpcs:ignore
		$since = $last ? substr( $last['created_at'], 0, 10 ) : '1970-01-01';
		$words = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Sawa_Lens_Store::t( 'words' ) . " WHERE user_id = %d AND status = 'learning' AND learning_on >= %s", $uid, $since ) ); // phpcs:ignore
		$sents = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Sawa_Lens_Store::t( 'sentences' ) . ' WHERE user_id = %d AND deleted = 0 AND created_on >= %s', $uid, $since ) ); // phpcs:ignore
		$s     = self::settings();
		$why   = '';
		if ( '' === $s['key'] ) {
			$why = 'off';
		} elseif ( $last && in_array( $last['status'], array( 'queued', 'writing', 'singing' ), true ) ) {
			$why = 'busy';
		} elseif ( $last && substr( $last['created_at'], 0, 10 ) === wp_date( 'Y-m-d' ) ) {
			$why = 'today';
		} elseif ( $words + $sents < self::UNLOCK ) {
			$why = 'more';
		} elseif ( self::spent_this_month() + $s['price'] > $s['budget'] ) {
			$why = 'budget';
		}
		return array( 'new' => $words + $sents, 'need' => self::UNLOCK, 'can' => '' === $why, 'why' => $why, 'styles' => array_keys( self::STYLES ) );
	}

	public static function songs( $uid ) {
		global $wpdb;
		// If the background job hasn't started within 30 seconds (some hosts run scheduled tasks late), make it now.
		$stuck = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t() . " WHERE user_id = %d AND status = 'queued' AND created_at < %s ORDER BY id LIMIT 1", $uid, gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 ) ) ); // created_at is site time // phpcs:ignore
		if ( $stuck ) {
			ignore_user_abort( true );
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 240 ); // phpcs:ignore
			}
			self::make( $stuck );
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE user_id = %d ORDER BY id DESC LIMIT 50', $uid ), ARRAY_A ); // phpcs:ignore
		$up   = wp_upload_dir();
		return array_map(
			function ( $r ) use ( $up ) {
				return array(
					'id'     => (int) $r['id'],
					'status' => $r['status'],
					'style'  => $r['style'],
					'title'  => $r['title'],
					'lyrics' => (string) $r['lyrics'],
					'words'  => json_decode( (string) $r['words'], true ) ?: array(),
					'audio'  => $r['file'] ? trailingslashit( $up['baseurl'] ) . 'sawa-lens-songs/' . $r['file'] : '',
					'error'  => $r['error'],
					'made'   => $r['created_at'],
				);
			},
			$rows
		);
	}

	/** Start a song: checked here, made in the background (writing + singing take about a minute). */
	public static function request( $uid, $style, $slow ) {
		global $wpdb;
		if ( ! self::allowed( $uid ) ) {
			return new WP_Error( 'beta', 'Songs are in a small beta for now.', array( 'status' => 403 ) );
		}
		$st = self::state( $uid );
		if ( ! $st['can'] ) {
			$msg = array(
				'off'    => 'Songs are not switched on yet.',
				'busy'   => 'Your song is still being made.',
				'today'  => 'One song a day. Come back tomorrow for the next one!',
				'more'   => 'Save ' . ( self::UNLOCK - $st['new'] ) . ' more words or sentences to unlock your next song.',
				'budget' => 'New songs are paused until next month.',
			);
			return new WP_Error( $st['why'], $msg[ $st['why'] ], array( 'status' => 400 ) );
		}
		$wpdb->insert( self::t(), array( 'user_id' => $uid, 'status' => 'queued', 'style' => isset( self::STYLES[ $style ] ) ? $style : 'pop', 'slow' => $slow ? 1 : 0, 'created_at' => current_time( 'mysql' ) ) ); // phpcs:ignore
		$id = (int) $wpdb->insert_id;
		wp_schedule_single_event( time(), 'sawa_lens_make_song', array( $id ) );
		spawn_cron();
		return array( 'id' => $id );
	}

	private static function fail( $id, $msg ) {
		global $wpdb;
		$wpdb->update( self::t(), array( 'status' => 'failed', 'error' => mb_substr( $msg, 0, 250 ) ), array( 'id' => $id ) ); // phpcs:ignore
	}

	/** Cron: write the lyrics, record the song, save the MP3. */
	public static function make( $id ) {
		global $wpdb;
		$song = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
		if ( ! $song || 'queued' !== $song['status'] ) {
			return;
		}
		$uid = (int) $song['user_id'];
		$wpdb->update( self::t(), array( 'status' => 'writing' ), array( 'id' => $id ) ); // phpcs:ignore
		// 1. The words: recent Learning words and saved phrases (new), and the level for the rest of the lyrics.
		$new   = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT lemma FROM ' . Sawa_Lens_Store::t( 'words' ) . " WHERE user_id = %d AND status = 'learning' ORDER BY learning_on DESC, lemma LIMIT 14", $uid ) ); // phpcs:ignore
		$sents = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT text FROM ' . Sawa_Lens_Store::t( 'sentences' ) . " WHERE user_id = %d AND deleted = 0 AND kind = 'phrase' ORDER BY id DESC LIMIT 4", $uid ) ); // phpcs:ignore
		$p     = (array) get_user_meta( $uid, 'sawa_lens_placement', true );
		$level = Sawa_Lens_Store::level( $uid ) ?: ( (int) ( $p['levelNum'] ?? 0 ) ?: 2 );
		$cefr  = array( 1 => 'A1', 2 => 'A2', 3 => 'B1', 4 => 'B2', 5 => 'C1', 6 => 'C2' )[ $level ];
		$lyr   = Sawa_Lens_AI::song_lyrics( $uid, $new, $sents, $cefr, $song['style'] );
		if ( is_wp_error( $lyr ) ) {
			return self::fail( $id, $lyr->get_error_message() );
		}
		$wpdb->update( self::t(), array( 'status' => 'singing', 'title' => mb_substr( $lyr['title'], 0, 120 ), 'lyrics' => $lyr['lyrics'], 'words' => wp_json_encode( $lyr['words'] ) ), array( 'id' => $id ) ); // phpcs:ignore
		// 2. Lyria sings it.
		$prompt = self::STYLES[ $song['style'] ] . '. ' . ( $song['slow'] ? 'Slow tempo (about 75 BPM), ' : 'Medium tempo, ' )
			. 'every word sung very clearly in standard American English, so a learner of English can understand each word. About 75 seconds long. '
			. 'Sing exactly these lyrics, in this order, without adding or changing words.' . "\n\nLyrics:\n" . $lyr['lyrics'];
		$res = wp_remote_post(
			'https://generativelanguage.googleapis.com/v1beta/interactions',
			array(
				'timeout' => 180,
				'headers' => array( 'Content-Type' => 'application/json', 'x-goog-api-key' => self::settings()['key'] ),
				'body'    => wp_json_encode( array( 'model' => self::MODEL, 'input' => $prompt ) ),
			)
		);
		$j     = is_wp_error( $res ) ? null : json_decode( wp_remote_retrieve_body( $res ), true );
		$audio = '';
		foreach ( (array) ( $j['steps'] ?? array() ) as $step ) {
			if ( 'model_output' !== ( $step['type'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $step['content'] ?? array() ) as $block ) {
				if ( 'audio' === ( $block['type'] ?? '' ) && ! empty( $block['data'] ) ) {
					$audio = base64_decode( $block['data'] ); // phpcs:ignore
				}
			}
		}
		if ( '' === $audio ) {
			$why = is_wp_error( $res ) ? $res->get_error_message() : ( $j['error']['message'] ?? 'no audio' );
			update_option( 'sawa_lens_last_song_error', current_time( 'mysql' ) . ' · ' . mb_substr( (string) $why, 0, 300 ), false );
			return self::fail( $id, 'The song couldn\'t be recorded this time. Try again later.' );
		}
		$up  = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . 'sawa-lens-songs';
		wp_mkdir_p( $dir );
		if ( ! file_exists( $dir . '/index.html' ) ) {
			file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore -- no folder listing
		}
		$file = $id . '-' . wp_generate_password( 20, false ) . '.mp3'; // hard-to-guess name
		file_put_contents( $dir . '/' . $file, $audio ); // phpcs:ignore
		$wpdb->update( self::t(), array( 'status' => 'ready', 'file' => $file, 'cost' => self::settings()['price'] ), array( 'id' => $id ) ); // phpcs:ignore
		Sawa_Lens_Cost::log( 'song_audio', 'google', self::MODEL, 0, 0, (float) self::settings()['price'], 0, $uid );
	}

	/** Finished the fill-the-gaps game for a song: XP once per song. */
	public static function played( $uid, $id, $right, $total ) {
		$done = (array) get_user_meta( $uid, 'sawa_lens_song_games', true );
		if ( isset( $done[ $id ] ) || $total < 1 ) {
			return array( 'xp' => 0 );
		}
		$done[ $id ] = 1;
		update_user_meta( $uid, 'sawa_lens_song_games', $done );
		$xp = 5 + (int) round( 10 * min( 1, $right / $total ) );
		if ( class_exists( 'Sawa_Lens_Activity' ) ) {
			Sawa_Lens_Activity::add( $uid, '', 1, 'Active Immersion', 3 ); // listening and singing along counts as study time
		}
		if ( class_exists( 'Sawa_Tracker_XP' ) ) {
			$xp = Sawa_Tracker_XP::award( $uid, $xp, 'song' );
		}
		return array( 'xp' => (int) $xp );
	}
}
