<?php

/** [amhdb_gigs this_week="yes"]: "This week in history" on the AMH homepage (#447). */
class ThisWeekTest extends AMHDB_Gigs_TestCase {

	private function serve( array $data ) {
		WP_Fake::$http = fn() => array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => json_encode( $data ) );
	}

	private function down() {
		WP_Fake::$http = fn() => new WP_Error( 'http_request_failed', 'timed out' );
	}

	/** A Unix time from a Sydney wall-clock time. */
	private function sydney( string $when ): int {
		return ( new DateTimeImmutable( $when, new DateTimeZone( 'Australia/Sydney' ) ) )->getTimestamp();
	}

	private function last_url(): string {
		return end( WP_Fake::$requests )['url'];
	}

	private function entries() {
		return $this->response(
			array(
				$this->entry( array( 'date' => '1979-10-30', 'year' => 1979, 'month' => 10, 'day' => 30 ) ),
				$this->entry( array( 'ids' => array( 2 ), 'date' => '1985-11-02', 'year' => 1985, 'month' => 11, 'day' => 2 ) ),
			),
			array( 'count' => 40, 'see_all_url' => 'https://australianmusicdatabase.com/bands/bandanna' )
		);
	}

	// ── The request ──────────────────────────────────────────────────────────

	public function test_asks_for_today_to_six_days_on_in_any_year() {
		$this->serve( $this->entries() );
		AMHDB_Gigs_Shortcode::render( array( 'this_week' => 'yes', 'limit' => '10' ) );
		$this->assertStringEndsWith( '?limit=10&md_from=10-29&md_to=11-04&seed=2025-10-29', $this->last_url() );
	}

	public function test_today_is_sydney_time_not_utc() {
		WP_Fake::$now = $this->sydney( '2025-10-30 00:30' ); // Still the 29th in UTC.
		$this->serve( $this->entries() );
		AMHDB_Gigs_Shortcode::render( array( 'this_week' => 'yes' ) );
		$this->assertStringContainsString( 'md_from=10-30&md_to=11-05&seed=2025-10-30', $this->last_url() );
	}

	public function test_days_and_the_new_year() {
		WP_Fake::$now = $this->sydney( '2025-12-29 09:00' );
		$this->serve( $this->entries() );
		AMHDB_Gigs_Shortcode::render( array( 'this_week' => 'yes' ) );
		$this->assertStringContainsString( 'md_from=12-29&md_to=01-04', $this->last_url() );

		AMHDB_Gigs_Shortcode::render( array( 'this_week' => 'yes', 'days' => '3' ) );
		$this->assertStringContainsString( 'md_from=12-29&md_to=12-31', $this->last_url() );
	}

	public function test_a_bad_days_value_means_seven() {
		$this->serve( $this->entries() );
		AMHDB_Gigs_Shortcode::render( array( 'this_week' => 'yes', 'days' => 'lots' ) );
		$this->assertStringContainsString( 'md_to=11-04', $this->last_url() );
	}

	public function test_combines_with_other_filters_and_sort() {
		$this->serve( $this->entries() );
		AMHDB_Gigs_Shortcode::render( array( 'artist' => 'bandanna', 'this_week' => 'yes', 'sort' => 'desc' ) );
		$this->assertStringContainsString( '?band=bandanna&sort=desc&md_from=10-29', $this->last_url() );
	}

	public function test_per_day_and_random() {
		$this->serve( $this->entries() );
		AMHDB_Gigs_Shortcode::render( array( 'this_week' => 'yes', 'per_day' => '2', 'random' => 'yes', 'limit' => '10' ) );
		$this->assertStringContainsString( 'limit=10&per_day=2&random=1&md_from=10-29&md_to=11-04&seed=2025-10-29', $this->last_url() );

		AMHDB_Gigs_Shortcode::render( array( 'this_week' => 'yes', 'per_day' => '2', 'random' => 'no' ) );
		$this->assertStringNotContainsString( 'random=', $this->last_url() );
	}

	// ── The list ─────────────────────────────────────────────────────────────

	public function test_year_headings_no_month_headings_and_no_see_all_link() {
		$this->serve( $this->entries() );
		$html = AMHDB_Gigs_Shortcode::render( array( 'this_week' => 'yes', 'limit' => '2' ) );
		$this->assertSame( 2, substr_count( $html, 'class="amhdb-gigs__year"' ) );
		$this->assertStringNotContainsString( 'amhdb-gigs__month', $html );
		$this->assertStringNotContainsString( 'amhdb-gigs__see-all', $html );
		$this->assertSame( 2, substr_count( $html, 'class="amhdb-gig__date"' ) );
	}

	public function test_empty_week() {
		$this->serve( $this->response( array() ) );
		$html = AMHDB_Gigs_Shortcode::render( array( 'this_week' => 'yes' ) );
		$this->assertStringContainsString( '<p class="amhdb-gigs__empty">No gigs recorded for this week yet.</p>', $html );
	}

	// ── Caching ──────────────────────────────────────────────────────────────

	public function test_never_shows_a_copy_from_another_day() {
		$this->serve( $this->entries() );
		AMHDB_Gigs_Shortcode::render( array( 'this_week' => 'yes' ) );

		WP_Fake::$now += DAY_IN_SECONDS;
		$this->down();
		$html = AMHDB_Gigs_Shortcode::render( array( 'this_week' => 'yes' ) );
		$this->assertStringContainsString( 'amhdb-gigs__unavailable', $html );
		$this->assertStringNotContainsString( 'amhdb-gig__date', $html );
	}

	public function test_the_same_day_uses_its_last_good_copy() {
		$this->serve( $this->entries() );
		AMHDB_Gigs_Shortcode::render( array( 'this_week' => 'yes' ) );

		WP_Fake::$transients = array(); // The day's transient is gone, the last-good copy isn't.
		$this->down();
		$html = AMHDB_Gigs_Shortcode::render( array( 'this_week' => 'yes' ) );
		$this->assertStringContainsString( 'amhdb-gig__date', $html );
	}

	public function test_schedules_the_daily_roll_over_just_after_midnight_sydney() {
		AMHDB_Gigs_Api::hooks();
		$events = array_values( array_filter( WP_Fake::$scheduled, fn( $e ) => 'amhdb_gigs_new_day' === $e['hook'] ) );
		$this->assertCount( 1, $events );
		$this->assertSame( $this->sydney( '2025-10-30 00:05' ), $events[0]['time'] );
		$this->assertArrayNotHasKey( 'recurrence', $events[0], 'one event a night, so a daylight-saving change never moves it off midnight' );

		AMHDB_Gigs_Api::hooks();
		$this->assertCount( 1, array_filter( WP_Fake::$scheduled, fn( $e ) => 'amhdb_gigs_new_day' === $e['hook'] ), 'scheduled once' );
	}

	public function test_the_roll_over_swaps_each_this_week_list_for_the_new_day_and_fetches_it() {
		$this->serve( $this->entries() );
		AMHDB_Gigs_Shortcode::render( array( 'this_week' => 'yes', 'per_day' => '2' ) );
		AMHDB_Gigs_Shortcode::render( array( 'band' => 'bandanna' ) );
		$yesterday = AMHDB_Gigs_Api::known_queries();

		WP_Fake::$now      = $this->sydney( '2025-10-30 00:05' );
		WP_Fake::$requests = array();
		AMHDB_Gigs_Api::new_day();

		$this->assertCount( 1, WP_Fake::$requests, 'only the this-week list is fetched' );
		$this->assertStringContainsString( 'per_day=2&md_from=10-30&md_to=11-05&seed=2025-10-30', $this->last_url() );

		$known = AMHDB_Gigs_Api::known_queries();
		$this->assertCount( 2, $known );
		$this->assertContains( array( 'band' => 'bandanna' ), $known );
		foreach ( array_diff_key( $yesterday, $known ) as $key => $old ) {
			$this->assertSame( '2025-10-29', $old['seed'] );
			$this->assertFalse( get_option( "amhdb_gigs_last_{$key}" ), "yesterday's copy is gone" );
		}

		// The first view of the day is then served from the cache.
		WP_Fake::$requests = array();
		AMHDB_Gigs_Shortcode::render( array( 'this_week' => 'yes', 'per_day' => '2' ) );
		$this->assertCount( 0, WP_Fake::$requests );
	}
}
