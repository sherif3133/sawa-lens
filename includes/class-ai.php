<?php
/**
 * Arabic explanations from Claude, through this server so the API key never leaves it.
 * Every answer is cached by (kind, word, sentence), so the same lookup is paid for once.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sawa_Lens_AI {

	const OPT = 'sawa_lens_settings';

	public static function settings() {
		return wp_parse_args(
			(array) get_option( self::OPT, array() ),
			array(
				'api_key'     => '',
				'model'       => 'claude-haiku-5-5',
				'daily_limit' => 150,
				'store_url'   => '',
			)
		);
	}

	public static function ready() {
		return '' !== self::settings()['api_key'];
	}

	/** Explains a word as used in a sentence: Arabic meaning, sentence translation, short note. */
	public static function explain( $uid, $word, $sentence, $lang = 'ar' ) {
		$lang     = 'en' === $lang ? 'en' : 'ar';
		$word     = mb_substr( trim( wp_strip_all_tags( (string) $word ) ), 0, 60 );
		$sentence = mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $sentence ) ) ), 0, 600 );
		if ( '' === $word ) {
			return new WP_Error( 'empty', 'No word.' );
		}
		$key = hash( 'sha256', 'explain|v1|' . ( 'en' === $lang ? 'en|' : '' ) . strtolower( $word ) . '|' . $sentence );
		$hit = self::cached( $key );
		if ( $hit ) {
			return $hit;
		}
		$limit = self::limit( $uid );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$system = 'You help Arabic-speaking learners of English (A2–B2). Answer in Modern Standard Arabic (فصحى), clear and short. '
			. 'Return ONLY a JSON object, no prose, with keys: '
			. '"meaning" (the Arabic meaning of the English word or phrase exactly as used in this sentence, 1–5 words), '
			. '"sentence" (a natural Arabic translation of the whole sentence), '
			. '"note" (one short Arabic sentence about usage only if useful: phrasal verb, idiom, collocation, false friend, or informal register; otherwise empty string). '
			. 'Never invent context that is not in the sentence.';
		if ( 'en' === $lang ) {
			$system = 'You help Arabic-speaking learners of English (A2–B2) understand English in English. Use simple, clear English (B1 level). '
				. 'Return ONLY a JSON object, no prose, with keys: '
				. '"meaning" (a short, simple English definition of the word or phrase exactly as used in this sentence, max 15 words), '
				. '"sentence" (the whole sentence said again in simpler English), '
				. '"note" (one short sentence about usage only if useful: phrasal verb, idiom, collocation, register; otherwise empty string). '
				. 'Never invent context that is not in the sentence.';
		}
		$user   = "Word: {$word}\nSentence: {$sentence}";
		$out    = self::call( $system, $user, 1200 );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		$res = array(
			'meaning'  => mb_substr( (string) ( $out['meaning'] ?? '' ), 0, 120 ),
			'sentence' => mb_substr( (string) ( $out['sentence'] ?? '' ), 0, 1000 ),
			'note'     => mb_substr( (string) ( $out['note'] ?? '' ), 0, 300 ),
		);
		self::store( $key, $res );
		return $res;
	}

	/** Translates a sentence (used when saving a sentence without a word focus). */
	public static function translate( $uid, $sentence ) {
		$sentence = mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $sentence ) ) ), 0, 800 );
		if ( '' === $sentence ) {
			return new WP_Error( 'empty', 'No sentence.' );
		}
		$key = hash( 'sha256', 'translate|v1|' . $sentence );
		$hit = self::cached( $key );
		if ( $hit ) {
			return $hit;
		}
		$limit = self::limit( $uid );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$out = self::call(
			'Translate the English text into natural Modern Standard Arabic for a learner. Return ONLY a JSON object: {"sentence": "..."}.',
			$sentence,
			1500
		);
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		$res = array( 'sentence' => mb_substr( (string) ( $out['sentence'] ?? '' ), 0, 1200 ) );
		self::store( $key, $res );
		return $res;
	}

	/**
	 * How a phrase or sentence sounds in natural, fast American English, and why:
	 * linking, flap t, weak forms, elision, assimilation, contractions.
	 */
	public static function sound( $uid, $text ) {
		$text = mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) ), 0, 400 );
		if ( '' === $text ) {
			return new WP_Error( 'empty', 'Nothing to explain.' );
		}
		$key = hash( 'sha256', 'sound|v1|' . strtolower( $text ) );
		$hit = self::cached( $key );
		if ( $hit ) {
			return $hit;
		}
		$limit = self::limit( $uid );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$system = 'You are a pronunciation coach for Arabic-speaking learners of American English (A2–B2). '
			. 'Show how the text sounds when a native speaker says it naturally and fairly fast. '
			. 'Return ONLY a JSON object with keys: '
			. '"soundsLike": a respelling of the whole text as it sounds, Google-style (syllables joined with "·" inside words, words separated by spaces, words that merge are written together, the stressed syllable of important words in CAPITALS), e.g. "wuh·DYUH wan·nuh DOO"; '
			. '"ipa": a broad American IPA transcription of the connected speech; '
			. '"features": an array (max 5) of objects {"where": the exact words from the text, "feature": short name (linking, flap t, weak form, elision, assimilation, contraction, -ed ending, stress, intonation), "why": one short simple-English sentence explaining the rule, e.g. "When a word ends in a consonant and the next starts with a vowel, we join them."}. '
			. 'Only include features that really happen in this text. Plain JSON, no markdown.';
		$out    = self::call( $system, "Text to analyse:\n" . $text, 1200 );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		$features = array();
		foreach ( array_slice( (array) ( $out['features'] ?? array() ), 0, 6 ) as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$features[] = array(
				'where'   => mb_substr( (string) ( $f['where'] ?? '' ), 0, 80 ),
				'feature' => mb_substr( (string) ( $f['feature'] ?? '' ), 0, 40 ),
				'why'     => mb_substr( (string) ( $f['why'] ?? '' ), 0, 240 ),
			);
		}
		$res = array(
			'soundsLike' => mb_substr( (string) ( $out['soundsLike'] ?? '' ), 0, 600 ),
			'ipa'        => mb_substr( (string) ( $out['ipa'] ?? '' ), 0, 600 ),
			'features'   => $features,
		);
		self::store( $key, $res );
		return $res;
	}

	/**
	 * Messages API body. Our answers are short JSON, so thinking is switched off where the model allows it:
	 * Claude Haiku 5.5 thinks by default and accepts {type: disabled} at its default effort; Opus 5.5 and
	 * Sonnet 5.5 reject "disabled", so for those we leave thinking at its default.
	 */
	private static function request_body( $model, $system, $user, $max_tokens ) {
		$body = array(
			'model'      => $model,
			'max_tokens' => max( 1500, (int) $max_tokens ),
			'system'     => $system,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
		);
		if ( 0 === strpos( $model, 'claude-haiku' ) ) {
			$body['thinking'] = array( 'type' => 'disabled' );
		}
		return $body;
	}

	/**
	 * The last complete {…} object in a reply. The model sometimes writes an answer, then a corrected one:
	 * the last valid object is the one it meant.
	 */
	private static function last_json_object( $text ) {
		$found = null;
		$len   = strlen( $text );
		for ( $i = 0; $i < $len; $i++ ) {
			if ( '{' !== $text[ $i ] ) {
				continue;
			}
			$depth = 0;
			$in    = false;
			for ( $k = $i; $k < $len; $k++ ) {
				$c = $text[ $k ];
				if ( $in ) {
					if ( '\\' === $c ) {
						$k++;
					} elseif ( '"' === $c ) {
						$in = false;
					}
					continue;
				}
				if ( '"' === $c ) {
					$in = true;
				} elseif ( '{' === $c ) {
					$depth++;
				} elseif ( '}' === $c ) {
					$depth--;
					if ( 0 === $depth ) {
						$obj = json_decode( substr( $text, $i, $k - $i + 1 ), true );
						if ( is_array( $obj ) ) {
							$found = $obj;
							$i     = $k;
						}
						break;
					}
				}
			}
		}
		return $found;
	}

	private static function cached( $key ) {
		global $wpdb;
		$body = $wpdb->get_var( $wpdb->prepare( 'SELECT body FROM ' . Sawa_Lens_Store::t( 'ai' ) . ' WHERE hash = %s', $key ) ); // phpcs:ignore
		$j    = $body ? json_decode( $body, true ) : null;
		return is_array( $j ) ? $j + array( 'cached' => true ) : null;
	}

	private static function store( $key, $res ) {
		global $wpdb;
		$wpdb->replace( // phpcs:ignore
			Sawa_Lens_Store::t( 'ai' ),
			array(
				'hash'       => $key,
				'body'       => wp_json_encode( $res ),
				'created_at' => current_time( 'mysql' ),
			)
		);
	}

	private static function limit( $uid ) {
		$k = 'sawa_lens_ai_' . $uid . '_' . wp_date( 'Ymd' );
		$n = (int) get_transient( $k );
		if ( $n >= (int) self::settings()['daily_limit'] ) {
			return new WP_Error( 'limit', 'You reached today\'s limit for AI explanations. The dictionary still works, and explanations come back tomorrow.', array( 'status' => 429 ) );
		}
		set_transient( $k, $n + 1, DAY_IN_SECONDS );
		return true;
	}

	private static function call( $system, $user, $max_tokens ) {
		$s = self::settings();
		if ( '' === $s['api_key'] ) {
			return new WP_Error( 'off', 'AI explanations are not switched on yet.', array( 'status' => 503 ) );
		}
		$res = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'timeout' => 25,
				'headers' => array(
					'x-api-key'         => $s['api_key'],
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode( self::request_body( $s['model'], $system, $user, $max_tokens ) ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'net', 'Could not reach the explanation service. Try again in a moment.', array( 'status' => 502 ) );
		}
		$code = wp_remote_retrieve_response_code( $res );
		$j    = json_decode( wp_remote_retrieve_body( $res ), true );
		// The answer can hold several blocks (e.g. thinking first): join the text ones.
		$text = '';
		foreach ( (array) ( $j['content'] ?? array() ) as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) && isset( $block['text'] ) ) {
				$text .= $block['text'];
			}
		}
		if ( 200 !== $code || '' === trim( $text ) ) {
			$types = implode( ',', array_map( function ( $b ) { return $b['type'] ?? '?'; }, (array) ( $j['content'] ?? array() ) ) );
			$msg   = $j['error']['message'] ?? ( 'HTTP ' . $code . ' · blocks: ' . ( $types ? $types : 'none' ) . ' · stop: ' . ( $j['stop_reason'] ?? '?' ) );
			update_option( 'sawa_lens_last_ai_error', current_time( 'mysql' ) . ' · ' . mb_substr( $msg, 0, 300 ), false );
			return new WP_Error( 'ai', 'The explanation service is busy. Try again in a moment.', array( 'status' => 502 ) );
		}
		$text = trim( $text );
		$text = preg_replace( '/^```(?:json)?\s*|\s*```$/', '', $text );
		$out  = json_decode( $text, true );
		if ( ! is_array( $out ) ) {
			$out = self::last_json_object( $text );
		}
		if ( ! is_array( $out ) ) {
			update_option( 'sawa_lens_last_ai_error', current_time( 'mysql' ) . ' · not JSON (stop: ' . ( $j['stop_reason'] ?? '?' ) . '): ' . mb_substr( $text, 0, 300 ), false );
			return new WP_Error( 'ai', 'The explanation came back incomplete. Try again.', array( 'status' => 502 ) );
		}
		$usage = (array) get_option( 'sawa_lens_usage', array() );
		$month = wp_date( 'Y-m' );
		$usage[ $month ] = array(
			'calls'  => (int) ( $usage[ $month ]['calls'] ?? 0 ) + 1,
			'input'  => (int) ( $usage[ $month ]['input'] ?? 0 ) + (int) ( $j['usage']['input_tokens'] ?? 0 ),
			'output' => (int) ( $usage[ $month ]['output'] ?? 0 ) + (int) ( $j['usage']['output_tokens'] ?? 0 ),
		);
		update_option( 'sawa_lens_usage', array_slice( $usage, -12, null, true ), false );
		return $out;
	}
}
