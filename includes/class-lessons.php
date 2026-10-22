<?php
/**
 * Video quizzes (EdPuzzle-style): questions that pause a YouTube video at the right moment.
 *
 * Sherif makes a lesson from a video's transcript (sent by the extension, or pasted from YouTube's
 * "Show transcript"), lists the key words, and the AI writes questions placed right after each word is said.
 * Students answer on YouTube (extension) or in the web app's Watch tab. Wrong answers put the word into
 * the student's Learning list, so it comes back as a review card.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sawa_Lens_Lessons {

	public static function install( $c ) {
		dbDelta(
			'CREATE TABLE ' . Sawa_Lens_Store::t( 'lessons' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			video_id varchar(11) NOT NULL,
			title varchar(200) NOT NULL DEFAULT '',
			kind varchar(8) NOT NULL DEFAULT 'video',
			words text,
			transcript longtext,
			questions longtext,
			published tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY video (video_id)
			) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . Sawa_Lens_Store::t( 'lesson_results' ) . " (
			user_id bigint(20) unsigned NOT NULL,
			lesson_id bigint(20) unsigned NOT NULL,
			answers longtext,
			correct smallint(5) unsigned NOT NULL DEFAULT 0,
			answered smallint(5) unsigned NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (user_id,lesson_id),
			KEY lesson (lesson_id)
			) $c;"
		);
	}

	private static function t( $n = 'lessons' ) {
		return Sawa_Lens_Store::t( $n );
	}

	public static function get( $id ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
		return $r ? self::row( $r ) : null;
	}

	public static function by_video( $video_id ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE video_id = %s AND published = 1 ORDER BY id DESC LIMIT 1', $video_id ), ARRAY_A ); // phpcs:ignore
		return $r ? self::row( $r ) : null;
	}

	private static function row( $r ) {
		$q = json_decode( (string) $r['questions'], true );
		$t = json_decode( (string) $r['transcript'], true );
		return array(
			'id'         => (int) $r['id'],
			'videoId'    => $r['video_id'],
			'title'      => $r['title'],
			'kind'       => 'song' === ( $r['kind'] ?? '' ) ? 'song' : 'video', // a song: a music video, its transcript is the lyrics
			'words'      => array_values( array_filter( array_map( 'trim', explode( ',', (string) $r['words'] ) ) ) ),
			'transcript' => is_array( $t ) ? $t : array(),
			'questions'  => is_array( $q ) ? $q : array(),
			'published'  => (bool) $r['published'],
			'updated'    => $r['updated_at'],
		);
	}

	public static function all( $published_only = false ) {
		global $wpdb;
		$rows = (array) $wpdb->get_results( 'SELECT id, video_id, title, kind, words, questions, published, updated_at FROM ' . self::t() . ( $published_only ? ' WHERE published = 1' : '' ) . ' ORDER BY id DESC', ARRAY_A ); // phpcs:ignore
		return array_map(
			function ( $r ) {
				$r['transcript'] = '';
				return self::row( $r );
			},
			$rows
		);
	}

	/** Creates or updates a lesson. $data keys: videoId, title, words (string), transcript (array), questions (array), published. */
	public static function save( $id, $data ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$row = array( 'updated_at' => $now );
		if ( isset( $data['videoId'] ) ) {
			$row['video_id'] = $data['videoId'];
		}
		if ( isset( $data['title'] ) ) {
			$row['title'] = mb_substr( sanitize_text_field( $data['title'] ), 0, 200 );
		}
		if ( isset( $data['kind'] ) ) {
			$row['kind'] = 'song' === $data['kind'] ? 'song' : 'video';
		}
		if ( isset( $data['words'] ) ) {
			$row['words'] = implode( ', ', array_slice( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( $data['words'] ) ) ) ), 0, 60 ) );
		}
		if ( isset( $data['transcript'] ) ) {
			$row['transcript'] = wp_json_encode( $data['transcript'] );
		}
		if ( isset( $data['questions'] ) ) {
			$row['questions'] = wp_json_encode( self::clean_questions( $data['questions'] ) );
		}
		if ( isset( $data['published'] ) ) {
			$row['published'] = $data['published'] ? 1 : 0;
		}
		if ( $id ) {
			$wpdb->update( self::t(), $row, array( 'id' => (int) $id ) ); // phpcs:ignore
			return (int) $id;
		}
		$row['created_at'] = $now;
		$wpdb->insert( self::t(), $row + array( 'title' => '', 'words' => '', 'transcript' => '[]', 'questions' => '[]' ) ); // phpcs:ignore
		return (int) $wpdb->insert_id;
	}

	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( self::t(), array( 'id' => (int) $id ) ); // phpcs:ignore
		$wpdb->delete( self::t( 'lesson_results' ), array( 'lesson_id' => (int) $id ) ); // phpcs:ignore
	}

	public static function clean_questions( $qs ) {
		$out = array();
		foreach ( array_slice( (array) $qs, 0, 40 ) as $q ) {
			if ( ! is_array( $q ) || '' === trim( (string) ( $q['q'] ?? '' ) ) ) {
				continue;
			}
			$type = in_array( $q['type'] ?? '', array( 'mc', 'blank', 'tf' ), true ) ? $q['type'] : 'mc';
			$opts = array_values( array_filter( array_map( 'strval', (array) ( $q['options'] ?? array() ) ), 'strlen' ) );
			if ( 'tf' === $type ) {
				$opts = array( 'True', 'False' );
			}
			$ans = 'blank' === $type ? trim( (string) ( $q['answer'] ?? '' ) ) : (int) ( $q['answer'] ?? 0 );
			if ( 'blank' !== $type && ( $ans < 0 || $ans >= count( $opts ) || count( $opts ) < 2 ) ) {
				continue;
			}
			$out[] = array(
				'id'      => ! empty( $q['id'] ) ? sanitize_key( $q['id'] ) : substr( md5( wp_rand() . microtime() ), 0, 8 ),
				't'       => max( 0, round( (float) ( $q['t'] ?? 0 ), 1 ) ),
				'type'    => $type,
				'q'       => mb_substr( sanitize_text_field( (string) $q['q'] ), 0, 300 ),
				'options' => 'blank' === $type ? array() : array_map( function ( $o ) { return mb_substr( sanitize_text_field( $o ), 0, 120 ); }, array_slice( $opts, 0, 5 ) ),
				'answer'  => 'blank' === $type ? mb_substr( sanitize_text_field( $ans ), 0, 80 ) : $ans,
				'word'    => mb_substr( strtolower( sanitize_text_field( (string) ( $q['word'] ?? '' ) ) ), 0, 64 ),
				'why'     => mb_substr( sanitize_text_field( (string) ( $q['why'] ?? '' ) ), 0, 300 ),
				'why_ar'  => mb_substr( sanitize_text_field( (string) ( $q['why_ar'] ?? '' ) ), 0, 400 ),
			);
		}
		usort( $out, function ( $a, $b ) { return $a['t'] <=> $b['t']; } );
		return $out;
	}

	/**
	 * Transcript from YouTube's "Show transcript" copy ("0:01" line, then text) or "0:01 text" lines.
	 * Returns [[seconds, text], …].
	 */
	public static function parse_transcript( $raw ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );
		$out   = array();
		$t     = null;
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^(?:(\d+):)?(\d{1,2}):(\d{2})\s*(.*)$/', $line, $m ) ) {
				$t = (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
				if ( '' !== trim( $m[4] ) ) {
					$out[] = array( $t, trim( $m[4] ) );
					$t     = null;
				}
				continue;
			}
			if ( null !== $t ) {
				$out[] = array( $t, $line );
				$t     = null;
			} elseif ( $out ) {
				$out[ count( $out ) - 1 ][1] .= ' ' . $line;
			}
		}
		return $out;
	}

	/** Lines from the extension: [{start, end, text}] in seconds. */
	public static function clean_lines( $lines ) {
		$out = array();
		foreach ( array_slice( (array) $lines, 0, 20000 ) as $l ) {
			$text = trim( wp_strip_all_tags( (string) ( $l['text'] ?? ( $l[1] ?? '' ) ) ) );
			if ( '' !== $text ) {
				$out[] = array( round( (float) ( $l['start'] ?? ( $l[0] ?? 0 ) ), 1 ), mb_substr( $text, 0, 500 ) );
			}
		}
		return $out;
	}

	/** What a student sees: questions without hidden data, plus their earlier answers. */
	public static function for_student( $lesson, $uid ) {
		global $wpdb;
		$res     = $wpdb->get_row( $wpdb->prepare( 'SELECT answers FROM ' . self::t( 'lesson_results' ) . ' WHERE user_id = %d AND lesson_id = %d', $uid, $lesson['id'] ), ARRAY_A ); // phpcs:ignore
		$answers = $res ? json_decode( $res['answers'], true ) : array();
		return array(
			'id'        => $lesson['id'],
			'videoId'   => $lesson['videoId'],
			'title'     => $lesson['title'],
			'kind'      => $lesson['kind'],
			'words'     => $lesson['words'],
			'transcript' => $lesson['transcript'], // [[seconds, text], …]: from the admin's own captions or a pasted transcript
			'questions' => array_map(
				function ( $q ) {
					return array_intersect_key( $q, array_flip( array( 'id', 't', 'type', 'q', 'options', 'word' ) ) );
				},
				$lesson['questions']
			),
			'answers'   => is_array( $answers ) ? $answers : array(),
		);
	}

	private static function norm( $s ) {
		return trim( preg_replace( '/[^a-z0-9\' ]+/', '', strtolower( str_replace( '’', "'", (string) $s ) ) ) );
	}

	/** Grades one answer, saves it, and returns {ok, answer, why, why_ar, score}. */
	public static function answer( $uid, $lesson_id, $qid, $given ) {
		global $wpdb;
		$lesson = self::get( $lesson_id );
		if ( ! $lesson || ! $lesson['published'] ) {
			return new WP_Error( 'lesson', 'This quiz is not available.', array( 'status' => 404 ) );
		}
		$q = null;
		foreach ( $lesson['questions'] as $x ) {
			if ( $x['id'] === $qid ) {
				$q = $x;
			}
		}
		if ( ! $q ) {
			return new WP_Error( 'question', 'Question not found.', array( 'status' => 404 ) );
		}
		$ok = 'blank' === $q['type'] ? self::norm( $given ) === self::norm( $q['answer'] ) : (int) $given === (int) $q['answer'];
		$t   = self::t( 'lesson_results' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT answers FROM $t WHERE user_id = %d AND lesson_id = %d", $uid, $lesson_id ), ARRAY_A ); // phpcs:ignore
		$ans = $row ? (array) json_decode( $row['answers'], true ) : array();
		$first = ! isset( $ans[ $qid ] );
		if ( $first ) {
			$ans[ $qid ] = array( 'a' => is_scalar( $given ) ? mb_substr( (string) $given, 0, 80 ) : '', 'ok' => $ok );
		}
		$correct = count( array_filter( $ans, function ( $a ) { return ! empty( $a['ok'] ); } ) );
		$wpdb->replace( $t, array( 'user_id' => $uid, 'lesson_id' => $lesson_id, 'answers' => wp_json_encode( $ans ), 'correct' => $correct, 'answered' => count( $ans ), 'updated_at' => current_time( 'mysql' ) ) ); // phpcs:ignore
		if ( $first ) {
			if ( ! $ok && '' !== $q['word'] ) {
				// Missed a key word: it goes into Learning, so it comes back as a review card.
				Sawa_Lens_Store::put_words( $uid, array( array( 'lemma' => $q['word'], 'status' => 'learning', 't' => (int) round( microtime( true ) * 1000 ) ) ) );
			}
			if ( $ok ) {
				Sawa_Lens_Skills::xp( $uid, 5, 'quiz' );
				Sawa_Lens_Skills::coins( $uid, 2 );
			}
			Sawa_Lens_Activity::add( $uid, 'quiz' );
		}
		return array(
			'ok'      => $ok,
			'answer'  => $q['answer'],
			'why'     => $q['why'],
			'why_ar'  => $q['why_ar'],
			'correct' => $correct,
			'total'   => count( $lesson['questions'] ),
		);
	}

	/** How many published lessons of a kind there are (video | song), for the Silk Road's camps. */
	public static function count_kind( $kind ) {
		global $wpdb;
		static $c = array();
		if ( ! isset( $c[ $kind ] ) ) {
			$c[ $kind ] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t() . ' WHERE published = 1 AND kind = %s', $kind ) ); // phpcs:ignore
		}
		return $c[ $kind ];
	}

	/** Published lessons with this student's score, for the Watch tab. */
	public static function list_for( $uid ) {
		global $wpdb;
		$scores = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT lesson_id, correct, answered FROM ' . self::t( 'lesson_results' ) . ' WHERE user_id = %d', $uid ), ARRAY_A ) as $r ) { // phpcs:ignore
			$scores[ (int) $r['lesson_id'] ] = array( (int) $r['correct'], (int) $r['answered'] );
		}
		return array_map(
			function ( $l ) use ( $scores ) {
				return array(
					'id'       => $l['id'],
					'videoId'  => $l['videoId'],
					'title'    => $l['title'],
					'kind'     => $l['kind'],
					'words'    => array_slice( $l['words'], 0, 12 ),
					'total'    => count( $l['questions'] ),
					'correct'  => $scores[ $l['id'] ][0] ?? 0,
					'answered' => $scores[ $l['id'] ][1] ?? 0,
				);
			},
			self::all( true )
		);
	}
}
