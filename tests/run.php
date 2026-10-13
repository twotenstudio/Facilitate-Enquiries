<?php
/**
 * Plugin checks that run without WordPress (not shipped in the plugin zip):
 *
 *   php tests/run.php                       mapping checks only
 *   FE_URL=https://estate.facilitate.app FE_KEY=... php tests/run.php
 *                                           also sends to a real platform
 *
 * WordPress and Gravity Forms are replaced by the few functions the plugin
 * uses. The form mirrors the Trevear Farm property page booking form.
 */

define( 'ABSPATH', __DIR__ );
define( 'FACILITATE_ENQUIRIES_VERSION', '1.1.0' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['fe'] = [
    'options' => [ 'facilitate_enquiries_settings' => [ 'platform_url' => (string) getenv( 'FE_URL' ), 'api_key' => (string) getenv( 'FE_KEY' ), 'form_ids' => '2' ] ],
    'meta'    => [],
    'notes'   => [],
    'events'  => [],
];

// ── WordPress stand-ins ──────────────────────────────────────────────
function get_option( $key, $default = false ) { return $GLOBALS['fe']['options'][ $key ] ?? $default; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, (array) $args ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function home_url() { return 'https://trevearfarm.co.uk'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function apply_filters( $hook, $value ) { return $value; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function absint( $v ) { return abs( (int) $v ); }
function add_action() {}
function add_filter() {}
class WP_Error {
    private $message;
    public function __construct( $code, $message ) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_remote_post( $url, $args ) {
    $headers = [];
    foreach ( $args['headers'] as $k => $v ) {
        $headers[] = "$k: $v";
    }
    $ch = curl_init( $url );
    curl_setopt_array( $ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $args['body'],
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $args['timeout'],
    ] );
    $body = curl_exec( $ch );
    if ( false === $body ) {
        return new WP_Error( 'http_request_failed', curl_error( $ch ) );
    }
    return [ 'code' => curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ), 'body' => $body ];
}
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_schedule_single_event( $time, $hook, $args ) { $GLOBALS['fe']['events'][] = [ $time, $hook, $args ]; }
function get_transient( $key ) { return $GLOBALS['fe']['transients'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['fe']['transients'][ $key ] = $value; }
function delete_transient( $key ) { unset( $GLOBALS['fe']['transients'][ $key ] ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function wp_remote_get( $url, $args ) {
    $GLOBALS['fe']['gets'][] = $url;
    return $GLOBALS['fe']['github'] ?? new WP_Error( 'http_request_failed', 'offline' );
}

// ── Gravity Forms stand-ins ──────────────────────────────────────────
function gform_get_meta( $id, $key ) { return $GLOBALS['fe']['meta'][ $id ][ $key ] ?? false; }
function gform_update_meta( $id, $key, $value ) { $GLOBALS['fe']['meta'][ $id ][ $key ] = $value; }
function gform_delete_meta( $id, $key ) { unset( $GLOBALS['fe']['meta'][ $id ][ $key ] ); }
class GFAPI {
    public static function add_note( $entry_id, $user_id, $user_name, $note ) { $GLOBALS['fe']['notes'][ $entry_id ][] = $note; }
}
class Field {
    public $id, $type, $label, $adminLabel = '', $inputType = '', $inputs = null;
    public function __construct( $id, $type, $label, $inputs = null ) {
        $this->id = $id; $this->type = $type; $this->label = $label; $this->inputs = $inputs;
    }
}

require __DIR__ . '/../includes/class-facilitate-enquiries-settings.php';
require __DIR__ . '/../includes/class-facilitate-enquiries-mapper.php';
require __DIR__ . '/../includes/class-facilitate-enquiries-sender.php';
require __DIR__ . '/../includes/class-facilitate-enquiries-updater.php';

$failures = 0;
function check( $name, $ok, $detail = '' ) {
    global $failures;
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( ! $ok && $detail ? "  [$detail]" : '' ) . "\n";
    if ( ! $ok ) {
        $failures++;
    }
}

// The Trevear Farm booking enquiry form (Gravity Forms form 2).
$ids = function ( $id, $subs ) { return array_map( function ( $s ) use ( $id ) { return [ 'id' => "$id.$s" ]; }, $subs ); };
$form = [
    'id'     => 2,
    'fields' => [
        new Field( 15, 'hidden', 'Cottage' ),
        new Field( 1, 'name', 'Name', $ids( 1, [ 2, 3, 4, 6, 8 ] ) ),
        new Field( 3, 'email', 'Email' ),
        new Field( 4, 'phone', 'Phone' ),
        new Field( 5, 'address', 'Address', $ids( 5, [ 1, 2, 3, 4, 5, 6 ] ) ),
        new Field( 6, 'date', 'Preferred Checkin Date' ),
        new Field( 7, 'date', 'Preferred Checkout Date' ),
        new Field( 8, 'select', 'Adults' ),
        new Field( 9, 'select', 'Children' ),
        new Field( 10, 'select', 'Dogs?' ),
        new Field( 17, 'select', 'High Chairs Required' ),
        new Field( 18, 'select', 'Cots Required' ),
        new Field( 11, 'select', 'How did you hear about us?' ),
        new Field( 12, 'textarea', 'Anything else you would like to tell us?' ),
        new Field( 14, 'consent', 'Consent', $ids( 14, [ 1, 2, 3 ] ) ),
        new Field( 19, 'turnstile', 'CAPTCHA' ),
    ],
];
$property_page = getenv( 'FE_PROPERTY_URL' ) ?: 'https://trevearfarm.co.uk/retreats/new-cottage/';
$entry = [
    'id'         => (int) ( getenv( 'FE_ENTRY_ID' ) ?: 1234 ),
    'form_id'    => 2,
    'status'     => 'active',
    'source_url' => $property_page,
    '15'         => 'NEW COTTAGE',
    '1.3'        => 'Rosie',
    '1.6'        => 'Penrose',
    '3'          => 'rosie@example.test',
    '4'          => '07700 900321',
    '5.1'        => '1 Harbour Row',
    '5.3'        => 'St Ives',
    '5.4'        => 'Cornwall',
    '5.5'        => 'TR26 1AA',
    '5.6'        => 'United Kingdom',
    '6'          => '2027-06-04',
    '7'          => '2027-06-11',
    '8'          => '2',
    '9'          => '1',
    '10'         => '1 (£30)',
    '17'         => '1',
    '18'         => '0',
    '11'         => 'Returning Guest',
    '12'         => "Do you have a travel cot?\nThanks!",
    '14.1'       => '1',
    '14.2'       => 'I agree to the privacy policy.',
    '14.3'       => '1',
    '19'         => 'turnstile-token',
];

// ── Mapping ──────────────────────────────────────────────────────────
$p = Facilitate_Enquiries_Mapper::map( $form, $entry );
check( 'externalId names the site, form and entry', $p['externalId'] === 'gf:trevearfarm.co.uk:2:' . $entry['id'], $p['externalId'] );
check( 'guest name joins first and last', $p['guest']['name'] === 'Rosie Penrose', $p['guest']['name'] );
check( 'email and phone', $p['guest']['email'] === 'rosie@example.test' && $p['guest']['phone'] === '07700 900321' );
check( 'address parts', $p['guest']['addressLine1'] === '1 Harbour Row' && $p['guest']['city'] === 'St Ives' && $p['guest']['postcode'] === 'TR26 1AA' && $p['guest']['country'] === 'United Kingdom' );
check( 'dates by label', $p['arrival'] === '2027-06-04' && $p['departure'] === '2027-06-11' );
check( 'party counts by label', $p['adults'] === '2' && $p['children'] === '1' && $p['pets'] === '1 (£30)' );
check( 'referral and message', $p['referral'] === 'Returning Guest' && strpos( $p['message'], 'travel cot' ) !== false );
check( 'property from the hidden field and the page', $p['property']['name'] === 'NEW COTTAGE' && $p['property']['url'] === $property_page );
check( 'privacy consent is not marketing consent', empty( $p['marketingConsent'] ) );
$labels = array_column( $p['answers'], 'label' );
check( 'answers keep every answered field', in_array( 'High Chairs Required', $labels, true ) && in_array( 'Cots Required', $labels, true ) && in_array( 'Consent', $labels, true ) );
check( 'answers skip the captcha', ! in_array( 'CAPTCHA', $labels, true ) );
$consent = array_values( array_filter( $p['answers'], function ( $a ) { return $a['label'] === 'Consent'; } ) );
check( 'consent answer is the agreed text', $consent && $consent[0]['value'] === 'I agree to the privacy policy.' );

$marketing_form = $form;
$marketing_form['fields'][] = new Field( 20, 'checkbox', 'Send me news and offers', $ids( 20, [ 1 ] ) );
$p2 = Facilitate_Enquiries_Mapper::map( $marketing_form, $entry + [ '20.1' => 'Yes please' ] );
check( 'a ticked marketing checkbox opts in', ! empty( $p2['marketingConsent'] ) );
$p3 = Facilitate_Enquiries_Mapper::map( [ 'id' => 2, 'fields' => [ new Field( 3, 'email', 'Email' ) ] ], [ 'id' => 9, '3' => 'solo@x.test' ] );
check( 'a form without a name field falls back to the email name', $p3['guest']['name'] === 'solo' );

// ── Updates from GitHub releases ─────────────────────────────────────
$release_json = function ( $tag, $extra = [] ) {
    return [ 'code' => 200, 'body' => json_encode( $extra + [
        'tag_name'     => $tag,
        'html_url'     => 'https://github.com/twotenstudio/Facilitate-Enquiries/releases/tag/' . $tag,
        'body'         => "* Fixed a thing",
        'published_at' => '2026-10-13T10:00:00Z',
        'assets'       => [ [ 'name' => 'facilitate-enquiries.zip', 'browser_download_url' => 'https://github.com/twotenstudio/Facilitate-Enquiries/releases/download/' . $tag . '/facilitate-enquiries.zip' ] ],
    ] ) ];
};
Facilitate_Enquiries_Updater::init( 'facilitate-enquiries/facilitate-enquiries.php' );
$GLOBALS['fe']['github'] = $release_json( 'v1.2.0' );
$t = Facilitate_Enquiries_Updater::inject_update( (object) [ 'response' => [], 'no_update' => [] ] );
$offer = $t->response['facilitate-enquiries/facilitate-enquiries.php'] ?? null;
check( 'offers a newer GitHub release as an update', $offer && $offer->new_version === '1.2.0' && strpos( $offer->package, '/v1.2.0/facilitate-enquiries.zip' ) !== false );
Facilitate_Enquiries_Updater::inject_update( (object) [ 'response' => [] ] );
check( 'caches the release lookup', count( $GLOBALS['fe']['gets'] ) === 1, (string) count( $GLOBALS['fe']['gets'] ) );
Facilitate_Enquiries_Updater::flush();
$GLOBALS['fe']['github'] = $release_json( 'v1.1.0' );
$t = Facilitate_Enquiries_Updater::inject_update( (object) [ 'response' => [ 'facilitate-enquiries/facilitate-enquiries.php' => 'stale' ], 'no_update' => [] ] );
check( 'the current version is listed as up to date', empty( $t->response ) && isset( $t->no_update['facilitate-enquiries/facilitate-enquiries.php'] ) );
check( 'ignores pre-releases and drafts', null === Facilitate_Enquiries_Updater::parse_release( json_decode( $release_json( 'v9.0.0', [ 'prerelease' => true ] )['body'], true ) ) );
check( 'ignores a release without the plugin zip', null === Facilitate_Enquiries_Updater::parse_release( [ 'tag_name' => 'v9.0.0', 'assets' => [] ] ) );
check( 'ignores tags that are not versions', null === Facilitate_Enquiries_Updater::parse_release( json_decode( $release_json( 'nightly' )['body'], true ) ) );
Facilitate_Enquiries_Updater::flush();
unset( $GLOBALS['fe']['github'] );
$t = Facilitate_Enquiries_Updater::inject_update( (object) [ 'response' => [] ] );
check( 'GitHub being unreachable offers nothing', empty( $t->response ) );
check( 'auto-updates this plugin', true === Facilitate_Enquiries_Updater::auto_update( null, (object) [ 'plugin' => 'facilitate-enquiries/facilitate-enquiries.php' ] ) );
check( 'leaves other plugins alone', null === Facilitate_Enquiries_Updater::auto_update( null, (object) [ 'plugin' => 'akismet/akismet.php' ] ) );
Facilitate_Enquiries_Updater::flush();
$GLOBALS['fe']['github'] = $release_json( 'v1.2.0' );
$info = Facilitate_Enquiries_Updater::plugin_info( false, 'plugin_information', (object) [ 'slug' => 'facilitate-enquiries' ] );
check( 'shows release notes in View details', $info && $info->version === '1.2.0' && strpos( $info->sections['changelog'], 'Fixed a thing' ) !== false );

// ── Sending (only with FE_URL and FE_KEY) ────────────────────────────
if ( getenv( 'FE_URL' ) && getenv( 'FE_KEY' ) ) {
    $first = Facilitate_Enquiries_Sender::send( $form, $entry );
    check( 'sends a new entry', $first === 'sent', $first . ' ' . json_encode( $GLOBALS['fe']['notes'] ) );
    check( 'saves the enquiry id on the entry', (bool) gform_get_meta( $entry['id'], 'facilitate_enquiry_id' ) );
    check( 'adds an entry note', isset( $GLOBALS['fe']['notes'][ $entry['id'] ] ) && strpos( end( $GLOBALS['fe']['notes'][ $entry['id'] ] ), 'Sent to Facilitate' ) === 0 );
    check( 'never sends an entry twice', Facilitate_Enquiries_Sender::send( $form, $entry ) === 'duplicate' );
    gform_delete_meta( $entry['id'], 'facilitate_enquiry_id' );
    check( 'the platform ignores a resend too', Facilitate_Enquiries_Sender::send( $form, $entry ) === 'duplicate' );
    check( 'skips spam', Facilitate_Enquiries_Sender::send( $form, [ 'status' => 'spam', 'id' => 777 ] + $entry ) === 'skipped' );

    $GLOBALS['fe']['options']['facilitate_enquiries_settings']['api_key'] = str_repeat( 'x', 64 );
    $bad = Facilitate_Enquiries_Sender::send( $form, [ 'id' => 778 ] + $entry );
    check( 'a wrong key is noted, not retried', $bad === 'failed' && strpos( end( $GLOBALS['fe']['notes'][778] ), 'rejected' ) !== false && ! $GLOBALS['fe']['events'], $bad );

    $GLOBALS['fe']['options']['facilitate_enquiries_settings']['platform_url'] = 'http://127.0.0.1:9';
    $down = Facilitate_Enquiries_Sender::send( $form, [ 'id' => 779 ] + $entry );
    check( 'an unreachable platform is retried later', $down === 'retrying' && count( $GLOBALS['fe']['events'] ) === 1 && $GLOBALS['fe']['events'][0][1] === 'facilitate_enquiries_retry', $down );
}

echo $failures ? "\n$failures failed\n" : "\nall passed\n";
exit( $failures ? 1 : 0 );
