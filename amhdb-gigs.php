<?php
/**
 * Plugin Name:       AMHDB Gigs
 * Plugin URI:        https://github.com/Gibbomeister/amhdb-gigs
 * Description:       Gig lists and story facts panels from the Australian Music Database, rendered into the page with [amhdb_gigs] and [amhdb_facts]. Replaces GigPress on australianmusichistory.com.
 * Version:           1.6.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            My Business Engine
 * Author URI:        https://mybusinessengine.com/
 * License:           GPL-2.0-or-later
 * Text Domain:       amhdb-gigs
 *
 * @package AMHDB_Gigs
 */

defined( 'ABSPATH' ) || exit;

define( 'AMHDB_GIGS_VERSION', '1.6.0' );
define( 'AMHDB_GIGS_FILE', __FILE__ );
define( 'AMHDB_GIGS_PATH', plugin_dir_path( __FILE__ ) );

/**
 * The AMHDB gigs endpoint. Define it in wp-config.php to point a test site at a
 * local or staging AMHDB.
 */
if ( ! defined( 'AMHDB_GIGS_API' ) ) {
	define( 'AMHDB_GIGS_API', 'https://australianmusicdatabase.com/api/v1/gigs' );
}

/** Where installs look for new versions: owner/repo of the public GitHub repository. */
define( 'AMHDB_GIGS_REPO', 'Gibbomeister/amhdb-gigs' );

require_once AMHDB_GIGS_PATH . 'includes/class-renderer.php';
require_once AMHDB_GIGS_PATH . 'includes/class-api.php';
require_once AMHDB_GIGS_PATH . 'includes/class-shortcode.php';
require_once AMHDB_GIGS_PATH . 'includes/class-facts.php';
require_once AMHDB_GIGS_PATH . 'includes/class-admin.php';
require_once AMHDB_GIGS_PATH . 'includes/class-updater.php';

register_deactivation_hook( __FILE__, array( 'AMHDB_Gigs_Api', 'deactivate' ) );

AMHDB_Gigs_Api::hooks();
AMHDB_Gigs_Shortcode::hooks();
AMHDB_Gigs_Facts::hooks();
AMHDB_Gigs_Admin::hooks();
AMHDB_Gigs_Updater::hooks();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once AMHDB_GIGS_PATH . 'includes/class-cli.php';
	WP_CLI::add_command( 'amhdb-gigs', 'AMHDB_Gigs_CLI' );
}
