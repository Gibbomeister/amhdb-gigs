<?php

class ShortcodeTest extends AMHDB_Gigs_TestCase {

	private function serve( array $data ) {
		WP_Fake::$http = fn() => array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => json_encode( $data ) );
	}

	private function down() {
		WP_Fake::$http = fn() => new WP_Error( 'http_request_failed', 'timed out' );
	}

	private function two_gigs( array $first = array() ) {
		return $this->response( array( $this->entry( $first ), $this->entry( array( 'ids' => array( 2 ) ) ) ) );
	}

	public function test_registers_the_shortcode() {
		AMHDB_Gigs_Shortcode::hooks();
		$this->assertArrayHasKey( 'amhdb_gigs', WP_Fake::$shortcodes );
	}

	public function test_passes_filters_to_the_api_and_renders_the_list() {
		$this->serve( $this->two_gigs() );
		$html = AMHDB_Gigs_Shortcode::render( array( 'venue' => 'bondi-lifesaver', 'year' => '1985', 'sort' => 'desc', 'limit' => '20' ) );

		$this->assertStringContainsString( 'venue=bondi-lifesaver&year=1985&sort=desc&limit=20', WP_Fake::$requests[0]['url'] );
		$this->assertStringContainsString( '<div class="amhdb-gigs-list">', $html );
		$this->assertSame( 2, substr_count( $html, '<li class="amhdb-gig">' ) );
	}

	public function test_an_act_list_names_only_the_other_acts_on_each_bill() {
		$this->serve( $this->two_gigs( array( 'acts' => array( $this->act(), $this->act( array( 'name' => 'The Hitmen', 'subject' => false ) ) ) ) ) );
		$html = AMHDB_Gigs_Shortcode::render( array( 'artist' => 'bandanna' ) );
		$this->assertStringContainsString( 'with <a', $html );
	}

	public function test_yes_no_attributes() {
		$this->serve( $this->two_gigs() );
		$html = AMHDB_Gigs_Shortcode::render( array( 'band' => 'bandanna', 'menu' => 'yes', 'menu_count' => 'YES' ) );
		$this->assertStringContainsString( '<nav class="amhdb-gigs__menu"', $html );
		$this->assertStringContainsString( 'amhdb-gigs__menu-count', $html );

		$html = AMHDB_Gigs_Shortcode::render( array( 'band' => 'bandanna', 'menu' => 'no' ) );
		$this->assertStringNotContainsString( '<nav', $html );
	}

	public function test_two_lists_on_one_page_get_their_own_anchors() {
		$this->serve( $this->two_gigs() );
		$first  = AMHDB_Gigs_Shortcode::render( array( 'band' => 'bandanna' ) );
		$second = AMHDB_Gigs_Shortcode::render( array( 'venue' => 'bondi-lifesaver' ) );
		$this->assertStringContainsString( 'id="amhdb-gigs-1-1985"', $first );
		$this->assertStringContainsString( 'id="amhdb-gigs-2-1985"', $second );
	}

	public function test_does_not_link_to_the_page_it_is_on() {
		WP_Fake::$permalink = 'https://australianmusichistory.com/bandanna/';
		$this->serve( $this->two_gigs() );
		$html = AMHDB_Gigs_Shortcode::render( array( 'venue' => 'bondi-lifesaver' ) );
		$this->assertStringNotContainsString( 'href="https://australianmusichistory.com/bandanna/"', $html );
	}

	public function test_loads_the_stylesheet_only_when_used() {
		$this->serve( $this->two_gigs() );
		$this->assertSame( array(), WP_Fake::$styles );
		AMHDB_Gigs_Shortcode::render( array( 'band' => 'bandanna' ) );
		$this->assertSame( array( 'amhdb-gigs' ), WP_Fake::$styles );
	}

	public function test_amhdb_down_with_no_copy_points_to_the_list_on_amhdb() {
		$this->down();
		$html = AMHDB_Gigs_Shortcode::render( array( 'band' => 'bandanna' ) );
		$this->assertStringContainsString( '<p class="amhdb-gigs__unavailable">', $html );
		$this->assertStringContainsString( 'href="https://australianmusicdatabase.com/bands/bandanna"', $html );
		$this->assertStringContainsString( 'Australian Music Database', $html );

		$html = AMHDB_Gigs_Shortcode::render( array( 'person' => 'rick-price' ) );
		$this->assertStringContainsString( 'href="https://australianmusicdatabase.com/people/rick-price"', $html );

		$html = AMHDB_Gigs_Shortcode::render( array( 'year' => '1985' ) );
		$this->assertStringContainsString( 'href="https://australianmusicdatabase.com/"', $html );
	}

	public function test_no_filter_makes_no_request() {
		$this->serve( $this->two_gigs() );
		$html = AMHDB_Gigs_Shortcode::render( array( 'sort' => 'desc' ) );
		$this->assertCount( 0, WP_Fake::$requests );
		$this->assertStringContainsString( '<!-- amhdb_gigs:', $html );
	}

	public function test_empty_attributes_are_fine() {
		$this->serve( $this->two_gigs() );
		$this->assertStringContainsString( '<!-- amhdb_gigs:', AMHDB_Gigs_Shortcode::render( '' ) );
	}
}
