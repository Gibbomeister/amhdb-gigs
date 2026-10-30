<?php

class RendererTest extends AMHDB_Gigs_TestCase {

	private function render( array $entries, array $opts = array(), array $response = array() ) {
		return AMHDB_Gigs_Renderer::render( $this->response( $entries, $response ), array_replace( array( 'instance' => 1 ), $opts ) );
	}

	private function dom( $html ) {
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div id="root">' . $html . '</div>' );
		libxml_clear_errors();
		return new DOMXPath( $doc );
	}

	private function text( DOMXPath $xp, $query ) {
		$node = $xp->query( $query )->item( 0 );
		return $node ? trim( preg_replace( '/\s+/u', ' ', $node->textContent ) ) : null;
	}

	// ── Structure ────────────────────────────────────────────────────────────

	public function test_one_list_item_per_gig_inside_a_list() {
		$xp = $this->dom( $this->render( array( $this->entry(), $this->entry( array( 'ids' => array( 102 ), 'date' => '1985-02-08', 'day' => 8 ) ) ) ) );
		$this->assertSame( 2, $xp->query( '//ul[contains(@class,"amhdb-gigs")]/li[@class="amhdb-gig"]' )->length );
	}

	public function test_year_and_month_headings() {
		$html = $this->render(
			array(
				$this->entry(),
				$this->entry( array( 'date' => '1986-03-01', 'year' => 1986, 'month' => 3 ) ),
			)
		);
		$xp = $this->dom( $html );
		$this->assertSame( array( '1985', '1986' ), array_map( function ( $n ) { return $n->textContent; }, iterator_to_array( $xp->query( '//h3[@class="amhdb-gigs__year"]' ) ) ) );
		$this->assertSame( array( 'February 1985', 'March 1986' ), array_map( function ( $n ) { return $n->textContent; }, iterator_to_array( $xp->query( '//h4[@class="amhdb-gigs__month"]' ) ) ) );
		$this->assertSame( 'amhdb-gigs-1-1985', $xp->query( '//h3' )->item( 0 )->getAttribute( 'id' ) );
	}

	public function test_heading_level_is_configurable() {
		$xp = $this->dom( $this->render( array( $this->entry() ), array( 'heading' => 'h2' ) ) );
		$this->assertSame( 1, $xp->query( '//h2[@class="amhdb-gigs__year"]' )->length );
		$this->assertSame( 1, $xp->query( '//h3[@class="amhdb-gigs__month"]' )->length );
	}

	// ── Dates ────────────────────────────────────────────────────────────────

	public function test_full_date_as_a_tile_with_parts_and_no_year_under_a_year_heading() {
		$xp = $this->dom( $this->render( array( $this->entry(), $this->entry( array( 'ids' => array( 2 ), 'date' => '1985-02-08', 'day' => 8 ) ) ) ) );
		$time = $xp->query( '//time[@class="amhdb-gig__date"]' )->item( 0 );
		$this->assertSame( '1985-02-01', $time->getAttribute( 'datetime' ) );
		$this->assertSame( 'Fri', $this->text( $xp, '//span[@class="amhdb-gig__dow"]' ) );
		$this->assertSame( '1', $this->text( $xp, '//span[@class="amhdb-gig__day"]' ) );
		$this->assertSame( 'Feb', $this->text( $xp, '//span[@class="amhdb-gig__month"]' ) );
		$this->assertSame( 0, $xp->query( '//span[@class="amhdb-gig__year"]' )->length );
		$this->assertSame( 'Fri 1 Feb', $this->text( $xp, '//time' ) );
	}

	public function test_year_in_the_date_when_the_list_has_no_year_headings() {
		$xp = $this->dom( $this->render( array( $this->entry(), $this->entry( array( 'ids' => array( 2 ) ) ) ), array( 'group_artists' => true ) ) );
		$this->assertSame( '1985', $this->text( $xp, '//span[@class="amhdb-gig__year"]' ) );
	}

	public function test_a_single_gig_gets_the_long_date() {
		$xp = $this->dom( $this->render( array( $this->entry( array( 'date' => '1991-05-09', 'year' => 1991, 'month' => 5, 'day' => 9 ) ) ) ) );
		$this->assertSame( 'Thursday 9 May 1991', $this->text( $xp, '//time' ) );
	}

	public function test_end_date_as_a_range() {
		$xp = $this->dom( $this->render( array( $this->entry( array( 'end_date' => '1985-02-03' ) ), $this->entry( array( 'ids' => array( 2 ) ) ) ) ) );
		$this->assertSame( '1985-02-03', $xp->query( '//time[@class="amhdb-gig__end"]' )->item( 0 )->getAttribute( 'datetime' ) );
		$this->assertSame( 'Fri 1 Feb – Sun 3 Feb', $this->text( $xp, '//span[@class="amhdb-gig__dates"]' ) );
	}

	public function test_unknown_year_says_so_and_never_shows_a_weekday() {
		$entry = $this->entry( array( 'date' => null, 'date_precision' => 'day_month', 'year' => null, 'month' => 2, 'day' => 1 ) );
		$xp    = $this->dom( $this->render( array( $entry, $this->entry( array( 'ids' => array( 2 ) ) ) ) ) );
		$this->assertSame( 'Year unknown', $this->text( $xp, '(//h3[@class="amhdb-gigs__year"])[last()]' ) );
		$this->assertSame( 'February, year unknown', $this->text( $xp, '(//h4[@class="amhdb-gigs__month"])[last()]' ) );
		$this->assertSame( '02-01', $xp->query( '(//time[@class="amhdb-gig__date"])[last()]' )->item( 0 )->getAttribute( 'datetime' ) );
		$this->assertSame( '1 Feb', $this->text( $xp, '(//time[@class="amhdb-gig__date"])[last()]' ) );
	}

	public function test_unknown_year_is_spelt_out_when_there_are_no_year_headings() {
		$entry = $this->entry( array( 'date' => null, 'date_precision' => 'day_month', 'year' => null ) );
		$xp    = $this->dom( $this->render( array( $entry, $this->entry( array( 'ids' => array( 2 ) ) ) ), array( 'group_artists' => true ) ) );
		$this->assertSame( '1 Feb year unknown', $this->text( $xp, '//time[@class="amhdb-gig__date"]' ) );
	}

	public function test_month_and_year_only_dates() {
		$month = $this->entry( array( 'date' => null, 'date_precision' => 'month', 'day' => null, 'month' => 7 ) );
		$year  = $this->entry( array( 'ids' => array( 2 ), 'date' => null, 'date_precision' => 'year', 'day' => null, 'month' => null ) );
		$xp    = $this->dom( $this->render( array( $year, $month ) ) );
		$this->assertSame( 'July 1985', $this->text( $xp, '(//h4[@class="amhdb-gigs__month"])[1]' ), 'known months come first' );
		$this->assertSame( 'Month unknown, 1985', $this->text( $xp, '(//h4[@class="amhdb-gigs__month"])[2]' ) );
		$this->assertSame( '1985-07', $xp->query( '(//time)[1]' )->item( 0 )->getAttribute( 'datetime' ) );
		$this->assertSame( 'Jul', $this->text( $xp, '(//time)[1]' ) );
		$this->assertSame( 'Date unknown', $this->text( $xp, '(//time)[2]' ) );
	}

	// ── Acts, venue, notes ───────────────────────────────────────────────────

	public function test_acts_and_venue_link_to_amh_when_it_has_a_page_otherwise_amhdb() {
		$xp = $this->dom( $this->render( array( $this->entry(), $this->entry( array( 'ids' => array( 2 ) ) ) ) ) );
		$this->assertSame( 'https://australianmusichistory.com/bandanna/', $xp->query( '//span[@class="amhdb-gig__act"]//a' )->item( 0 )->getAttribute( 'href' ) );
		$this->assertSame( 'https://australianmusicdatabase.com/venues/bondi-lifesaver', $xp->query( '//span[@class="amhdb-gig__venue"]//a' )->item( 0 )->getAttribute( 'href' ) );
		$this->assertSame( 'Bondi Lifesaver, Bondi Junction, NSW', $this->text( $xp, '//span[@class="amhdb-gig__venue"]' ) );
	}

	public function test_venue_without_a_state_or_city_leaves_them_out() {
		$no_state = $this->entry( array( 'venue' => array_replace( $this->entry()['venue'], array( 'state' => null ) ) ) );
		$no_city  = $this->entry( array( 'ids' => array( 2 ), 'venue' => array_replace( $this->entry()['venue'], array( 'city' => '' ) ) ) );
		$xp       = $this->dom( $this->render( array( $no_state, $no_city ) ) );
		$this->assertSame( 'Bondi Lifesaver, Bondi Junction', $this->text( $xp, '(//span[@class="amhdb-gig__venue"])[1]' ) );
		$this->assertSame( 'Bondi Lifesaver, NSW', $this->text( $xp, '(//span[@class="amhdb-gig__venue"])[2]' ) );
	}

	public function test_no_link_back_to_the_page_the_list_is_on() {
		WP_Fake::$permalink = 'https://australianmusichistory.com/bandanna/';
		$xp = $this->dom( $this->render( array( $this->entry(), $this->entry( array( 'ids' => array( 2 ) ) ) ), array( 'current_url' => 'https://australianmusichistory.com/bandanna/' ) ) );
		$this->assertSame( 0, $xp->query( '//span[@class="amhdb-gig__act"]//a' )->length );
		$this->assertSame( 'Bandanna', $this->text( $xp, '//span[@class="amhdb-gig__act"]' ) );
	}

	public function test_an_artist_list_names_only_the_other_acts_on_the_bill() {
		$entry = $this->entry( array( 'acts' => array( $this->act(), $this->act( array( 'name' => 'The Hitmen', 'subject' => false, 'amh_url' => null, 'amhdb_url' => 'https://australianmusicdatabase.com/bands/the-hitmen' ) ) ) ) );
		$xp    = $this->dom( $this->render( array( $entry, $this->entry( array( 'ids' => array( 2 ) ) ) ), array( 'subject' => true ) ) );
		$this->assertSame( 'with The Hitmen', $this->text( $xp, '//span[@class="amhdb-gig__act"]' ) );
		$this->assertSame( 0, $xp->query( '(//li)[2]//span[@class="amhdb-gig__act"]' )->length, 'a solo bill needs no act line' );
	}

	public function test_other_lists_name_every_act() {
		$entry = $this->entry( array( 'acts' => array( $this->act(), $this->act( array( 'name' => 'The Hitmen', 'amh_url' => null ) ) ) ) );
		$xp    = $this->dom( $this->render( array( $entry, $this->entry( array( 'ids' => array( 2 ) ) ) ) ) );
		$this->assertSame( 'Bandanna, The Hitmen', $this->text( $xp, '//span[@class="amhdb-gig__act"]' ) );
	}

	public function test_note_and_source_lines_escape_their_text() {
		$entry = $this->entry( array( 'acts' => array( $this->act( array( 'notes' => 'Encore: <Gloria>', 'source' => "Mark's 1985 work diary" ) ) ) ) );
		$html  = $this->render( array( $entry, $this->entry( array( 'ids' => array( 2 ) ) ) ) );
		$xp    = $this->dom( $html );
		$this->assertSame( 'Encore: <Gloria>', $this->text( $xp, '//p[@class="amhdb-gig__note"]' ) );
		$this->assertStringContainsString( 'Encore: &lt;Gloria&gt;', $html );
		$this->assertSame( "Source: Mark's 1985 work diary", $this->text( $xp, '//p[@class="amhdb-gig__source"]' ) );
		$this->assertSame( 1, $xp->query( '//p[@class="amhdb-gig__note"]' )->length, 'no empty note lines' );
	}

	public function test_no_correction_link_on_each_gig() {
		$html = $this->render( array( $this->entry(), $this->entry( array( 'ids' => array( 2 ) ) ) ) );
		$this->assertStringNotContainsString( 'amhdb-gig__report', $html );
		$this->assertStringNotContainsString( 'mailto:', $html );
		$this->assertStringNotContainsString( 'Spotted something wrong', $html );
	}

	// ── Grouping by artist ───────────────────────────────────────────────────

	public function test_group_artists_puts_gigs_under_artist_headings_alphabetically() {
		$entries = array(
			$this->entry( array( 'acts' => array( $this->act( array( 'name' => 'Matt Finish' ) ) ) ) ),
			$this->entry( array( 'ids' => array( 2 ), 'acts' => array( $this->act( array( 'name' => 'Bandanna' ) ) ) ) ),
		);
		$xp = $this->dom( $this->render( $entries, array( 'group_artists' => true ) ) );
		$this->assertSame( array( 'Bandanna', 'Matt Finish' ), array_map( function ( $n ) { return trim( $n->textContent ); }, iterator_to_array( $xp->query( '//h3[@class="amhdb-gigs__artist"]' ) ) ) );
		$this->assertSame( 0, $xp->query( '//span[@class="amhdb-gig__act"]' )->length );
	}

	// ── Menu, see all, empty ─────────────────────────────────────────────────

	public function test_jump_menu_of_anchor_links_with_optional_counts() {
		$entries = array(
			$this->entry(),
			$this->entry( array( 'ids' => array( 2 ), 'date' => '1985-03-01', 'month' => 3 ) ),
			$this->entry( array( 'ids' => array( 3 ), 'date' => '1986-03-01', 'year' => 1986, 'month' => 3 ) ),
		);
		$xp = $this->dom( $this->render( $entries, array( 'menu' => true, 'menu_count' => true ) ) );
		$nav = $xp->query( '//nav[@class="amhdb-gigs__menu"]' )->item( 0 );
		$this->assertNotNull( $nav );
		$this->assertSame( 'Jump to a year', $nav->getAttribute( 'aria-label' ) );
		$this->assertSame( '#amhdb-gigs-1-1985', $xp->query( '//nav//a' )->item( 0 )->getAttribute( 'href' ) );
		$this->assertSame( '#amhdb-gigs-1-1985-3', $xp->query( '//nav//li/ul//a' )->item( 1 )->getAttribute( 'href' ) );
		$this->assertSame( '1985', $this->text( $xp, '//nav/ul/li[1]/a' ) );
		$this->assertSame( '(2 gigs)', $this->text( $xp, '//nav/ul/li[1]/span[@class="amhdb-gigs__menu-count"]' ) );
		$this->assertNotNull( $xp->query( '//*[@id="amhdb-gigs-1-1985-3"]' )->item( 0 ) );
	}

	public function test_no_menu_unless_asked() {
		$xp = $this->dom( $this->render( array( $this->entry(), $this->entry( array( 'ids' => array( 2 ) ) ) ) ) );
		$this->assertSame( 0, $xp->query( '//nav' )->length );
	}

	public function test_see_all_link_when_limit_cut_the_list_short() {
		$xp = $this->dom( $this->render( array( $this->entry(), $this->entry( array( 'ids' => array( 2 ) ) ) ), array(), array( 'count' => 217, 'see_all_url' => 'https://australianmusicdatabase.com/bands/bandanna' ) ) );
		$link = $xp->query( '//p[@class="amhdb-gigs__see-all"]/a' )->item( 0 );
		$this->assertSame( 'See all 217 gigs in the Australian Music Database', trim( $link->textContent ) );
		$this->assertSame( 'https://australianmusicdatabase.com/bands/bandanna', $link->getAttribute( 'href' ) );
	}

	public function test_no_see_all_link_when_the_whole_list_is_shown() {
		$xp = $this->dom( $this->render( array( $this->entry(), $this->entry( array( 'ids' => array( 2 ) ) ) ), array(), array( 'see_all_url' => 'https://australianmusicdatabase.com/bands/bandanna' ) ) );
		$this->assertSame( 0, $xp->query( '//p[@class="amhdb-gigs__see-all"]' )->length );
	}

	public function test_empty_state() {
		$xp = $this->dom( $this->render( array() ) );
		$this->assertSame( 'No gigs recorded yet.', $this->text( $xp, '//p[@class="amhdb-gigs__empty"]' ) );
		$this->assertSame( 0, $xp->query( '//ul' )->length );
	}

	public function test_wrapped_in_one_container() {
		$xp = $this->dom( $this->render( array( $this->entry() ) ) );
		$this->assertSame( 1, $xp->query( '/html/body/div/div[@class="amhdb-gigs-list"]' )->length );
	}

	public function test_without_month_headings() {
		$xp = $this->dom( $this->render( array( $this->entry(), $this->entry( array( 'ids' => array( 2 ), 'date' => '1986-03-01', 'year' => 1986, 'month' => 3 ) ) ), array( 'months' => false ) ) );
		$this->assertSame( 2, $xp->query( '//h3[@class="amhdb-gigs__year"]' )->length );
		$this->assertSame( 0, $xp->query( '//*[@class="amhdb-gigs__month"]' )->length );
		$this->assertSame( 2, $xp->query( '//h3/following-sibling::ul[1]/li[@class="amhdb-gig"]' )->length );
	}

	public function test_see_all_link_can_be_left_out() {
		$xp = $this->dom( $this->render( array( $this->entry() ), array( 'see_all' => false ), array( 'count' => 217, 'see_all_url' => 'https://australianmusicdatabase.com/bands/bandanna' ) ) );
		$this->assertSame( 0, $xp->query( '//p[@class="amhdb-gigs__see-all"]' )->length );
	}

	public function test_empty_state_text_can_be_changed() {
		$xp = $this->dom( $this->render( array(), array( 'empty' => 'No gigs recorded for this week yet.' ) ) );
		$this->assertSame( 'No gigs recorded for this week yet.', $this->text( $xp, '//p[@class="amhdb-gigs__empty"]' ) );
	}
}
