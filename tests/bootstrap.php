<?php
/**
 * Test bootstrap: just enough of WordPress, faked in memory, to exercise the
 * plugin's own logic without a WordPress install. Each fake mirrors the real
 * function's contract for the arguments the plugin uses; anything else stays
 * unfaked so a new dependency fails loudly.
 *
 * Real WordPress behaviour (cron, the shortcode in a post, the theme) is
 * covered by TESTING.md on a Local site.
 */

require dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

/** In-memory WordPress state, reset between tests by AMHDB_Gigs_TestCase. */
class WP_Fake {
	public static $transients = array();
	public static $options    = array();
	public static $scheduled  = array();
	public static $styles     = array();
	public static $shortcodes = array();
	public static $actions    = array();
	public static $requests   = array();
	/** @var callable|null fn( string $url, array $args ): array|WP_Error */
	public static $http       = null;
	public static $now        = 1761700000;
	public static $permalink  = 'https://australianmusichistory.com/some-post/';

	public static function reset() {
		self::$transients = array();
		self::$options    = array();
		self::$scheduled  = array();
		self::$styles     = array();
		self::$shortcodes = array();
		self::$actions    = array();
		self::$requests   = array();
		self::$http       = null;
		self::$now        = 1761700000;
		self::$permalink  = 'https://australianmusichistory.com/some-post/';
	}
}

class WP_Error {
	private $message;
	public function __construct( $code = '', $message = '' ) {
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
	$url = (string) $url;
	if ( ! preg_match( '#^(https?:|mailto:|\#)#i', $url ) ) {
		return '';
	}
	return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function esc_html__( $text, $domain = 'default' ) {
	return esc_html( $text );
}

function esc_attr__( $text, $domain = 'default' ) {
	return esc_attr( $text );
}

function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}

function absint( $value ) {
	return abs( (int) $value );
}

function sanitize_title( $title ) {
	return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $title ) ), '-' );
}

function wp_json_encode( $data ) {
	return json_encode( $data );
}

function add_query_arg( $args, $url ) {
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
}

function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	$atts = (array) $atts;
	$out  = array();
	foreach ( $pairs as $name => $default ) {
		$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
	}
	return $out;
}

function add_shortcode( $tag, $callback ) {
	WP_Fake::$shortcodes[ $tag ] = $callback;
}

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	WP_Fake::$actions[ $hook ][] = $callback;
}

function time_now() {
	return WP_Fake::$now;
}

function get_transient( $key ) {
	if ( ! isset( WP_Fake::$transients[ $key ] ) ) {
		return false;
	}
	list( $value, $expires ) = WP_Fake::$transients[ $key ];
	if ( $expires && $expires <= WP_Fake::$now ) {
		unset( WP_Fake::$transients[ $key ] );
		return false;
	}
	return $value;
}

function set_transient( $key, $value, $ttl = 0 ) {
	WP_Fake::$transients[ $key ] = array( $value, $ttl ? WP_Fake::$now + $ttl : 0 );
	return true;
}

function delete_transient( $key ) {
	unset( WP_Fake::$transients[ $key ] );
	return true;
}

function get_option( $key, $default = false ) {
	return array_key_exists( $key, WP_Fake::$options ) ? WP_Fake::$options[ $key ] : $default;
}

function update_option( $key, $value, $autoload = null ) {
	WP_Fake::$options[ $key ] = $value;
	return true;
}

function delete_option( $key ) {
	unset( WP_Fake::$options[ $key ] );
	return true;
}

function wp_next_scheduled( $hook, $args = array() ) {
	foreach ( WP_Fake::$scheduled as $event ) {
		if ( $event['hook'] === $hook && $event['args'] === $args ) {
			return $event['time'];
		}
	}
	return false;
}

function wp_schedule_single_event( $time, $hook, $args = array() ) {
	WP_Fake::$scheduled[] = array( 'time' => $time, 'hook' => $hook, 'args' => $args );
	return true;
}

function wp_remote_get( $url, $args = array() ) {
	WP_Fake::$requests[] = array( 'url' => $url, 'args' => $args );
	if ( ! WP_Fake::$http ) {
		return new WP_Error( 'http_request_failed', 'No HTTP fake set' );
	}
	return call_user_func( WP_Fake::$http, $url, $args );
}

function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) ? $response['response']['code'] : '';
}

function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) ? $response['body'] : '';
}

function wp_remote_retrieve_header( $response, $header ) {
	if ( ! is_array( $response ) ) {
		return '';
	}
	$headers = array_change_key_case( $response['headers'], CASE_LOWER );
	return isset( $headers[ strtolower( $header ) ] ) ? $headers[ strtolower( $header ) ] : '';
}

function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false ) {
	WP_Fake::$styles[] = $handle;
}

function wp_register_style( $handle, $src, $deps = array(), $ver = false ) {
	return true;
}

function plugins_url( $path = '', $plugin = '' ) {
	return 'https://australianmusichistory.com/wp-content/plugins/amhdb-gigs/' . ltrim( $path, '/' );
}

function home_url() {
	return 'https://australianmusichistory.com';
}

function get_permalink() {
	return WP_Fake::$permalink;
}

function wp_date( $format, $timestamp = null ) {
	return gmdate( $format, null === $timestamp ? WP_Fake::$now : $timestamp );
}

define( 'AMHDB_GIGS_VERSION', 'test' );
define( 'AMHDB_GIGS_FILE', dirname( __DIR__ ) . '/amhdb-gigs.php' );
define( 'AMHDB_GIGS_API', 'https://australianmusicdatabase.com/api/v1/gigs' );

foreach ( array( 'renderer', 'api', 'shortcode' ) as $class_file ) {
	$path = dirname( __DIR__ ) . "/includes/class-$class_file.php";
	if ( file_exists( $path ) ) {
		require $path;
	}
}

/** Base test case: fresh fake WordPress, plus a builder for API entries. */
abstract class AMHDB_Gigs_TestCase extends PHPUnit\Framework\TestCase {
	protected function setUp(): void {
		WP_Fake::reset();
		if ( class_exists( 'AMHDB_Gigs_Shortcode' ) ) {
			AMHDB_Gigs_Shortcode::$instance = 0;
		}
	}

	/** One API entry, shaped like GET /api/v1/gigs returns (see AMHDB docs/gigs-api.md). */
	protected function entry( array $overrides = array() ) {
		$entry = array(
			'ids'            => array( 101 ),
			'date'           => '1985-02-01',
			'end_date'       => null,
			'date_precision' => 'day',
			'year'           => 1985,
			'month'          => 2,
			'day'            => 1,
			'acts'           => array( $this->act() ),
			'venue'          => array(
				'name'      => 'Bondi Lifesaver',
				'city'      => 'Bondi Junction',
				'state'     => 'NSW',
				'slug'      => 'bondi-lifesaver',
				'amhdb_url' => 'https://australianmusicdatabase.com/venues/bondi-lifesaver',
				'amh_url'   => null,
			),
			'tags'           => array(),
			'report_url'     => 'mailto:australianmusichistory@gmail.com?subject=Gig%20correction%20%23101',
		);
		return array_replace( $entry, $overrides );
	}

	protected function act( array $overrides = array() ) {
		return array_replace(
			array(
				'gig_id'    => 101,
				'name'      => 'Bandanna',
				'type'      => 'Band',
				'slug'      => 'bandanna',
				'amhdb_url' => 'https://australianmusicdatabase.com/bands/bandanna',
				'amh_url'   => 'https://australianmusichistory.com/bandanna/',
				'subject'   => true,
				'notes'     => null,
				'source'    => null,
			),
			$overrides
		);
	}

	/** A whole API response around some entries. */
	protected function response( array $entries, array $overrides = array() ) {
		return array_replace(
			array(
				'generated_at' => '2026-10-29T10:00:00+11:00',
				'count'        => count( $entries ),
				'see_all_url'  => null,
				'months'       => array(),
				'gigs'         => $entries,
			),
			$overrides
		);
	}
}
