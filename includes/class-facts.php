<?php
/**
 * [amhdb_facts] — the facts panel on AMH story pages, filled from AMHDB's facts
 * API (#448, #449), so the facts are entered once, in AMHDB.
 *
 *   [amhdb_facts]              the panel: When, Where, Members, Recordings,
 *                              Label, Charted, Source, Gigs, and the Database button
 *   [amhdb_facts part="when"]  the amber date tile above the title
 *
 * Which record: a band, person or venue attribute; else a post field
 * amhdb_band, amhdb_person or amhdb_venue; else the AMHDB record whose amh_url
 * is this post (#419). Any amh_* post field that's filled overrides its row,
 * for story-specific wording. gigs="no" leaves out the gigs row.
 *
 * The markup is what the Post 2026 template already styles:
 * div.amh-card > p.amh-card-row > span.amh-card-label + span.amh-card-value,
 * then p.amh-card-db > a. With nothing from AMHDB or the post, it's empty, and
 * the template hides an empty panel.
 *
 * Cached like the gig lists: a day fresh, then a last-good copy served while
 * WP-Cron refreshes it. A post AMHDB doesn't know is remembered for a day, so
 * it isn't asked about on every view.
 *
 * @package AMHDB_Gigs
 */

defined( 'ABSPATH' ) || exit;

class AMHDB_Gigs_Facts {

	public const TAG       = 'amhdb_facts';
	public const HOOK      = 'amhdb_gigs_facts_refresh';
	private const FRESH    = DAY_IN_SECONDS;
	private const BACK_OFF = 15 * MINUTE_IN_SECONDS;
	private const REGISTRY = 'amhdb_gigs_facts_keys';

	/** Row label => the post field that overrides it, in panel order. */
	private const ROWS = array(
		'when'       => array( 'When', 'amh_when' ),
		'where'      => array( 'Where', 'amh_where' ),
		'members'    => array( 'Members', 'amh_members' ),
		'recordings' => array( 'Recordings', 'amh_recordings' ),
		'label'      => array( 'Label', 'amh_label' ),
		'charted'    => array( 'Charted', 'amh_charted' ),
		'source'     => array( 'Source', 'amh_source' ),
		'gigs'       => array( 'Gigs in the Database', '' ),
	);

	private const DEFAULTS = array(
		'band'   => '',
		'person' => '',
		'venue'  => '',
		'part'   => '',
		'gigs'   => 'yes',
	);

	public static function hooks(): void {
		add_shortcode( self::TAG, array( __CLASS__, 'shortcode' ) );
		add_action( self::HOOK, array( __CLASS__, 'refresh' ) );
	}

	/**
	 * @param array|string $atts Shortcode attributes ('' when there are none).
	 */
	public static function shortcode( $atts ): string {
		$atts      = shortcode_atts( self::DEFAULTS, is_array( $atts ) ? $atts : array(), self::TAG );
		$facts     = self::get( self::target( $atts ) );
		$overrides = array();
		foreach ( self::ROWS as $row => [ , $field ] ) {
			if ( $field ) {
				$overrides[ $row ] = (string) get_post_meta( get_the_ID(), $field, true );
			}
		}
		$overrides['db_url'] = (string) get_post_meta( get_the_ID(), 'amh_db_url', true );

		if ( 'when' === strtolower( $atts['part'] ) ) {
			$when = self::rows( $facts, $overrides, false )['when'] ?? '';
			return '' === $when ? '' : '<p class="amh-when"><span>' . $when . '</span></p>';
		}
		return self::panel( $facts, $overrides, ! in_array( strtolower( $atts['gigs'] ), array( 'no', 'false', '0' ), true ) );
	}

	/**
	 * The panel's HTML. Pure: AMHDB's facts (or null) and the post's fields in,
	 * markup out.
	 *
	 * @param array|null           $facts     A facts API response.
	 * @param array<string,string> $overrides Post field values by row, plus db_url.
	 */
	public static function panel( ?array $facts, array $overrides, bool $gigs = true ): string {
		$out = '';
		foreach ( self::rows( $facts, $overrides, $gigs ) as $row => $value ) {
			$label = 'members' === $row && 'person' === ( $facts['type'] ?? '' ) && '' === ( $overrides['members'] ?? '' ) ? 'Bands' : self::ROWS[ $row ][0];
			$out  .= '<p class="amh-card-row"><span class="amh-card-label">' . esc_html( $label ) . '</span><span class="amh-card-value">' . $value . '</span></p>';
		}
		$db_url = '' !== ( $overrides['db_url'] ?? '' ) ? $overrides['db_url'] : ( $facts['amhdb_url'] ?? '' );
		if ( $db_url ) {
			$out .= '<p class="amh-card-db"><a href="' . esc_url( $db_url ) . '">' . esc_html__( 'In the Australian Music Database', 'amhdb-gigs' ) . '</a></p>';
		}
		return '' === $out ? '' : '<div class="amh-card">' . $out . '</div>';
	}

	/** Which AMHDB record this post is about. */
	public static function target( array $atts ): array {
		$atts = array_replace( self::DEFAULTS, $atts );
		$types = array( 'band' => 'bands', 'person' => 'people', 'venue' => 'venues' );
		foreach ( array( true, false ) as $from_atts ) {
			foreach ( $types as $type => $path ) {
				$slug = $from_atts ? $atts[ $type ] : (string) get_post_meta( get_the_ID(), "amhdb_{$type}", true );
				if ( '' !== $slug ) {
					return array( 'path' => $path . '/' . sanitize_title( $slug ) );
				}
			}
		}
		return array( 'amh_url' => (string) get_permalink() );
	}

	// ── Rows ─────────────────────────────────────────────────────────────────

	/** Each row's HTML, overrides first, leaving out empty rows. */
	private static function rows( ?array $facts, array $overrides, bool $gigs ): array {
		$from_amhdb = $facts ? self::amhdb_rows( $facts ) : array();
		if ( ! $gigs ) {
			unset( $from_amhdb['gigs'] );
		}
		$rows = array();
		foreach ( array_keys( self::ROWS ) as $row ) {
			$field = trim( $overrides[ $row ] ?? '' );
			if ( '' !== $field ) {
				$rows[ $row ] = preg_replace( '/\R/', "<br>\n", wp_kses_post( $field ) );
			} elseif ( ! empty( $from_amhdb[ $row ] ) ) {
				$rows[ $row ] = implode( '<br>', array_map( 'esc_html', $from_amhdb[ $row ] ) );
			}
		}
		return $rows;
	}

	/** Plain-text lines for each row AMHDB can fill. */
	private static function amhdb_rows( array $facts ): array {
		$recordings = $facts['recordings'] ?? array();
		$rows       = array(
			'members'    => array_map( array( __CLASS__, 'membership' ), $facts['members'] ?? $facts['bands'] ?? array() ),
			'recordings' => array_map( fn( $r ) => self::join( array( $r['title'] ?? '', $r['type'] ?? '', (string) ( $r['year'] ?? '' ) ) ), $recordings ),
			'label'      => array( implode( ', ', array_unique( array_filter( array_column( $recordings, 'label' ) ) ) ) ),
			'charted'    => array(),
			'source'     => array( implode( '; ', $facts['sources'] ?? array() ) ),
			'gigs'       => array( self::gigs( $facts['gigs'] ?? array() ) ),
		);
		foreach ( $recordings as $r ) {
			if ( ! empty( $r['highest_position'] ) ) {
				$year              = $r['first_charted'] ? substr( $r['first_charted'], 0, 4 ) : (string) ( $r['year'] ?? '' );
				$rows['charted'][] = $r['title'] . ', No. ' . $r['highest_position'] . ( $year ? " ($year)" : '' );
			}
		}
		if ( 'person' === ( $facts['type'] ?? '' ) ) {
			$rows['when'] = array( self::lifetime( $facts['born']['year'] ?? null, $facts['died']['year'] ?? null ) );
		}
		if ( 'venue' === ( $facts['type'] ?? '' ) ) {
			$rows['where'] = array( self::join( array( $facts['city'] ?? '', $facts['state'] ?? '' ) ) );
		}
		return array_map( fn( $lines ) => array_values( array_filter( $lines, 'strlen' ) ), $rows );
	}

	/** "Andy Cichon, vocals and guitar (1991–1994)". */
	private static function membership( array $m ): string {
		$from  = $m['joined_year'] ?? null;
		$until = $m['left_year'] ?? null;
		if ( $from && $until ) {
			$years = $from === $until ? "$from" : "{$from}–{$until}";
		} elseif ( $from ) {
			/* translators: %d: year joined */
			$years = sprintf( __( 'from %d', 'amhdb-gigs' ), $from );
		} elseif ( $until ) {
			/* translators: %d: year left */
			$years = sprintf( __( 'until %d', 'amhdb-gigs' ), $until );
		} else {
			$years = '';
		}
		return self::join( array( $m['name'] ?? '', (string) ( $m['details'] ?? '' ) ) ) . ( $years ? " ($years)" : '' );
	}

	/** "31 (1991 to 1992)". */
	private static function gigs( array $gigs ): string {
		$count = (int) ( $gigs['count'] ?? 0 );
		if ( ! $count ) {
			return '';
		}
		$first = $gigs['first']['year'] ?? null;
		$last  = $gigs['last']['year'] ?? null;
		if ( ! $first ) {
			return (string) $count;
		}
		return $count . ' (' . ( $first === $last || ! $last ? $first : "$first to $last" ) . ')';
	}

	private static function lifetime( ?int $born, ?int $died ): string {
		if ( $born && $died ) {
			return "$born to $died";
		}
		if ( $born ) {
			/* translators: %d: year of birth */
			return sprintf( __( 'Born %d', 'amhdb-gigs' ), $born );
		}
		/* translators: %d: year of death */
		return $died ? sprintf( __( 'Died %d', 'amhdb-gigs' ), $died ) : '';
	}

	private static function join( array $parts ): string {
		return implode( ', ', array_filter( array_map( 'trim', $parts ), 'strlen' ) );
	}

	// ── Cache ────────────────────────────────────────────────────────────────

	/** The record's facts, or null when AMHDB has none (or can't be reached and there's no copy). */
	public static function get( array $target ): ?array {
		$key   = self::key( $target );
		$fresh = get_transient( "amhdb_facts_{$key}" );
		if ( is_array( $fresh ) ) {
			return $fresh['data'];
		}

		$last = get_option( "amhdb_facts_last_{$key}" );
		if ( is_array( $last ) ) {
			if ( ! get_transient( "amhdb_facts_backoff_{$key}" ) && ! wp_next_scheduled( self::HOOK, array( $target ) ) ) {
				wp_schedule_single_event( time(), self::HOOK, array( $target ) );
			}
			return $last['data'];
		}

		if ( get_transient( "amhdb_facts_backoff_{$key}" ) ) {
			return null;
		}
		self::refresh( $target );
		$fresh = get_transient( "amhdb_facts_{$key}" );
		return is_array( $fresh ) ? $fresh['data'] : null;
	}

	/** Fetch now and store the result. True unless AMHDB couldn't be reached. */
	public static function refresh( array $target ): bool {
		$key  = self::key( $target );
		$last = get_option( "amhdb_facts_last_{$key}" );
		self::remember( $key );

		$result = AMHDB_Gigs_Api::request( self::url( $target ), is_array( $last ) ? ( $last['etag'] ?? '' ) : '' );

		if ( 'not_modified' === $result && is_array( $last ) ) {
			set_transient( "amhdb_facts_{$key}", array( 'data' => $last['data'] ), self::FRESH );
			return true;
		}
		if ( 'not_found' === $result ) {
			delete_option( "amhdb_facts_last_{$key}" );
			set_transient( "amhdb_facts_{$key}", array( 'data' => null ), self::FRESH );
			return true;
		}
		if ( ! is_array( $result ) || ! isset( $result['data']['type'] ) ) {
			set_transient( "amhdb_facts_backoff_{$key}", 1, self::BACK_OFF );
			return false;
		}

		update_option( "amhdb_facts_last_{$key}", array( 'data' => $result['data'], 'etag' => $result['etag'], 'fetched_at' => time() ), false );
		set_transient( "amhdb_facts_{$key}", array( 'data' => $result['data'] ), self::FRESH );
		delete_transient( "amhdb_facts_backoff_{$key}" );
		return true;
	}

	public static function clear(): void {
		foreach ( array_keys( (array) get_option( self::REGISTRY, array() ) ) as $key ) {
			delete_transient( "amhdb_facts_{$key}" );
			delete_transient( "amhdb_facts_backoff_{$key}" );
			delete_option( "amhdb_facts_last_{$key}" );
		}
		delete_option( self::REGISTRY );
	}

	public static function url( array $target ): string {
		if ( isset( $target['path'] ) ) {
			return AMHDB_Gigs_Api::root() . '/' . $target['path'] . '/facts';
		}
		return AMHDB_Gigs_Api::root() . '/facts?amh_url=' . rawurlencode( $target['amh_url'] ?? '' );
	}

	private static function key( array $target ): string {
		return md5( wp_json_encode( $target ) );
	}

	private static function remember( string $key ): void {
		$known = (array) get_option( self::REGISTRY, array() );
		if ( ! isset( $known[ $key ] ) ) {
			$known[ $key ] = true;
			update_option( self::REGISTRY, $known, false );
		}
	}
}
