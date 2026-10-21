<?php
/**
 * Plugin Name:       Sawa Lens
 * Description:       Server side of Language Lens – Sawa English: sign-in for the browser extension, sync of known words and saved sentences, AI explanations in Arabic, daily progress for the Sawa Tracker, and the Language Lens web app (skill tree, practice, writing checker) at /lens/.
 * Version:           0.12.1
 * Requires PHP:      7.4
 * Author:            Sawa English
 * License:           GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SAWA_LENS_VER', '0.12.1' );
define( 'SAWA_LENS_DB', 7 );
define( 'SAWA_LENS_URL', plugin_dir_url( __FILE__ ) );
define( 'SAWA_LENS_DIR', plugin_dir_path( __FILE__ ) );

require_once SAWA_LENS_DIR . 'includes/class-store.php';
require_once SAWA_LENS_DIR . 'includes/class-auth.php';
require_once SAWA_LENS_DIR . 'includes/class-ai.php';
require_once SAWA_LENS_DIR . 'includes/class-skills.php';
require_once SAWA_LENS_DIR . 'includes/class-review.php';
require_once SAWA_LENS_DIR . 'includes/class-activity.php';
require_once SAWA_LENS_DIR . 'includes/class-lessons.php';
require_once SAWA_LENS_DIR . 'includes/class-images.php';
require_once SAWA_LENS_DIR . 'includes/class-placement.php';
require_once SAWA_LENS_DIR . 'includes/class-placement-web.php';
require_once SAWA_LENS_DIR . 'includes/class-app.php';
require_once SAWA_LENS_DIR . 'includes/class-chat.php';
require_once SAWA_LENS_DIR . 'includes/class-rest.php';
if ( is_admin() ) {
	require_once SAWA_LENS_DIR . 'includes/class-admin.php';
	Sawa_Lens_Admin::init();
	require_once SAWA_LENS_DIR . 'includes/class-lessons-admin.php';
	Sawa_Lens_Lessons_Admin::init();
	require_once SAWA_LENS_DIR . 'includes/class-placement-admin.php';
	Sawa_Lens_Placement_Admin::init();
}

Sawa_Lens_Auth::init();
Sawa_Lens_Rest::init();
Sawa_Lens_App::init();
Sawa_Lens_Chat::init();
Sawa_Lens_Placement_Web::init();

register_activation_hook( __FILE__, array( 'Sawa_Lens_Store', 'install' ) );
add_action(
	'plugins_loaded',
	function () {
		if ( (int) get_option( 'sawa_lens_db' ) !== SAWA_LENS_DB ) {
			Sawa_Lens_Store::install();
		}
	}
);
