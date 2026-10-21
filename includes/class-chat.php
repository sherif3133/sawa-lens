<?php
/**
 * Bridge to the Sawa Tracker chat: transcripts of voice messages and private feedback on a learner's own
 * messages. Feedback errors go into the same error log as the writing checker (source "chat" or "voice"),
 * so the skill tree and the "are my mistakes going down" trends include what learners say and write in chat.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sawa_Lens_Chat {

	public static function init() {
		add_filter( 'sawa_tracker_chat_ai', array( __CLASS__, 'features' ), 10, 2 );
		add_filter( 'sawa_tracker_transcribe', array( __CLASS__, 'transcribe' ), 10, 3 );
		add_filter( 'sawa_tracker_feedback', array( __CLASS__, 'feedback' ), 10, 5 );
		add_filter( 'sawa_tracker_translate', array( __CLASS__, 'translate' ), 10, 3 );
	}

	public static function features( $f, $uid ) {
		$s = Sawa_Lens_AI::settings();
		return array(
			'transcribe' => '' !== $s['stt_key'],
			'feedback'   => Sawa_Lens_AI::ready() && Sawa_Lens_Auth::allowed( $uid ),
			'translate'  => Sawa_Lens_AI::ready() && Sawa_Lens_Auth::allowed( $uid ),
		);
	}

	public static function translate( $default, $text, $uid ) {
		if ( ! Sawa_Lens_AI::ready() || ! Sawa_Lens_Auth::allowed( $uid ) ) {
			return $default;
		}
		return Sawa_Lens_AI::chat_translate( $uid, $text );
	}

	public static function transcribe( $default, $path, $uid ) {
		return Sawa_Lens_AI::transcribe( $uid, $path );
	}

	public static function feedback( $default, $text, $uid, $source, $msg_id ) {
		if ( ! Sawa_Lens_AI::ready() || ! Sawa_Lens_Auth::allowed( $uid ) ) {
			return $default;
		}
		$text = trim( wp_strip_all_tags( (string) $text ) );
		if ( count( preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) ) < 3 || ! preg_match( '/[a-z]{2}/i', $text ) ) {
			return new WP_Error( 'short', 'الرسالة قصيرة أوي. الرأي بيشتغل على جملة إنجليزي كاملة على الأقل.' );
		}
		// One analysis per message: asking again shows the same feedback and doesn't log the errors twice.
		$key  = 'sawa_lens_chatfb_' . (int) $msg_id . '_' . substr( md5( $text ), 0, 8 );
		$res  = get_user_meta( $uid, $key, true );
		if ( ! is_array( $res ) ) {
			$res = Sawa_Lens_AI::check_writing( $uid, $text, 'voice' === $source ? 'voice' : 'chat' );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$src = 'voice' === $source ? 'voice' : 'chat';
			global $wpdb;
			$wpdb->insert( // phpcs:ignore
				Sawa_Lens_Store::t( 'writing' ),
				array(
					'user_id'    => $uid,
					'source'     => $src,
					'body'       => $text,
					'result'     => wp_json_encode( $res ),
					'words'      => count( preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) ),
					'errors'     => count( $res['errors'] ),
					'created_at' => current_time( 'mysql' ),
				)
			);
			Sawa_Lens_Skills::log_errors( $uid, $res['errors'], $src, (int) $wpdb->insert_id );
			Sawa_Lens_Skills::record_uses( $uid, $res['good'] );
			update_user_meta( $uid, $key, $res );
		}
		// Explanations only for points the learner has open (same rule as the writing checker).
		$status = array();
		foreach ( Sawa_Lens_Skills::tree( $uid ) as $n ) {
			$status[ $n['id'] ] = $n;
		}
		foreach ( $res['errors'] as &$e ) {
			$n              = $status[ $e['skill'] ] ?? null;
			$e['locked']    = $n && 'locked' === $n['status'];
			$e['skillName'] = $n ? $n['name'] : '';
			if ( $e['locked'] ) {
				$e['why']    = '';
				$e['why_ar'] = '';
			}
		}
		unset( $e );
		$res['app'] = Sawa_Lens_App::url();
		return $res;
	}
}
