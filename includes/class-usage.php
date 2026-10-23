<?php
/**
 * Weekly real usage (admin only): how many different students used each part of Sawa English per week.
 *
 * Counts people, not events, and never what they read or wrote. Sources:
 * - Language Lens: the extension signed in that day (a daily mark), or a word saved / marked (words.client_ts).
 * - Review: at least one card answered (revlog.day).
 * - The app: /app opened that day (a daily mark).
 * - Silk Road: the game synced that day (a daily mark written by Sawa Tracker).
 * The daily marks are user meta "sawa_seen_<kind>": up to 70 days as "Ymd" numbers, newest first, written at most once a day.
 * They start with Sawa Lens 0.30.0 (2026-10-23), so earlier weeks show only words and reviews.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sawa_Lens_Usage {

	const PAGE  = 'sawa-lens-usage';
	const WEEKS = 8;
	const KINDS = array( 'lens' => 'Used Language Lens', 'review' => 'Did a Review', 'app' => 'Opened the app', 'game' => 'Played the Silk Road' );

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 41 );
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
	}

	/** Today's mark for this student (once a day, one small user meta write). */
	public static function mark( $uid, $kind ) {
		$uid = (int) $uid;
		if ( ! $uid || ! isset( self::KINDS[ $kind ] ) || 'review' === $kind ) {
			return;
		}
		$today = (int) current_time( 'Ymd' );
		$days  = array_filter( array_map( 'intval', explode( ' ', (string) get_user_meta( $uid, 'sawa_seen_' . $kind, true ) ) ) );
		if ( $days && reset( $days ) === $today ) {
			return;
		}
		array_unshift( $days, $today );
		update_user_meta( $uid, 'sawa_seen_' . $kind, implode( ' ', array_slice( $days, 0, 70 ) ) );
	}

	/** The last 8 ISO weeks, newest first: [{week: "2026-W43", from: "2026-10-19", counts: {lens, review, app, game}}], plus account totals. */
	public static function report() {
		global $wpdb;
		$admins = array_map( 'intval', get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) );
		$mon    = strtotime( 'monday this week', current_time( 'timestamp' ) );
		$weeks  = array();
		for ( $i = 0; $i < self::WEEKS; $i++ ) {
			$from              = strtotime( '-' . $i . ' week', $mon );
			$weeks[ date( 'o-\WW', $from ) ] = array( 'from' => date( 'Y-m-d', $from ), 'users' => array_fill_keys( array_keys( self::KINDS ), array() ) );
		}
		$start = end( $weeks )['from'];
		$put   = function ( $kind, $uid, $ts ) use ( &$weeks, $admins ) {
			$k = date( 'o-\WW', $ts );
			if ( isset( $weeks[ $k ] ) && ! in_array( (int) $uid, $admins, true ) ) {
				$weeks[ $k ]['users'][ $kind ][ (int) $uid ] = 1;
			}
		};
		// Daily marks (lens, app, game).
		$rows = $wpdb->get_results( "SELECT user_id, meta_key, meta_value FROM $wpdb->usermeta WHERE meta_key IN ('sawa_seen_lens','sawa_seen_app','sawa_seen_game')" ); // phpcs:ignore
		foreach ( $rows as $r ) {
			foreach ( explode( ' ', $r->meta_value ) as $d ) {
				$ts = strtotime( (string) $d );
				if ( $ts ) {
					$put( substr( $r->meta_key, 10 ), $r->user_id, $ts );
				}
			}
		}
		// Words saved or marked (Language Lens, also from before the marks existed).
		$w    = Sawa_Lens_Store::t( 'words' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT user_id, FROM_UNIXTIME(FLOOR(client_ts/1000), '%%Y-%%m-%%d') AS d FROM $w WHERE client_ts >= %d", ( strtotime( $start ) - DAY_IN_SECONDS ) * 1000 ) ); // phpcs:ignore
		foreach ( $rows as $r ) {
			$put( 'lens', $r->user_id, strtotime( $r->d ) );
		}
		// Reviews.
		$rl   = Sawa_Lens_Store::t( 'revlog' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT user_id, day FROM $rl WHERE day >= %s", $start ) ); // phpcs:ignore
		foreach ( $rows as $r ) {
			$put( 'review', $r->user_id, strtotime( $r->day ) );
		}
		$out = array();
		foreach ( $weeks as $k => $wk ) {
			$out[] = array( 'week' => $k, 'from' => $wk['from'], 'counts' => array_map( 'count', $wk['users'] ), 'ids' => array_map( 'array_keys', $wk['users'] ) );
		}
		// Accounts: active students (approved, not admins), members (paid) vs free.
		$ids  = array_map( 'intval', $wpdb->get_col( "SELECT user_id FROM $wpdb->usermeta WHERE meta_key = 'sawa_status' AND meta_value = 'approved'" ) ); // phpcs:ignore
		$ids  = array_diff( $ids, $admins );
		$free = 0;
		foreach ( $ids as $id ) {
			$free += 'free' === Sawa_Lens_Tiers::tier( $id ) ? 1 : 0;
		}
		return array(
			'weeks'    => $out,
			'accounts' => array( 'total' => count( $ids ), 'members' => count( $ids ) - $free, 'free' => $free ),
			'marksSince' => '2026-10-23',
		);
	}

	/** GET /wp-json/sawa-lens/v1/admin/usage-weekly (admins only): the same numbers as JSON, without the student lists. */
	public static function rest() {
		register_rest_route(
			'sawa-lens/v1',
			'/admin/usage-weekly',
			array(
				'methods'             => 'GET',
				'callback'            => function () {
					$r = self::report();
					foreach ( $r['weeks'] as &$wk ) {
						unset( $wk['ids'] );
					}
					return rest_ensure_response( $r );
				},
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
		// The quiet-member lists as JSON (admins only), for the board's quiet-list run.
		register_rest_route(
			'sawa-lens/v1',
			'/admin/quiet',
			array(
				'methods'             => 'GET',
				'callback'            => function () {
					return rest_ensure_response( array( 'date' => current_time( 'Y-m-d' ), 'days' => self::QUIET_DAYS ) + self::quiet() );
				},
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	public static function menu() {
		add_submenu_page( 'sawa-lens-quizzes', 'Weekly usage', 'Weekly usage', 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
		add_submenu_page( 'sawa-lens-quizzes', 'Quiet members', 'Quiet members', 'manage_options', self::QUIET_PAGE, array( __CLASS__, 'quiet_page' ) );
	}

	/* ------------------------------------------------------------------ quiet members (decided 2026-10-23) */

	const QUIET_PAGE = 'sawa-lens-quiet';
	/** Days without any activity before someone shows up: paying members (checked daily), free accounts (checked weekly). */
	const QUIET_DAYS = array( 'member' => 2, 'free' => 5 );

	/**
	 * Each active student's last day with any activity on the site: Language Lens, the app, the Silk Road (daily marks),
	 * a saved word, a Review, a tracker log or a chat message. [Ymd, where it came from] per user id; missing = never.
	 */
	public static function last_active() {
		global $wpdb;
		$last = array();
		$put  = function ( $uid, $day, $from ) use ( &$last ) {
			$uid = (int) $uid;
			$day = (string) $day;
			if ( $day && ( ! isset( $last[ $uid ] ) || $day > $last[ $uid ][0] ) ) {
				$last[ $uid ] = array( $day, $from );
			}
		};
		foreach ( (array) $wpdb->get_results( "SELECT user_id, meta_key, meta_value FROM $wpdb->usermeta WHERE meta_key IN ('sawa_seen_lens','sawa_seen_app','sawa_seen_game')" ) as $r ) { // phpcs:ignore
			$put( $r->user_id, strtok( (string) $r->meta_value, ' ' ), substr( $r->meta_key, 10 ) ); // newest first
		}
		foreach ( (array) $wpdb->get_results( 'SELECT user_id, MAX(client_ts) AS ts FROM ' . Sawa_Lens_Store::t( 'words' ) . ' GROUP BY user_id' ) as $r ) { // phpcs:ignore
			if ( (int) $r->ts > 0 ) {
				$put( $r->user_id, wp_date( 'Ymd', (int) floor( $r->ts / 1000 ) ), 'word' );
			}
		}
		foreach ( (array) $wpdb->get_results( 'SELECT user_id, MAX(day) AS d FROM ' . Sawa_Lens_Store::t( 'revlog' ) . ' GROUP BY user_id' ) as $r ) { // phpcs:ignore
			$put( $r->user_id, str_replace( '-', '', (string) $r->d ), 'review' );
		}
		// Sawa Tracker: study logs and chat messages (when the tracker is active).
		if ( class_exists( 'Sawa_Tracker_Game' ) ) {
			foreach ( (array) $wpdb->get_results( 'SELECT user_id, MAX(day) AS d FROM ' . Sawa_Tracker_Game::table( 'logs' ) . ' GROUP BY user_id' ) as $r ) { // phpcs:ignore
				$put( $r->user_id, str_replace( '-', '', (string) $r->d ), 'log' );
			}
		}
		if ( class_exists( 'Sawa_Tracker_Social' ) ) {
			$m = Sawa_Tracker_Social::t( 'messages' );
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $m ) ) === $m ) {
				foreach ( (array) $wpdb->get_results( "SELECT user_id, MAX(created_at) AS d FROM $m WHERE deleted = 0 GROUP BY user_id" ) as $r ) { // phpcs:ignore
					$put( $r->user_id, substr( str_replace( '-', '', (string) $r->d ), 0, 8 ), 'chat' );
				}
			}
		}
		return $last;
	}

	/** Who has gone quiet: {member: [...], free: [...]}, each {id, name, email, joined, last, from, days} with the quietest first. $all = everyone, quiet or not. */
	public static function quiet( $all = false ) {
		global $wpdb;
		$admins = array_map( 'intval', get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) );
		$ids    = array_diff( array_map( 'intval', $wpdb->get_col( "SELECT user_id FROM $wpdb->usermeta WHERE meta_key = 'sawa_status' AND meta_value = 'approved'" ) ), $admins ); // phpcs:ignore
		$last   = self::last_active();
		$today  = new DateTime( current_time( 'Y-m-d' ) );
		$out    = array( 'member' => array(), 'free' => array() );
		foreach ( $ids as $id ) {
			$u = get_userdata( $id );
			if ( ! $u ) {
				continue;
			}
			$tier   = 'free' === Sawa_Lens_Tiers::tier( $id ) ? 'free' : 'member';
			$joined = substr( (string) $u->user_registered, 0, 10 );
			$ref    = isset( $last[ $id ] ) ? DateTime::createFromFormat( '!Ymd', $last[ $id ][0] ) : new DateTime( $joined );
			$days   = $ref ? (int) $ref->diff( $today )->format( '%r%a' ) : 999;
			if ( ! $all && $days <= self::QUIET_DAYS[ $tier ] ) {
				continue; // active recently, or joined too recently to count as quiet
			}
			$out[ $tier ][] = array(
				'id'     => $id,
				'name'   => $u->display_name,
				'email'  => $u->user_email,
				'joined' => $joined,
				'last'   => isset( $last[ $id ] ) ? $ref->format( 'Y-m-d' ) : '',
				'from'   => isset( $last[ $id ] ) ? $last[ $id ][1] : '',
				'days'   => $days,
			);
		}
		foreach ( $out as &$list ) {
			usort( $list, function ( $a, $b ) { return $b['days'] - $a['days']; } );
		}
		unset( $list );
		return $out;
	}

	public static function quiet_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$all    = ! empty( $_GET['all'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$q      = self::quiet( $all );
		$titles = array(
			'member' => array( 'Paying members', 'check daily', 'more than ' . self::QUIET_DAYS['member'] . ' days with no activity' ),
			'free'   => array( 'Free accounts', 'check weekly', 'more than ' . self::QUIET_DAYS['free'] . ' days with no activity' ),
		);
		?>
		<div class="wrap">
			<h1>Quiet members</h1>
			<p>Students who have gone quiet, so you can send them a friendly message. Not counting admins or people who joined in the last few days. Today: <?php echo esc_html( current_time( 'Y-m-d' ) ); ?>. <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::QUIET_PAGE . ( $all ? '' : '&all=1' ) ) ); ?>"><?php echo $all ? 'Show only quiet people' : 'Show everyone'; ?></a></p>
			<?php foreach ( $titles as $tier => $t ) : ?>
				<h2><?php echo esc_html( $t[0] ); ?> (<?php echo count( $q[ $tier ] ); ?>) <small style="font-weight:normal">· <?php echo esc_html( $t[1] . ' · ' . $t[2] ); ?></small></h2>
				<?php if ( ! $q[ $tier ] ) : ?>
					<p><?php echo $all ? 'Nobody here.' : 'Nobody is quiet right now. 🎉'; ?></p>
				<?php else : ?>
					<table class="widefat striped" style="max-width:900px">
						<thead><tr><th>Name</th><th>Email</th><th>Last active</th><th>Doing</th><th>Quiet for</th><th>Joined</th></tr></thead>
						<tbody>
						<?php foreach ( $q[ $tier ] as $r ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( get_edit_user_link( $r['id'] ) ); ?>"><?php echo esc_html( $r['name'] ); ?></a></td>
								<td><a href="mailto:<?php echo esc_attr( $r['email'] ); ?>"><?php echo esc_html( $r['email'] ); ?></a></td>
								<td><?php echo esc_html( $r['last'] ? $r['last'] : 'never' ); ?></td>
								<td><?php echo esc_html( $r['from'] ); ?></td>
								<td><b><?php echo $r['days'] >= 999 ? '—' : (int) $r['days'] . ' days'; ?></b></td>
								<td><?php echo esc_html( $r['joined'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endforeach; ?>
			<p class="description">"Activity" = Language Lens used, the app opened, the Silk Road played, a word saved, a Review, a study log or a chat message on this site. Posts in Skool are not counted here (Skool has no way to share them). The extension, app and game marks start on 2026-10-23, so before that only words, reviews, logs and chat count. Paying vs free comes from Sawa Lens → Free vs Member. JSON for tools: <code>/wp-json/sawa-lens/v1/admin/quiet</code> (admins only).</p>
		</div>
		<?php
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$r    = self::report();
		$show = isset( $_GET['week'] ) ? sanitize_text_field( wp_unslash( $_GET['week'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap">
			<h1>Weekly usage</h1>
			<p>Different students per week (Monday to Sunday), not counting admins. Accounts now: <b><?php echo (int) $r['accounts']['total']; ?></b> students · <b><?php echo (int) $r['accounts']['members']; ?></b> members (paid) · <b><?php echo (int) $r['accounts']['free']; ?></b> free.</p>
			<table class="widefat striped" style="max-width:900px">
				<thead><tr><th>Week</th><?php foreach ( self::KINDS as $label ) : ?><th><?php echo esc_html( $label ); ?></th><?php endforeach; ?><th></th></tr></thead>
				<tbody>
				<?php foreach ( $r['weeks'] as $wk ) : ?>
					<tr>
						<td><b><?php echo esc_html( $wk['week'] ); ?></b><br><small><?php echo esc_html( $wk['from'] ); ?></small></td>
						<?php foreach ( array_keys( self::KINDS ) as $k ) : ?><td style="font-size:18px"><?php echo (int) $wk['counts'][ $k ]; ?></td><?php endforeach; ?>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&week=' . $wk['week'] ) ); ?>">Who</a></td>
					</tr>
					<?php if ( $show === $wk['week'] ) : ?>
						<tr><td colspan="6">
							<?php foreach ( self::KINDS as $k => $label ) : ?>
								<p><b><?php echo esc_html( $label ); ?>:</b> <?php echo esc_html( implode( ', ', array_map( function ( $id ) { $u = get_userdata( $id ); return $u ? $u->display_name : '#' . $id; }, $wk['ids'][ $k ] ) ) ?: '—' ); ?></p>
							<?php endforeach; ?>
						</td></tr>
					<?php endif; ?>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">"Used Language Lens" = the extension was used that day, or a word was saved or marked. "Opened the app", the extension part of Language Lens, and "Played the Silk Road" are counted from 2026-10-23 (Sawa Lens 0.30.0, Sawa Tracker 2.20.0); earlier weeks show only saved words and reviews. JSON for tools: <code>/wp-json/sawa-lens/v1/admin/usage-weekly</code> (admins only).</p>
		</div>
		<?php
	}
}
