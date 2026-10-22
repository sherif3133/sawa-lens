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
				'stt_key'     => '',
				'stt_model'   => 'gpt-4o-mini-transcribe',
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
	public static function transcribe( $uid, $path, $prompt = '', $max_mb = 8 ) {
		$s = self::settings();
		if ( '' === $s['stt_key'] ) {
			return new WP_Error( 'off', 'تحويل الصوت لنص مش متفعّل لسه.' );
		}
		if ( ! is_readable( $path ) || filesize( $path ) > min( 25, max( 1, (int) $max_mb ) ) * MB_IN_BYTES ) {
			return new WP_Error( 'audio', 'الملف ده مش راضي يتقري.' );
		}
		$limit = self::limit( $uid );
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
		$k = 'sawa_lens_ai_' . $uid . '_' . wp_date( 'Ymd' );
		$n = (int) get_transient( $k );
		if ( $n >= (int) self::settings()['daily_limit'] ) {
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
