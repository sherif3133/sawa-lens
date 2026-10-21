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

	// v2 cache: Commons added.
	private static function clean( $word ) {
		return mb_substr( strtolower( trim( preg_replace( '/[^\p{L}\p{N}\' -]+/u', '', (string) $word ) ) ), 0, 60 );
	}

	private static function get_json( $url ) {
		$res = wp_remote_get( $url, array( 'timeout' => 12, 'headers' => array( 'User-Agent' => 'SawaEnglish-LanguageLens/1.0 (https://sawaenglish.com)' ) ) );
		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		return json_decode( wp_remote_retrieve_body( $res ), true );
	}

	/** Up to 4 pictures: [{thumb, url, credit, link, source}], plus an AI picture if one was made. */
	public static function lookup( $word ) {
		$word = self::clean( $word );
		if ( '' === $word ) {
			return array( 'images' => array(), 'ai' => '' );
		}
		$key = 'sawa_lens_img2_' . md5( $word );
		$hit = get_transient( $key );
		if ( ! is_array( $hit ) ) {
			$hit = array();
			// 1. Wikipedia: the article's main picture (skip disambiguation pages).
			$wp = self::get_json( add_query_arg( array( 'action' => 'query', 'format' => 'json', 'prop' => 'pageimages|description', 'piprop' => 'thumbnail', 'pithumbsize' => 400, 'redirects' => 1, 'titles' => ucfirst( $word ) ), 'https://en.wikipedia.org/w/api.php' ) );
			foreach ( (array) ( $wp['query']['pages'] ?? array() ) as $p ) {
				if ( ! empty( $p['thumbnail']['source'] ) && false === stripos( (string) ( $p['description'] ?? '' ), 'topics referred to' ) ) {
					$hit[] = array( 'thumb' => $p['thumbnail']['source'], 'url' => $p['thumbnail']['source'], 'credit' => 'Wikipedia', 'link' => 'https://en.wikipedia.org/wiki/' . rawurlencode( str_replace( ' ', '_', $p['title'] ) ), 'source' => 'wikipedia' );
				}
			}
			// 2. Wikimedia Commons search: free photos and drawings.
			$cm = self::get_json( add_query_arg( array( 'action' => 'query', 'format' => 'json', 'generator' => 'search', 'gsrsearch' => 'filetype:bitmap ' . $word, 'gsrnamespace' => 6, 'gsrlimit' => 6, 'prop' => 'imageinfo', 'iiprop' => 'url|extmetadata', 'iiurlwidth' => 320 ), 'https://commons.wikimedia.org/w/api.php' ) );
			$pages = (array) ( $cm['query']['pages'] ?? array() );
			usort( $pages, function ( $a, $b ) { return ( $a['index'] ?? 0 ) <=> ( $b['index'] ?? 0 ); } );
			foreach ( $pages as $p ) {
				if ( count( $hit ) >= 4 ) {
					break;
				}
				$ii = $p['imageinfo'][0] ?? null;
				if ( ! $ii || empty( $ii['thumburl'] ) ) {
					continue;
				}
				$artist = trim( wp_strip_all_tags( (string) ( $ii['extmetadata']['Artist']['value'] ?? '' ) ) );
				$hit[]  = array( 'thumb' => $ii['thumburl'], 'url' => $ii['thumburl'], 'credit' => mb_substr( ( $artist ? $artist . ' · ' : '' ) . 'Wikimedia Commons', 0, 80 ), 'link' => (string) ( $ii['descriptionurl'] ?? '' ), 'source' => 'commons' );
			}
			// 3. Openverse: openly licensed photos and illustrations.
			$ov = self::get_json( add_query_arg( array( 'q' => $word, 'page_size' => 8, 'mature' => 'false' ), 'https://api.openverse.org/v1/images/' ) );
			foreach ( (array) ( $ov['results'] ?? array() ) as $r ) {
				if ( count( $hit ) >= 4 ) {
					break;
				}
				if ( empty( $r['thumbnail'] ) || empty( $r['url'] ) ) {
					continue;
				}
				$hit[] = array(
					'thumb'  => $r['thumbnail'],
					'url'    => $r['url'],
					'credit' => trim( ( $r['creator'] ?? '' ) . ' · ' . strtoupper( (string) ( $r['license'] ?? '' ) ), ' ·' ),
					'link'   => (string) ( $r['foreign_landing_url'] ?? $r['url'] ),
					'source' => 'openverse',
				);
			}
			set_transient( $key, $hit, count( $hit ) >= 2 ? 30 * DAY_IN_SECONDS : DAY_IN_SECONDS );
		}
		return array( 'images' => $hit, 'ai' => (string) get_option( 'sawa_lens_aiimg_' . md5( $word ), '' ) );
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
