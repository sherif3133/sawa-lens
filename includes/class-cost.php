<?php
/**
 * AI cost meter (admin only). Every paid AI call is logged to one table: time, feature, student, provider,
 * model, tokens and an estimated $. Prices are an editable table. An optional site-wide monthly budget
 * stops the non-essential AI features when it is reached (empty = no limit, nothing changes).
 *
 * Logging never throws and never blocks a student request: any failure is swallowed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sawa_Lens_Cost {

	const PAGE        = 'sawa-lens-cost';
	const OPT_PRICES  = 'sawa_lens_prices';
	const OPT_BUDGET  = 'sawa_lens_month_budget';
	const OPT_MONTH   = 'sawa_lens_cost_month'; // array( 'Y-m', dollars ): a cheap running total for the budget check

	/** Features that keep working when the budget is reached (the core word meanings and translations). */
	const ESSENTIAL = array( 'explain', 'translate', 'translate_lines', 'to_english', 'to_english_many', 'chat_translate' );

	/** Student the next AI call is for (set where the per-student limit is checked). */
	private static $uid = 0;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 40 );
		add_action( 'admin_post_sawa_lens_cost_save', array( __CLASS__, 'save' ) );
	}

	public static function table() {
		return Sawa_Lens_Store::t( 'cost' );
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			feature varchar(40) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			provider varchar(20) NOT NULL DEFAULT '',
			model varchar(60) NOT NULL DEFAULT '',
			tokens_in int(10) unsigned NOT NULL DEFAULT 0,
			tokens_out int(10) unsigned NOT NULL DEFAULT 0,
			cost decimal(10,5) NOT NULL DEFAULT 0,
			ms int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY user_time (user_id,created_at)
			) " . $wpdb->get_charset_collate() . ';'
		);
	}

	/* ---------------------------------------------------------------- prices */

	/** Dollars per million tokens (in, out) per model, or per call for flat-priced services. Editable in the admin page. */
	public static function default_prices() {
		return array(
			'claude-haiku-5-5'        => array( 'in' => 1.0, 'out' => 5.0 ),
			'claude-sonnet-5-5'       => array( 'in' => 3.0, 'out' => 15.0 ),
			'claude-opus-5-5'         => array( 'in' => 5.0, 'out' => 25.0 ),
			'default'                 => array( 'in' => 3.0, 'out' => 15.0 ), // any model not listed
			// Flat, dollars per call.
			'gpt-4o-mini-transcribe'  => array( 'call' => 0.003 ),
			'gpt-image-1'             => array( 'call' => 0.011 ),
			'gpt-4o-mini-tts'         => array( 'call' => 0.003 ),
		);
	}

	public static function prices() {
		$saved = get_option( self::OPT_PRICES, array() );
		return is_array( $saved ) && $saved ? $saved + self::default_prices() : self::default_prices();
	}

	public static function estimate( $model, $in, $out ) {
		$p = self::prices();
		$p = $p[ $model ] ?? $p['default'] ?? array( 'in' => 3.0, 'out' => 15.0 );
		if ( isset( $p['call'] ) ) {
			return (float) $p['call'];
		}
		return ( $in * (float) ( $p['in'] ?? 0 ) + $out * (float) ( $p['out'] ?? 0 ) ) / 1000000;
	}

	/* --------------------------------------------------------------- logging */

	public static function set_user( $uid ) {
		self::$uid = (int) $uid;
	}

	/** The feature name = the public method that asked for the AI call. */
	public static function caller( $skip = 2 ) {
		try {
			$bt = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, $skip + 2 ); // phpcs:ignore
			return isset( $bt[ $skip ]['function'] ) ? (string) $bt[ $skip ]['function'] : 'other';
		} catch ( \Throwable $e ) {
			return 'other';
		}
	}

	/** Adds one row. $cost null = estimate from the price table. Never throws. */
	public static function log( $feature, $provider, $model, $in = 0, $out = 0, $cost = null, $ms = 0, $uid = null ) {
		try {
			global $wpdb;
			$cost = null === $cost ? self::estimate( $model, (int) $in, (int) $out ) : (float) $cost;
			$wpdb->suppress_errors( true );
			$wpdb->insert( // phpcs:ignore
				self::table(),
				array(
					'created_at' => current_time( 'mysql' ),
					'feature'    => mb_substr( (string) $feature, 0, 40 ),
					'user_id'    => null === $uid ? self::$uid : (int) $uid,
					'provider'   => mb_substr( (string) $provider, 0, 20 ),
					'model'      => mb_substr( (string) $model, 0, 60 ),
					'tokens_in'  => max( 0, (int) $in ),
					'tokens_out' => max( 0, (int) $out ),
					'cost'       => round( $cost, 5 ),
					'ms'         => max( 0, (int) $ms ),
				)
			);
			$wpdb->suppress_errors( false );
			$m   = wp_date( 'Y-m' );
			$run = (array) get_option( self::OPT_MONTH, array() );
			$run = ( $run[0] ?? '' ) === $m ? $run : array( $m, 0 );
			update_option( self::OPT_MONTH, array( $m, (float) $run[1] + $cost ), false );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/* ---------------------------------------------------------------- budget */

	/** The monthly budget in dollars, or 0 for none. */
	public static function budget() {
		return max( 0, (float) get_option( self::OPT_BUDGET, 0 ) );
	}

	/** Spent so far this month (cheap: one option read). */
	public static function month_spent() {
		$run = (array) get_option( self::OPT_MONTH, array() );
		return ( $run[0] ?? '' ) === wp_date( 'Y-m' ) ? (float) ( $run[1] ?? 0 ) : 0.0;
	}

	/** True when this feature must stop because the monthly budget is used up. */
	public static function blocked( $feature ) {
		$b = self::budget();
		if ( $b <= 0 || in_array( $feature, self::ESSENTIAL, true ) ) {
			return false;
		}
		return self::month_spent() >= $b;
	}

	/* ----------------------------------------------------------------- admin */

	public static function menu() {
		add_submenu_page( 'sawa-lens-quizzes', 'AI cost', 'AI cost', 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sawa_lens_cost_save' ) ) {
			wp_die( 'Not allowed.' );
		}
		$b = isset( $_POST['budget'] ) ? trim( (string) wp_unslash( $_POST['budget'] ) ) : '';
		update_option( self::OPT_BUDGET, '' === $b ? 0 : max( 0, (float) $b ), false );
		$prices = array();
		foreach ( (array) ( $_POST['price'] ?? array() ) as $model => $p ) {
			$model = sanitize_text_field( wp_unslash( $model ) );
			$p     = (array) $p;
			if ( '' === $model ) {
				continue;
			}
			$prices[ $model ] = isset( $p['call'] ) ? array( 'call' => max( 0, (float) $p['call'] ) ) : array( 'in' => max( 0, (float) ( $p['in'] ?? 0 ) ), 'out' => max( 0, (float) ( $p['out'] ?? 0 ) ) );
		}
		$new = sanitize_text_field( wp_unslash( $_POST['new_model'] ?? '' ) );
		if ( '' !== $new ) {
			$prices[ $new ] = array( 'in' => max( 0, (float) ( $_POST['new_in'] ?? 0 ) ), 'out' => max( 0, (float) ( $_POST['new_out'] ?? 0 ) ) );
		}
		if ( $prices ) {
			update_option( self::OPT_PRICES, $prices, false );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&saved=1' ) );
		exit;
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		global $wpdb;
		$t     = self::table();
		$month = isset( $_GET['month'] ) && preg_match( '/^\d{4}-\d{2}$/', (string) $_GET['month'] ) ? (string) $_GET['month'] : wp_date( 'Y-m' ); // phpcs:ignore
		$from  = $month . '-01 00:00:00';
		$to    = gmdate( 'Y-m-d 00:00:00', strtotime( $month . '-01 +1 month' ) );
		$q     = function ( $sql, ...$args ) use ( $wpdb ) {
			return $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) ); // phpcs:ignore
		};
		$total    = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(cost),0) FROM $t WHERE created_at >= %s AND created_at < %s", $from, $to ) ); // phpcs:ignore
		$calls    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE created_at >= %s AND created_at < %s", $from, $to ) ); // phpcs:ignore
		$students = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM $t WHERE user_id > 0 AND created_at >= %s AND created_at < %s", $from, $to ) ); // phpcs:ignore
		$feat     = $q( "SELECT feature, COUNT(*) n, SUM(tokens_in) tin, SUM(tokens_out) tout, SUM(cost) c FROM $t WHERE created_at >= %s AND created_at < %s GROUP BY feature ORDER BY c DESC", $from, $to ); // phpcs:ignore
		$top      = $q( "SELECT user_id, COUNT(*) n, SUM(cost) c FROM $t WHERE user_id > 0 AND created_at >= %s AND created_at < %s GROUP BY user_id ORDER BY c DESC LIMIT 10", $from, $to ); // phpcs:ignore
		$days     = $q( "SELECT DATE(created_at) d, SUM(cost) c, COUNT(*) n FROM $t WHERE created_at >= %s AND created_at < %s GROUP BY d ORDER BY d", $from, $to ); // phpcs:ignore
		$months   = $wpdb->get_col( "SELECT DISTINCT DATE_FORMAT(created_at,'%Y-%m') FROM $t ORDER BY 1 DESC LIMIT 12" ); // phpcs:ignore
		$max      = 0.0;
		foreach ( $days as $d ) {
			$max = max( $max, (float) $d->c );
		}
		$budget = self::budget();
		?>
		<div class="wrap">
			<h1>AI cost</h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore ?>
				<div class="notice notice-success"><p>Saved.</p></div>
			<?php endif; ?>
			<p>Estimated from tokens × the price table below (not the real invoice). Only calls since this page was added are counted.
			<?php if ( $months ) : ?>
				Month:
				<?php foreach ( $months as $mo ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&month=' . $mo ) ); ?>"><?php echo $mo === $month ? '<b>' . esc_html( $mo ) . '</b>' : esc_html( $mo ); ?></a>
				<?php endforeach; ?>
			<?php endif; ?></p>
			<table class="widefat" style="max-width:720px"><tbody>
				<tr><th>Total <?php echo esc_html( $month ); ?></th><td><b>$<?php echo esc_html( number_format( $total, 2 ) ); ?></b> · <?php echo esc_html( $calls ); ?> AI calls</td></tr>
				<tr><th>Students who used AI</th><td><?php echo esc_html( $students ); ?></td></tr>
				<tr><th>$ per active student</th><td><b><?php echo $students ? '$' . esc_html( number_format( $total / $students, 2 ) ) : '—'; ?></b> <span class="description">(the membership is $29/month)</span></td></tr>
				<tr><th>Monthly budget</th><td><?php echo $budget > 0 ? '$' . esc_html( number_format( self::month_spent(), 2 ) ) . ' of $' . esc_html( number_format( $budget, 2 ) ) . ( self::month_spent() >= $budget ? ' — <b style="color:#b32d2e">reached: non-essential AI is paused</b>' : '' ) : 'none set'; ?></td></tr>
			</tbody></table>

			<h2>By feature</h2>
			<table class="widefat striped" style="max-width:720px"><thead><tr><th>Feature</th><th>Calls</th><th>Tokens in</th><th>Tokens out</th><th>$</th></tr></thead><tbody>
			<?php foreach ( $feat as $f ) : ?>
				<tr><td><?php echo esc_html( $f->feature ); ?><?php echo in_array( $f->feature, self::ESSENTIAL, true ) ? ' <span class="description">(essential)</span>' : ''; ?></td><td><?php echo esc_html( $f->n ); ?></td><td><?php echo esc_html( number_format( (int) $f->tin ) ); ?></td><td><?php echo esc_html( number_format( (int) $f->tout ) ); ?></td><td>$<?php echo esc_html( number_format( (float) $f->c, 3 ) ); ?></td></tr>
			<?php endforeach; ?>
			<?php if ( ! $feat ) : ?><tr><td colspan="5">Nothing logged this month yet.</td></tr><?php endif; ?>
			</tbody></table>

			<h2>Top 10 students by cost</h2>
			<table class="widefat striped" style="max-width:720px"><thead><tr><th>Student</th><th>Calls</th><th>$</th></tr></thead><tbody>
			<?php foreach ( $top as $r ) : $u = get_userdata( (int) $r->user_id ); ?>
				<tr><td><?php echo esc_html( $u ? $u->display_name . ' (' . $u->user_email . ')' : '#' . $r->user_id ); ?></td><td><?php echo esc_html( $r->n ); ?></td><td>$<?php echo esc_html( number_format( (float) $r->c, 3 ) ); ?></td></tr>
			<?php endforeach; ?>
			<?php if ( ! $top ) : ?><tr><td colspan="3">Nothing yet.</td></tr><?php endif; ?>
			</tbody></table>

			<h2>Per day</h2>
			<table class="widefat" style="max-width:720px"><tbody>
			<?php foreach ( $days as $d ) : ?>
				<tr><td style="width:100px"><?php echo esc_html( $d->d ); ?></td><td><div style="background:#2271b1;height:12px;width:<?php echo esc_attr( $max > 0 ? max( 1, round( 100 * (float) $d->c / $max ) ) : 0 ); ?>%"></div></td><td style="width:140px">$<?php echo esc_html( number_format( (float) $d->c, 3 ) ); ?> · <?php echo esc_html( $d->n ); ?> calls</td></tr>
			<?php endforeach; ?>
			<?php if ( ! $days ) : ?><tr><td>Nothing yet.</td></tr><?php endif; ?>
			</tbody></table>

			<h2>Budget and prices</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sawa_lens_cost_save">
				<?php wp_nonce_field( 'sawa_lens_cost_save' ); ?>
				<p><label><b>Site-wide monthly budget ($)</b> <input type="number" step="0.01" min="0" name="budget" value="<?php echo esc_attr( $budget > 0 ? $budget : '' ); ?>" placeholder="empty = no limit" style="width:120px"></label><br>
				<span class="description">When the month's estimated cost reaches this, the extra AI features (writing checker, practice sets, stories, songs, lessons, scans…) answer "limit reached". Word meanings, translations and chat translation keep working. Empty = never stops anything.</span></p>
				<table class="widefat" style="max-width:720px"><thead><tr><th>Model</th><th>$ per million tokens in</th><th>$ per million tokens out</th></tr></thead><tbody>
				<?php foreach ( self::prices() as $model => $p ) : ?>
					<tr><td><?php echo esc_html( $model ); ?></td>
					<?php if ( isset( $p['call'] ) ) : ?>
						<td colspan="2">$ per call <input type="number" step="0.0001" min="0" name="price[<?php echo esc_attr( $model ); ?>][call]" value="<?php echo esc_attr( $p['call'] ); ?>" style="width:100px"></td>
					<?php else : ?>
						<td><input type="number" step="0.01" min="0" name="price[<?php echo esc_attr( $model ); ?>][in]" value="<?php echo esc_attr( $p['in'] ?? 0 ); ?>" style="width:100px"></td>
						<td><input type="number" step="0.01" min="0" name="price[<?php echo esc_attr( $model ); ?>][out]" value="<?php echo esc_attr( $p['out'] ?? 0 ); ?>" style="width:100px"></td>
					<?php endif; ?>
					</tr>
				<?php endforeach; ?>
					<tr><td><input type="text" name="new_model" placeholder="add a model id" style="width:180px"></td><td><input type="number" step="0.01" min="0" name="new_in" style="width:100px"></td><td><input type="number" step="0.01" min="0" name="new_out" style="width:100px"></td></tr>
				</tbody></table>
				<p class="description">Starting prices are a guess from Anthropic's published rates: check them against your Anthropic and OpenAI billing pages.</p>
				<?php submit_button( 'Save' ); ?>
			</form>
		</div>
		<?php
	}
}
