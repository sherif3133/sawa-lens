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

	/** Keys can live in wp-config.php instead of the database: define( 'SAWA_LENS_CLAUDE_KEY', '…' ) etc. A defined constant wins. */
	const KEY_CONSTANTS = array(
		'api_key'    => 'SAWA_LENS_CLAUDE_KEY',
		'stt_key'    => 'SAWA_LENS_OPENAI_KEY',
		'gemini_key' => 'SAWA_LENS_GEMINI_KEY',
	);

	/** True when this key comes from wp-config.php. */
	public static function key_in_config( $field ) {
		$c = self::KEY_CONSTANTS[ $field ] ?? '';
		return $c && defined( $c ) && '' !== (string) constant( $c );
	}

	/** Settings with keys from wp-config.php filled in. Everything that calls an AI provider reads this. */
	public static function settings() {
		$s = self::stored();
		foreach ( self::KEY_CONSTANTS as $field => $c ) {
			if ( self::key_in_config( $field ) ) {
				$s[ $field ] = (string) constant( $c );
			}
		}
		return $s;
	}

	/** Settings exactly as saved in the database (the admin page saves these, so config keys never get copied into the database). */
	public static function stored() {
		return wp_parse_args(
			(array) get_option( self::OPT, array() ),
			array(
				'api_key'     => '',
				'model'       => 'claude-haiku-5-5',
				'daily_limit' => 150,
				'store_url'   => '',
				'stt_key'     => '',
				'stt_model'   => 'gpt-4o-mini-transcribe',
			)
		);
	}

	public static function ready() {
		return '' !== self::settings()['api_key'];
	}

	/** The student's "Explanations" setting: 'en' (default) or 'ar'. Shared by the extension and /app. */
	public static function explain_lang( $uid ) {
		return 'ar' === get_user_meta( $uid, 'sawa_lens_explain', true ) ? 'ar' : 'en';
	}

	/** 'en' | 'ar' from a request value; anything else (an old client sent nothing) gives $fallback. */
	public static function pick_lang( $v, $fallback ) {
		return in_array( $v, array( 'en', 'ar' ), true ) ? $v : $fallback;
	}

	/**
	 * Explains a word as used in a sentence: meaning, sentence translation, short note.
	 * $lang is the meaning's language (Arabic meaning or English definition); $expl the note's language
	 * ("Explanations" setting). Without $expl the note follows $lang, as before.
	 */
	public static function explain( $uid, $word, $sentence, $lang = 'ar', $expl = '' ) {
		$lang     = 'en' === $lang ? 'en' : 'ar';
		$expl     = self::pick_lang( $expl, $lang );
		$word     = mb_substr( trim( wp_strip_all_tags( (string) $word ) ), 0, 60 );
		$sentence = mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $sentence ) ) ), 0, 600 );
		if ( '' === $word ) {
			return new WP_Error( 'empty', 'No word.' );
		}
		$key = hash( 'sha256', 'explain|v1|' . ( 'en' === $lang ? 'en|' : '' ) . ( $expl !== $lang ? 'x' . $expl . '|' : '' ) . strtolower( $word ) . '|' . $sentence );
		$hit = self::cached( $key );
		if ( $hit ) {
			return $hit + array( 'noteLang' => $expl ); // older cached answers don't say their language
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
		if ( $expl !== $lang ) {
			$system .= 'ar' === $expl
				? ' Exception: write "note" in short Modern Standard Arabic (فصحى), keeping quoted English words in English.'
				: ' Exception: write "note" in simple, clear English (B1 level).';
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
			'noteLang' => $expl,
		);
		self::store( $key, $res );
		return $res;
	}

	/**
	 * A word that isn't in Language Lens's own dictionary (Sawa_Lens_Dict). With $senses (English definitions from a free
	 * dictionary): just the Arabic for each. Without: the whole entry. Returns {found, lemma, entries: [{pos, en, ar, ex}]}.
	 */
	public static function dict_word( $uid, $word, $senses = array() ) {
		$limit = self::limit( $uid );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		if ( $senses ) {
			$list = implode( "\n", array_map( function ( $s, $i ) { return ( $i + 1 ) . '. (' . $s['pos'] . ') ' . $s['en']; }, $senses, array_keys( $senses ) ) );
			$out  = self::call( 'You write short Arabic dictionary meanings for Arabic-speaking learners of English. Return ONLY a JSON object: {"ar": [one Arabic meaning per numbered English sense, same order, 1–4 words each, Modern Standard Arabic]}.', "Word: {$word}\n{$list}", 600 );
			if ( is_wp_error( $out ) ) {
				return $out;
			}
			$ar = array_values( (array) ( $out['ar'] ?? array() ) );
			foreach ( $senses as $i => $s ) {
				$senses[ $i ]['ar'] = mb_substr( (string) ( $ar[ $i ] ?? '' ), 0, 80 );
			}
			return array( 'found' => true, 'lemma' => $word, 'entries' => $senses );
		}
		$out = self::call(
			'You are an English dictionary for Arabic-speaking learners (A2–B2). Return ONLY a JSON object: '
			. '{"found": false if this is not a real English word or name, "lemma": its dictionary form (e.g. "epiphanies" → "epiphany"), '
			. '"entries": up to 3 main senses, each {"pos": noun|verb|adjective|adverb|other, "en": a simple English definition (max 15 words), "ar": the Arabic meaning (1–4 words, Modern Standard Arabic), "ex": a short simple example sentence}}.',
			'Word: ' . $word,
			700
		);
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		$entries = array();
		foreach ( array_slice( (array) ( $out['entries'] ?? array() ), 0, 3 ) as $e ) {
			if ( is_array( $e ) && ! empty( $e['en'] ) ) {
				$entries[] = array( 'pos' => sanitize_key( (string) ( $e['pos'] ?? '' ) ), 'en' => mb_substr( (string) $e['en'], 0, 160 ), 'ar' => mb_substr( (string) ( $e['ar'] ?? '' ), 0, 80 ), 'ex' => mb_substr( (string) ( $e['ex'] ?? '' ), 0, 160 ) );
			}
		}
		return array( 'found' => ! empty( $out['found'] ) && $entries, 'lemma' => strtolower( preg_replace( '/[^A-Za-z\' -]/', '', (string) ( $out['lemma'] ?? $word ) ) ) ?: $word, 'entries' => $entries );
	}

	/** Shared cache for Sawa_Lens_Dict: one entry per word for every student. */
	public static function dict_cached( $word ) {
		return self::cached( hash( 'sha256', 'dict|v1|' . $word ) );
	}

	public static function dict_store( $word, $res ) {
		self::store( hash( 'sha256', 'dict|v1|' . $word ), $res );
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
	 * Caption sentences → Arabic, for the line under YouTube captions in Language Lens.
	 * Up to 12 consecutive sentences per call (the AI reads them as one passage, so each is translated in context);
	 * every sentence is cached on its own (shared with translate()), so a popular video costs once for everyone.
	 * Uses its own daily counter, so watching videos never eats the student's AI explanations.
	 */
	public static function translate_lines( $uid, $lines ) {
		$clean = array();
		foreach ( array_slice( (array) $lines, 0, 12 ) as $l ) {
			$clean[] = mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $l ) ) ), 0, 800 );
		}
		$out = array_fill( 0, count( $clean ), '' );
		$todo = array();
		foreach ( $clean as $i => $l ) {
			if ( '' === $l ) {
				continue;
			}
			$hit = self::cached( hash( 'sha256', 'translate|v1|' . $l ) );
			if ( $hit && isset( $hit['sentence'] ) ) {
				$out[ $i ] = (string) $hit['sentence'];
			} else {
				$todo[ $i ] = $l;
			}
		}
		if ( ! $todo ) {
			return array( 'lines' => $out );
		}
		Sawa_Lens_Cost::set_user( $uid );
		$k = 'sawa_lens_captr_' . $uid . '_' . wp_date( 'Ymd' );
		$n = (int) get_transient( $k );
		if ( $n >= 400 ) { // about 4,000 sentences: hours of video
			return new WP_Error( 'limit', 'Caption translations are paused until tomorrow.', array( 'status' => 429 ) );
		}
		set_transient( $k, $n + 1, DAY_IN_SECONDS );
		$user = "Sentences:\n";
		foreach ( $todo as $i => $l ) {
			$user .= $i . ': ' . $l . "\n";
		}
		$res = self::call(
			'You translate English sentences from a video\'s captions into natural Modern Standard Arabic for learners. '
			. 'The numbered sentences are consecutive: use the others for context (who "it" or "they" is, the topic). '
			. 'Translate each one by its meaning, not word by word: phrasal verbs and idioms by what they mean. '
			. 'Captions can be unpunctuated or end mid-thought: still translate only that sentence\'s words, naturally. '
			. 'Return ONLY a JSON object, no markdown: {"lines":[{"i":0,"ar":"…"}, …]} with every number once.',
			$user,
			3000,
			60
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		foreach ( (array) ( $res['lines'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['i'] ) || ! isset( $todo[ (int) $row['i'] ] ) ) {
				continue;
			}
			$ar = mb_substr( trim( (string) ( $row['ar'] ?? '' ) ), 0, 1200 );
			if ( '' === $ar ) {
				continue;
			}
			$out[ (int) $row['i'] ] = $ar;
			self::store( hash( 'sha256', 'translate|v1|' . $todo[ (int) $row['i'] ] ), array( 'sentence' => $ar ) );
		}
		return array( 'lines' => $out );
	}

	const TO_EN = 'You translate Arabic (Modern Standard Arabic, Egyptian or any dialect, or Franco-Arabic written in Latin letters and numbers) into natural, simple English that an A2–B1 learner can read. Keep the meaning and tone (casual stays casual). Keep people\'s names, usernames and @mentions as they are; never translate a name. Keep emojis. Plain JSON, no markdown.';

	/**
	 * Arabic → English, so learners read English even when the page is in Arabic.
	 * With $word (an Arabic word in the text): also that word's English meaning here.
	 */
	public static function to_english( $uid, $text, $word = '' ) {
		$text = mb_substr( trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $text ) ) ), 0, 800 );
		$word = mb_substr( trim( (string) $word ), 0, 40 );
		if ( '' === $text ) {
			return new WP_Error( 'empty', 'Nothing to translate.' );
		}
		$key = hash( 'sha256', 'to_en|v1|' . $word . '|' . $text );
		$hit = self::cached( $key );
		if ( $hit ) {
			return $hit;
		}
		$limit = self::limit( $uid );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$out = self::call( self::TO_EN . ' Return ONLY a JSON object: {"english": the whole text in English' . ( '' !== $word ? ', "word": the English for the Arabic word "' . $word . '" as used here (1–3 words), "pos": its part of speech (noun, verb, adjective, adverb, other)' : '' ) . '}.', $text, 1200 );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		$res = array(
			'english' => mb_substr( (string) ( $out['english'] ?? '' ), 0, 1500 ),
			'word'    => mb_substr( (string) ( $out['word'] ?? '' ), 0, 60 ),
			'pos'     => sanitize_key( (string) ( $out['pos'] ?? '' ) ),
		);
		self::store( $key, $res );
		return $res;
	}

	/** Many short Arabic texts (posts, comments) → English in one AI call; each one cached on its own. */
	public static function to_english_many( $uid, $texts ) {
		$texts = array_slice( array_values( array_filter( array_map( function ( $t ) { return mb_substr( trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $t ) ) ), 0, 600 ); }, (array) $texts ) ) ), 0, 12 );
		$out   = array();
		$need  = array();
		foreach ( $texts as $i => $t ) {
			$hit = self::cached( hash( 'sha256', 'to_en|v1||' . $t ) );
			if ( $hit ) {
				$out[ $i ] = (string) $hit['english'];
			} else {
				$need[ $i ] = $t;
			}
		}
		if ( $need ) {
			$limit = self::limit( $uid );
			if ( is_wp_error( $limit ) ) {
				return $limit;
			}
			$r = self::call( self::TO_EN . ' You get a JSON array of texts. Return ONLY a JSON object: {"english": [one English translation for each text, same order, same count]}.', wp_json_encode( array_values( $need ), JSON_UNESCAPED_UNICODE ), 3000, 60 );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$en = array_values( (array) ( $r['english'] ?? array() ) );
			$k  = 0;
			foreach ( $need as $i => $t ) {
				$e = mb_substr( (string) ( $en[ $k++ ] ?? '' ), 0, 1200 );
				$out[ $i ] = $e;
				if ( '' !== $e ) {
					self::store( hash( 'sha256', 'to_en|v1||' . $t ), array( 'english' => $e, 'word' => '', 'pos' => '' ) );
				}
			}
		}
		ksort( $out );
		return array( 'english' => array_values( $out ) );
	}

	/**
	 * Lyrics for a learner's song ("My songs" beta): built around their new words, the rest in words they know
	 * at their level, the hardest 3–4 new words in a repeated chorus, about 75 seconds when sung.
	 * Returns { title, lyrics (with [Verse]/[Chorus] tags), words: [{word, en, ar}] }.
	 */
	public static function song_lyrics( $uid, $words, $phrases, $cefr, $style ) {
		$words   = array_slice( array_values( array_filter( array_map( 'sanitize_text_field', (array) $words ) ) ), 0, 14 );
		$phrases = array_slice( array_values( array_filter( array_map( 'sanitize_text_field', (array) $phrases ) ) ), 0, 4 );
		if ( ! $words && ! $phrases ) {
			return new WP_Error( 'empty', 'No words to sing about yet.' );
		}
		$limit = self::limit( $uid );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$system = 'You write short, catchy songs that help Arabic-speaking learners of English (CEFR ' . sanitize_text_field( $cefr ) . ') remember new words. '
			. 'Use as many of the learner\'s new words as fit naturally (at least 6), each in a clear sentence that shows its meaning. Every other word must be easy for a ' . sanitize_text_field( $cefr ) . ' learner. '
			. 'Structure: [Verse 1] 4 lines, [Chorus] 4 lines, [Verse 2] 4 lines, [Chorus] again (repeat it exactly). Short lines (5–9 words), simple rhymes, present and past simple mostly. '
			. 'The chorus repeats the 3–4 hardest new words. About 75 seconds when sung. Style: ' . sanitize_key( $style ) . ( 'rap' === $style ? ' (rhythmic lines, clean language)' : '' ) . '. '
			. 'Wholesome and positive: nothing about romance, alcohol, drugs, violence or bad language; suitable for Muslim students. No names of real people or artists, and do not copy any existing song. '
			. 'Return ONLY a JSON object: {"title": a short song title, "lyrics": the lyrics with the section tags on their own lines, "words": [for each new word you used: {"word": the word, "en": a very simple English meaning, "ar": its Arabic meaning}]}. Plain JSON, no markdown.';
		$user = 'New words: ' . implode( ', ', $words ) . ( $phrases ? "\nSaved phrases (use one or two if they fit): " . implode( ' | ', $phrases ) : '' );
		$out  = self::call( $system, $user, 4000, 60, self::ADAPT_MODEL );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		$lyrics = trim( (string) ( $out['lyrics'] ?? '' ) );
		if ( '' === $lyrics ) {
			return new WP_Error( 'ai', 'The lyrics came back empty. Try again.' );
		}
		$list = array();
		foreach ( array_slice( (array) ( $out['words'] ?? array() ), 0, 20 ) as $w ) {
			if ( ! empty( $w['word'] ) ) {
				$list[] = array( 'word' => mb_substr( strtolower( (string) $w['word'] ), 0, 40 ), 'en' => mb_substr( (string) ( $w['en'] ?? '' ), 0, 160 ), 'ar' => mb_substr( (string) ( $w['ar'] ?? '' ), 0, 120 ) );
			}
		}
		return array( 'title' => sanitize_text_field( (string) ( $out['title'] ?? 'My song' ) ), 'lyrics' => mb_substr( $lyrics, 0, 3000 ), 'words' => $list );
	}

	/** The model for graded texts: rewriting well at a level needs a stronger model than short explanations. */
	const ADAPT_MODEL = 'claude-sonnet-5-5';

	/**
	 * Graded reading (Krashen's i+1): rewrites a piece of text so a learner at $level (1 = A1 … 6 = C2) knows about
	 * 95–98% of the words, plus a few useful words just above their level (the "+1"), preferably words they are
	 * already learning. Meaning, facts, names and the order of ideas stay the same.
	 * Returns { paragraphs: [...], targets: [{word, en, ar}] }. Long texts are sent in chunks by the app.
	 */
	public static function adapt( $uid, $text, $level, $learning = array() ) {
		$text  = mb_substr( trim( preg_replace( "/[ \t]+/u", ' ', wp_strip_all_tags( (string) $text ) ) ), 0, 6000 );
		$level = max( 1, min( 6, (int) $level ) );
		if ( '' === $text ) {
			return new WP_Error( 'empty', 'No text to adapt.' );
		}
		$cefr  = array( 1 => 'A1', 2 => 'A2', 3 => 'B1', 4 => 'B2', 5 => 'C1', 6 => 'C2' );
		$cur   = $cefr[ $level ];
		$next  = $cefr[ min( 6, $level + 1 ) ];
		$learn = array_slice( array_values( array_filter( array_map( 'sanitize_text_field', (array) $learning ) ) ), 0, 40 );
		$key   = hash( 'sha256', 'adapt|v1|' . $level . '|' . implode( ',', $learn ) . '|' . $text );
		$hit   = self::cached( $key );
		if ( $hit ) {
			return $hit;
		}
		$limit = self::limit( $uid );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$system = 'You write graded readers for Arabic-speaking learners of English. Rewrite the text for a learner at CEFR ' . $cur . ', following Krashen\'s i+1: '
			. 'about 95–98% of the running words must be words a ' . $cur . ' learner knows (the most common ' . ( $level <= 2 ? '1,500' : ( 3 === $level ? '3,000' : '5,000' ) ) . ' English words), and the rest are a few useful words just above that level (' . $next . '), about 2 for every 100 words, never more than 4. '
			. 'Those new words must be understandable from the sentence around them. '
			. ( $learn ? 'If they fit naturally, use some of the words this learner is studying as new words: ' . implode( ', ', $learn ) . '. ' : '' )
			. 'Keep the meaning, the facts, the order of ideas and the tone. Keep people\'s and place names as they are. Do not add opinions or new information, and do not shorten it into a summary: keep every idea. '
			. ( $level <= 2 ? 'Use short sentences (about 8–14 words), present and past simple mostly, and clear linking words (and, but, because, so, then). ' : 'Use clear sentences (about 12–20 words); keep useful grammar but split very long sentences. ' )
			. 'Explain idioms and cultural references in simple words inside the text. Keep the paragraph breaks of the original. '
			. 'Return ONLY a JSON object: {"paragraphs": [the rewritten paragraphs, as strings], "targets": [for each new above-level word you used: {"word": the word as it appears (base form), "en": a very simple English meaning, "ar": its Arabic meaning}]}. Plain JSON, no markdown.';
		$out = self::call( $system, $text, 9000, 90, self::ADAPT_MODEL );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		$paras = array_values( array_filter( array_map( function ( $p ) { return mb_substr( trim( (string) $p ), 0, 4000 ); }, (array) ( $out['paragraphs'] ?? array() ) ) ) );
		if ( ! $paras ) {
			return new WP_Error( 'ai', 'The text came back empty. Try again.', array( 'status' => 502 ) );
		}
		$targets = array();
		foreach ( array_slice( (array) ( $out['targets'] ?? array() ), 0, 30 ) as $t ) {
			if ( ! empty( $t['word'] ) ) {
				$targets[] = array( 'word' => mb_substr( strtolower( (string) $t['word'] ), 0, 40 ), 'en' => mb_substr( (string) ( $t['en'] ?? '' ), 0, 160 ), 'ar' => mb_substr( (string) ( $t['ar'] ?? '' ), 0, 120 ) );
			}
		}
		$res = array( 'paragraphs' => $paras, 'targets' => $targets, 'level' => $cur );
		self::store( $key, $res );
		return $res;
	}

	/**
	 * "Stories for you" (beta): an original graded reader about a learner's interest, at their level (i+1).
	 * $b: level, next, words (length), topic, format (story|explainer|series), chapter, of, story (chapters so far),
	 * name (series name), avoid (titles already written), use (Learning words to weave in), seed.
	 * Returns { title, paragraphs, targets: [{word, en, ar}], used, questions: [{q, options, answer, ar}], recap, teaser }.
	 */
	public static function reader( $uid, $b ) {
		$limit = self::limit( $uid );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$lv     = sanitize_text_field( (string) $b['level'] );
		$low    = in_array( $lv, array( 'A1', 'A2' ), true );
		$use    = array_slice( array_values( array_filter( array_map( 'sanitize_text_field', (array) $b['use'] ) ) ), 0, 6 );
		$format = array(
			'story'     => 'a short story with one original main character (an Egyptian or Arab young person is a good choice) and a clear beginning, problem and ending. Show feelings and small real-life details',
			'explainer' => 'a true-to-life explainer: how something in this topic works, or why it happens, told with one everyday example. Only well-known, general facts; if you are not sure a fact is true, leave it out',
			'series'    => 'chapter ' . (int) $b['chapter'] . ' of ' . (int) $b['of'] . ' of a story series with one original main character. ' . ( (int) $b['chapter'] < (int) $b['of'] ? 'End on a small cliffhanger so the reader wants the next chapter tomorrow' : 'This is the last chapter: give the story a satisfying ending' ),
		)[ $b['format'] ] ?? 'a short story';
		$system = 'You write original graded readers for Arabic-speaking learners of English (mostly young Egyptians). '
			. 'Write ' . $format . ', about this topic the learner loves: ' . sanitize_text_field( (string) $b['topic'] ) . '. Make it fun and gripping for a fan of this topic, with the topic as the hook. '
			. 'Level: CEFR ' . $lv . ', following Krashen\'s i+1: about 95–98% of the running words are words a ' . $lv . ' learner knows, plus about 2 useful new words per 100 words just above that level (' . sanitize_text_field( (string) $b['next'] ) . '), each one understandable from the sentence around it. '
			. 'Length: ' . sanitize_text_field( (string) $b['words'] ) . ' words, in short paragraphs (2–5 sentences each). '
			. ( $low ? 'Use short sentences (about 8–14 words), mostly present and past simple, and clear linking words. ' : 'Use clear sentences (about 12–20 words) with natural, varied grammar. ' )
			. ( $use ? 'Weave in these words the learner is studying, only where they fit the story naturally, each once or twice, never as a list or a vocabulary lesson: ' . implode( ', ', $use ) . '. ' : '' )
			. 'Rules: everything is original. Never copy or quote song lyrics, books, articles, films or speeches. Never use real people as characters and never say anything about a real, named person (no footballers, rappers, YouTubers, actors or politicians): invent original characters, teams, bands and shops inspired by the topic. Real places, countries and cities are fine. '
			. 'Keep it family-friendly and respectful of Egyptian and Muslim culture: no romance, alcohol, swearing, gambling or graphic violence. '
			. ( $b['story'] ? 'The story so far: ' . implode( ' ', array_map( 'sanitize_text_field', (array) $b['story'] ) ) . ' Continue it with the same characters and names. ' : '' )
			. ( $b['avoid'] ? 'Other readers on this topic already have these titles; write something clearly different: ' . implode( ' | ', array_map( 'sanitize_text_field', array_slice( (array) $b['avoid'], 0, 15 ) ) ) . '. ' : '' )
			. 'Then write 4 quick comprehension questions about the text (who, what, why, and one about the meaning of a word), each with 3 short answer options and exactly one right answer' . ( $low ? ', plus the question in simple Arabic' : '' ) . '. '
			. 'Return ONLY a JSON object: {"title": a short catchy title (no chapter number), "paragraphs": [the text, as strings], "targets": [each new above-level word you used: {"word": base form, "en": a very simple English meaning, "ar": its Arabic meaning}], "used": [the words from the learner\'s list that you used], "questions": [{"q": the question, "options": [3 answers], "answer": the index (0–2) of the right one, "ar": the question in Arabic or ""}], "recap": 2 sentences that sum up what happened (for writing the next chapter), "teaser": one short sentence in English that makes someone want to read it}. Plain JSON, no markdown.';
		$out    = self::call( $system, 'Write it now. (Variation #' . (int) $b['seed'] . ')', 9000, 120, self::ADAPT_MODEL );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		$paras = array_values( array_filter( array_map( function ( $p ) { return mb_substr( trim( (string) $p ), 0, 3000 ); }, (array) ( $out['paragraphs'] ?? array() ) ) ) );
		if ( count( $paras ) < 2 || '' === trim( (string) ( $out['title'] ?? '' ) ) ) {
			return new WP_Error( 'ai', 'The story came back incomplete. Try again.', array( 'status' => 502 ) );
		}
		$targets = array();
		foreach ( array_slice( (array) ( $out['targets'] ?? array() ), 0, 25 ) as $t ) {
			if ( ! empty( $t['word'] ) ) {
				$targets[] = array( 'word' => mb_substr( strtolower( (string) $t['word'] ), 0, 40 ), 'en' => mb_substr( (string) ( $t['en'] ?? '' ), 0, 160 ), 'ar' => mb_substr( (string) ( $t['ar'] ?? '' ), 0, 120 ) );
			}
		}
		$qs = array();
		foreach ( array_slice( (array) ( $out['questions'] ?? array() ), 0, 6 ) as $q ) {
			$opts = array_values( array_map( function ( $o ) { return mb_substr( (string) $o, 0, 160 ); }, (array) ( $q['options'] ?? array() ) ) );
			$a    = (int) ( $q['answer'] ?? -1 );
			if ( ! empty( $q['q'] ) && count( $opts ) >= 2 && $a >= 0 && $a < count( $opts ) ) {
				$qs[] = array( 'q' => mb_substr( (string) $q['q'], 0, 240 ), 'options' => $opts, 'answer' => $a, 'ar' => mb_substr( (string) ( $q['ar'] ?? '' ), 0, 240 ) );
			}
		}
		return array(
			'title'      => mb_substr( trim( (string) $out['title'] ), 0, 120 ),
			'paragraphs' => $paras,
			'targets'    => $targets,
			'used'       => array_slice( array_values( array_intersect( array_map( 'strtolower', (array) ( $out['used'] ?? array() ) ), array_map( 'strtolower', $use ) ) ), 0, 6 ),
			'questions'  => $qs,
			'recap'      => mb_substr( (string) ( $out['recap'] ?? '' ), 0, 600 ),
			'teaser'     => mb_substr( (string) ( $out['teaser'] ?? '' ), 0, 200 ),
		);
	}

	/**
	 * How a phrase or sentence sounds in natural, fast American English, and why:
	 * linking, flap t, weak forms, elision, assimilation, contractions.
	 */
	public static function sound( $uid, $text, $lang = 'en' ) {
		$lang = 'ar' === $lang ? 'ar' : 'en';
		$text = mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) ), 0, 400 );
		if ( '' === $text ) {
			return new WP_Error( 'empty', 'Nothing to explain.' );
		}
		$key = hash( 'sha256', 'sound|v1|' . ( 'ar' === $lang ? 'ar|' : '' ) . strtolower( $text ) );
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
		if ( 'ar' === $lang ) {
			$system .= ' Write every "why" in short, simple Modern Standard Arabic (فصحى) instead of English, keeping the English words and sounds you quote in English; "feature" stays an English name.';
		}
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
			'lang'       => $lang,
		);
		self::store( $key, $res );
		return $res;
	}

	/**
	 * A whole song's lines for 🎵 Music in Language Lens: each line translated into Arabic by what it actually means
	 * (slang, metaphors and idioms resolved, not word by word), and its slang explained: the literal meaning and the
	 * real one ("quesería": literally "cheese shop"; cheese = money). Notes in English (default) or Arabic.
	 * One request per song, cached and shared by every student.
	 */
	public static function song_lines( $uid, $song, $lines, $lang = 'en' ) {
		$lang  = 'ar' === $lang ? 'ar' : 'en';
		$song  = mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $song ) ) ), 0, 120 );
		$clean = array();
		foreach ( array_slice( (array) $lines, 0, 120 ) as $l ) {
			$clean[] = mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $l ) ) ), 0, 200 );
		}
		if ( ! array_filter( $clean ) ) {
			return new WP_Error( 'empty', 'No lyrics.' );
		}
		$key = hash( 'sha256', 'songlines|v2|' . $lang . '|' . strtolower( $song ) . '|' . implode( "\n", $clean ) );
		$hit = self::cached( $key );
		if ( $hit ) {
			return $hit;
		}
		$limit = self::limit( $uid );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$notes  = 'ar' === $lang ? 'clear, short Modern Standard Arabic' : 'simple English (B1)';
		$system = 'You translate English song lyrics for Arabic-speaking learners of English (A2–B2). '
			. 'For EVERY numbered line, write "ar": a natural Modern Standard Arabic translation of what the singer actually means: resolve slang, metaphors, idioms and wordplay; do not translate word by word. '
			. 'Then, only for lines that have them, list "slang": the slang, idioms, metaphors, wordplay, non-English words, or words used with a non-standard meaning. '
			. 'For each: "text" (the exact words from the line), "literal" (the literal, word-for-word meaning, in ' . $notes . '), "meaning" (what it really means in this song, in ' . $notes . ', max 20 words). '
			. 'Example: "My block made of quesería" → slang [{"text":"quesería","literal":"a cheese shop (Spanish)","meaning":"lots of money: cheese is slang for money"}]. '
			. 'Rude, vulgar or sexual words and insults (e.g. asshole, fuck, bitch, pussy, dick, slurs) are ALWAYS listed: "literal" and "meaning" are said politely and formally (no rude words in Arabic or English, e.g. “an insult for a mean person”); "severity" is one of "mild" (common, not very rude, often casual), "moderate", "strong" or "slur" (hurtful to a group: never use it); "context" (in ' . $notes . ', max 30 words) says how the meaning and strength change with context and tone, e.g. jokingly between close friends vs said in anger to a stranger. Other notes have "severity":"" and no context. '
			. 'The "ar" line translation stays polite and formal too, while keeping the real meaning. '
			. 'Use the other lines for context. Be faithful; do not invent a story. '
			. 'Return ONLY a JSON object, no markdown: {"lines":[{"i":0,"ar":"…","slang":[…]}, …]} with every line once, in order.';
		$user = ( $song ? "Song: {$song}\n" : '' ) . "Lines:\n";
		foreach ( $clean as $i => $l ) {
			$user .= $i . ': ' . $l . "\n";
		}
		$out = self::call( $system, $user, 8000, 120 );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		$res = array();
		foreach ( (array) ( $out['lines'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['i'] ) || ! isset( $clean[ (int) $row['i'] ] ) ) {
				continue;
			}
			$slang = array();
			foreach ( array_slice( (array) ( $row['slang'] ?? array() ), 0, 4 ) as $s ) {
				if ( is_array( $s ) && ! empty( $s['text'] ) ) {
					$sev     = (string) ( $s['severity'] ?? '' );
					$slang[] = array(
						'severity' => in_array( $sev, array( 'mild', 'moderate', 'strong', 'slur' ), true ) ? $sev : '',
						'context'  => mb_substr( (string) ( $s['context'] ?? '' ), 0, 300 ),
						'text'    => mb_substr( (string) $s['text'], 0, 80 ),
						'literal' => mb_substr( (string) ( $s['literal'] ?? '' ), 0, 160 ),
						'meaning' => mb_substr( (string) ( $s['meaning'] ?? '' ), 0, 240 ),
					);
				}
			}
			$res[ (int) $row['i'] ] = array(
				'ar'    => mb_substr( (string) ( $row['ar'] ?? '' ), 0, 400 ),
				'slang' => $slang,
			);
		}
		if ( ! $res ) {
			return new WP_Error( 'ai', 'Could not translate the song. Try again.' );
		}
		$lines_out = array();
		foreach ( $clean as $i => $l ) {
			$lines_out[] = $res[ $i ] ?? array( 'ar' => '', 'slang' => array() );
		}
		$res = array( 'lang' => $lang, 'lines' => $lines_out );
		self::store( $key, $res );
		return $res;
	}

	/**
	 * Lyrics breakdown: one line of a song explained for a learner (🎵 Music in Language Lens).
	 * English (default) or Arabic. Shared cache: each line of a song is paid for once per language.
	 * Only the line and the lines around it are sent, never the whole song.
	 */
	public static function lyric( $uid, $line, $song = '', $context = '', $lang = 'en' ) {
		$lang    = 'ar' === $lang ? 'ar' : 'en';
		$clean   = function ( $s, $n ) {
			return mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $s ) ) ), 0, $n );
		};
		$line    = $clean( $line, 300 );
		$song    = $clean( $song, 120 );
		$context = $clean( $context, 600 );
		if ( '' === $line ) {
			return new WP_Error( 'empty', 'Nothing to explain.' );
		}
		$key = hash( 'sha256', 'lyric|v2|' . $lang . '|' . strtolower( $song . '|' . $line ) );
		$hit = self::cached( $key );
		if ( $hit ) {
			return $hit;
		}
		$limit = self::limit( $uid );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$in     = 'ar' === $lang
			? 'Write every explanation in clear, short Modern Standard Arabic (فصحى); keep quoted English words in English. '
			: 'Write every explanation in simple, clear English (B1 level, short sentences). ';
		$system = 'You explain English song lyrics to Arabic-speaking learners of English (A2–B2). ' . $in
			. 'Explain what the line means in the song, and the language in it: slang, idioms, metaphors, informal grammar (gonna, ain\'t, dropped words), and words used in an unusual way. '
			. 'Be faithful to the line; do not invent a story that is not in the lyrics. Rude, vulgar or sexual words and insults are explained politely and formally (no rude words in your explanation), and each such part also gets "severity" ("mild" = common, not very rude; "moderate"; "strong"; "slur" = hurtful to a group, never use it) and "context" (max 30 words: how its meaning and strength change with context and tone, e.g. joking with close friends vs angry at a stranger). '
			. 'Return ONLY a JSON object, no markdown, with keys: '
			. '"meaning": what the line means, said simply (max 30 words); '
			. '"parts": an array (max 4) of {"text": the exact words from the line, "why": a short explanation (max 25 words)} for the slang, idioms, metaphors or grammar worth noticing (empty array if none); '
			. '"plain": the line said again in plain, standard English (max 25 words, always in English).';
		$user   = ( $song ? "Song: {$song}\n" : '' ) . ( $context ? "Lines around it: {$context}\n" : '' ) . "Line to explain: {$line}";
		$out    = self::call( $system, $user, 1200 );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		$parts = array();
		foreach ( array_slice( (array) ( $out['parts'] ?? array() ), 0, 4 ) as $p ) {
			if ( is_array( $p ) && ! empty( $p['text'] ) ) {
				$sev     = (string) ( $p['severity'] ?? '' );
				$parts[] = array(
					'text'     => mb_substr( (string) $p['text'], 0, 80 ),
					'why'      => mb_substr( (string) ( $p['why'] ?? '' ), 0, 300 ),
					'severity' => in_array( $sev, array( 'mild', 'moderate', 'strong', 'slur' ), true ) ? $sev : '',
					'context'  => mb_substr( (string) ( $p['context'] ?? '' ), 0, 300 ),
				);
			}
		}
		$res = array(
			'lang'    => $lang,
			'meaning' => mb_substr( (string) ( $out['meaning'] ?? '' ), 0, 400 ),
			'parts'   => $parts,
			'plain'   => mb_substr( (string) ( $out['plain'] ?? '' ), 0, 300 ),
		);
		self::store( $key, $res );
		return $res;
	}

	/**
	 * A practice set for one skill-tree point. Sets are shared by every learner and cached
	 * (variant 0–5), so each one is paid for once.
	 */
	public static function practice_set( $uid, $id, $variant ) {
		$k = Sawa_Lens_Skills::get( $id );
		if ( ! $k ) {
			return new WP_Error( 'skill', 'Unknown skill.', array( 'status' => 404 ) );
		}
		$variant = (int) $variant % 6;
		$key     = hash( 'sha256', 'set|v1|' . $id . '|' . $variant );
		$hit     = self::cached( $key );
		if ( $hit ) {
			return $hit;
		}
		$limit = self::limit( $uid );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$topics = array( 'daily life and family', 'school, university and work', 'food, shopping and travel', 'friends, phone and social media', 'sport, health and free time', 'city life, weather and news' );
		$common = 'You write practice exercises for Arabic-speaking learners of English (A2–B1, mostly from Egypt and other Arab countries). '
			. 'Use simple A2–B1 vocabulary and everyday situations about ' . $topics[ $variant ] . '. Names and places can be Arab or international. '
			. "Point: {$k['name']}. Rule: {$k['rule']} Typical errors of Arab learners: {$k['errors']}. "
			. 'Each "why" is one short, simple English sentence (max 20 words) explaining the rule for that item; "why_ar" says the same in short Modern Standard Arabic. '
			. 'Return ONLY a JSON object {"items":[…]} with no markdown. ';
		if ( 'pron' === $k['track'] ) {
			$system = $common . 'Make exactly 10 items: '
				. '6 listening items {"type":"hear","say":"the word or short phrase the app will speak with text-to-speech","options":["2 or 3 choices: the target and minimal-pair or wrong-form alternatives, written as plain English words or simple respellings"],"answer":index of the correct option,"why":…,"why_ar":…}; '
				. '4 rule items {"type":"choose","q":"a question about the sound rule, e.g. How does -ed sound in \'walked\'? or Which syllable is stressed in \'hotel\'?","options":["2–4 short choices"],"answer":index,"why":…,"why_ar":…}. '
				. 'For hear items the options must be real, distinct and pronounced differently by a US voice (e.g. park / bark, think / sink, ship / sheep). Vary the answer position.';
		} else {
			$system = $common . 'Make exactly 10 items, in this order: '
				. '2 noticing items {"type":"spot","text":"2–3 short connected sentences that use the point correctly 2–4 times","targets":["the exact words in the text that show the point, copied exactly, each 1–4 words, in order of appearance"],"why":…,"why_ar":…}; '
				. '5 choice items {"type":"choose","q":"one sentence with ___ for the gap","options":["3 choices, one correct, the others typical Arab-learner errors"],"answer":index,"why":…,"why_ar":…}; '
				. '3 correction items {"type":"fix","q":"one sentence with exactly ONE typical Arab-learner error of this point","answer":["the corrected sentence","other fully acceptable corrections, if any"],"why":…,"why_ar":…}. '
				. 'Vary the answer position. Every item must test THIS point only, and every correct answer must be clearly right.';
		}
		$out = self::call( $system, 'Write the set now.', 8000, 90 );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		$items = array();
		foreach ( array_slice( (array) ( $out['items'] ?? array() ), 0, 12 ) as $it ) {
			if ( ! is_array( $it ) || ! in_array( $it['type'] ?? '', array( 'spot', 'choose', 'fix', 'hear' ), true ) ) {
				continue;
			}
			$clean = array(
				'type'   => $it['type'],
				'why'    => mb_substr( (string) ( $it['why'] ?? '' ), 0, 300 ),
				'why_ar' => mb_substr( (string) ( $it['why_ar'] ?? '' ), 0, 400 ),
			);
			if ( 'spot' === $it['type'] ) {
				$clean['text']    = mb_substr( (string) ( $it['text'] ?? '' ), 0, 600 );
				$clean['targets'] = array_values( array_filter( array_map( 'strval', array_slice( (array) ( $it['targets'] ?? array() ), 0, 8 ) ) ) );
				if ( ! $clean['targets'] ) {
					continue;
				}
			} elseif ( 'fix' === $it['type'] ) {
				$clean['q']      = mb_substr( (string) ( $it['q'] ?? '' ), 0, 300 );
				$clean['answer'] = array_values( array_filter( array_map( 'strval', array_slice( (array) ( $it['answer'] ?? array() ), 0, 5 ) ) ) );
				if ( ! $clean['answer'] ) {
					continue;
				}
			} else {
				$clean['q']       = mb_substr( (string) ( $it['q'] ?? '' ), 0, 300 );
				$clean['say']     = mb_substr( (string) ( $it['say'] ?? '' ), 0, 80 );
				$clean['options'] = array_values( array_map( 'strval', array_slice( (array) ( $it['options'] ?? array() ), 0, 4 ) ) );
				$clean['answer']  = (int) ( $it['answer'] ?? 0 );
				if ( count( $clean['options'] ) < 2 || $clean['answer'] < 0 || $clean['answer'] >= count( $clean['options'] ) ) {
					continue;
				}
			}
			$items[] = $clean;
		}
		if ( count( $items ) < 5 ) {
			return new WP_Error( 'ai', 'The exercises came back incomplete. Try again.', array( 'status' => 502 ) );
		}
		$res = array( 'items' => $items );
		self::store( $key, $res );
		return $res;
	}

	/**
	 * Checks a learner's writing: every clear error with its skill-tree point, plus correct uses of points,
	 * so the tree can open, reopen or close points from real writing.
	 */
	public static function check_writing( $uid, $text, $mode = 'write' ) {
		$limit = self::limit( $uid );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$ids = array();
		foreach ( Sawa_Lens_Skills::all() as $id => $k ) {
			if ( $k['write'] ) {
				$ids[] = $id . ' = ' . $k['name'] . ' (' . $k['errors'] . ')';
			}
		}
		$system = 'You are a kind, careful English writing tutor for Arabic-speaking learners (A2–B1). Check the learner\'s text. '
			. 'Find only CLEAR errors: grammar, spelling, capitals, punctuation, apostrophes, word choice that is wrong (not just less elegant), and tone that would sound rude or strange to an English speaker '
			. '(bare orders used as requests, whole sentences in capitals, slang in a formal message). Do not rewrite style. Do not flag Arabic names or words in quotes. Max 20 errors. '
			. "Skill ids (use one per error, or \"other\"):\n" . implode( "\n", $ids ) . "\n"
			. 'Return ONLY a JSON object, no markdown: '
			. '{"errors":[{"wrong":"the shortest exact span copied from the learner\'s text that contains the error","right":"what should replace that span","skill":"skill id or other","why":"one short simple-English sentence (max 18 words)","why_ar":"the same in short Modern Standard Arabic","sentence":"the learner\'s whole original sentence","fixed":"that sentence fully corrected"}], '
			. '"good":{"skill id": number of times the learner used this point CORRECTLY in an obligatory context}, '
			. '"corrected":"the whole text corrected with minimal changes", '
			. '"praise":"one short specific sentence about something they did well", '
			. '"tip":"the one most useful thing to work on next, one short sentence"}. '
			. 'List errors in the order they appear. If the text has no errors, return an empty errors array.';
		if ( 'voice' === $mode ) {
			$system .= ' The text is an automatic TRANSCRIPT of the learner speaking in a voice message: ignore punctuation, capital letters and spelling (the transcriber wrote those), ignore fillers (um, uh) and self-corrections, and only flag grammar, word choice and tone. Use "corrected" for the corrected spoken text.';
		} elseif ( 'chat' === $mode ) {
			$system .= ' The text is a casual chat message: casual style, emojis and informal words are fine in chat; only flag clear errors.';
		}
		$out = self::call( $system, "Learner's text:\n" . $text, 9000, 90 );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		$known  = Sawa_Lens_Skills::all();
		$errors = array();
		foreach ( array_slice( (array) ( $out['errors'] ?? array() ), 0, 25 ) as $e ) {
			if ( ! is_array( $e ) || '' === trim( (string) ( $e['wrong'] ?? '' ) ) ) {
				continue;
			}
			$skill    = sanitize_key( (string) ( $e['skill'] ?? 'other' ) );
			$errors[] = array(
				'wrong'    => mb_substr( (string) $e['wrong'], 0, 200 ),
				'right'    => mb_substr( (string) ( $e['right'] ?? '' ), 0, 200 ),
				'skill'    => isset( $known[ $skill ] ) ? $skill : 'other',
				'why'      => mb_substr( (string) ( $e['why'] ?? '' ), 0, 300 ),
				'why_ar'   => mb_substr( (string) ( $e['why_ar'] ?? '' ), 0, 400 ),
				'sentence' => mb_substr( (string) ( $e['sentence'] ?? '' ), 0, 600 ),
				'fixed'    => mb_substr( (string) ( $e['fixed'] ?? '' ), 0, 600 ),
			);
		}
		$good = array();
		foreach ( (array) ( $out['good'] ?? array() ) as $id => $n ) {
			$id = sanitize_key( (string) $id );
			if ( isset( $known[ $id ] ) && (int) $n > 0 ) {
				$good[ $id ] = min( 20, (int) $n );
			}
		}
		return array(
			'errors'    => $errors,
			'good'      => $good,
			'corrected' => mb_substr( (string) ( $out['corrected'] ?? '' ), 0, 6000 ),
			'praise'    => mb_substr( (string) ( $out['praise'] ?? '' ), 0, 300 ),
			'tip'       => mb_substr( (string) ( $out['tip'] ?? '' ), 0, 300 ),
		);
	}

	/**
	 * A chat message written in Arabic (Egyptian, MSA, Gulf, Levantine, Maghrebi…) → natural English in the same tone,
	 * plus a few useful phrases from it, so translating also teaches.
	 */
	public static function chat_translate( $uid, $text ) {
		$text = mb_substr( trim( (string) $text ), 0, 1000 );
		$key  = hash( 'sha256', 'chattr|v1|' . $text );
		$hit  = self::cached( $key );
		if ( $hit ) {
			return $hit;
		}
		$limit = self::limit( $uid );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$system = 'You translate chat messages written by Arabic-speaking English learners (any dialect: Egyptian, MSA, Gulf, Levantine, Iraqi, Maghrebi, or Arabizi/Franco like "ana 3ayez") into natural English for a friendly study-group chat. '
			. 'Keep the meaning, tone and register (casual stays casual, polite stays polite), keep names, emojis and any English words already in the message. Use simple, natural English (A2–B1) that a native speaker would really write in a chat; do not add anything. '
			. 'Return ONLY a JSON object, no markdown: {"english": "the translation", "phrases": [up to 3 objects {"en": "a useful word or phrase from your translation", "ar": "its meaning in short Arabic"}]}. '
			. 'Pick phrases that are worth learning (phrasal verbs, chunks, polite forms), not single easy words.';
		$out = self::call( $system, "Message:
" . $text, 2000 );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		$phrases = array();
		foreach ( array_slice( (array) ( $out['phrases'] ?? array() ), 0, 3 ) as $p ) {
			if ( is_array( $p ) && ! empty( $p['en'] ) ) {
				$phrases[] = array( 'en' => mb_substr( (string) $p['en'], 0, 80 ), 'ar' => mb_substr( (string) ( $p['ar'] ?? '' ), 0, 120 ) );
			}
		}
		$res = array(
			'english' => mb_substr( trim( (string) ( $out['english'] ?? '' ) ), 0, 1000 ),
			'phrases' => $phrases,
		);
		if ( '' === $res['english'] ) {
			return new WP_Error( 'ai', 'الترجمة مرجعتش. جرب تاني.' );
		}
		self::store( $key, $res );
		return $res;
	}

	/**
	 * Quiz questions for a video lesson. $lines: [[seconds, text], …]; $words: key words to teach (may be empty:
	 * then the AI picks useful B1–B2 words). Each question sits right after the line where its word is said.
	 */
	public static function lesson_questions( $uid, $lines, $words, $count = 8 ) {
		if ( ! $lines ) {
			return new WP_Error( 'transcript', 'This lesson has no transcript yet.' );
		}
		$limit = self::limit( $uid );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$text = '';
		foreach ( $lines as $i => $l ) {
			$text .= '[' . $i . '] ' . gmdate( $l[0] >= 3600 ? 'G:i:s' : 'i:s', (int) $l[0] ) . ' ' . $l[1] . "\n";
			if ( strlen( $text ) > 400000 ) {
				break;
			}
		}
		$count  = max( 3, min( 20, (int) $count ) );
		$system = 'You make EdPuzzle-style comprehension and vocabulary questions for Arabic-speaking learners of English (A2–B1) watching a video. '
			. 'The video pauses right after a line, then shows your question. '
			. ( $words ? 'Key words the teacher wants taught: ' . implode( ', ', $words ) . '. Make one question for each key word that appears in the transcript (use the line where it is said), then fill up with comprehension questions. ' : 'Choose the most useful B1–B2 words and phrases in the transcript to teach, and mix in comprehension questions. ' )
			. "Write exactly {$count} questions spread across the video, in time order. Types: "
			. '"mc" (multiple choice: 3–4 short options, one correct, wrong options plausible), '
			. '"blank" (fill in the blank: quote the sentence from the line with the key word replaced by ____; answer is that exact word as said), '
			. '"tf" (true/false statement about what was just said; answer 0 = True, 1 = False). '
			. 'Use simple English. "why" explains the answer in one short simple-English sentence; "why_ar" says it in short Modern Standard Arabic. '
			. 'Return ONLY a JSON object: {"questions":[{"line": the [number] of the line just before the pause, "type":"mc|blank|tf", "q":"question", "options":["…"] (empty for blank), "answer": index for mc/tf or the word for blank, "word":"the key word it teaches, base form, or empty", "why":"…", "why_ar":"…"}]}.';
		$out = self::call( $system, "Transcript:\n" . $text, 12000, 120 );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		$qs = array();
		foreach ( (array) ( $out['questions'] ?? array() ) as $q ) {
			if ( ! is_array( $q ) ) {
				continue;
			}
			$i = max( 0, min( count( $lines ) - 1, (int) ( $q['line'] ?? 0 ) ) );
			// Pause when the next line starts (the sentence has just been said).
			$q['t'] = isset( $lines[ $i + 1 ] ) ? $lines[ $i + 1 ][0] : $lines[ $i ][0] + 4;
			$qs[]   = $q;
		}
		$qs = Sawa_Lens_Lessons::clean_questions( $qs );
		if ( ! $qs ) {
			return new WP_Error( 'ai', 'The questions came back empty. Try again.' );
		}
		return $qs;
	}

	/** Placement test writing: a CEFR level for a short text, with a one-line reason. */
	/**
	 * Beta feedback, summed up for Sherif: notes in Arabic or English (with the screen each came from) become a short
	 * report in English: themes with counts and an example, the screens with the most trouble, and what to do first.
	 */
	public static function feedback_summary( $notes ) {
		$lines = array();
		foreach ( array_slice( (array) $notes, 0, 300 ) as $n ) {
			$lines[] = '[' . $n['kind'] . ' · ' . $n['area'] . ( $n['target'] ? ' · tapped: ' . $n['target'] : '' ) . '] ' . mb_substr( (string) $n['body'], 0, 300 );
		}
		if ( ! $lines ) {
			return new WP_Error( 'empty', 'No feedback to sum up yet.' );
		}
		$out = self::call(
			'You help the founder of an English-learning app for Arabic-speaking A2–B1 learners read beta-test feedback. Notes are in Arabic (often Egyptian), English or Franco-Arabic; each starts with its reaction type and the screen it came from. '
			. 'Translate as needed and group the notes into themes (problems and praise). Be concrete and short; never invent things the notes do not say. '
			. 'Return ONLY JSON: {"summary":"2-3 sentences","themes":[{"title":"short","kind":"problem|praise|idea","count":0,"screens":["..."],"example":"one note, translated to English","fix":"one concrete suggestion (problems only)"}],"first":"the one thing to fix first, and why"}. At most 8 themes, most common first.',
			implode( "
", $lines ),
			3000,
			90
		);
		return is_wp_error( $out ) ? $out : array( 'summary' => (string) ( $out['summary'] ?? '' ), 'themes' => array_slice( (array) ( $out['themes'] ?? array() ), 0, 8 ), 'first' => (string) ( $out['first'] ?? '' ) );
	}

	/**
	 * Feedback & Dev (Sawa Tracker): can a beta note be acted on, or should we ask the student for more first?
	 * Cheap model, cached per note. Returns array( 'clear' => bool, 'missing' => [where|did|happened|expected|what|why] ), or null.
	 */
	public static function feedback_clarity( $kind, $text, $has_where ) {
		$key = hash( 'sha256', 'fbclear|v1|' . $kind . '|' . ( $has_where ? 1 : 0 ) . '|' . $text );
		$hit = self::cached( $key );
		if ( $hit ) {
			return $hit;
		}
		if ( ! self::uid_limit_ok() ) {
			return null; // the simple check takes over
		}
		$idea = in_array( $kind, array( 'idea', 'request' ), true );
		$out  = self::call(
			'You check beta feedback from Arabic-speaking A2–B1 learners about an English-learning app (Arabic, Egyptian, English or Franco-Arabic). Decide if the team can act on the note as written. '
			. ( $idea
				? 'It is an idea or request. It is clear when it says what the student wants (a concrete feature, change or content), even briefly. Missing: "what" (what exactly they want), "why" (when they would use it).'
				: 'It is a bug report. It is clear when a developer could find the problem: what the student did and what went wrong (or what they expected instead). ' . ( $has_where ? 'The screen is already known, so never ask where. ' : 'Missing "where" if the screen or feature is not named. ' ) . 'Missing: "where", "did" (what they did), "happened", "expected". Vague notes like "it doesn\'t work", "bad", "fix the game" are not clear.' )
			. ' Be generous: if a short note is specific, it is clear. Return ONLY JSON: {"clear":true|false,"missing":["..."]}.',
			mb_substr( (string) $text, 0, 1200 ),
			120,
			15,
			'claude-haiku-5-5'
		);
		if ( is_wp_error( $out ) || ! isset( $out['clear'] ) ) {
			return null;
		}
		$res = array( 'clear' => (bool) $out['clear'], 'missing' => array_values( array_map( 'strval', (array) ( $out['missing'] ?? array() ) ) ) );
		self::store( $key, $res );
		return $res;
	}

	/** A site-wide daily cap for small background checks (feedback clarity). */
	private static function uid_limit_ok() {
		$k = 'sawa_lens_fbclear_' . wp_date( 'Ymd' );
		$n = (int) get_transient( $k );
		if ( $n >= 300 ) {
			return false;
		}
		set_transient( $k, $n + 1, DAY_IN_SECONDS );
		return true;
	}

	public static function grade_writing( $uid, $text, $prompt ) {
		$limit = self::limit( $uid );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$out = self::call(
			'You are a certified CEFR examiner. Rate this short placement-test text by an English learner on the CEFR scale (A1, A2, B1, B2, C1, C2), '
			. 'using range and accuracy of grammar and vocabulary, coherence, and task completion, as in the CEFR written production descriptors. Be strict and consistent; a few errors are normal at B1. '
			. 'Return ONLY a JSON object: {"level":"A1|A2|B1|B2|C1|C2","good":"one short sentence on what was done well","next":"one short sentence on the most important thing to improve"}.',
			"Task: {$prompt}\n\nText:\n" . mb_substr( $text, 0, 3000 ),
			3000
		);
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		$lv = strtoupper( (string) ( $out['level'] ?? '' ) );
		if ( ! in_array( $lv, array( 'A1', 'A2', 'B1', 'B2', 'C1', 'C2' ), true ) ) {
			return new WP_Error( 'ai', 'No level.' );
		}
		return array( 'level' => $lv, 'good' => mb_substr( (string) ( $out['good'] ?? '' ), 0, 200 ), 'next' => mb_substr( (string) ( $out['next'] ?? '' ), 0, 200 ) );
	}

	/**
	 * New placement-test items for a CEFR level (1–6): grammar questions ("mc"), C-test texts ("ctest")
	 * or dictation sentences ("dictation"). They're saved as active items; review them in the admin page.
	 */
	public static function placement_items( $uid, $type, $level, $n ) {
		$lv  = Sawa_Lens_Placement::LEVELS[ $level ];
		$ask = array(
			'mc'        => "Write {$n} grammar and usage multiple-choice questions at exactly CEFR {$lv} (use the English Grammar Profile for what is typical at {$lv}). Each: one sentence with ___ for the gap, 4 options, exactly one correct; wrong options are typical learner errors. Cover different points. "
				. 'JSON: {"items":[{"q":"…","options":["…","…","…","…"],"answer":index,"point":"grammar point"}]}',
			'ctest'     => "Write {$n} short texts (70–90 words each) at exactly CEFR {$lv} reading level, on different everyday or general topics, for a C-test. Natural, well-formed English with simple punctuation. "
				. 'JSON: {"items":[{"title":"…","text":"…"}]}',
			'dictation' => "Write {$n} sentences for a listen-and-type test at exactly CEFR {$lv}: " . ( $level <= 2 ? '5–8 words, common words' : ( $level <= 4 ? '8–13 words' : '12–18 words, natural connected speech' ) ) . '. No names, no numbers, no abbreviations. '
				. 'JSON: {"items":[{"text":"…"}]}',
		);
		if ( ! isset( $ask[ $type ] ) ) {
			return new WP_Error( 'type', 'Unknown item type.' );
		}
		$out = self::call( 'You write items for an English placement test (like EF SET or the Duolingo English Test). Items must be accurate, unambiguous and exactly at the requested CEFR level. Return ONLY the JSON object, no markdown.', $ask[ $type ], 12000, 120 );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		$k = 0;
		foreach ( (array) ( $out['items'] ?? array() ) as $it ) {
			if ( ! is_array( $it ) ) {
				continue;
			}
			if ( 'mc' === $type && ( empty( $it['q'] ) || count( (array) ( $it['options'] ?? array() ) ) !== 4 || ! isset( $it['answer'] ) ) ) {
				continue;
			}
			if ( 'mc' !== $type && empty( $it['text'] ) ) {
				continue;
			}
			Sawa_Lens_Placement::add_item( $type, $level, 'mc' === $type ? array( 'q' => (string) $it['q'], 'options' => array_map( 'strval', $it['options'] ), 'answer' => (int) $it['answer'], 'point' => (string) ( $it['point'] ?? '' ) ) : array( 'title' => (string) ( $it['title'] ?? '' ), 'text' => (string) $it['text'] ) );
			$k++;
		}
		return $k;
	}

	/** Read-aloud sentences for a pronunciation point: 8 short sentences full of that sound. Shared and cached. */
	public static function readaloud( $uid, $id, $variant = 0 ) {
		$k = Sawa_Lens_Skills::get( $id );
		if ( ! $k ) {
			return new WP_Error( 'skill', 'Unknown skill.', array( 'status' => 404 ) );
		}
		$key = hash( 'sha256', 'readaloud|v1|' . $id . '|' . ( (int) $variant % 3 ) );
		$hit = self::cached( $key );
		if ( $hit ) {
			return $hit;
		}
		$limit = self::limit( $uid );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$out = self::call(
			'You write read-aloud practice for Arabic-speaking learners of English (A2–B1). '
			. "Pronunciation point: {$k['name']}. Rule: {$k['rule']} Typical errors: {$k['errors']}. "
			. 'Write 8 short, natural everyday sentences (5–10 words, simple vocabulary) that each contain the target sound or feature 2–3 times. '
			. 'Return ONLY a JSON object: {"items":[{"text":"the sentence","targets":["the words in it that contain the target sound, copied exactly"]}]}.',
			'Write them now. Variation ' . ( (int) $variant % 3 + 1 ) . '.',
			3000
		);
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		$items = array();
		foreach ( array_slice( (array) ( $out['items'] ?? array() ), 0, 10 ) as $it ) {
			if ( is_array( $it ) && ! empty( $it['text'] ) ) {
				$items[] = array(
					'text'    => mb_substr( (string) $it['text'], 0, 200 ),
					'targets' => array_values( array_map( 'strval', array_slice( (array) ( $it['targets'] ?? array() ), 0, 6 ) ) ),
				);
			}
		}
		if ( count( $items ) < 3 ) {
			return new WP_Error( 'ai', 'The sentences came back incomplete. Try again.', array( 'status' => 502 ) );
		}
		$res = array( 'items' => $items );
		self::store( $key, $res );
		return $res;
	}

	/**
	 * Photo of a book page or screenshot → its English text, in reading order (Claude vision).
	 * $data is base64 JPEG/PNG/WebP, already resized by the browser.
	 */
	public static function scan( $uid, $data, $mime ) {
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) || strlen( $data ) > 7 * MB_IN_BYTES ) {
			return new WP_Error( 'image', 'Use a JPG or PNG photo under 5 MB.', array( 'status' => 400 ) );
		}
		$key = hash( 'sha256', 'scan|v1|' . md5( $data ) );
		$hit = self::cached( $key );
		if ( $hit ) {
			return $hit;
		}
		$limit = self::limit( $uid );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$content = array(
			array(
				'type'   => 'image',
				'source' => array(
					'type'       => 'base64',
					'media_type' => $mime,
					'data'       => $data,
				),
			),
			array(
				'type' => 'text',
				'text' => 'Copy the English text from this photo exactly, in reading order.',
			),
		);
		$out = self::call(
			'You read photos of book pages, worksheets, signs and screenshots for an English learner. Copy the English text exactly as printed (fix only obvious OCR-style line-break hyphenation), in natural reading order, keeping paragraphs. Skip page numbers, headers and footers. '
			. 'Return ONLY a JSON object: {"title": "a short title for the text (max 8 words)", "paragraphs": ["paragraph 1", "paragraph 2", …]}. If there is no readable English text, return {"title": "", "paragraphs": []}.',
			$content,
			6000,
			90
		);
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		$paras = array();
		foreach ( array_slice( (array) ( $out['paragraphs'] ?? array() ), 0, 60 ) as $p ) {
			$p = trim( (string) $p );
			if ( '' !== $p ) {
				$paras[] = mb_substr( $p, 0, 3000 );
			}
		}
		$res = array(
			'title'      => mb_substr( (string) ( $out['title'] ?? '' ), 0, 100 ),
			'paragraphs' => $paras,
		);
		self::store( $key, $res );
		return $res;
	}

	/**
	 * Voice message → text with OpenAI's transcription API (Claude doesn't take audio input).
	 * The prompt asks for a verbatim transcript, so learners' grammar mistakes stay in.
	 */
	/**
	 * Speech to text (OpenAI). $prompt guides the wording (default: a learner speaking English);
	 * $max_mb is the biggest file accepted (OpenAI's own limit is 25 MB). Other plugins (testimonials) use this too.
	 */
	public static function transcribe( $uid, $path, $prompt = '', $max_mb = 8, $per_user = true ) {
		$s = self::settings();
		if ( '' === $s['stt_key'] ) {
			return new WP_Error( 'off', 'تحويل الصوت لنص مش متفعّل لسه.' );
		}
		if ( ! is_readable( $path ) || filesize( $path ) > min( 25, max( 1, (int) $max_mb ) ) * MB_IN_BYTES ) {
			return new WP_Error( 'audio', 'الملف ده مش راضي يتقري.' );
		}
		$limit = $per_user ? self::limit( $uid ) : true; // shared work (radio captions) has its own monthly budget instead
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$ext      = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$types    = array( 'webm' => 'audio/webm', 'weba' => 'audio/webm', 'm4a' => 'audio/mp4', 'mp4' => 'audio/mp4', 'ogg' => 'audio/ogg', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'mpeg' => 'audio/mpeg', 'mpga' => 'audio/mpeg' );
		$boundary = wp_generate_password( 24, false );
		$fields   = array(
			'model'           => $s['stt_model'],
			'response_format' => 'json',
			'prompt'          => '' !== $prompt ? $prompt : 'An Arabic-speaking learner of English is speaking, sometimes with a few Arabic words. Write exactly what they say, word for word, keeping their grammar mistakes. Do not correct or improve anything.',
		);
		$body = '';
		$crlf = "\r\n";
		foreach ( $fields as $k => $v ) {
			$body .= '--' . $boundary . $crlf . 'Content-Disposition: form-data; name="' . $k . '"' . $crlf . $crlf . $v . $crlf;
		}
		$body .= '--' . $boundary . $crlf . 'Content-Disposition: form-data; name="file"; filename="voice.' . $ext . '"' . $crlf
			. 'Content-Type: ' . ( $types[ $ext ] ?? 'application/octet-stream' ) . $crlf . $crlf
			. file_get_contents( $path ) . $crlf . '--' . $boundary . '--' . $crlf; // phpcs:ignore
		$res = wp_remote_post(
			'https://api.openai.com/v1/audio/transcriptions',
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $s['stt_key'],
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'net', 'مش قادرين نوصل لخدمة تحويل الصوت. جرب كمان شوية.' );
		}
		$j = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $res ) || ! isset( $j['text'] ) ) {
			update_option( 'sawa_lens_last_ai_error', current_time( 'mysql' ) . ' · transcription: ' . mb_substr( (string) ( $j['error']['message'] ?? wp_remote_retrieve_response_code( $res ) ), 0, 300 ), false );
			return new WP_Error( 'stt', 'تحويل الصوت لنص مشتغلش المرة دي. جرب تاني.' );
		}
		Sawa_Lens_Cost::log( 'transcribe', 'openai', $s['stt_model'], 0, 0, null, 0, $per_user ? (int) $uid : 0 );
		$usage = (array) get_option( 'sawa_lens_stt_usage', array() );
		$month = wp_date( 'Y-m' );
		$usage[ $month ] = (int) ( $usage[ $month ] ?? 0 ) + 1;
		update_option( 'sawa_lens_stt_usage', array_slice( $usage, -12, null, true ), false );
		return mb_substr( trim( (string) $j['text'] ), 0, 4000 );
	}

	/**
	 * Messages API body. Thinking stays on (it keeps translations and pronunciation notes accurate) but at
	 * low effort, so it's brief; max_tokens leaves room for the thinking plus the short JSON answer.
	 * Low effort is accepted by Claude Haiku 5.5, Sonnet 5.5 and Opus 5.5 alike.
	 */
	private static function request_body( $model, $system, $user, $max_tokens ) {
		return array(
			'model'         => $model,
			'max_tokens'    => max( 4000, (int) $max_tokens ),
			'output_config' => array( 'effort' => 'low' ),
			'system'        => $system,
			'messages'      => array(
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
		);
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

	/** A practice set only if it was already made (never calls the AI). */
	public static function cached_set( $id, $variant ) {
		return self::cached( hash( 'sha256', 'set|v1|' . $id . '|' . ( (int) $variant % 6 ) ) );
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
		Sawa_Lens_Cost::set_user( $uid ); // the cost meter charges the next AI call to this student
		$k = 'sawa_lens_ai_' . $uid . '_' . wp_date( 'Ymd' );
		$n = (int) get_transient( $k );
		$max = (int) self::settings()['daily_limit'];
		if ( class_exists( 'Sawa_Lens_Tiers' ) && 'free' === Sawa_Lens_Tiers::tier( $uid ) ) {
			$max = min( $max, Sawa_Lens_Tiers::free_ai() ); // free accounts get fewer AI answers a day
		}
		if ( $n >= $max ) {
			return new WP_Error( 'limit', 'You reached today\'s limit for AI explanations. The dictionary still works, and explanations come back tomorrow.', array( 'status' => 429 ) );
		}
		set_transient( $k, $n + 1, DAY_IN_SECONDS );
		return true;
	}

	private static function call( $system, $user, $max_tokens, $timeout = 45, $model = '' ) {
		$s = self::settings();
		if ( '' === $s['api_key'] ) {
			return new WP_Error( 'off', 'AI explanations are not switched on yet.', array( 'status' => 503 ) );
		}
		$feature = Sawa_Lens_Cost::caller();
		if ( Sawa_Lens_Cost::blocked( $feature ) ) { // the optional site-wide monthly budget is used up
			return new WP_Error( 'limit', 'You reached today\'s limit for AI explanations. The dictionary still works, and explanations come back tomorrow.', array( 'status' => 429 ) );
		}
		$t0  = microtime( true );
		$res = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'timeout' => $timeout,
				'headers' => array(
					'x-api-key'         => $s['api_key'],
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode( self::request_body( '' !== $model ? $model : $s['model'], $system, $user, $max_tokens ) ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'net', 'Could not reach the explanation service. Try again in a moment.', array( 'status' => 502 ) );
		}
		$code = wp_remote_retrieve_response_code( $res );
		$j    = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( isset( $j['usage'] ) ) { // cost meter: fails silently, never slows the answer
			Sawa_Lens_Cost::log( $feature, 'anthropic', '' !== $model ? $model : $s['model'], (int) ( $j['usage']['input_tokens'] ?? 0 ), (int) ( $j['usage']['output_tokens'] ?? 0 ), null, (int) round( ( microtime( true ) - $t0 ) * 1000 ) );
		}
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
