<?php

class ApiTest extends AMHDB_Gigs_TestCase {

	private function ok( array $data, string $etag = 'W/"abc"' ) {
		return array( 'response' => array( 'code' => 200 ), 'headers' => array( 'ETag' => $etag ), 'body' => json_encode( $data ) );
	}

	private function http_status( int $code ) {
		return array( 'response' => array( 'code' => $code ), 'headers' => array(), 'body' => json_encode( array( 'error' => 'nope' ) ) );
	}

	private function serve( callable $fn ) {
		WP_Fake::$http = $fn;
	}

	private function band_query() {
		return array( 'band' => 'bandanna' );
	}

	// ── Building the request ─────────────────────────────────────────────────

	public function test_builds_the_url_from_known_filters_only() {
		$url = AMHDB_Gigs_Api::url( array( 'band' => 'bandanna', 'year' => '1985', 'sort' => 'desc', 'limit' => '', 'colour' => 'red' ) );
		$this->assertSame( 'https://australianmusicdatabase.com/api/v1/gigs?band=bandanna&year=1985&sort=desc', $url );
	}

	public function test_the_same_filters_in_any_order_share_a_cache_entry() {
		$this->assertSame(
			AMHDB_Gigs_Api::key( array( 'band' => 'bandanna', 'year' => '1985' ) ),
			AMHDB_Gigs_Api::key( array( 'year' => '1985', 'band' => 'bandanna', 'limit' => '' ) )
		);
	}

	// ── First view ───────────────────────────────────────────────────────────

	public function test_first_view_fetches_with_a_short_timeout_and_keeps_a_copy() {
		$data = $this->response( array( $this->entry() ) );
		$this->serve( fn() => $this->ok( $data ) );

		$this->assertSame( $data, AMHDB_Gigs_Api::get( $this->band_query() ) );
		$this->assertCount( 1, WP_Fake::$requests );
		$this->assertLessThanOrEqual( 5, WP_Fake::$requests[0]['args']['timeout'] );
		$this->assertStringContainsString( 'AMHDB-Gigs/', WP_Fake::$requests[0]['args']['headers']['User-Agent'] );

		// A second view is served from the cache.
		$this->assertSame( $data, AMHDB_Gigs_Api::get( $this->band_query() ) );
		$this->assertCount( 1, WP_Fake::$requests );
	}

	public function test_first_view_with_amhdb_down_gives_nothing() {
		$this->serve( fn() => new WP_Error( 'http_request_failed', 'timed out' ) );
		$this->assertNull( AMHDB_Gigs_Api::get( $this->band_query() ) );
	}

	public function test_an_error_response_is_not_cached_as_data() {
		$this->serve( fn() => $this->http_status( 404 ) );
		$this->assertNull( AMHDB_Gigs_Api::get( $this->band_query() ) );
		$this->assertFalse( get_option( 'amhdb_gigs_last_' . AMHDB_Gigs_Api::key( $this->band_query() ) ) );
	}

	// ── After the cache expires ──────────────────────────────────────────────

	public function test_expired_cache_serves_the_last_good_copy_at_once_and_refreshes_in_the_background() {
		$old = $this->response( array( $this->entry() ) );
		$this->serve( fn() => $this->ok( $old ) );
		AMHDB_Gigs_Api::get( $this->band_query() );

		WP_Fake::$now += DAY_IN_SECONDS + 1;
		WP_Fake::$requests = array();

		$this->assertSame( $old, AMHDB_Gigs_Api::get( $this->band_query() ) );
		$this->assertCount( 0, WP_Fake::$requests, 'the visitor never waits on AMHDB' );
		$this->assertCount( 1, WP_Fake::$scheduled );
		$this->assertSame( 'amhdb_gigs_refresh', WP_Fake::$scheduled[0]['hook'] );
		$this->assertSame( array( $this->band_query() ), WP_Fake::$scheduled[0]['args'] );

		AMHDB_Gigs_Api::get( $this->band_query() );
		$this->assertCount( 1, WP_Fake::$scheduled, 'scheduled once, not per view' );
	}

	public function test_refresh_replaces_the_copy_with_new_data() {
		$this->serve( fn() => $this->ok( $this->response( array( $this->entry() ) ) ) );
		AMHDB_Gigs_Api::get( $this->band_query() );

		$new = $this->response( array( $this->entry(), $this->entry( array( 'ids' => array( 2 ) ) ) ) );
		$this->serve( fn() => $this->ok( $new, 'W/"def"' ) );
		$this->assertTrue( AMHDB_Gigs_Api::refresh( $this->band_query() ) );

		$this->assertSame( $new, AMHDB_Gigs_Api::get( $this->band_query() ) );
	}

	public function test_refresh_sends_the_etag_and_a_304_keeps_the_copy_fresh() {
		$data = $this->response( array( $this->entry() ) );
		$this->serve( fn() => $this->ok( $data, 'W/"abc"' ) );
		AMHDB_Gigs_Api::get( $this->band_query() );

		WP_Fake::$now += DAY_IN_SECONDS + 1;
		$this->serve( fn() => $this->http_status( 304 ) );
		$this->assertTrue( AMHDB_Gigs_Api::refresh( $this->band_query() ) );
		$this->assertSame( 'W/"abc"', end( WP_Fake::$requests )['args']['headers']['If-None-Match'] );

		WP_Fake::$requests = array();
		$this->assertSame( $data, AMHDB_Gigs_Api::get( $this->band_query() ) );
		$this->assertCount( 0, WP_Fake::$scheduled, 'fresh again, so nothing to schedule' );
	}

	public function test_a_failed_refresh_keeps_the_last_good_copy_and_backs_off() {
		$data = $this->response( array( $this->entry() ) );
		$this->serve( fn() => $this->ok( $data ) );
		AMHDB_Gigs_Api::get( $this->band_query() );

		WP_Fake::$now += DAY_IN_SECONDS + 1;
		$this->serve( fn() => $this->http_status( 500 ) );
		$this->assertFalse( AMHDB_Gigs_Api::refresh( $this->band_query() ) );

		WP_Fake::$scheduled = array();
		$this->assertSame( $data, AMHDB_Gigs_Api::get( $this->band_query() ) );
		$this->assertCount( 0, WP_Fake::$scheduled, 'no retry storm while AMHDB is down' );

		WP_Fake::$now += 16 * MINUTE_IN_SECONDS;
		AMHDB_Gigs_Api::get( $this->band_query() );
		$this->assertCount( 1, WP_Fake::$scheduled, 'tries again after the back-off' );
	}

	// ── artist= ──────────────────────────────────────────────────────────────

	public function test_artist_tries_a_band_then_a_person() {
		$data = $this->response( array( $this->entry() ) );
		$this->serve(
			function ( $url ) use ( $data ) {
				return str_contains( $url, 'person=rick-price' ) ? $this->ok( $data ) : $this->http_status( 404 );
			}
		);

		$this->assertSame( $data, AMHDB_Gigs_Api::get( array( 'artist' => 'rick-price' ) ) );
		$this->assertStringContainsString( 'band=rick-price', WP_Fake::$requests[0]['url'] );
		$this->assertStringContainsString( 'person=rick-price', WP_Fake::$requests[1]['url'] );
	}

	public function test_artist_found_as_neither_gives_nothing() {
		$this->serve( fn() => $this->http_status( 404 ) );
		$this->assertNull( AMHDB_Gigs_Api::get( array( 'artist' => 'nobody' ) ) );
	}

	// ── Clearing ─────────────────────────────────────────────────────────────

	public function test_remembers_each_list_so_they_can_all_be_refreshed_or_cleared() {
		$this->serve( fn() => $this->ok( $this->response( array( $this->entry() ) ) ) );
		AMHDB_Gigs_Api::get( $this->band_query() );
		AMHDB_Gigs_Api::get( array( 'venue' => 'bondi-lifesaver' ) );

		$this->assertEquals( array( $this->band_query(), array( 'venue' => 'bondi-lifesaver' ) ), array_values( AMHDB_Gigs_Api::known_queries() ) );

		$this->assertSame( 2, AMHDB_Gigs_Api::refresh_all() );

		AMHDB_Gigs_Api::clear();
		WP_Fake::$requests = array();
		AMHDB_Gigs_Api::get( $this->band_query() );
		$this->assertCount( 1, WP_Fake::$requests, 'cleared, so fetched again' );
	}
}
