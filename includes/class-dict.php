<?php
/**
 * Words Language Lens's own dictionary doesn't have ("epiphanies"): looked up once for every student, then cached.
 *   1. the shared cache (Sawa_Lens_AI's cache table, one row per word)
 *   2. free dictionaries, both Wiktionary content under CC BY-SA (shown with its source and link, as the licence asks):
 *      the Free Dictionary API (dictionaryapi.dev), then Wiktionary's own API (en.wiktionary.org/api/rest_v1) when that one is down
 *   3. the AI, as the last step
 * The Arabic meanings come from the AI (one small call per word, cached with the entry).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sawa_Lens_Dict {

	/** Forms to try, the word itself first: epiphanies → epiphany, tried → try, making → make, stopped → stop. */
	public static function candidates( $w ) {
		$w   = strtolower( preg_replace( "/[^A-Za-z'-]/", '', str_replace( '’', "'", (string) $w ) ) );
		$w   = preg_replace( "/'s$/", '', $w );
		$out = array( $w );
		$rules = array(
			array( '/ies$/', 'y' ), array( '/ied$/', 'y' ), array( '/ier$/', 'y' ), array( '/iest$/', 'y' ),
			array( '/([^aeiou])\1(ed|ing|er|est)$/', '$1' ), array( '/(ed|ing)$/', 'e' ), array( '/(ed|ing|er|est)$/', '' ),
			array( '/ves$/', 'f' ), array( '/ves$/', 'fe' ), array( '/s$/', '' ), array( '/ly$/', '' ),
		);
		foreach ( $rules as $r ) {
			if ( preg_match( $r[0], $w ) ) {
				$c = preg_replace( $r[0], $r[1], $w );
				if ( strlen( $c ) >= 2 && ! in_array( $c, $out, true ) ) {
					$out[] = $c;
				}
			}
		}
		foreach ( array( '/(ch|sh|ss|x|z|o)es$/' ) as $re ) { // boxes → box, watches → watch
			if ( preg_match( $re, $w ) ) {
				$out[] = substr( $w, 0, -2 );
			}
		}
		return array_values( array_unique( array_filter( $out, function ( $c ) { return strlen( $c ) >= 2 && strlen( $c ) <= 40; } ) ) );
	}

	/** English senses from the free dictionary, or null when it doesn't know the word. */
	public static function free_dictionary( $word ) {
		$res = wp_remote_get( 'https://api.dictionaryapi.dev/api/v2/entries/en/' . rawurlencode( $word ), array( 'timeout' => 4, 'headers' => array( 'Accept' => 'application/json' ) ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		$j = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $j ) || empty( $j[0]['meanings'] ) ) {
			return null;
		}
		$senses = array();
		$seen   = array();
		foreach ( $j as $entry ) {
			foreach ( (array) ( $entry['meanings'] ?? array() ) as $m ) {
				$pos = sanitize_key( (string) ( $m['partOfSpeech'] ?? '' ) );
				$d   = (array) ( $m['definitions'][0] ?? array() );
				if ( empty( $d['definition'] ) || isset( $seen[ $pos ] ) || count( $senses ) >= 3 ) {
					continue;
				}
				$seen[ $pos ] = true;
				$senses[]     = array( 'pos' => $pos, 'en' => mb_substr( wp_strip_all_tags( (string) $d['definition'] ), 0, 200 ), 'ex' => mb_substr( wp_strip_all_tags( (string) ( $d['example'] ?? '' ) ), 0, 160 ) );
			}
		}
		if ( ! $senses ) {
			return null;
		}
		return array( 'lemma' => strtolower( (string) ( $j[0]['word'] ?? $word ) ), 'senses' => $senses, 'url' => esc_url_raw( (string) ( $j[0]['sourceUrls'][0] ?? 'https://en.wiktionary.org/wiki/' . rawurlencode( $word ) ) ) );
	}

	/** English senses from Wiktionary's own API. "plural of epiphany"-style entries are skipped, so the base form is tried next. */
	public static function wiktionary( $word ) {
		$res = wp_remote_get( 'https://en.wiktionary.org/api/rest_v1/page/definition/' . rawurlencode( $word ), array( 'timeout' => 6, 'headers' => array( 'Accept' => 'application/json', 'User-Agent' => 'SawaEnglish/1.0 (https://sawaenglish.com; Language Lens)' ) ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		$j      = json_decode( wp_remote_retrieve_body( $res ), true );
		$senses = array();
		$seen   = array();
		$clean  = function ( $h ) { return trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( (string) $h ), ENT_QUOTES ) ) ); };
		foreach ( (array) ( $j['en'] ?? array() ) as $m ) {
			$pos = sanitize_key( strtolower( (string) ( $m['partOfSpeech'] ?? '' ) ) );
			foreach ( (array) ( $m['definitions'] ?? array() ) as $d ) {
				$def = $clean( $d['definition'] ?? '' );
				if ( '' === $def || isset( $seen[ $pos ] ) || count( $senses ) >= 3 ) {
					continue;
				}
				if ( preg_match( '/^(plural|(simple )?past|present|third-person|alternative|comparative|superlative|obsolete|archaic)\b.*\bof\b/i', $def ) ) {
					continue; // a form of another word: let the base form answer
				}
				$seen[ $pos ] = true;
				$senses[]     = array( 'pos' => $pos, 'en' => mb_substr( $def, 0, 200 ), 'ex' => mb_substr( $clean( $d['examples'][0] ?? '' ), 0, 160 ) );
			}
		}
		return $senses ? array( 'lemma' => strtolower( $word ), 'senses' => $senses, 'url' => 'https://en.wiktionary.org/wiki/' . rawurlencode( $word ) ) : null;
	}

	/**
	 * The entry for a word: {word, lemma, entries: [{pos, en, ar, ex}], src: 'wiktionary'|'ai'|'none', url, cached}.
	 * Every result is cached for everyone, including "not a word", so each word costs at most once.
	 */
	public static function define( $uid, $word ) {
		$cands = self::candidates( $word );
		if ( ! $cands ) {
			return new WP_Error( 'empty', 'No word.' );
		}
		foreach ( $cands as $c ) {
			$hit = Sawa_Lens_AI::dict_cached( $c );
			if ( $hit && ( $hit['entries'] || $c === $cands[0] ) ) {
				return array( 'word' => $cands[0] ) + $hit;
			}
		}
		foreach ( array_slice( $cands, 0, 4 ) as $c ) { // at most 4 calls to the free dictionary
			$free = self::free_dictionary( $c );
			if ( ! $free ) {
				$free = self::wiktionary( $c );
			}
			if ( ! $free ) {
				continue;
			}
			$entries = $free['senses'];
			$ai      = Sawa_Lens_AI::ready() ? Sawa_Lens_AI::dict_word( $uid, $free['lemma'], $entries ) : null;
			if ( is_array( $ai ) ) {
				$entries = $ai['entries'];
			}
			$res = array( 'lemma' => $free['lemma'], 'entries' => $entries, 'src' => 'wiktionary', 'url' => $free['url'] );
			if ( is_array( $ai ) ) {
				// Cached only with its Arabic: without it (AI limit, no key) the next student tries again.
				Sawa_Lens_AI::dict_store( $free['lemma'], $res );
				if ( $cands[0] !== $free['lemma'] ) {
					Sawa_Lens_AI::dict_store( $cands[0], $res );
				}
			}
			return array( 'word' => $cands[0] ) + $res;
		}
		if ( ! Sawa_Lens_AI::ready() ) {
			return new WP_Error( 'none', 'This word isn\'t in the dictionary.', array( 'status' => 404 ) );
		}
		$ai = Sawa_Lens_AI::dict_word( $uid, $cands[0] );
		if ( is_wp_error( $ai ) ) {
			return $ai;
		}
		$res = array( 'lemma' => $ai['found'] ? $ai['lemma'] : $cands[0], 'entries' => $ai['found'] ? $ai['entries'] : array(), 'src' => $ai['found'] ? 'ai' : 'none', 'url' => '' );
		Sawa_Lens_AI::dict_store( $cands[0], $res );
		return array( 'word' => $cands[0] ) + $res;
	}

	/* ----------------------------------------------------------------- admin: test a word */

	public static function admin_init() {
		add_action( 'admin_menu', function () {
			add_submenu_page( 'sawa-lens-quizzes', 'Test a word', 'Test a word', 'manage_options', 'sawa-lens-test-word', array( __CLASS__, 'test_page' ) );
		}, 42 );
	}

	/** Looks a word up exactly as Language Lens's word card does (/define), and says where the answer came from. */
	public static function test_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$word = isset( $_GET['word'] ) ? sanitize_text_field( wp_unslash( $_GET['word'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap">
			<h1>Test a word</h1>
			<p>Looks a word up the way the Language Lens word card does: shared cache → free dictionary (Wiktionary) → AI. A word that isn't cached yet costs one small AI call (for the Arabic), charged to you in AI cost.</p>
			<form method="get"><input type="hidden" name="page" value="sawa-lens-test-word"><?php wp_nonce_field( 'sawa_lens_test_word', '_wpnonce', false ); ?>
				<input name="word" value="<?php echo esc_attr( $word ); ?>" placeholder="epiphanies" class="regular-text"> <button class="button button-primary">Look up</button></form>
			<?php
			if ( '' !== $word && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'sawa_lens_test_word' ) ) {
				$cands = self::candidates( $word );
				$was   = $cands ? Sawa_Lens_AI::dict_cached( $cands[0] ) : null;
				$t     = microtime( true );
				$res   = self::define( get_current_user_id(), $word );
				$ms    = (int) round( ( microtime( true ) - $t ) * 1000 );
				if ( is_wp_error( $res ) ) {
					echo '<p><b>Error:</b> ' . esc_html( $res->get_error_message() ) . '</p>';
				} else {
					$ar = count( array_filter( $res['entries'], function ( $e ) { return ! empty( $e['ar'] ); } ) );
					echo '<p><b>' . esc_html( $res['word'] ) . '</b> → ' . esc_html( $res['lemma'] ) . ' · source: <b>' . esc_html( $res['src'] ) . '</b> · ' . ( $was ? '<b>from the cache</b>' : 'new lookup (now cached)' ) . ' · ' . (int) count( $res['entries'] ) . ' meanings, ' . (int) $ar . ' with Arabic · ' . (int) $ms . ' ms</p>';
					echo '<ol>';
					foreach ( $res['entries'] as $e ) {
						echo '<li><i>' . esc_html( $e['pos'] ?? '' ) . '</i> ' . esc_html( $e['en'] ?? '' ) . ' — <span dir="rtl">' . esc_html( $e['ar'] ?? '(no Arabic)' ) . '</span></li>';
					}
					echo '</ol>';
				}
			}
			?>
		</div>
		<?php
	}
}
