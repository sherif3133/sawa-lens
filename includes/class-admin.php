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
		$gem = isset( $_POST['gemini_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['gemini_key'] ) ) ) : '';
		if ( '' !== $gem && false === strpos( $gem, '•' ) ) {
			$s['gemini_key'] = $gem;
		}
		if ( ! empty( $_POST['remove_gemini'] ) ) {
			$s['gemini_key'] = '';
		}
		$s['songs_beta']   = isset( $_POST['songs_beta'] ) ? sanitize_textarea_field( wp_unslash( $_POST['songs_beta'] ) ) : ( $s['songs_beta'] ?? '' );
		$s['songs_budget'] = isset( $_POST['songs_budget'] ) ? max( 0, (float) $_POST['songs_budget'] ) : ( $s['songs_budget'] ?? 10 );
		$s['songs_price']  = isset( $_POST['songs_price'] ) ? max( 0, (float) $_POST['songs_price'] ) : ( $s['songs_price'] ?? 0.08 );
		$s['model']       = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : $s['model'];
		$s['daily_limit'] = isset( $_POST['daily_limit'] ) ? max( 0, (int) $_POST['daily_limit'] ) : $s['daily_limit'];
		$s['store_url']   = isset( $_POST['store_url'] ) ? esc_url_raw( wp_unslash( $_POST['store_url'] ) ) : $s['store_url'];
		update_option( Sawa_Lens_AI::OPT, $s, false );
		update_option( 'sawa_lens_app_open', empty( $_POST['app_open'] ) ? 0 : 1, true );
		update_option( 'sawa_lens_chat_auto', empty( $_POST['chat_auto'] ) ? '0' : '1', false );
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
						<th>Sawa App</th>
						<td>
							<label><input type="checkbox" name="app_open" value="1" <?php checked( Sawa_Lens_App::is_open() ); ?>> <b>Open the Sawa App to students</b></label>
							<p class="description">
								<?php if ( Sawa_Lens_App::is_open() ) : ?>
									Open: approved students can use <a href="<?php echo esc_url( home_url( '/app/' ) ); ?>">the app</a>.
								<?php else : ?>
									Closed: approved students see "You're on the list! Please wait for Sherif to publish the Sawa App." You (admins) can still use <a href="<?php echo esc_url( home_url( '/app/' ) ); ?>">the app</a> to test it.
								<?php endif; ?>
							</p>
						</td>
					</tr>
					<tr>
						<th>Chat English</th>
						<td>
							<label><input type="checkbox" name="chat_auto" value="1" <?php checked( '0' !== (string) get_option( 'sawa_lens_chat_auto', '1' ) ); ?>> <b>Check students' chat English automatically</b></label>
							<p class="description">Once a day per student, all their English chat messages are checked in one AI call (at most 150 checks a day for the whole site). Mistakes feed the skill tree and the progress trends; the student gets a private notification. Nothing is shown in the chat.</p>
						</td>
					</tr>
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
					<?php
					$gk    = (string) ( $s['gemini_key'] ?? '' );
					$mgk   = $gk ? substr( $gk, 0, 6 ) . str_repeat( '•', 12 ) . substr( $gk, -4 ) : '';
					$spent = 0;
					if ( class_exists( 'Sawa_Lens_Songs' ) ) {
						global $wpdb;
						$spent = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(cost),0) FROM ' . Sawa_Lens_Store::t( 'songs' ) . ' WHERE created_at >= %s', wp_date( 'Y-m-01 00:00:00' ) ) ); // phpcs:ignore
					}
					?>
					<tr>
						<th><label for="gemini_key">🎵 My songs (beta): Google Gemini key</label></th>
						<td>
							<input type="password" id="gemini_key" name="gemini_key" class="regular-text" value="<?php echo esc_attr( $mgk ); ?>" autocomplete="off" placeholder="AIza…">
							<?php if ( $gk ) : ?>
								<label style="margin-inline-start:12px"><input type="checkbox" name="remove_gemini" value="1"> Remove key</label>
							<?php endif; ?>
							<p class="description">Turns on "My songs": Claude writes a short song from a student's new words and Google's Lyria (lyria-3.5) sings it. Create a key at aistudio.google.com → Get API key (billing must be on for Lyria). It stays on this server.</p>
						</td>
					</tr>
					<tr>
						<th><label for="songs_beta">Song beta testers</label></th>
						<td><textarea id="songs_beta" name="songs_beta" rows="2" class="large-text" placeholder="12, student@example.com, … or all"><?php echo esc_textarea( (string) ( $s['songs_beta'] ?? '' ) ); ?></textarea><p class="description">User IDs or emails, separated by commas; "all" opens it to everyone. Admins always have it. One song a day per student, after 10 new Learning words or saved sentences.</p></td>
					</tr>
					<tr>
						<th><label for="songs_budget">Song budget</label></th>
						<td>$<input id="songs_budget" name="songs_budget" type="number" step="0.01" min="0" class="small-text" value="<?php echo esc_attr( (string) ( $s['songs_budget'] ?? 10 ) ); ?>"> a month, at $<input name="songs_price" type="number" step="0.001" min="0" class="small-text" value="<?php echo esc_attr( (string) ( $s['songs_price'] ?? 0.08 ) ); ?>"> a song · spent this month: <b>$<?php echo esc_html( number_format( $spent, 2 ) ); ?></b><p class="description">New songs pause when the month's budget is used up. Check the current Lyria price on Google's pricing page.</p></td>
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
