<?php
/**
 * Settings → Language Lens: the Claude API key, model, daily AI limit and usage.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sawa_Lens_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_sawa_lens_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_sawa_lens_revoke', array( __CLASS__, 'revoke' ) );
		add_action( 'admin_post_sawa_lens_test', array( __CLASS__, 'test' ) );
	}

	public static function menu() {
		add_options_page( 'Language Lens', 'Language Lens', 'manage_options', 'sawa-lens', array( __CLASS__, 'page' ) );
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sawa_lens_save' ) ) {
			wp_die( 'Not allowed.' );
		}
		$s   = Sawa_Lens_AI::settings();
		$key = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';
		if ( '' !== $key && false === strpos( $key, '•' ) ) {
			$s['api_key'] = $key;
		}
		if ( ! empty( $_POST['remove_key'] ) ) {
			$s['api_key'] = '';
		}
		$stt = isset( $_POST['stt_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['stt_key'] ) ) ) : '';
		if ( '' !== $stt && false === strpos( $stt, '•' ) ) {
			$s['stt_key'] = $stt;
		}
		if ( ! empty( $_POST['remove_stt'] ) ) {
			$s['stt_key'] = '';
		}
		$s['model']       = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : $s['model'];
		$s['daily_limit'] = isset( $_POST['daily_limit'] ) ? max( 0, (int) $_POST['daily_limit'] ) : $s['daily_limit'];
		$s['store_url']   = isset( $_POST['store_url'] ) ? esc_url_raw( wp_unslash( $_POST['store_url'] ) ) : $s['store_url'];
		update_option( Sawa_Lens_AI::OPT, $s, false );
		wp_safe_redirect( admin_url( 'options-general.php?page=sawa-lens&saved=1' ) );
		exit;
	}

	/** Runs one explanation with a fresh example so the key, model and network are all checked. */
	public static function test() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sawa_lens_test' ) ) {
			wp_die( 'Not allowed.' );
		}
		$res = Sawa_Lens_AI::explain( get_current_user_id(), 'run', 'She runs a small bakery near the station (' . wp_date( 'H:i:s' ) . ').' );
		if ( ! is_wp_error( $res ) ) {
			// Also check "How it sounds" (connected speech).
			$snd = Sawa_Lens_AI::sound( get_current_user_id(), 'What do you want to do about it? (' . wp_date( 'H:i:s' ) . ')' );
			$res['sound'] = is_wp_error( $snd ) ? array( 'error' => $snd->get_error_message() ) : $snd;
		}
		if ( ! is_wp_error( $res ) && '' !== Sawa_Lens_AI::settings()['stt_key'] ) {
			// Speech-to-text: a short recorded sample ("Yesterday I go to the market with my brother.").
			$stt          = Sawa_Lens_AI::transcribe( get_current_user_id(), SAWA_LENS_DIR . 'assets/stt-test.wav' );
			$res['voice'] = is_wp_error( $stt ) ? array( 'error' => $stt->get_error_message() . ' ' . get_option( 'sawa_lens_last_ai_error', '' ) ) : array( 'text' => $stt );
		}
		set_transient( 'sawa_lens_test_' . get_current_user_id(), is_wp_error( $res ) ? array( 'error' => $res->get_error_message(), 'detail' => get_option( 'sawa_lens_last_ai_error', '' ) ) : $res, 300 );
		wp_safe_redirect( admin_url( 'options-general.php?page=sawa-lens&tested=1' ) );
		exit;
	}

	/** Signs a browser out of Language Lens (its token stops working at once). */
	public static function revoke() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sawa_lens_revoke' ) ) {
			wp_die( 'Not allowed.' );
		}
		global $wpdb;
		$wpdb->delete( Sawa_Lens_Store::t( 'tokens' ), array( 'id' => isset( $_POST['id'] ) ? (int) $_POST['id'] : 0 ) ); // phpcs:ignore
		wp_safe_redirect( admin_url( 'options-general.php?page=sawa-lens&revoked=1#devices' ) );
		exit;
	}

	public static function page() {
		global $wpdb;
		$s       = Sawa_Lens_AI::settings();
		$usage   = (array) get_option( 'sawa_lens_usage', array() );
		$err     = get_option( 'sawa_lens_last_ai_error', '' );
		$devices = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Sawa_Lens_Store::t( 'tokens' ) ); // phpcs:ignore
		$users   = (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT user_id) FROM ' . Sawa_Lens_Store::t( 'tokens' ) ); // phpcs:ignore
		$masked  = $s['api_key'] ? substr( $s['api_key'], 0, 10 ) . str_repeat( '•', 12 ) . substr( $s['api_key'], -4 ) : '';
		$mstt    = $s['stt_key'] ? substr( $s['stt_key'], 0, 7 ) . str_repeat( '•', 12 ) . substr( $s['stt_key'], -4 ) : '';
		?>
		<div class="wrap">
			<h1>Language Lens</h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore ?>
				<div class="notice notice-success"><p>Saved.</p></div>
			<?php endif; ?>
			<p><?php echo esc_html( $users ); ?> learners connected on <?php echo esc_html( $devices ); ?> browsers.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sawa_lens_save">
				<?php wp_nonce_field( 'sawa_lens_save' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="api_key">Claude API key</label></th>
						<td>
							<input type="password" id="api_key" name="api_key" class="regular-text" value="<?php echo esc_attr( $masked ); ?>" autocomplete="off" placeholder="sk-ant-…">
							<?php if ( $s['api_key'] ) : ?>
								<label style="margin-inline-start:12px"><input type="checkbox" name="remove_key" value="1"> Remove key</label>
							<?php endif; ?>
							<p class="description">Create one at console.anthropic.com → API keys. It stays on this server; the extension never sees it. Without a key the dictionary still works, only the "in this sentence" explanations are off.</p>
						</td>
					</tr>
					<tr>
						<th><label for="stt_key">Speech-to-text key (OpenAI)</label></th>
						<td>
							<input type="password" id="stt_key" name="stt_key" class="regular-text" value="<?php echo esc_attr( $mstt ); ?>" autocomplete="off" placeholder="sk-…">
							<?php if ( $s['stt_key'] ) : ?>
								<label style="margin-inline-start:12px"><input type="checkbox" name="remove_stt" value="1"> Remove key</label>
							<?php endif; ?>
							<p class="description">Turns on "write it as text" for voice messages in the tracker chat (and feedback on voice messages). Claude can't listen to audio, so this one step uses OpenAI's transcription (gpt-4o-mini-transcribe, about $0.003 per minute). Create a key at platform.openai.com → API keys. Each voice message is transcribed once, then saved.</p>
						</td>
					</tr>
					<tr>
						<th><label for="model">Model</label></th>
						<td><input id="model" name="model" class="regular-text" value="<?php echo esc_attr( $s['model'] ); ?>"><p class="description">Default claude-haiku-5-5 (fast and cheap).</p></td>
					</tr>
					<tr>
						<th><label for="daily_limit">AI explanations per learner per day</label></th>
						<td><input id="daily_limit" name="daily_limit" type="number" min="0" value="<?php echo esc_attr( $s['daily_limit'] ); ?>"><p class="description">Answers are cached, so a repeated lookup is free and doesn't count.</p></td>
					</tr>
					<tr>
						<th><label for="store_url">Extension install link</label></th>
						<td><input id="store_url" name="store_url" type="url" class="regular-text" value="<?php echo esc_attr( $s['store_url'] ); ?>" placeholder="https://chromewebstore.google.com/detail/…"><p class="description">Shown in the tracker to learners who haven't connected Language Lens yet.</p></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sawa_lens_test">
				<?php wp_nonce_field( 'sawa_lens_test' ); ?>
				<p><button class="button" type="submit" <?php disabled( ! $s['api_key'] ); ?>>Test explanation</button> <span class="description">Explains "run" in "She runs a small bakery…" (one small paid call).</span></p>
			</form>
			<?php
			$t = isset( $_GET['tested'] ) ? get_transient( 'sawa_lens_test_' . get_current_user_id() ) : null; // phpcs:ignore
			if ( $t ) :
				?>
				<div class="notice <?php echo isset( $t['error'] ) ? 'notice-error' : 'notice-success'; ?>" style="max-width:700px">
					<?php if ( isset( $t['error'] ) ) : ?>
						<p><strong>Test failed:</strong> <?php echo esc_html( $t['error'] ); ?></p><p><?php echo esc_html( $t['detail'] ); ?></p>
					<?php else : ?>
						<p><strong>Working.</strong></p>
						<p dir="rtl" style="font-size:15px"><?php echo esc_html( $t['meaning'] ); ?><br><?php echo esc_html( $t['sentence'] ); ?><?php echo $t['note'] ? '<br>' . esc_html( $t['note'] ) : ''; ?></p>
						<?php if ( ! empty( $t['sound'] ) ) : ?>
							<?php if ( isset( $t['sound']['error'] ) ) : ?>
								<p><strong>How it sounds failed:</strong> <?php echo esc_html( $t['sound']['error'] ); ?> <?php echo esc_html( get_option( 'sawa_lens_last_ai_error', '' ) ); ?></p>
							<?php else : ?>
								<p><strong>How it sounds:</strong> "What do you want to do about it?" → <?php echo esc_html( $t['sound']['soundsLike'] ); ?></p>
								<ul style="list-style:disc;margin-left:20px">
									<?php foreach ( (array) $t['sound']['features'] as $f ) : ?>
										<li><b><?php echo esc_html( $f['where'] ); ?></b> · <?php echo esc_html( $f['feature'] ); ?>: <?php echo esc_html( $f['why'] ); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						<?php endif; ?>
						<?php if ( ! empty( $t['voice'] ) ) : ?>
							<p><strong>Speech-to-text:</strong>
								<?php echo isset( $t['voice']['error'] ) ? 'failed – ' . esc_html( $t['voice']['error'] ) : 'sample "Yesterday I go to the market with my brother." → "' . esc_html( $t['voice']['text'] ) . '"'; ?>
							</p>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<?php if ( $err ) : ?>
				<p><strong>Last AI error:</strong> <?php echo esc_html( $err ); ?></p>
			<?php endif; ?>
			<h2 id="devices">Connected browsers</h2>
			<?php if ( isset( $_GET['revoked'] ) ) : // phpcs:ignore ?>
				<div class="notice notice-success"><p>Signed out.</p></div>
			<?php endif; ?>
			<?php $rows = $wpdb->get_results( 'SELECT id, user_id, label, created_at, last_used FROM ' . Sawa_Lens_Store::t( 'tokens' ) . ' ORDER BY COALESCE(last_used, created_at) DESC LIMIT 200' ); // phpcs:ignore ?>
			<?php if ( ! $rows ) : ?>
				<p>No browsers connected yet.</p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:900px">
					<thead><tr><th>Learner</th><th>Browser</th><th>Connected</th><th>Last used</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $rows as $r ) : $u = get_userdata( $r->user_id ); ?>
						<tr>
							<td><?php echo esc_html( $u ? $u->display_name : '#' . $r->user_id ); ?></td>
							<td><?php echo esc_html( preg_match( '~(Edg|Chrome|Firefox|Safari)/[0-9]+~', $r->label, $m ) ? str_replace( 'Edg', 'Edge', $m[0] ) : ( $r->label ? mb_substr( $r->label, 0, 40 ) : '—' ) ); ?></td>
							<td><?php echo esc_html( mysql2date( 'M j, Y', $r->created_at ) ); ?></td>
							<td><?php echo esc_html( $r->last_used ? human_time_diff( strtotime( $r->last_used ), current_time( 'timestamp' ) ) . ' ago' : 'never' ); ?></td>
							<td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0"><input type="hidden" name="action" value="sawa_lens_revoke"><input type="hidden" name="id" value="<?php echo esc_attr( $r->id ); ?>"><?php wp_nonce_field( 'sawa_lens_revoke' ); ?><button class="button-link-delete" type="submit">Remove</button></form></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<?php if ( $usage ) : ?>
				<h2>AI usage</h2>
				<table class="widefat striped" style="max-width:520px">
					<thead><tr><th>Month</th><th>Explanations</th><th>Input tokens</th><th>Output tokens</th></tr></thead>
					<tbody>
					<?php foreach ( array_reverse( $usage, true ) as $m => $u ) : ?>
						<tr><td><?php echo esc_html( $m ); ?></td><td><?php echo esc_html( number_format( $u['calls'] ) ); ?></td><td><?php echo esc_html( number_format( $u['input'] ) ); ?></td><td><?php echo esc_html( number_format( $u['output'] ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
