<?php
/**
 * Tables and data access. Words and sentences carry a change id from a shared sequence,
 * so a device asks "what changed since N" and gets everything newer, in order.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sawa_Lens_Store {

	public static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'sawa_lens_' . $name;
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::t( 'tokens' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			hash char(64) NOT NULL,
			label varchar(80) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			last_used datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY hash (hash),
			KEY user_id (user_id)
			) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::t( 'seq' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			PRIMARY KEY  (id)
			) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::t( 'words' ) . " (
			user_id bigint(20) unsigned NOT NULL,
			lemma varchar(64) NOT NULL,
			status varchar(10) NOT NULL DEFAULT '',
			level tinyint(3) unsigned NOT NULL DEFAULT 0,
			client_ts bigint(20) unsigned NOT NULL DEFAULT 0,
			known_on date DEFAULT NULL,
			learning_on date DEFAULT NULL,
			change_id bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (user_id,lemma),
			KEY user_change (user_id,change_id),
			KEY known_on (user_id,known_on)
			) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::t( 'sentences' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			uuid char(36) NOT NULL,
			body text NOT NULL,
			ar text NOT NULL,
			focus varchar(200) NOT NULL DEFAULT '',
			source_url varchar(500) NOT NULL DEFAULT '',
			source_title varchar(300) NOT NULL DEFAULT '',
			video_id varchar(20) NOT NULL DEFAULT '',
			kind varchar(12) NOT NULL DEFAULT 'sentence',
			tag varchar(60) NOT NULL DEFAULT '',
			start_ms int(10) unsigned NOT NULL DEFAULT 0,
			end_ms int(10) unsigned NOT NULL DEFAULT 0,
			deleted tinyint(1) NOT NULL DEFAULT 0,
			created_on date NOT NULL,
			client_ts bigint(20) unsigned NOT NULL DEFAULT 0,
			change_id bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY user_uuid (user_id,uuid),
			KEY user_change (user_id,change_id),
			KEY created_on (user_id,created_on)
			) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::t( 'ai' ) . " (
			hash char(64) NOT NULL,
			body mediumtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (hash)
			) $c;"
		);
		// Writing checked by the writing checker (and later chat messages): one row per check.
		dbDelta(
			'CREATE TABLE ' . self::t( 'writing' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			source varchar(10) NOT NULL DEFAULT 'write',
			body text NOT NULL,
			result mediumtext NOT NULL,
			words int(10) unsigned NOT NULL DEFAULT 0,
			errors smallint(5) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user_time (user_id,created_at)
			) $c;"
		);
		// Every error found, with its skill-tree point: drives the tree and the "are errors going down" trends.
		dbDelta(
			'CREATE TABLE ' . self::t( 'errors' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			skill varchar(20) NOT NULL DEFAULT 'other',
			source varchar(10) NOT NULL DEFAULT 'write',
			writing_id bigint(20) unsigned NOT NULL DEFAULT 0,
			wrong varchar(200) NOT NULL DEFAULT '',
			fix varchar(200) NOT NULL DEFAULT '',
			why varchar(300) NOT NULL DEFAULT '',
			why_ar varchar(400) NOT NULL DEFAULT '',
			sentence varchar(600) NOT NULL DEFAULT '',
			fixed varchar(600) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user_skill (user_id,skill),
			KEY user_time (user_id,created_at)
			) $c;"
		);
		Sawa_Lens_Review::install( $c );
		update_option( 'sawa_lens_db', SAWA_LENS_DB );
	}

	public static function next_change() {
		global $wpdb;
		$wpdb->insert( self::t( 'seq' ), array( 'id' => null ) ); // phpcs:ignore
		$id = (int) $wpdb->insert_id;
		if ( 0 === $id % 1000 ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t( 'seq' ) . ' WHERE id < %d', $id ) ); // phpcs:ignore
		}
		return $id;
	}

	public static function today() {
		return wp_date( 'Y-m-d' );
	}

	/* ------------------------------------------------------------------ settings per learner */

	public static function level( $uid ) {
		$l = (int) get_user_meta( $uid, 'sawa_lens_level', true );
		return $l >= 1 && $l <= 6 ? $l : 0;
	}

	/* ------------------------------------------------------------------ words */

	/**
	 * Saves word marks. Last write wins per word, by the device's clock (client_ts, ms).
	 * $items: [ ['lemma' => 'run', 'status' => 'known'|'learning'|'', 'level' => 2, 't' => 1700000000000], ... ]
	 */
	public static function put_words( $uid, $items ) {
		global $wpdb;
		$t     = self::t( 'words' );
		$today = self::today();
		$n     = 0;
		foreach ( array_slice( (array) $items, 0, 2000 ) as $it ) {
			$lemma  = mb_substr( strtolower( trim( (string) ( $it['lemma'] ?? '' ) ) ), 0, 64 );
			$status = in_array( $it['status'] ?? '', array( 'known', 'learning', '' ), true ) ? $it['status'] : null;
			$ts     = (int) ( $it['t'] ?? 0 );
			if ( '' === $lemma || null === $status || ! preg_match( "/^[a-z][a-z' .-]*$/", $lemma ) ) {
				continue;
			}
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT status, client_ts, known_on, learning_on FROM $t WHERE user_id = %d AND lemma = %s", $uid, $lemma ), ARRAY_A ); // phpcs:ignore
			if ( $row && (int) $row['client_ts'] > $ts ) {
				continue; // a newer mark already won
			}
			if ( $row && $row['status'] === $status ) {
				continue;
			}
			$data = array(
				'user_id'     => $uid,
				'lemma'       => $lemma,
				'status'      => $status,
				'level'       => max( 0, min( 6, (int) ( $it['level'] ?? 0 ) ) ),
				'client_ts'   => $ts,
				// Words known from the placement test ("base") aren't "learned today".
				'known_on'    => 'known' === $status ? ( $row && $row['known_on'] ? $row['known_on'] : ( empty( $it['base'] ) ? $today : null ) ) : ( $row ? $row['known_on'] : null ),
				'learning_on' => 'learning' === $status ? ( $row && $row['learning_on'] ? $row['learning_on'] : $today ) : ( $row ? $row['learning_on'] : null ),
				'change_id'   => self::next_change(),
			);
			if ( 'known' !== $status && $row && $row['known_on'] === $today ) {
				$data['known_on'] = null; // un-marked the same day: don't count it
			}
			$wpdb->replace( $t, $data ); // phpcs:ignore
			++$n;
		}
		return $n;
	}

	public static function words_since( $uid, $since, $limit = 5000 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT lemma, status, client_ts AS t, change_id FROM ' . self::t( 'words' ) . ' WHERE user_id = %d AND change_id > %d ORDER BY change_id LIMIT %d', $uid, $since, $limit ), ARRAY_A ); // phpcs:ignore
	}

	/* ------------------------------------------------------------------ sentences */

	public static function put_sentences( $uid, $items ) {
		global $wpdb;
		$t = self::t( 'sentences' );
		$n = 0;
		foreach ( array_slice( (array) $items, 0, 500 ) as $it ) {
			$uuid = strtolower( (string) ( $it['uuid'] ?? '' ) );
			if ( ! preg_match( '/^[0-9a-f-]{36}$/', $uuid ) ) {
				continue;
			}
			$ts  = (int) ( $it['t'] ?? 0 );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, client_ts, created_on FROM $t WHERE user_id = %d AND uuid = %s", $uid, $uuid ), ARRAY_A ); // phpcs:ignore
			if ( $row && (int) $row['client_ts'] > $ts ) {
				continue;
			}
			$body = trim( wp_strip_all_tags( (string) ( $it['text'] ?? '' ) ) );
			if ( '' === $body && empty( $it['deleted'] ) ) {
				continue;
			}
			$data = array(
				'user_id'      => $uid,
				'uuid'         => $uuid,
				'body'         => mb_substr( $body, 0, 1000 ),
				'ar'           => mb_substr( sanitize_textarea_field( (string) ( $it['ar'] ?? '' ) ), 0, 1000 ),
				'focus'        => mb_substr( sanitize_text_field( (string) ( $it['focus'] ?? '' ) ), 0, 200 ),
				'source_url'   => mb_substr( esc_url_raw( (string) ( $it['url'] ?? '' ) ), 0, 500 ),
				'source_title' => mb_substr( sanitize_text_field( (string) ( $it['title'] ?? '' ) ), 0, 300 ),
				'video_id'     => preg_match( '/^[\w-]{11}$/', (string) ( $it['videoId'] ?? '' ) ) ? $it['videoId'] : '',
				'kind'         => in_array( $it['kind'] ?? '', array( 'sentence', 'phrase', 'grammar' ), true ) ? $it['kind'] : 'sentence',
				'tag'          => mb_substr( sanitize_key( (string) ( $it['tag'] ?? '' ) ), 0, 60 ),
				'start_ms'     => max( 0, (int) ( $it['startMs'] ?? 0 ) ),
				'end_ms'       => max( 0, (int) ( $it['endMs'] ?? 0 ) ),
				'deleted'      => empty( $it['deleted'] ) ? 0 : 1,
				'created_on'   => $row ? $row['created_on'] : self::today(),
				'client_ts'    => $ts,
				'change_id'    => self::next_change(),
			);
			if ( $row ) {
				$wpdb->update( $t, $data, array( 'id' => $row['id'] ) ); // phpcs:ignore
			} else {
				$wpdb->insert( $t, $data ); // phpcs:ignore
			}
			++$n;
		}
		return $n;
	}

	public static function sentences_since( $uid, $since, $limit = 2000 ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'sentences' ) . ' WHERE user_id = %d AND change_id > %d ORDER BY change_id LIMIT %d', $uid, $since, $limit ), ARRAY_A ); // phpcs:ignore
		return array_map( array( __CLASS__, 'sentence_out' ), $rows );
	}

	public static function sentence_out( $r ) {
		return array(
			'uuid'      => $r['uuid'],
			'text'      => $r['body'],
			'ar'        => $r['ar'],
			'focus'     => $r['focus'],
			'url'       => $r['source_url'],
			'title'     => $r['source_title'],
			'videoId'   => $r['video_id'],
			'kind'      => isset( $r['kind'] ) ? $r['kind'] : 'sentence',
			'tag'       => isset( $r['tag'] ) ? $r['tag'] : '',
			'startMs'   => (int) $r['start_ms'],
			'endMs'     => (int) $r['end_ms'],
			'deleted'   => (bool) $r['deleted'],
			'createdOn' => $r['created_on'],
			't'         => (int) $r['client_ts'],
			'change_id' => (int) $r['change_id'],
		);
	}

	/* ------------------------------------------------------------------ progress */

	/** Totals and the last 30 days of words learned and sentences saved. */
	public static function progress( $uid, $days = 30 ) {
		global $wpdb;
		$w     = self::t( 'words' );
		$s     = self::t( 'sentences' );
		$from  = wp_date( 'Y-m-d', strtotime( '-' . ( $days - 1 ) . ' days', current_time( 'timestamp' ) ) );
		$known = $wpdb->get_results( $wpdb->prepare( "SELECT known_on AS d, COUNT(*) AS n FROM $w WHERE user_id = %d AND status = 'known' AND known_on >= %s GROUP BY known_on", $uid, $from ), OBJECT_K ); // phpcs:ignore
		$sent  = $wpdb->get_results( $wpdb->prepare( "SELECT created_on AS d, COUNT(*) AS n FROM $s WHERE user_id = %d AND deleted = 0 AND created_on >= %s GROUP BY created_on", $uid, $from ), OBJECT_K ); // phpcs:ignore
		$daily = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$d       = wp_date( 'Y-m-d', strtotime( "-$i days", current_time( 'timestamp' ) ) );
			$daily[] = array(
				'day'       => $d,
				'words'     => isset( $known[ $d ] ) ? (int) $known[ $d ]->n : 0,
				'sentences' => isset( $sent[ $d ] ) ? (int) $sent[ $d ]->n : 0,
			);
		}
		$today = end( $daily );
		return array(
			'level'          => self::level( $uid ),
			'wordsKnown'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $w WHERE user_id = %d AND status = 'known'", $uid ) ), // phpcs:ignore
			'wordsLearning'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $w WHERE user_id = %d AND status = 'learning'", $uid ) ), // phpcs:ignore
			'sentences'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $s WHERE user_id = %d AND deleted = 0", $uid ) ), // phpcs:ignore
			'todayWords'     => $today['words'],
			'todaySentences' => $today['sentences'],
			'daily'          => $daily,
		);
	}
}
