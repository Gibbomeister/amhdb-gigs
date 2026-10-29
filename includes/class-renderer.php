<?php
/**
 * Turns an AMHDB gigs API response into HTML.
 *
 * Pure: data in, markup out, no WordPress state. The class names are a public
 * contract once AMH's theme styles against them (#424), so change them only
 * with a major version: amhdb-gigs-list, amhdb-gigs, amhdb-gig, amhdb-gig__date
 * (with __dow, __day, __month, __year), amhdb-gig__end, amhdb-gig__act,
 * amhdb-gig__venue, amhdb-gig__note, amhdb-gig__source.
 *
 * Dates follow AMHDB's precision fields (#429): a part the API sends as null is
 * unknown and is never shown, guessed or derived.
 *
 * @package AMHDB_Gigs
 */

defined( 'ABSPATH' ) || exit;

class AMHDB_Gigs_Renderer {

	private const DEFAULTS = array(
		'instance'      => 1,
		'heading'       => 'h3',
		'subject'       => false, // The list is one act's: name only the other acts on each bill.
		'group_artists' => false,
		'menu'          => false,
		'menu_count'    => false,
		'current_url'   => '',
	);

	private const HEADINGS = array( 'h2', 'h3', 'h4', 'h5' );

	private array $opts;
	private bool $long_dates;

	public static function render( array $data, array $opts = array() ): string {
		return ( new self( $opts ) )->html( $data );
	}

	private function __construct( array $opts ) {
		$this->opts = array_replace( self::DEFAULTS, $opts );
		if ( ! in_array( $this->opts['heading'], self::HEADINGS, true ) ) {
			$this->opts['heading'] = 'h3';
		}
	}

	private function html( array $data ): string {
		$entries = $data['gigs'] ?? array();

		if ( ! $entries ) {
			return '<div class="amhdb-gigs-list"><p class="amhdb-gigs__empty">' . esc_html__( 'No gigs recorded yet.', 'amhdb-gigs' ) . '</p></div>';
		}

		// A list of one reads as a sentence, so it gets the full date.
		$this->long_dates = 1 === count( $entries );

		$body = $this->opts['group_artists'] ? $this->by_artist( $entries ) : $this->by_date( $entries );

		return '<div class="amhdb-gigs-list">' . $body . $this->see_all( $data, count( $entries ) ) . '</div>';
	}

	// ── Layouts ──────────────────────────────────────────────────────────────

	/** Year headings, month headings under them, gigs under those. */
	private function by_date( array $entries ): string {
		$groups = $this->group_by_date( $entries );
		$out    = $this->opts['menu'] ? $this->date_menu( $groups ) : '';
		$h_year = $this->opts['heading'];
		$h_mon  = $this->sub_heading();

		foreach ( $groups as $year_key => $months ) {
			$out .= sprintf(
				'<%1$s class="amhdb-gigs__year" id="%2$s">%3$s</%1$s>',
				$h_year,
				esc_attr( $this->anchor( $year_key ) ),
				esc_html( 'unknown' === $year_key ? __( 'Year unknown', 'amhdb-gigs' ) : (string) $year_key )
			);
			foreach ( $months as $month_key => $month_entries ) {
				$out .= sprintf(
					'<%1$s class="amhdb-gigs__month" id="%2$s">%3$s</%1$s>',
					$h_mon,
					esc_attr( $this->anchor( $year_key, $month_key ) ),
					esc_html( 'unknown' === $month_key ? __( 'Month unknown', 'amhdb-gigs' ) : $this->month_name( (int) $month_key ) )
				);
				$out .= $this->gig_list( $month_entries, false );
			}
		}
		return $out;
	}

	/** One heading per act, alphabetically, with that act's gigs under it. */
	private function by_artist( array $entries ): string {
		$artists = array();
		foreach ( $entries as $entry ) {
			foreach ( $this->subject_acts( $entry ) as $act ) {
				$artists[ $act['name'] ]['act']       = $act;
				$artists[ $act['name'] ]['entries'][] = $entry;
			}
		}
		uksort( $artists, 'strcasecmp' );

		$out = '';
		if ( $this->opts['menu'] ) {
			$items = '';
			foreach ( $artists as $name => $group ) {
				$items .= '<li><a href="#' . esc_attr( $this->anchor( 'artist', sanitize_title( $name ) ) ) . '">' . esc_html( $name ) . '</a>' . $this->menu_count( count( $group['entries'] ) ) . '</li>';
			}
			$out .= '<nav class="amhdb-gigs__menu" aria-label="' . esc_attr__( 'Jump to an artist', 'amhdb-gigs' ) . '"><ul>' . $items . '</ul></nav>';
		}
		foreach ( $artists as $name => $group ) {
			$out .= sprintf(
				'<%1$s class="amhdb-gigs__artist" id="%2$s">%3$s</%1$s>',
				$this->opts['heading'],
				esc_attr( $this->anchor( 'artist', sanitize_title( $name ) ) ),
				$this->link( $group['act'] )
			);
			$out .= $this->gig_list( $group['entries'], true );
		}
		return $out;
	}

	/**
	 * Entries grouped year → month, keeping the API's order (it sorts), with
	 * year-unknown last and, within a year, unknown-month entries last.
	 */
	private function group_by_date( array $entries ): array {
		$groups = array();
		foreach ( $entries as $entry ) {
			$year  = $entry['year'] ?? 'unknown';
			$month = $entry['month'] ?? 'unknown';
			$groups[ $year ][ $month ][] = $entry;
		}
		if ( isset( $groups['unknown'] ) ) {
			$unknown = $groups['unknown'];
			unset( $groups['unknown'] );
			$groups['unknown'] = $unknown;
		}
		foreach ( $groups as $year => $months ) {
			if ( isset( $months['unknown'] ) ) {
				$unknown = $months['unknown'];
				unset( $months['unknown'] );
				$months['unknown'] = $unknown;
				$groups[ $year ]   = $months;
			}
		}
		return $groups;
	}

	private function date_menu( array $groups ): string {
		$items = '';
		foreach ( $groups as $year_key => $months ) {
			$label     = 'unknown' === $year_key ? __( 'Year unknown', 'amhdb-gigs' ) : (string) $year_key;
			$month_lis = '';
			$total     = 0;
			foreach ( $months as $month_key => $month_entries ) {
				$total += count( $month_entries );
				$name   = 'unknown' === $month_key ? __( 'Month unknown', 'amhdb-gigs' ) : $this->month_name( (int) $month_key, 'M' );
				$month_lis .= '<li><a href="#' . esc_attr( $this->anchor( $year_key, $month_key ) ) . '">' . esc_html( $name ) . '</a>' . $this->menu_count( count( $month_entries ) ) . '</li>';
			}
			$items .= '<li><a href="#' . esc_attr( $this->anchor( $year_key ) ) . '">' . esc_html( $label ) . '</a>' . $this->menu_count( $total ) . '<ul>' . $month_lis . '</ul></li>';
		}
		return '<nav class="amhdb-gigs__menu" aria-label="' . esc_attr__( 'Jump to a year', 'amhdb-gigs' ) . '"><ul>' . $items . '</ul></nav>';
	}

	private function menu_count( int $n ): string {
		if ( ! $this->opts['menu_count'] ) {
			return '';
		}
		/* translators: %d: number of gigs */
		$text = sprintf( 1 === $n ? __( '(%d gig)', 'amhdb-gigs' ) : __( '(%d gigs)', 'amhdb-gigs' ), $n );
		return ' <span class="amhdb-gigs__menu-count">' . esc_html( $text ) . '</span>';
	}

	// ── One gig ──────────────────────────────────────────────────────────────

	private function gig_list( array $entries, bool $show_year ): string {
		$items = '';
		foreach ( $entries as $entry ) {
			$items .= $this->gig( $entry, $show_year );
		}
		return '<ul class="amhdb-gigs">' . $items . '</ul>';
	}

	private function gig( array $entry, bool $show_year ): string {
		$show_year = $show_year || $this->long_dates;
		$details   = $this->opts['group_artists'] ? '' : $this->acts_line( $entry );
		$details  .= $this->venue_line( $entry['venue'] );

		$notes = $sources = array();
		foreach ( $this->subject_acts( $entry ) as $act ) {
			if ( ! empty( $act['notes'] ) ) {
				$notes[ $act['notes'] ] = true;
			}
			if ( ! empty( $act['source'] ) ) {
				$sources[ $act['source'] ] = true;
			}
		}
		foreach ( array_keys( $notes ) as $note ) {
			$details .= '<p class="amhdb-gig__note">' . esc_html( $note ) . '</p>';
		}
		foreach ( array_keys( $sources ) as $source ) {
			/* translators: %s: where the gig record comes from, e.g. a work diary */
			$details .= '<p class="amhdb-gig__source">' . esc_html( sprintf( __( 'Source: %s', 'amhdb-gigs' ), $source ) ) . '</p>';
		}

		return '<li class="amhdb-gig">' . $this->dates( $entry, $show_year ) . '<div class="amhdb-gig__body">' . $details . '</div></li>';
	}

	private function dates( array $entry, bool $show_year ): string {
		$start = $this->date_tile( $entry, $show_year, 'amhdb-gig__date' );

		// end_date only comes with a full start date (#418, #429).
		if ( empty( $entry['end_date'] ) || 'day' !== $entry['date_precision'] ) {
			return $start;
		}
		[ $y, $m, $d ] = array_map( 'intval', explode( '-', $entry['end_date'] ) );
		$end = $this->date_tile(
			array( 'date' => $entry['end_date'], 'date_precision' => 'day', 'year' => $y, 'month' => $m, 'day' => $d ),
			$show_year,
			'amhdb-gig__end'
		);
		return '<span class="amhdb-gig__dates">' . $start . ' – ' . $end . '</span>';
	}

	/**
	 * The date as a tile of parts AMH can style, e.g. <time datetime="1985-02-01">
	 * Fri 1 Feb</time>. The year part is only there when no heading carries it.
	 */
	private function date_tile( array $entry, bool $show_year, string $class ): string {
		$y = $entry['year'];
		$m = $entry['month'];
		$d = $entry['day'];
		$long  = $this->long_dates;
		$parts = array();

		switch ( $entry['date_precision'] ) {
			case 'day':
				$datetime = sprintf( '%04d-%02d-%02d', $y, $m, $d );
				$ts       = gmmktime( 0, 0, 0, $m, $d, $y );
				$parts[]  = $this->part( 'dow', gmdate( $long ? 'l' : 'D', $ts ) );
				$parts[]  = $this->part( 'day', (string) $d );
				$parts[]  = $this->part( 'month', $this->month_name( $m, $long ? 'F' : 'M' ) );
				$year     = (string) $y;
				break;
			case 'day_month':
				$datetime = sprintf( '%02d-%02d', $m, $d ); // An HTML "yearless date".
				$parts[]  = $this->part( 'day', (string) $d );
				$parts[]  = $this->part( 'month', $this->month_name( $m, $long ? 'F' : 'M' ) );
				$year     = __( 'year unknown', 'amhdb-gigs' );
				break;
			case 'month':
				$datetime = sprintf( '%04d-%02d', $y, $m );
				$parts[]  = $this->part( 'month', $this->month_name( $m, $long ? 'F' : 'M' ) );
				$year     = (string) $y;
				break;
			default: // year
				$datetime = sprintf( '%04d', $y );
				$parts[]  = $this->part( 'unknown', __( 'Date unknown', 'amhdb-gigs' ) );
				$year     = (string) $y;
		}
		if ( $show_year ) {
			$parts[] = $this->part( 'year', $year );
		}
		return '<time class="' . esc_attr( $class ) . '" datetime="' . esc_attr( $datetime ) . '">' . implode( ' ', $parts ) . '</time>';
	}

	private function part( string $name, string $text ): string {
		return '<span class="amhdb-gig__' . $name . '">' . esc_html( $text ) . '</span>';
	}

	/** "with The Hitmen" on one act's list; every act otherwise. */
	private function acts_line( array $entry ): string {
		if ( $this->opts['subject'] ) {
			$others = array_filter( $entry['acts'], fn( $act ) => empty( $act['subject'] ) );
			if ( ! $others ) {
				return '';
			}
			/* translators: %s: the other acts on the bill */
			$text = sprintf( esc_html__( 'with %s', 'amhdb-gigs' ), implode( ', ', array_map( array( $this, 'link' ), $others ) ) );
		} else {
			$text = implode( ', ', array_map( array( $this, 'link' ), $entry['acts'] ) );
		}
		return '<span class="amhdb-gig__act">' . $text . '</span>';
	}

	/** "Bondi Lifesaver, Bondi Junction, NSW", as on AMHDB's own pages. */
	private function venue_line( array $venue ): string {
		$place = array_filter( array( $venue['city'] ?? '', $venue['state'] ?? '' ), 'strlen' );
		$where = $place ? ', ' . esc_html( implode( ', ', $place ) ) : '';
		return '<span class="amhdb-gig__venue">' . $this->link( $venue ) . $where . '</span>';
	}

	/** An act or venue name, linked to its AMH post if it has one, else AMHDB. */
	private function link( array $thing ): string {
		$name = esc_html( $thing['name'] ?? '' );
		$url  = $thing['amh_url'] ?: ( $thing['amhdb_url'] ?? '' );
		if ( ! $url || $this->same_page( $url ) ) {
			return $name;
		}
		return '<a href="' . esc_url( $url ) . '">' . $name . '</a>';
	}

	private function same_page( string $url ): bool {
		$here = $this->opts['current_url'];
		return $here && untrailingslashit( $url ) === untrailingslashit( $here );
	}

	private function see_all( array $data, int $shown ): string {
		$count = (int) ( $data['count'] ?? 0 );
		if ( $count <= $shown || empty( $data['see_all_url'] ) ) {
			return '';
		}
		/* translators: %d: total number of gigs */
		$text = sprintf( __( 'See all %d gigs in the Australian Music Database', 'amhdb-gigs' ), $count );
		return '<p class="amhdb-gigs__see-all"><a href="' . esc_url( $data['see_all_url'] ) . '">' . esc_html( $text ) . '</a></p>';
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	/** On one act's list the subject acts carry the notes; otherwise every act does. */
	private function subject_acts( array $entry ): array {
		return array_values( array_filter( $entry['acts'], fn( $act ) => ! empty( $act['subject'] ) ) );
	}

	private function anchor( string|int $year_key, string|int|null $month_key = null ): string {
		$id = 'amhdb-gigs-' . (int) $this->opts['instance'] . '-' . $year_key;
		return null === $month_key ? $id : $id . '-' . $month_key;
	}

	private function sub_heading(): string {
		$level = (int) substr( $this->opts['heading'], 1 );
		return 'h' . min( 6, $level + 1 );
	}

	private function month_name( int $month, string $format = 'F' ): string {
		return gmdate( $format, gmmktime( 0, 0, 0, $month, 1, 2000 ) );
	}
}
