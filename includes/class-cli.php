<?php
/**
 * WP-CLI: refresh or clear the cached gig lists.
 *
 * @package AMHDB_Gigs
 */

defined( 'ABSPATH' ) || exit;

class AMHDB_Gigs_CLI {

	/**
	 * Fetch every gig list the site has shown again from AMHDB, now.
	 *
	 * ## EXAMPLES
	 *
	 *     wp amhdb-gigs refresh
	 */
	public function refresh(): void {
		$total = count( AMHDB_Gigs_Api::known_queries() );
		$ok    = AMHDB_Gigs_Api::refresh_all();
		if ( $ok < $total ) {
			WP_CLI::warning( sprintf( '%d of %d lists could not be refreshed; their last good copies are kept.', $total - $ok, $total ) );
		}
		WP_CLI::success( sprintf( 'Refreshed %d of %d gig lists.', $ok, $total ) );
	}

	/**
	 * Forget every cached gig list, including the last good copies. Each list
	 * is fetched again the next time it's viewed.
	 *
	 * ## EXAMPLES
	 *
	 *     wp amhdb-gigs clear
	 */
	public function clear(): void {
		AMHDB_Gigs_Api::clear();
		WP_CLI::success( 'Cleared the cached gig lists.' );
	}
}
