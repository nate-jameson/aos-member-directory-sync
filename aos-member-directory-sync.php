<?php
/**
 * Plugin Name: AOS Member Directory Sync
 * Description: Syncs CiviCRM memberships with the Directorist member directory — deactivates expired listings and creates AI-enriched drafts for new members.
 * Version: 1.3.1
 * Author: American Orthodontic Society
 * Text Domain: aos-member-directory-sync
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'AOS_MS_VERSION', '1.3.1' );
define( 'AOS_MS_PLUGIN_FILE', __FILE__ );
define( 'AOS_MS_DIR', plugin_dir_path( __FILE__ ) );
define( 'AOS_MS_URL', plugin_dir_url( __FILE__ ) );

require_once AOS_MS_DIR . 'includes/class-settings.php';
require_once AOS_MS_DIR . 'includes/class-civicrm.php';
require_once AOS_MS_DIR . 'includes/class-matcher.php';
require_once AOS_MS_DIR . 'includes/class-listing-creator.php';
require_once AOS_MS_DIR . 'includes/class-gemini.php';
require_once AOS_MS_DIR . 'includes/class-admin.php';
require_once AOS_MS_DIR . 'includes/class-enrich-metabox.php';

if ( is_admin() ) {
    AOS_MS_Enrich_Metabox::init();
}

function aos_ms_init() {
    new AOS_MS_Admin();
}
add_action( 'plugins_loaded', 'aos_ms_init' );
