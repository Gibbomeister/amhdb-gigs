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
 * A "this week" list (#447) is a new list each day: its query carries the
 * day's month-day range and the start date, so the cache key changes at
 * midnight and a last-good copy is never from another day. Just after midnight
 * Sydney time, new_day() swaps each one for the new day's and fetches it, so
 * no visitor waits on the first view.
 *
 * API reference: docs/gigs-api.md in the AMHDB repo.
 *
 * @package AMHDB_Gigs
 */

defined( 'ABSPATH' ) || exit;

class AMHDB_Gigs_Api {

	public const HOOK      = 'amhdb_gigs_refresh';
	public const NEW_DAY   = 'amhdb_gigs_new_day';
	public const FILTERS   = array( 'band', 'person', 'artist', 'venue', 'tag', 'year', 'month', 'from', 'to', 'sort', 'limit', 'per_day', 'random', 'md_from', 'md_to', 'seed' );
	/** Kept in the query (and so the cache key) but not sent to AMHDB. */
	private const LOCAL    = array( 'this_week' );
	private const FRESH    = DAY_IN_SECONDS;
	private const BACK_OFF = 15 * MINUTE_IN_SECONDS;
	private const TIMEOUT  = 5;
	private const REGISTRY = 'amhdb_gigs_queries';

	public static function hooks(): void {
		add_action( self::HOOK, array( __CLASS__, 'refresh' ) );
		add_action( self::NEW_DAY, array( __CLASS__, 'new_day' ) );

		// A single event each night, not a daily one, so a daylight-saving
		// change never moves it off midnight.
		if ( ! wp_next_scheduled( self::NEW_DAY ) ) {
			wp_schedule_single_event( current_datetime()->modify( 'tomorrow 00:05' )->getTimestamp(), self::NEW_DAY );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::NEW_DAY );
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( AMHDB_Gigs_Facts::HOOK );
	}

	/**
	 * A "this week" query for the $days days from $start, in any year:
	 * md_from/md_to for AMHDB, and the start date as the seed for random picks
	 * (and in the cache key).
	 */
	public static function this_week( array $query, int $days, DateTimeImmutable $start ): array {
		$query['this_week'] = (string) $days;
		$query['md_from']   = $start->format( 'm-d' );
		$query['md_to']     = $start->modify( '+' . ( $days - 1 ) . ' days' )->format( 'm-d' );
		$query['seed']      = $start->format( 'Y-m-d' );
		return self::normalise( $query );
	}

	/** Swap each "this week" list for today's, fetch it, and forget the old one. */
	public static function new_day(): void {
		$today = current_datetime()->setTime( 0, 0 );
		foreach ( self::known_queries() as $key => $query ) {
			if ( ! isset( $query['this_week'] ) || $today->format( 'Y-m-d' ) === ( $query['seed'] ?? '' ) ) {
				continue;
			}
			self::forget( $key );
			$fresh = self::this_week( $query, (int) $query['this_week'], $today );
			self::remember( self::key( $fresh ), $fresh );
			self::refresh( $fresh );
		}
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
			self::forget( $key );
		}
		delete_option( self::REGISTRY );
		if ( class_exists( 'AMHDB_Gigs_Facts' ) ) {
			AMHDB_Gigs_Facts::clear();
		}
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
		$params = array_diff_key( self::normalise( $query ), array_flip( self::LOCAL ) );
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
		foreach ( array_merge( self::FILTERS, self::LOCAL ) as $filter ) {
			if ( isset( $query[ $filter ] ) && '' !== (string) $query[ $filter ] ) {
				$out[ $filter ] = (string) $query[ $filter ];
			}
		}
		return $out;
	}

	private static function endpoint(): string {
		return defined( 'AMHDB_GIGS_API' ) ? AMHDB_GIGS_API : 'https://australianmusicdatabase.com/api/v1/gigs';
	}

	/** AMHDB's API root, e.g. https://australianmusicdatabase.com/api/v1, for its other endpoints. */
	public static function root(): string {
		return preg_replace( '#/gigs/?$#', '', self::endpoint() );
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
		$result = self::request( self::url( $query ), $etag );
		if ( 'not_found' === $result || ( is_array( $result ) && ! isset( $result['data']['gigs'] ) ) ) {
			return false;
		}
		return $result;
	}

	/**
	 * GET a URL on AMHDB, sending the ETag if there is one.
	 *
	 * @return array{data: array, etag: string}|'not_modified'|'not_found'|false
	 */
	public static function request( string $url, string $etag ) {
		$headers = array(
			'Accept'     => 'application/json',
			'User-Agent' => 'AMHDB-Gigs/' . AMHDB_GIGS_VERSION . '; ' . home_url(), // So AMHDB's logs say who's calling.
		);
		if ( $etag ) {
			$headers['If-None-Match'] = $etag;
		}

		$response = wp_remote_get( $url, array( 'timeout' => self::TIMEOUT, 'headers' => $headers ) );
		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 304 === $code ) {
			return 'not_modified';
		}
		if ( 404 === $code ) {
			return 'not_found';
		}
		if ( 200 !== $code ) {
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
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

	private static function forget( string $key ): void {
		delete_transient( "amhdb_gigs_{$key}" );
		delete_transient( "amhdb_gigs_backoff_{$key}" );
		delete_option( "amhdb_gigs_last_{$key}" );

		$known = self::known_queries();
		if ( isset( $known[ $key ] ) ) {
			unset( $known[ $key ] );
			update_option( self::REGISTRY, $known, false );
		}
	}

	private static function remember( string $key, array $query ): void {
		$known = self::known_queries();
		if ( ! isset( $known[ $key ] ) ) {
			$known[ $key ] = $query;
			update_option( self::REGISTRY, $known, false );
		}
	}
}

