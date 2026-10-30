<?php

/** [amhdb_facts]: the facts panel on AMH story pages, from AMHDB (#449). */
class FactsTest extends AMHDB_Gigs_TestCase {

	private function ok( array $data, string $etag = 'W/"f1"' ) {
		return array( 'response' => array( 'code' => 200 ), 'headers' => array( 'ETag' => $etag ), 'body' => json_encode( $data ) );
	}

	private function http_status( int $code ) {
		return array( 'response' => array( 'code' => $code ), 'headers' => array(), 'body' => '{"error":"nope"}' );
	}

	private function serve( $data ) {
		WP_Fake::$http = fn() => $this->ok( $data );
	}

	private function meta( array $fields ) {
		WP_Fake::$meta[ WP_Fake::$post_id ] = $fields;
	}

	private function band( array $overrides = array() ) {
		return array_replace(
			array(
				'type'       => 'band',
				'name'       => 'Judge Mercy',
				'slug'       => 'judge-mercy',
				'amhdb_url'  => 'https://australianmusicdatabase.com/bands/judge-mercy',
				'amh_url'    => 'https://australianmusichistory.com/judge-mercy/',
				'members'    => array(
					array( 'name' => 'Andy Cichon', 'instruments' => array( 'vocals', 'guitar' ), 'details' => 'Founder', 'joined_year' => 1991, 'left_year' => 1994 ),
					array( 'name' => 'Craig Foster', 'instruments' => array( 'bass' ), 'details' => null, 'joined_year' => 1992, 'left_year' => null ),
					array( 'name' => 'Ismet Osmanovic', 'instruments' => array( 'guitar', 'keyboards', 'backing vocals' ), 'details' => null, 'joined_year' => null, 'left_year' => null ),
					array( 'name' => 'Mick O\'Shea', 'instruments' => array(), 'details' => 'Drums', 'joined_year' => null, 'left_year' => null ),
				),
				'recordings' => array(
					array( 'title' => 'Live With It', 'type' => 'EP', 'year' => 1991, 'month' => 6, 'label' => 'Mushroom', 'highest_position' => 42, 'weeks_in_chart' => 3, 'first_charted' => '1991-07-01' ),
					array( 'title' => 'Protocol So Holy', 'type' => 'Album', 'year' => 1993, 'month' => null, 'label' => null, 'highest_position' => null, 'weeks_in_chart' => null, 'first_charted' => null ),
				),
				'gigs'       => array(
					'count' => 31,
					'first' => array( 'date' => '1991-04-18', 'date_precision' => 'day', 'year' => 1991, 'month' => 4, 'day' => 18 ),
					'last'  => array( 'date' => '1992-11-13', 'date_precision' => 'day', 'year' => 1992, 'month' => 11, 'day' => 13 ),
				),
				'sources'    => array( 'Rock Detector' ),
			),
			$overrides
		);
	}

	private function rows( string $html ): array {
		preg_match_all( '#<span class="amh-card-label">(.*?)</span><span class="amh-card-value">(.*?)</span>#s', $html, $m, PREG_SET_ORDER );
		$rows = array();
		foreach ( $m as $row ) {
			$rows[ $row[1] ] = $row[2];
		}
		return $rows;
	}

	// ── Finding the record ───────────────────────────────────────────────────

	public function test_looks_the_post_up_by_its_address() {
		$this->serve( $this->band() );
		AMHDB_Gigs_Facts::shortcode( array() );
		$this->assertSame(
			'https://australianmusicdatabase.com/api/v1/facts?amh_url=' . rawurlencode( 'https://australianmusichistory.com/some-post/' ),
			WP_Fake::$requests[0]['url']
		);
	}

	public function test_a_post_field_or_attribute_names_the_record() {
		$this->serve( $this->band() );
		$this->meta( array( 'amhdb_band' => 'judge-mercy' ) );
		AMHDB_Gigs_Facts::shortcode( array() );
		$this->assertSame( 'https://australianmusicdatabase.com/api/v1/bands/judge-mercy/facts', WP_Fake::$requests[0]['url'] );

		AMHDB_Gigs_Facts::shortcode( array( 'venue' => 'the-venue' ) );
		$this->assertSame( 'https://australianmusicdatabase.com/api/v1/venues/the-venue/facts', end( WP_Fake::$requests )['url'] );

		$this->meta( array( 'amhdb_person' => 'andy-cichon' ) );
		AMHDB_Gigs_Facts::shortcode( array() );
		$this->assertSame( 'https://australianmusicdatabase.com/api/v1/people/andy-cichon/facts', end( WP_Fake::$requests )['url'] );
	}

	// ── The panel ────────────────────────────────────────────────────────────

	public function test_renders_the_panel_markup_the_template_styles() {
		$this->serve( $this->band() );
		$html = AMHDB_Gigs_Facts::shortcode( array() );

		$this->assertStringStartsWith( '<div class="amh-card"><p class="amh-card-row"><span class="amh-card-label">', $html );
		$this->assertSame(
			array(
				'Members'              => 'Andy Cichon, vocals and guitar (1991–1994)<br>Craig Foster, bass (from 1992)<br>Ismet Osmanovic, guitar, keyboards and backing vocals<br>Mick O&#039;Shea, Drums',
				'Recordings'           => 'Live With It, EP, 1991<br>Protocol So Holy, Album, 1993',
				'Label'                => 'Mushroom',
				'Charted'              => 'Live With It, No. 42 (1991)',
				'Source'               => 'Rock Detector',
				'Gigs in the Database' => '31 (1991 to 1992)',
			),
			$this->rows( $html )
		);
		$this->assertStringEndsWith( '<p class="amh-card-db"><a href="https://australianmusicdatabase.com/bands/judge-mercy">In the Australian Music Database</a></p></div>', $html );
	}

	public function test_a_filled_post_field_overrides_its_row() {
		$this->serve( $this->band() );
		$this->meta(
			array(
				'amh_when'    => '1991 to 1994',
				'amh_members' => "Andy Cichon, vocals and guitar\nCraig Forster, bass",
				'amh_source'  => 'Rock Detector, <em>Juke</em> 1992',
				'amh_db_url'  => 'https://australianmusicdatabase.com/bands/judge-mercy-2',
			)
		);
		$html = AMHDB_Gigs_Facts::shortcode( array() );
		$rows = $this->rows( $html );

		$this->assertSame( '1991 to 1994', $rows['When'] );
		$this->assertSame( "Andy Cichon, vocals and guitar<br>\nCraig Forster, bass", $rows['Members'] );
		$this->assertSame( 'Rock Detector, <em>Juke</em> 1992', $rows['Source'] );
		$this->assertSame( 'Live With It, EP, 1991<br>Protocol So Holy, Album, 1993', $rows['Recordings'], 'rows without a field still come from AMHDB' );
		$this->assertStringContainsString( 'href="https://australianmusicdatabase.com/bands/judge-mercy-2"', $html );
		$this->assertSame( array_keys( $rows ), array( 'When', 'Members', 'Recordings', 'Label', 'Charted', 'Source', 'Gigs in the Database' ) );
	}

	public function test_gigs_row_can_be_turned_off_and_is_left_out_with_no_gigs() {
		$this->serve( $this->band() );
		$this->assertArrayNotHasKey( 'Gigs in the Database', $this->rows( AMHDB_Gigs_Facts::shortcode( array( 'gigs' => 'no' ) ) ) );

		$facts = $this->band( array( 'gigs' => array( 'count' => 0, 'first' => null, 'last' => null ) ) );
		$this->assertArrayNotHasKey( 'Gigs in the Database', $this->rows( AMHDB_Gigs_Facts::panel( $facts, array() ) ) );

		$one = $this->band( array( 'gigs' => array( 'count' => 1, 'first' => array( 'year' => 1991 ), 'last' => array( 'year' => 1991 ) ) ) );
		$this->assertSame( '1 (1991)', $this->rows( AMHDB_Gigs_Facts::panel( $one, array() ) )['Gigs in the Database'] );
	}

	public function test_a_person_shows_bands_and_lifetime_and_a_venue_where() {
		$person = array(
			'type'       => 'person', 'name' => 'Andy Cichon', 'amhdb_url' => 'https://australianmusicdatabase.com/people/andy-cichon',
			'born'       => array( 'year' => 1965 ), 'died' => array( 'year' => 2001 ),
			'bands'      => array( array( 'name' => 'Judge Mercy', 'details' => null, 'joined_year' => 1991, 'left_year' => 1994 ) ),
			'recordings' => array(), 'gigs' => array( 'count' => 0, 'first' => null, 'last' => null ), 'sources' => array(),
		);
		$rows = $this->rows( AMHDB_Gigs_Facts::panel( $person, array() ) );
		$this->assertSame( array( 'When' => '1965 to 2001', 'Bands' => 'Judge Mercy (1991–1994)' ), $rows );

		$venue = array(
			'type' => 'venue', 'name' => 'The Venue', 'city' => 'St Kilda', 'state' => 'VIC', 'amhdb_url' => 'https://australianmusicdatabase.com/venues/the-venue',
			'gigs' => array( 'count' => 2, 'first' => array( 'year' => 1988 ), 'last' => array( 'year' => 1991 ) ), 'sources' => array(),
		);
		$this->assertSame( array( 'Where' => 'St Kilda, VIC', 'Gigs in the Database' => '2 (1988 to 1991)' ), $this->rows( AMHDB_Gigs_Facts::panel( $venue, array() ) ) );
	}

	public function test_the_when_tile() {
		$this->serve( $this->band() );
		$this->assertSame( '', AMHDB_Gigs_Facts::shortcode( array( 'part' => 'when' ) ), 'AMHDB has no years active for a band' );

		$this->meta( array( 'amh_when' => '1991 to 1994' ) );
		$this->assertSame( '<p class="amh-when"><span>1991 to 1994</span></p>', AMHDB_Gigs_Facts::shortcode( array( 'part' => 'when' ) ) );
	}

	// ── Nothing in AMHDB ─────────────────────────────────────────────────────

	public function test_nothing_in_amhdb_and_no_fields_renders_nothing() {
		WP_Fake::$http = fn() => $this->http_status( 404 );
		$this->assertSame( '', AMHDB_Gigs_Facts::shortcode( array() ) );
	}

	public function test_nothing_in_amhdb_still_shows_the_post_fields() {
		WP_Fake::$http = fn() => $this->http_status( 404 );
		$this->meta( array( 'amh_where' => 'Brisbane' ) );
		$this->assertSame( array( 'Where' => 'Brisbane' ), $this->rows( AMHDB_Gigs_Facts::shortcode( array() ) ) );
	}

	public function test_a_post_amhdb_does_not_know_is_asked_about_once_a_day() {
		WP_Fake::$http = fn() => $this->http_status( 404 );
		AMHDB_Gigs_Facts::shortcode( array() );
		AMHDB_Gigs_Facts::shortcode( array() );
		$this->assertCount( 1, WP_Fake::$requests );

		WP_Fake::$now += DAY_IN_SECONDS + 1;
		AMHDB_Gigs_Facts::shortcode( array() );
		$this->assertCount( 2, WP_Fake::$requests );
	}

	// ── Caching ──────────────────────────────────────────────────────────────

	public function test_cached_for_a_day_then_served_stale_while_refreshing_in_the_background() {
		$this->serve( $this->band() );
		$first = AMHDB_Gigs_Facts::shortcode( array() );
		AMHDB_Gigs_Facts::shortcode( array() );
		$this->assertCount( 1, WP_Fake::$requests );

		WP_Fake::$now += DAY_IN_SECONDS + 1;
		$this->assertSame( $first, AMHDB_Gigs_Facts::shortcode( array() ) );
		$this->assertCount( 1, WP_Fake::$requests, 'the visitor never waits' );
		$this->assertSame( 'amhdb_gigs_facts_refresh', WP_Fake::$scheduled[0]['hook'] );

		WP_Fake::$http = fn() => $this->http_status( 304 );
		$this->assertTrue( AMHDB_Gigs_Facts::refresh( WP_Fake::$scheduled[0]['args'][0] ) );
		$this->assertSame( 'W/"f1"', end( WP_Fake::$requests )['args']['headers']['If-None-Match'] );
	}

	public function test_amhdb_down_keeps_the_last_good_copy_and_backs_off() {
		$this->serve( $this->band() );
		$first = AMHDB_Gigs_Facts::shortcode( array() );

		WP_Fake::$now += DAY_IN_SECONDS + 1;
		WP_Fake::$http = fn() => new WP_Error( 'http_request_failed', 'timed out' );
		$target = AMHDB_Gigs_Facts::target( array() );
		$this->assertFalse( AMHDB_Gigs_Facts::refresh( $target ) );
		$this->assertSame( $first, AMHDB_Gigs_Facts::shortcode( array() ) );
	}

	public function test_amhdb_down_with_no_copy_shows_the_post_fields_without_waiting_again() {
		WP_Fake::$http = fn() => new WP_Error( 'http_request_failed', 'timed out' );
		$this->meta( array( 'amh_where' => 'Brisbane' ) );
		$this->assertSame( array( 'Where' => 'Brisbane' ), $this->rows( AMHDB_Gigs_Facts::shortcode( array() ) ) );
		AMHDB_Gigs_Facts::shortcode( array() );
		$this->assertCount( 1, WP_Fake::$requests, 'backs off instead of a request on every view' );
	}

	public function test_clear_forgets_every_cached_panel() {
		$this->serve( $this->band() );
		AMHDB_Gigs_Facts::shortcode( array() );
		AMHDB_Gigs_Api::clear();
		AMHDB_Gigs_Facts::shortcode( array() );
		$this->assertCount( 2, WP_Fake::$requests );
	}
}
