<?php
/**
 * [amhdb_gigs] — a gig list from AMHDB, rendered into the page on the server so
 * search engines see it. Replaces [gigpress_shows] and [gigpress_menu] (#425).
 *
 * Filters (combine with AND; at least one is needed):
 *   artist  AMHDB band or person slug (tries a band first)
 *   band, person, venue, tag (a tour), year, month, from, to (YYYY-MM-DD)
 * Display:
 *   sort          asc (default) or desc
 *   limit         show the first N, with a "See all" link to AMHDB
 *   group_artists yes: a heading per artist; no (default): year/month headings
 *   menu          yes: a jump menu of year/month (or artist) links
 *   menu_count    yes: counts in the jump menu
 *   heading       h2–h5, the level of the top headings (default h3)
 *
 * @package AMHDB_Gigs
 */

defined( 'ABSPATH' ) || exit;

class AMHDB_Gigs_Shortcode {

	public const TAG  = 'amhdb_gigs';
	private const SITE = 'https://australianmusicdatabase.com/';

	private const DEFAULTS = array(
		'artist'        => '',
		'band'          => '',
		'person'        => '',
		'venue'         => '',
		'tag'           => '',
		'year'          => '',
		'month'         => '',
		'from'          => '',
		'to'            => '',
		'sort'          => '',
		'limit'         => '',
		'group_artists' => 'no',
		'menu'          => 'no',
		'menu_count'    => 'no',
		'heading'       => 'h3',
	);

	/** Counts lists on the page, so each one's anchors are unique. */
	public static int $instance = 0;

	public static function hooks(): void {
		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
	}

	/**
	 * @param array|string $atts Shortcode attributes ('' when there are none).
	 */
	public static function render( $atts ): string {
		$atts  = shortcode_atts( self::DEFAULTS, is_array( $atts ) ? $atts : array(), self::TAG );
		$query = array_intersect_key( $atts, array_flip( AMHDB_Gigs_Api::FILTERS ) );

		$filters = array_diff( AMHDB_Gigs_Api::FILTERS, array( 'sort', 'limit' ) );
		if ( ! array_filter( array_intersect_key( $query, array_flip( $filters ) ), 'strlen' ) ) {
			return '<!-- amhdb_gigs: give at least one of artist, band, person, venue, tag, year, from or to -->';
		}

		wp_enqueue_style( 'amhdb-gigs', plugins_url( 'assets/amhdb-gigs.css', AMHDB_GIGS_FILE ), array(), AMHDB_GIGS_VERSION );

		$data = AMHDB_Gigs_Api::get( $query );
		if ( null === $data ) {
			return self::unavailable( $atts );
		}

		$group_artists = self::yes( $atts['group_artists'] );

		return AMHDB_Gigs_Renderer::render(
			$data,
			array(
				'instance'      => ++self::$instance,
				'heading'       => strtolower( $atts['heading'] ),
				'subject'       => ! $group_artists && ( $atts['artist'] || $atts['band'] || $atts['person'] ),
				'group_artists' => $group_artists,
				'menu'          => self::yes( $atts['menu'] ),
				'menu_count'    => self::yes( $atts['menu_count'] ),
				'current_url'   => (string) get_permalink(),
			)
		);
	}

	/**
	 * AMHDB couldn't be reached and there's no saved copy yet: point to where
	 * the list lives instead of showing an error.
	 */
	private static function unavailable( array $atts ): string {
		$url = self::SITE;
		if ( $atts['band'] ) {
			$url .= 'bands/' . rawurlencode( $atts['band'] );
		} elseif ( $atts['person'] ) {
			$url .= 'people/' . rawurlencode( $atts['person'] );
		} elseif ( $atts['venue'] ) {
			$url .= 'venues/' . rawurlencode( $atts['venue'] );
		}

		return '<div class="amhdb-gigs-list"><p class="amhdb-gigs__unavailable">'
			. sprintf(
				/* translators: %s: link to the Australian Music Database */
				esc_html__( 'This gig list is on the %s.', 'amhdb-gigs' ),
				'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Australian Music Database', 'amhdb-gigs' ) . '</a>'
			)
			. '</p></div>';
	}

	private static function yes( string $value ): bool {
		return in_array( strtolower( trim( $value ) ), array( 'yes', 'true', '1' ), true );
	}
}
