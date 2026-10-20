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
	public static function explain( $uid, $word, $sentence ) {
		$word     = mb_substr( trim( wp_strip_all_tags( (string) $word ) ), 0, 60 );
		$sentence = mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $sentence ) ) ), 0, 600 );
		if ( '' === $word ) {
			return new WP_Error( 'empty', 'لا توجد كلمة.' );
		}
		$key = hash( 'sha256', 'explain|v1|' . strtolower( $word ) . '|' . $sentence );
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
		$user   = "Word: {$word}\nSentence: {$sentence}";
		$out    = self::call( $system, $user, 400 );
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
			return new WP_Error( 'empty', 'لا توجد جملة.' );
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
			500
		);
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		$res = array( 'sentence' => mb_substr( (string) ( $out['sentence'] ?? '' ), 0, 1200 ) );
		self::store( $key, $res );
		return $res;
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
			return new WP_Error( 'limit', 'وصلت إلى الحد اليومي للشرح. القاموس ما زال يعمل، ويعود الشرح غدًا.', array( 'status' => 429 ) );
		}
		set_transient( $k, $n + 1, DAY_IN_SECONDS );
		return true;
	}

	private static function call( $system, $user, $max_tokens ) {
		$s = self::settings();
		if ( '' === $s['api_key'] ) {
			return new WP_Error( 'off', 'الشرح في سياق الجملة غير مفعّل بعد.', array( 'status' => 503 ) );
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
				'body'    => wp_json_encode(
					array(
						'model'      => $s['model'],
						'max_tokens' => $max_tokens,
						'system'     => $system,
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => $user,
							),
						),
					)
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'net', 'تعذّر الوصول إلى خدمة الشرح الآن.', array( 'status' => 502 ) );
		}
		$code = wp_remote_retrieve_response_code( $res );
		$j    = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || empty( $j['content'][0]['text'] ) ) {
			$msg = $j['error']['message'] ?? ( 'HTTP ' . $code );
			update_option( 'sawa_lens_last_ai_error', current_time( 'mysql' ) . ' · ' . mb_substr( $msg, 0, 300 ), false );
			return new WP_Error( 'ai', 'خدمة الشرح غير متاحة الآن. حاول بعد قليل.', array( 'status' => 502 ) );
		}
		$text = trim( $j['content'][0]['text'] );
		$text = preg_replace( '/^```(?:json)?\s*|\s*```$/', '', $text );
		$out  = json_decode( $text, true );
		if ( ! is_array( $out ) && preg_match( '/\{.*\}/s', $text, $m ) ) {
			$out = json_decode( $m[0], true );
		}
		if ( ! is_array( $out ) ) {
			return new WP_Error( 'ai', 'وصل الشرح بشكل غير صحيح. حاول مرة أخرى.', array( 'status' => 502 ) );
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
