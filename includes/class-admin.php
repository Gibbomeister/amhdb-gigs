<?php
/**
 * Tools → AMHDB Gigs: what's cached, and buttons to refresh or clear it.
 *
 * @package AMHDB_Gigs
 */

defined( 'ABSPATH' ) || exit;

class AMHDB_Gigs_Admin {

	private const PAGE = 'amhdb-gigs';

	public static function hooks(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_amhdb_gigs_refresh', array( __CLASS__, 'handle_refresh' ) );
		add_action( 'admin_post_amhdb_gigs_clear', array( __CLASS__, 'handle_clear' ) );
	}

	public static function menu(): void {
		add_management_page( __( 'AMHDB Gigs', 'amhdb-gigs' ), __( 'AMHDB Gigs', 'amhdb-gigs' ), 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
	}

	public static function page(): void {
		$queries = AMHDB_Gigs_Api::known_queries();
		$notice  = isset( $_GET['amhdb_gigs_done'] ) ? sanitize_key( wp_unslash( $_GET['amhdb_gigs_done'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AMHDB Gigs', 'amhdb-gigs' ); ?></h1>
			<?php if ( 'refreshed' === $notice ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Gig lists refreshed from AMHDB.', 'amhdb-gigs' ); ?></p></div>
			<?php elseif ( 'cleared' === $notice ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Cache cleared. Each list is fetched again on its next view.', 'amhdb-gigs' ); ?></p></div>
			<?php endif; ?>
			<p>
				<?php
				/* translators: %s: AMHDB API address */
				printf( esc_html__( 'Gig lists come from %s and are refreshed daily in the background. If AMHDB is unreachable, the last good copy is shown.', 'amhdb-gigs' ), '<code>' . esc_html( AMHDB_GIGS_API ) . '</code>' );
				?>
			</p>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'List (shortcode filters)', 'amhdb-gigs' ); ?></th><th><?php esc_html_e( 'Last fetched', 'amhdb-gigs' ); ?></th></tr></thead>
				<tbody>
				<?php if ( ! $queries ) : ?>
					<tr><td colspan="2"><?php esc_html_e( 'No lists shown yet.', 'amhdb-gigs' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $queries as $query ) : ?>
					<?php $fetched = AMHDB_Gigs_Api::fetched_at( $query ); ?>
					<tr>
						<td><code><?php echo esc_html( http_build_query( $query, '', ' ' ) ); ?></code></td>
						<td><?php echo $fetched ? esc_html( wp_date( 'j M Y, g:ia', $fetched ) ) : esc_html__( 'Never (AMHDB unreachable or list not found)', 'amhdb-gigs' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<?php self::button( 'amhdb_gigs_refresh', __( 'Refresh all now', 'amhdb-gigs' ), 'primary' ); ?>
				<?php self::button( 'amhdb_gigs_clear', __( 'Clear cache', 'amhdb-gigs' ), 'secondary' ); ?>
			</p>
		</div>
		<?php
	}

	private static function button( string $action, string $label, string $type ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<?php wp_nonce_field( $action ); ?>
			<?php submit_button( $label, $type, 'submit', false ); ?>
		</form>
		<?php
	}

	public static function handle_refresh(): void {
		self::authorise( 'amhdb_gigs_refresh' );
		AMHDB_Gigs_Api::refresh_all();
		self::back( 'refreshed' );
	}

	public static function handle_clear(): void {
		self::authorise( 'amhdb_gigs_clear' );
		AMHDB_Gigs_Api::clear();
		self::back( 'cleared' );
	}

	private static function authorise( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'amhdb-gigs' ), 403 );
		}
		check_admin_referer( $action );
	}

	private static function back( string $done ): void {
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'amhdb_gigs_done' => $done ), admin_url( 'tools.php' ) ) );
		exit;
	}
}
