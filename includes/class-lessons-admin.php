<?php
/**
 * WordPress admin → Video quizzes: make, generate, edit and publish EdPuzzle-style quizzes on YouTube videos.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sawa_Lens_Lessons_Admin {

	const PAGE = 'sawa-lens-quizzes';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_sawa_lens_lesson', array( __CLASS__, 'post' ) );
	}

	public static function menu() {
		add_menu_page( 'Video quizzes', 'Video quizzes', 'manage_options', self::PAGE, array( __CLASS__, 'page' ), 'dashicons-video-alt3', 27 );
	}

	public static function url( $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE ) );
	}

	public static function video_id( $url ) {
		$url = trim( (string) $url );
		if ( preg_match( '/^[\w-]{11}$/', $url ) ) {
			return $url;
		}
		return preg_match( '~(?:v=|youtu\.be/|/shorts/|/embed/|/live/)([\w-]{11})~', $url, $m ) ? $m[1] : '';
	}

	public static function post() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sawa_lens_lesson' ) ) {
			wp_die( 'Not allowed.' );
		}
		$id  = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : 'save';
		$msg = 'saved';
		if ( 'delete' === $do && $id ) {
			Sawa_Lens_Lessons::delete( $id );
			wp_safe_redirect( self::url( array( 'msg' => 'deleted' ) ) );
			exit;
		}
		$data = array(
			'title' => isset( $_POST['title'] ) ? wp_unslash( $_POST['title'] ) : '', // phpcs:ignore
			'words' => isset( $_POST['words'] ) ? wp_unslash( $_POST['words'] ) : '', // phpcs:ignore
			'kind'  => isset( $_POST['kind'] ) && 'song' === $_POST['kind'] ? 'song' : 'video', // phpcs:ignore
		);
		if ( isset( $_POST['video'] ) ) {
			$vid = self::video_id( wp_unslash( $_POST['video'] ) ); // phpcs:ignore
			if ( ! $vid ) {
				wp_safe_redirect( self::url( array( 'edit' => $id ?: 'new', 'msg' => 'badurl' ) ) );
				exit;
			}
			$data['videoId'] = $vid;
		}
		$raw = isset( $_POST['transcript'] ) ? trim( (string) wp_unslash( $_POST['transcript'] ) ) : ''; // phpcs:ignore
		if ( '' !== $raw ) {
			$data['transcript'] = Sawa_Lens_Lessons::parse_transcript( $raw );
		}
		if ( isset( $_POST['q'] ) && is_array( $_POST['q'] ) ) {
			$qs = array();
			foreach ( wp_unslash( $_POST['q'] ) as $q ) { // phpcs:ignore
				if ( ! empty( $q['remove'] ) ) {
					continue;
				}
				$t       = (string) ( $q['time'] ?? '0' );
				$parts   = array_map( 'intval', explode( ':', $t ) );
				$secs    = 0;
				foreach ( $parts as $p ) {
					$secs = $secs * 60 + $p;
				}
				$type    = $q['type'] ?? 'mc';
				$qs[]    = array(
					'id'      => $q['id'] ?? '',
					't'       => $secs,
					'type'    => $type,
					'q'       => $q['q'] ?? '',
					'options' => array_map( 'trim', explode( "\n", (string) ( $q['options'] ?? '' ) ) ),
					'answer'  => 'blank' === $type ? ( $q['answer'] ?? '' ) : max( 0, (int) ( $q['answer'] ?? 1 ) - 1 ),
					'word'    => $q['word'] ?? '',
					'why'     => $q['why'] ?? '',
					'why_ar'  => $q['why_ar'] ?? '',
				);
			}
			$data['questions'] = $qs;
		}
		if ( 'publish' === $do ) {
			$data['published'] = 1;
			$msg               = 'published';
		} elseif ( 'unpublish' === $do ) {
			$data['published'] = 0;
			$msg               = 'unpublished';
		}
		$id = Sawa_Lens_Lessons::save( $id, $data );
		if ( 'generate' === $do ) {
			$l   = Sawa_Lens_Lessons::get( $id );
			$res = Sawa_Lens_AI::lesson_questions( get_current_user_id(), $l['transcript'], $l['words'], isset( $_POST['count'] ) ? (int) $_POST['count'] : 8 );
			if ( is_wp_error( $res ) ) {
				set_transient( 'sawa_lens_lesson_err', $res->get_error_message() . ' ' . get_option( 'sawa_lens_last_ai_error', '' ), 120 );
				$msg = 'error';
			} else {
				$keep = ! empty( $_POST['append'] ) ? $l['questions'] : array();
				Sawa_Lens_Lessons::save( $id, array( 'questions' => array_merge( $keep, $res ) ) );
				$msg = 'generated';
			}
		}
		wp_safe_redirect( self::url( array( 'edit' => $id, 'msg' => $msg ) ) );
		exit;
	}

	private static function mmss( $s ) {
		$s = (int) round( $s );
		return $s >= 3600 ? gmdate( 'G:i:s', $s ) : intdiv( $s, 60 ) . ':' . str_pad( (string) ( $s % 60 ), 2, '0', STR_PAD_LEFT );
	}

	public static function page() {
		$msgs = array(
			'saved'       => 'Saved.',
			'published'   => 'Published: students can take this quiz now.',
			'unpublished' => 'Unpublished.',
			'generated'   => 'Questions generated. Check them, edit anything, then Publish.',
			'deleted'     => 'Deleted.',
			'badurl'      => 'That doesn\'t look like a YouTube link.',
			'error'       => 'The AI could not make questions: ' . get_transient( 'sawa_lens_lesson_err' ),
		);
		$m = isset( $_GET['msg'] ) ? sanitize_key( $_GET['msg'] ) : ''; // phpcs:ignore
		echo '<div class="wrap"><h1 class="wp-heading-inline">Video quizzes</h1>';
		if ( ! isset( $_GET['edit'] ) ) { // phpcs:ignore
			echo ' <a class="page-title-action" href="' . esc_url( self::url( array( 'edit' => 'new' ) ) ) . '">New quiz</a>';
		}
		echo '<hr class="wp-header-end">';
		if ( isset( $msgs[ $m ] ) ) {
			echo '<div class="notice ' . ( in_array( $m, array( 'error', 'badurl' ), true ) ? 'notice-error' : 'notice-success' ) . '"><p>' . esc_html( $msgs[ $m ] ) . '</p></div>';
		}
		if ( isset( $_GET['edit'] ) ) { // phpcs:ignore
			self::editor( 'new' === $_GET['edit'] ? null : Sawa_Lens_Lessons::get( (int) $_GET['edit'] ) ); // phpcs:ignore
		} else {
			self::table();
		}
		echo '</div>';
	}

	private static function table() {
		global $wpdb;
		$lessons = Sawa_Lens_Lessons::all();
		echo '<p>Quizzes pause a YouTube video and ask about the words you want students to learn. Students answer on YouTube with Language Lens, or on their phone at <a href="' . esc_url( Sawa_Lens_App::url() . '#watch' ) . '" target="_blank">' . esc_html( Sawa_Lens_App::url() ) . '</a> → Watch. A missed key word goes into the student\'s Learning words and review cards.</p>';
		echo '<p><b>Fastest way:</b> open the video on YouTube with Language Lens, open the transcript panel, and click <i>Make a quiz from this video</i>. Or click New quiz and paste the transcript from YouTube (… → Show transcript → select all → copy).</p>';
		if ( ! $lessons ) {
			echo '<p><em>No quizzes yet.</em></p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Video</th><th>Key words</th><th>Questions</th><th>Students</th><th>Status</th></tr></thead><tbody>';
		foreach ( $lessons as $l ) {
			$n   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Sawa_Lens_Store::t( 'lesson_results' ) . ' WHERE lesson_id = %d', $l['id'] ) ); // phpcs:ignore
			$avg = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT AVG(correct / GREATEST(answered,1)) FROM ' . Sawa_Lens_Store::t( 'lesson_results' ) . ' WHERE lesson_id = %d', $l['id'] ) ); // phpcs:ignore
			echo '<tr><td><a href="' . esc_url( self::url( array( 'edit' => $l['id'] ) ) ) . '"><img src="https://i.ytimg.com/vi/' . esc_attr( $l['videoId'] ) . '/default.jpg" alt="" style="width:80px;vertical-align:middle;margin-right:8px;border-radius:4px"><b>' . esc_html( $l['title'] ? $l['title'] : $l['videoId'] ) . '</b></a></td>'
				. '<td>' . ( 'song' === $l['kind'] ? '🎵 ' : '📺 ' ) . esc_html( implode( ', ', array_slice( $l['words'], 0, 8 ) ) ) . '</td><td>' . count( $l['questions'] ) . '</td>'
				. '<td>' . ( $n ? esc_html( $n . ' · ' . round( $avg * 100 ) . '% right' ) : '–' ) . '</td><td>' . ( $l['published'] ? '<span style="color:#15803D;font-weight:600">Published</span>' : 'Draft' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function editor( $l ) {
		$id = $l ? $l['id'] : 0;
		echo '<p><a href="' . esc_url( self::url() ) . '">← All quizzes</a></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="sawa_lens_lesson"><input type="hidden" name="id" value="' . esc_attr( $id ) . '">';
		wp_nonce_field( 'sawa_lens_lesson' );
		echo '<table class="form-table"><tr><th><label for="video">YouTube video</label></th><td><input id="video" name="video" class="regular-text" value="' . esc_attr( $l ? 'https://www.youtube.com/watch?v=' . $l['videoId'] : '' ) . '" placeholder="https://www.youtube.com/watch?v=…" required>';
		if ( $l ) {
			echo '<p><a href="https://www.youtube.com/watch?v=' . esc_attr( $l['videoId'] ) . '" target="_blank">Open on YouTube</a></p>';
		}
		echo '</td></tr>';
		$song = $l && 'song' === $l['kind'];
		echo '<tr><th>Type</th><td><label><input type="radio" name="kind" value="video"' . ( $song ? '' : ' checked' ) . '> 📺 Video</label> &nbsp; <label><input type="radio" name="kind" value="song"' . ( $song ? ' checked' : '' ) . '> 🎵 Song (a music video: the transcript is the lyrics)</label><p class="description">In the Silk Road, videos open at the 📺 camp and songs at the 🎵 camp, with the transcript under the player: students tap any word for its meaning, on a phone too.</p></td></tr>';
		echo '<tr><th><label for="title">Title</label></th><td><input id="title" name="title" class="regular-text" value="' . esc_attr( $l ? $l['title'] : '' ) . '"></td></tr>';
		echo '<tr><th><label for="words">Key words to teach</label></th><td><textarea id="words" name="words" class="large-text" rows="2" placeholder="afford, give up, get along with…">' . esc_textarea( $l ? implode( ', ', $l['words'] ) : '' ) . '</textarea><p class="description">Comma-separated. Leave empty and the AI picks the most useful words from the video.</p></td></tr>';
		$lines = $l ? count( $l['transcript'] ) : 0;
		echo '<tr><th><label for="transcript">Transcript</label></th><td>' . ( $lines ? '<p>' . (int) $lines . ' lines saved (to ' . esc_html( self::mmss( end( $l['transcript'] )[0] ) ) . '). Paste a new one below only to replace it.</p>' : '' )
			. '<textarea id="transcript" name="transcript" class="large-text code" rows="' . ( $lines ? 3 : 8 ) . '" placeholder="0:00&#10;Hello everyone, welcome back…&#10;0:04&#10;Today we are going to…"></textarea><p class="description">On YouTube: … (under the video) → Show transcript → select it all → copy → paste here. Or use the Language Lens button on the video. Use your own captions, or the lyrics with times for a song. Students see this transcript under the video in the Silk Road.</p></td></tr></table>';

		echo '<p><label>Questions to make: <input type="number" name="count" min="3" max="20" value="8" style="width:60px"></label> ';
		echo '<label><input type="checkbox" name="append" value="1"> Keep the questions I have and add new ones</label></p>';
		echo '<p><button class="button button-primary" name="do" value="generate"' . ( $lines || ! $l ? '' : '' ) . '>✨ Generate questions with AI</button> <button class="button" name="do" value="save">Save</button></p>';

		if ( $l && $l['questions'] ) {
			echo '<h2>Questions (' . count( $l['questions'] ) . ')</h2><p class="description">Time = when the video pauses (m:ss). Options one per line; Answer = the number of the right option (1, 2, 3…), or the word for fill-in-the-blank.</p>';
			foreach ( $l['questions'] as $i => $q ) {
				$n = 'q[' . $i . ']';
				echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;margin:10px 0;max-width:820px">';
				echo '<input type="hidden" name="' . esc_attr( $n ) . '[id]" value="' . esc_attr( $q['id'] ) . '">';
				echo '<p><label>Time <input name="' . esc_attr( $n ) . '[time]" value="' . esc_attr( self::mmss( $q['t'] ) ) . '" style="width:70px"></label> ';
				echo '<label>Type <select name="' . esc_attr( $n ) . '[type]">';
				foreach ( array( 'mc' => 'Multiple choice', 'blank' => 'Fill in the blank', 'tf' => 'True / False' ) as $k => $v ) {
					echo '<option value="' . esc_attr( $k ) . '"' . selected( $q['type'], $k, false ) . '>' . esc_html( $v ) . '</option>';
				}
				echo '</select></label> <label>Word <input name="' . esc_attr( $n ) . '[word]" value="' . esc_attr( $q['word'] ) . '" style="width:140px"></label> ';
				echo '<a href="https://www.youtube.com/watch?v=' . esc_attr( $l['videoId'] ) . '&t=' . (int) max( 0, $q['t'] - 8 ) . 's" target="_blank">▶ check moment</a>';
				echo ' <label style="float:right;color:#b32d2e"><input type="checkbox" name="' . esc_attr( $n ) . '[remove]" value="1"> Remove</label></p>';
				echo '<p><input name="' . esc_attr( $n ) . '[q]" value="' . esc_attr( $q['q'] ) . '" class="large-text" style="font-weight:600"></p>';
				echo '<p style="display:flex;gap:10px;flex-wrap:wrap"><label style="flex:1;min-width:240px">Options<br><textarea name="' . esc_attr( $n ) . '[options]" rows="4" class="large-text">' . esc_textarea( implode( "\n", $q['options'] ) ) . '</textarea></label>';
				echo '<label>Answer<br><input name="' . esc_attr( $n ) . '[answer]" value="' . esc_attr( 'blank' === $q['type'] ? $q['answer'] : (int) $q['answer'] + 1 ) . '" style="width:120px"></label></p>';
				echo '<p><label>Why (English) <input name="' . esc_attr( $n ) . '[why]" value="' . esc_attr( $q['why'] ) . '" class="large-text"></label></p>';
				echo '<p><label>Why (Arabic) <input name="' . esc_attr( $n ) . '[why_ar]" value="' . esc_attr( $q['why_ar'] ) . '" class="large-text" dir="rtl"></label></p></div>';
			}
			$n = 'q[' . count( $l['questions'] ) . ']';
			echo '<details style="max-width:820px"><summary style="cursor:pointer;font-weight:600">+ Add a question by hand</summary><div style="background:#fff;border:1px dashed #dcdcde;border-radius:8px;padding:12px 14px;margin:10px 0">';
			echo '<p><label>Time <input name="' . esc_attr( $n ) . '[time]" placeholder="1:23" style="width:70px"></label> <label>Type <select name="' . esc_attr( $n ) . '[type]"><option value="mc">Multiple choice</option><option value="blank">Fill in the blank</option><option value="tf">True / False</option></select></label> <label>Word <input name="' . esc_attr( $n ) . '[word]" style="width:140px"></label></p>';
			echo '<p><input name="' . esc_attr( $n ) . '[q]" placeholder="Question (leave empty to skip)" class="large-text"></p><p><label>Options (one per line)<br><textarea name="' . esc_attr( $n ) . '[options]" rows="3" class="large-text"></textarea></label></p><p><label>Answer <input name="' . esc_attr( $n ) . '[answer]" style="width:120px"></label></p></div></details>';
		}

		echo '<p style="margin-top:20px"><button class="button button-primary" name="do" value="save">Save</button> ';
		if ( $l ) {
			echo $l['published'] ? '<button class="button" name="do" value="unpublish">Unpublish</button> ' : '<button class="button button-primary" name="do" value="publish"' . ( $l['questions'] ? '' : ' disabled' ) . '>Publish to students</button> ';
			echo '<button class="button-link-delete" name="do" value="delete" onclick="return confirm(\'Delete this quiz and its results?\')" style="margin-left:12px">Delete quiz</button>';
		}
		echo '</p></form>';
	}
}
