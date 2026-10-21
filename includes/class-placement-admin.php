<?php
/**
 * WordPress admin → Video quizzes → Placement test: the question bank (make items with AI, switch items off),
 * calibration from real answers, and recent results.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sawa_Lens_Placement_Admin {

	const PAGE = 'sawa-lens-placement';

	public static function init() {
		add_action( 'admin_menu', function () {
			add_submenu_page( 'sawa-lens-quizzes', 'Placement test', 'Placement test', 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
		}, 20 );
		add_action( 'admin_post_sawa_lens_pt', array( __CLASS__, 'post' ) );
	}

	private static function url( $a = array() ) {
		return add_query_arg( $a, admin_url( 'admin.php?page=' . self::PAGE ) );
	}

	public static function post() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sawa_lens_pt' ) ) {
			wp_die( 'Not allowed.' );
		}
		global $wpdb;
		$do  = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) ); // phpcs:ignore
		$msg = '';
		if ( 'generate' === $do ) {
			$level = max( 1, min( 6, (int) ( $_POST['level'] ?? 1 ) ) ); // phpcs:ignore
			$made  = array();
			$only = sanitize_key( wp_unslash( $_POST['only'] ?? '' ) ); // phpcs:ignore
			foreach ( array( 'mc' => 12, 'ctest' => 3, 'dictation' => 6 ) as $type => $n ) {
				if ( '' !== $only && $only !== $type ) {
					continue;
				}
				$k = Sawa_Lens_AI::placement_items( get_current_user_id(), $type, $level, $n );
				$made[] = $type . ': ' . ( is_wp_error( $k ) ? 'failed (' . $k->get_error_message() . ')' : $k );
			}
			$msg = Sawa_Lens_Placement::LEVELS[ $level ] . ' items made — ' . implode( ', ', $made );
		} elseif ( 'toggle' === $do ) {
			$id = (int) ( $_POST['id'] ?? 0 ); // phpcs:ignore
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . Sawa_Lens_Store::t( 'pt_items' ) . ' SET active = 1 - active WHERE id = %d', $id ) ); // phpcs:ignore
			$msg = 'Item updated.';
		} elseif ( 'recalibrate' === $do ) {
			$msg = 'Recalibrated ' . Sawa_Lens_Placement::recalibrate() . ' items from real answers.';
		}
		set_transient( 'sawa_lens_pt_msg', $msg, 120 );
		wp_safe_redirect( self::url( array( 'type' => sanitize_key( wp_unslash( $_POST['type'] ?? '' ) ) ) ) ); // phpcs:ignore
		exit;
	}

	public static function page() {
		global $wpdb;
		$msg    = get_transient( 'sawa_lens_pt_msg' );
		delete_transient( 'sawa_lens_pt_msg' );
		$counts = Sawa_Lens_Placement::counts();
		$types  = array( 'mc' => 'Grammar', 'ctest' => 'Reading (C-test)', 'dictation' => 'Listening (dictation)' );
		$nonce  = wp_nonce_field( 'sawa_lens_pt', '_wpnonce', true, false );
		$form   = function ( $do, $label, $extra = '', $cls = 'button' ) use ( $nonce ) {
			return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">' . $nonce . '<input type="hidden" name="action" value="sawa_lens_pt"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">' . $extra . '<button class="' . esc_attr( $cls ) . '">' . $label . '</button></form>';
		};
		echo '<div class="wrap"><h1>Placement test</h1>';
		if ( $msg ) {
			echo '<div class="notice notice-success"><p>' . esc_html( $msg ) . '</p></div>';
		}
		echo '<p>The test lives at <a href="' . esc_url( home_url( '/placement/' ) ) . '" target="_blank">' . esc_html( home_url( '/placement/' ) ) . '</a> — free for everyone; members get their level saved. It adapts to each person (Rasch model): every question has a difficulty, and the test picks the next one that fits.</p>';
		echo '<h2>Question bank</h2><table class="widefat striped" style="max-width:820px"><thead><tr><th>Level</th>';
		foreach ( $types as $t => $name ) {
			echo '<th>' . esc_html( $name ) . '</th>';
		}
		echo '<th></th></tr></thead><tbody>';
		foreach ( Sawa_Lens_Placement::LEVELS as $lv => $name ) {
			echo '<tr><td><b>' . esc_html( $name ) . '</b></td>';
			foreach ( $types as $t => $_ ) {
				echo '<td>' . (int) ( $counts[ $t ][ $lv ] ?? 0 ) . '</td>';
			}
			echo '<td>' . $form( 'generate', '✨ Make ' . esc_html( $name ) . ' items', '<input type="hidden" name="level" value="' . (int) $lv . '">' ) . '</td></tr>'; // phpcs:ignore
		}
		echo '</tbody></table><p class="description">Each click makes 12 grammar questions, 3 reading texts and 6 listening sentences for that level (about 1 minute, a few cents). A good bank has at least 2 clicks per level. Read the new items below and switch off any you don\'t like.</p>';

		$share = Sawa_Lens_Placement::calibrated_share();
		echo '<h2>Calibration</h2><p>' . (int) round( $share * 100 ) . '% of items have 30+ answers. Results show a "beta" note until 60% do. Recalibrating sets each item\'s difficulty from how real students did.</p><p>' . $form( 'recalibrate', 'Recalibrate from real answers', '', 'button button-primary' ) . '</p>'; // phpcs:ignore

		$type = isset( $_GET['type'] ) && isset( $types[ $_GET['type'] ] ) ? sanitize_key( $_GET['type'] ) : 'mc'; // phpcs:ignore
		echo '<h2>Items</h2><p>';
		foreach ( $types as $t => $name ) {
			echo $t === $type ? '<b>' . esc_html( $name ) . '</b> · ' : '<a href="' . esc_url( self::url( array( 'type' => $t ) ) ) . '">' . esc_html( $name ) . '</a> · '; // phpcs:ignore
		}
		echo '</p><table class="widefat striped"><thead><tr><th>Level</th><th>Item</th><th>Difficulty</th><th>Answers</th><th>Right</th><th></th></tr></thead><tbody>';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Sawa_Lens_Store::t( 'pt_items' ) . ' WHERE type = %s ORDER BY level, id', $type ), ARRAY_A ); // phpcs:ignore
		foreach ( (array) $rows as $r ) {
			$b    = json_decode( $r['body'], true );
			$text = 'mc' === $type ? $b['q'] . ' → ' . implode( ' / ', array_map( function ( $o, $i ) use ( $b ) { return $i === (int) $b['answer'] ? '<b>' . esc_html( $o ) . '</b>' : esc_html( $o ); }, $b['options'], array_keys( $b['options'] ) ) ) : esc_html( ( $b['title'] ? $b['title'] . ': ' : '' ) . $b['text'] );
			echo '<tr style="' . ( $r['active'] ? '' : 'opacity:.45' ) . '"><td>' . esc_html( Sawa_Lens_Placement::LEVELS[ (int) $r['level'] ] ) . '</td><td style="max-width:640px">' . ( 'mc' === $type ? esc_html( $b['q'] ) . '<br><small>' . implode( ' / ', array_map( function ( $o, $i ) use ( $b ) { return $i === (int) $b['answer'] ? '<b>' . esc_html( $o ) . '</b>' : esc_html( $o ); }, $b['options'], array_keys( $b['options'] ) ) ) . ( ! empty( $b['point'] ) ? ' · <i>' . esc_html( $b['point'] ) . '</i>' : '' ) . '</small>' : esc_html( ( $b['title'] ? $b['title'] . ': ' : '' ) . $b['text'] ) ) . '</td>' // phpcs:ignore
				. '<td>' . esc_html( number_format( (float) $r['b'], 2 ) ) . '</td><td>' . (int) $r['n'] . '</td><td>' . ( $r['n'] ? (int) round( 100 * (float) $r['score'] ) . '%' : '–' ) . '</td>'
				. '<td>' . $form( 'toggle', $r['active'] ? 'Switch off' : 'Switch on', '<input type="hidden" name="id" value="' . (int) $r['id'] . '"><input type="hidden" name="type" value="' . esc_attr( $type ) . '">', 'button-link' ) . '</td></tr>'; // phpcs:ignore
			unset( $text );
		}
		echo '</tbody></table>';

		$res = $wpdb->get_results( 'SELECT user_id, result, finished_at FROM ' . Sawa_Lens_Store::t( 'pt_sessions' ) . ' WHERE finished_at IS NOT NULL ORDER BY id DESC LIMIT 30', ARRAY_A ); // phpcs:ignore
		echo '<h2>Recent results</h2>' . ( $res ? '<table class="widefat striped" style="max-width:820px"><thead><tr><th>Who</th><th>When</th><th>Level</th><th>Score</th><th>Vocabulary</th></tr></thead><tbody>' : '<p><em>No finished tests yet.</em></p>' );
		foreach ( (array) $res as $r ) {
			$x = json_decode( $r['result'], true );
			$u = $r['user_id'] ? get_userdata( (int) $r['user_id'] ) : null;
			echo '<tr><td>' . esc_html( $u ? $u->display_name : 'Visitor' ) . '</td><td>' . esc_html( mysql2date( 'j M, H:i', $r['finished_at'] ) ) . '</td><td><b>' . esc_html( $x['level'] ?? '' ) . '</b></td><td>' . (int) ( $x['score'] ?? 0 ) . '</td><td>' . (int) ( $x['vocab'] ?? 0 ) . '</td></tr>';
		}
		echo $res ? '</tbody></table>' : '';
		echo '</div>';
	}
}
