<?php
/**
 * Pictures for words, so vocabulary sticks: real photos from Wikipedia and Openverse (free, openly licensed,
 * no key needed), and an AI picture on request for abstract words. Everything is cached per word and shared
 * by all learners, so each word is looked up (or drawn) once.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sawa_Lens_Images {

	public static function init() {
		add_action( 'sawa_lens_draw', array( __CLASS__, 'draw_queue' ) );
	}

	// v2 cache: Commons added.
	private static function clean( $word ) {
		return mb_substr( strtolower( trim( preg_replace( '/[^\p{L}\p{N}\' -]+/u', '', (string) $word ) ) ), 0, 60 );
	}

	private static function get_json( $url ) {
		$res = wp_remote_get( $url, array( 'timeout' => 4, 'headers' => array( 'User-Agent' => 'SawaEnglish-LanguageLens/1.0 (https://sawaenglish.com)' ) ) );
		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		return json_decode( wp_remote_retrieve_body( $res ), true );
	}

	/**
	 * One picture for the word, fast: {images: [{thumb, url, credit, link, source}], ai}.
	 * Photos only for things you can photograph: the word must be a noun ($pos, when the caller knows it) and
	 * Wikipedia's article must be about exactly that word (not a brand, band, film or place). Tests over 60 words
	 * showed this is right for most concrete nouns; for verbs, adjectives and abstract words the photos were wrong,
	 * so those get an AI picture instead (drawn ahead of time for Learning words, see queue()).
	 */
	public static function lookup( $word, $pos = '' ) {
		$word = self::clean( $word );
		if ( '' === $word ) {
			return array( 'images' => array(), 'ai' => '' );
		}
		$ai = (string) get_option( 'sawa_lens_aiimg_' . md5( $word ), '' );
		if ( '' !== $pos && 'noun' !== $pos ) {
			return array( 'images' => array(), 'ai' => $ai );
		}
		$key = 'sawa_lens_img4_' . md5( $word );
		$hit = get_transient( $key );
		if ( ! is_array( $hit ) ) {
			$hit = array();
			$wp  = self::get_json( add_query_arg( array( 'action' => 'query', 'format' => 'json', 'prop' => 'pageimages|description', 'piprop' => 'thumbnail', 'pithumbsize' => 640, 'redirects' => 1, 'titles' => ucfirst( $word ) ), 'https://en.wikipedia.org/w/api.php' ) );
			foreach ( (array) ( $wp['query']['pages'] ?? array() ) as $p ) {
				$title = strtolower( (string) ( $p['title'] ?? '' ) );
				$desc  = (string) ( $p['description'] ?? '' );
				$same  = in_array( $title, array( $word, $word . 's', $word . 'es' ), true ) || $title === preg_replace( '/s$/', '', $word );
				$off   = preg_match( '/topics referred to|company|chain|brand|band|album|song|film|movie|television|series|novel|book|magazine|software|browser|website|video game|surname|given name|city|town|village|county|river|island|footballer|politician|singer|actor|musician|organization|party|newspaper|programming|record label/i', $desc ) || preg_match( '/^(ability|state|feeling|concept|quality|condition|act of|process)|material/i', $desc ); // ideas and materials: a drawn picture explains them better
				if ( ! $hit && $same && ! $off && ! empty( $p['thumbnail']['source'] ) ) {
					$hit[] = array( 'thumb' => $p['thumbnail']['source'], 'url' => $p['thumbnail']['source'], 'credit' => 'Wikipedia', 'link' => 'https://en.wikipedia.org/wiki/' . rawurlencode( str_replace( ' ', '_', $p['title'] ) ), 'source' => 'wikipedia' );
				}
			}
			set_transient( $key, $hit, $hit ? 30 * DAY_IN_SECONDS : 7 * DAY_IN_SECONDS );
		}
		return array( 'images' => $hit, 'ai' => $ai );
	}

	/** AI pictures drawn ahead of time each day, for the whole site (each costs about one US cent, once per word). */
	const DAILY = 60;

	/** A word was marked Learning: draw its picture in the background so it's ready when the learner reviews it. */
	public static function queue( $word, $sentence = '' ) {
		$word = self::clean( $word );
		if ( '' === $word || '' !== (string) get_option( 'sawa_lens_aiimg_' . md5( $word ), '' ) || '' === Sawa_Lens_AI::settings()['stt_key'] ) {
			return;
		}
		$q = (array) get_option( 'sawa_lens_img_queue', array() );
		if ( isset( $q[ $word ] ) || count( $q ) >= 500 ) {
			return;
		}
		$q[ $word ] = mb_substr( (string) $sentence, 0, 200 );
		update_option( 'sawa_lens_img_queue', $q, false );
		if ( ! wp_next_scheduled( 'sawa_lens_draw' ) ) {
			wp_schedule_single_event( time() + 60, 'sawa_lens_draw' );
		}
	}

	/** Cron: draw a few queued pictures, then come back for the rest (within the daily limit). */
	public static function draw_queue() {
		$q    = (array) get_option( 'sawa_lens_img_queue', array() );
		$dk   = 'sawa_lens_img_auto_' . wp_date( 'Ymd' );
		$done = (int) get_transient( $dk );
		$n    = 0;
		foreach ( $q as $word => $sentence ) {
			if ( $n >= 4 || $done >= self::DAILY ) {
				break;
			}
			unset( $q[ $word ] );
			update_option( 'sawa_lens_img_queue', $q, false );
			if ( '' !== (string) get_option( 'sawa_lens_aiimg_' . md5( $word ), '' ) ) {
				continue;
			}
			$r = self::draw( $word, $sentence );
			++$n;
			++$done;
			set_transient( $dk, $done, DAY_IN_SECONDS );
			if ( is_wp_error( $r ) ) {
				break; // try the rest later
			}
		}
		if ( $q ) {
			wp_schedule_single_event( time() + ( $done >= self::DAILY ? HOUR_IN_SECONDS * 6 : 120 ), 'sawa_lens_draw' );
		}
	}

	/** An AI picture that explains the word (OpenAI image model), saved in uploads and reused by everyone. */
	public static function ai( $uid, $word, $sentence = '' ) {
		$word = self::clean( $word );
		$opt  = 'sawa_lens_aiimg_' . md5( $word );
		$have = (string) get_option( $opt, '' );
		if ( '' !== $have ) {
			return array( 'url' => $have );
		}
		$s = Sawa_Lens_AI::settings();
		if ( '' === $s['stt_key'] ) {
			return new WP_Error( 'off', 'AI pictures are not switched on yet.', array( 'status' => 503 ) );
		}
		// AI pictures cost more than text: at most 10 a learner a day.
		$k = 'sawa_lens_aiimg_' . $uid . '_' . wp_date( 'Ymd' );
		$n = (int) get_transient( $k );
		if ( $n >= 10 && ! user_can( $uid, 'manage_options' ) ) {
			return new WP_Error( 'limit', 'You made 10 AI pictures today. More tomorrow!', array( 'status' => 429 ) );
		}
		set_transient( $k, $n + 1, DAY_IN_SECONDS );
		return self::draw( $word, $sentence );
	}

	/** Draw and save the picture (no limits: callers check them). */
	private static function draw( $word, $sentence = '' ) {
		$s   = Sawa_Lens_AI::settings();
		$opt = 'sawa_lens_aiimg_' . md5( $word );
		$prompt = 'A simple, clear, friendly flat illustration that shows the meaning of the English word "' . $word . '"'
			. ( '' !== $sentence ? ' as used in this sentence: "' . mb_substr( wp_strip_all_tags( $sentence ), 0, 200 ) . '"' : '' )
			. '. Made for a language learner to remember the word. One clear scene, bright colours, plain background. No text, no letters, no words in the image.';
		$res = wp_remote_post(
			'https://api.openai.com/v1/images/generations',
			array(
				'timeout' => 90,
				'headers' => array( 'Authorization' => 'Bearer ' . $s['stt_key'], 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'model' => 'gpt-image-1', 'prompt' => $prompt, 'size' => '1024x1024', 'quality' => 'low', 'n' => 1 ) ),
			)
		);
		$j = is_wp_error( $res ) ? null : json_decode( wp_remote_retrieve_body( $res ), true );
		$b64 = $j['data'][0]['b64_json'] ?? '';
		if ( '' !== $b64 ) {
			Sawa_Lens_Cost::log( 'picture', 'openai', 'gpt-image-1' );
		}
		if ( '' === $b64 ) {
			update_option( 'sawa_lens_last_ai_error', current_time( 'mysql' ) . ' · image: ' . mb_substr( (string) ( $j['error']['message'] ?? ( is_wp_error( $res ) ? $res->get_error_message() : 'no image' ) ), 0, 300 ), false );
			return new WP_Error( 'ai', 'The AI picture didn\'t work this time. Try again later.', array( 'status' => 502 ) );
		}
		$up  = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . 'sawa-lens-pictures';
		wp_mkdir_p( $dir );
		$name = sanitize_file_name( str_replace( ' ', '-', $word ) ) . '-' . substr( md5( $word ), 0, 6 );
		$png  = $dir . '/' . $name . '.png';
		file_put_contents( $png, base64_decode( $b64 ) ); // phpcs:ignore
		$file = $name . '.png';
		$ed   = wp_get_image_editor( $png );
		if ( ! is_wp_error( $ed ) ) {
			$ed->resize( 512, 512 );
			$ed->set_quality( 82 );
			$saved = $ed->save( $dir . '/' . $name . '.jpg', 'image/jpeg' );
			if ( ! is_wp_error( $saved ) ) {
				wp_delete_file( $png );
				$file = $name . '.jpg';
			}
		}
		$url = trailingslashit( $up['baseurl'] ) . 'sawa-lens-pictures/' . $file;
		update_option( $opt, $url, false );
		$usage = (array) get_option( 'sawa_lens_img_usage', array() );
		$m     = wp_date( 'Y-m' );
		$usage[ $m ] = (int) ( $usage[ $m ] ?? 0 ) + 1;
		update_option( 'sawa_lens_img_usage', array_slice( $usage, -12, null, true ), false );
		return array( 'url' => $url );
	}
}
