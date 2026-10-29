<?php
/**
 * Fetches gig lists from the AMHDB API and caches them, so a visitor never waits
 * on AMHDB and an AMHDB outage never empties a page.
 *
 * Each distinct filter set has:
 * - a transient, fresh for a day;
 * - a last-good copy in an option, kept until a newer one replaces it.
 *
 * A view with a fresh transient costs nothing. A view after it expires gets the
 * last-good copy at once and schedules a background refresh (WP-Cron). Only the
 * very first view of a list fetches while the visitor waits, with a short
 * timeout. Refreshes send the ETag, so an unchanged list is a cheap 304. A
 * failed refresh keeps the last-good copy and waits a while before trying
 * again, so an outage doesn't mean a request on every view.
 *
 * API reference: docs/gigs-api.md in the AMHDB repo.
 *
 * @package AMHDB_Gigs
 */

defined( 'ABSPATH' ) || exit;

class AMHDB_Gigs_Api {

	public const HOOK      = 'amhdb_gigs_refresh';
	public const FILTERS   = array( 'band', 'person', 'artist', 'venue', 'tag', 'year', 'month', 'from', 'to', 'sort', 'limit' );
	private const FRESH    = DAY_IN_SECONDS;
	private const BACK_OFF = 15 * MINUTE_IN_SECONDS;
	private const TIMEOUT  = 5;
	private const REGISTRY = 'amhdb_gigs_queries';

	public static function hooks(): void {
		add_action( self::HOOK, array( __CLASS__, 'refresh' ) );
	}

	/**
	 * The list for these filters, or null when there's no copy and AMHDB can't
	 * be reached (or doesn't know the act, venue or tag).
	 */
	public static function get( array $query ): ?array {
		$query = self::normalise( $query );
		$key   = self::key( $query );
		self::remember( $key, $query );

		$fresh = get_transient( "amhdb_gigs_{$key}" );
		if ( is_array( $fresh ) ) {
			return $fresh;
		}

		$last = get_option( "amhdb_gigs_last_{$key}" );
		if ( is_array( $last ) ) {
			self::schedule_refresh( $key, $query );
			return $last['data'];
		}

		return self::refresh( $query ) ? get_option( "amhdb_gigs_last_{$key}" )['data'] : null;
	}

	/** Fetch now and store the result. True if a good copy is now cached. */
	public static function refresh( array $query ): bool {
		$query = self::normalise( $query );
		$key   = self::key( $query );
		$last  = get_option( "amhdb_gigs_last_{$key}" );
		$etag  = is_array( $last ) ? ( $last['etag'] ?? '' ) : '';

		$result = isset( $query['artist'] ) ? self::fetch_artist( $query, $etag ) : self::fetch( $query, $etag );

		if ( 'not_modified' === $result && is_array( $last ) ) {
			set_transient( "amhdb_gigs_{$key}", $last['data'], self::FRESH );
			return true;
		}
		if ( ! is_array( $result ) ) {
			set_transient( "amhdb_gigs_backoff_{$key}", 1, self::BACK_OFF );
			return false;
		}

		update_option(
			"amhdb_gigs_last_{$key}",
			array( 'data' => $result['data'], 'etag' => $result['etag'], 'fetched_at' => time() ),
			false
		);
		set_transient( "amhdb_gigs_{$key}", $result['data'], self::FRESH );
		delete_transient( "amhdb_gigs_backoff_{$key}" );
		return true;
	}

	/** Refresh every list the site has shown. Returns how many succeeded. */
	public static function refresh_all(): int {
		$ok = 0;
		foreach ( self::known_queries() as $query ) {
			$ok += self::refresh( $query ) ? 1 : 0;
		}
		return $ok;
	}

	/** Forget every cached list; each is fetched again on its next view. */
	public static function clear(): void {
		foreach ( array_keys( self::known_queries() ) as $key ) {
			delete_transient( "amhdb_gigs_{$key}" );
			delete_transient( "amhdb_gigs_backoff_{$key}" );
			delete_option( "amhdb_gigs_last_{$key}" );
		}
		delete_option( self::REGISTRY );
	}

	/** @return array<string, array> Filter sets keyed by cache key. */
	public static function known_queries(): array {
		$known = get_option( self::REGISTRY, array() );
		return is_array( $known ) ? $known : array();
	}

	/** When each list was last fetched, for the admin screen. */
	public static function fetched_at( array $query ): ?int {
		$last = get_option( 'amhdb_gigs_last_' . self::key( $query ) );
		return is_array( $last ) ? (int) $last['fetched_at'] : null;
	}

	public static function url( array $query ): string {
		$params = array_intersect_key( self::normalise( $query ), array_flip( self::FILTERS ) );
		unset( $params['artist'] );
		return self::endpoint() . '?' . http_build_query( $params );
	}

	public static function key( array $query ): string {
		return md5( wp_json_encode( self::normalise( $query ) ) );
	}

	// ── Internals ────────────────────────────────────────────────────────────

	/** Known, non-empty filters in a fixed order, so equal lists share a key. */
	private static function normalise( array $query ): array {
		$out = array();
		foreach ( self::FILTERS as $filter ) {
			if ( isset( $query[ $filter ] ) && '' !== (string) $query[ $filter ] ) {
				$out[ $filter ] = (string) $query[ $filter ];
			}
		}
		return $out;
	}

	private static function endpoint(): string {
		return defined( 'AMHDB_GIGS_API' ) ? AMHDB_GIGS_API : 'https://australianmusicdatabase.com/api/v1/gigs';
	}

	/** artist= could be a band or a person on AMHDB; try a band first. */
	private static function fetch_artist( array $query, string $etag ) {
		$slug = $query['artist'];
		unset( $query['artist'] );
		$as_band = self::fetch( $query + array( 'band' => $slug ), $etag );
		if ( false !== $as_band ) {
			return $as_band;
		}
		return self::fetch( $query + array( 'person' => $slug ), $etag );
	}

	/**
	 * @return array{data: array, etag: string}|'not_modified'|false
	 */
	private static function fetch( array $query, string $etag ) {
		$headers = array(
			'Accept'     => 'application/json',
			'User-Agent' => 'AMHDB-Gigs/' . AMHDB_GIGS_VERSION . '; ' . home_url(), // So AMHDB's logs say who's calling.
		);
		if ( $etag ) {
			$headers['If-None-Match'] = $etag;
		}

		$response = wp_remote_get( self::url( $query ), array( 'timeout' => self::TIMEOUT, 'headers' => $headers ) );
		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 304 === $code ) {
			return 'not_modified';
		}
		if ( 200 !== $code ) {
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['gigs'] ) ) {
			return false;
		}
		return array( 'data' => $data, 'etag' => (string) wp_remote_retrieve_header( $response, 'etag' ) );
	}

	private static function schedule_refresh( string $key, array $query ): void {
		if ( get_transient( "amhdb_gigs_backoff_{$key}" ) || wp_next_scheduled( self::HOOK, array( $query ) ) ) {
			return;
		}
		wp_schedule_single_event( time(), self::HOOK, array( $query ) );
	}

	private static function remember( string $key, array $query ): void {
		$known = self::known_queries();
		if ( ! isset( $known[ $key ] ) ) {
			$known[ $key ] = $query;
			update_option( self::REGISTRY, $known, false );
		}
	}
}

