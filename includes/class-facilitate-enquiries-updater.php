<?php
/**
 * Updates from GitHub releases, through WordPress's normal plugin updates.
 *
 * The plugin's repository publishes each version as a GitHub release with a
 * `facilitate-enquiries.zip` asset (built by .github/workflows/release.yml).
 * WordPress's twice-daily update check asks GitHub for the latest release
 * (cached for six hours) and, when it is newer, offers it like any other
 * plugin update. Auto-updates are on by default so a fix reaches every site
 * without anyone logging in; define FACILITATE_ENQUIRIES_DISABLE_AUTO_UPDATE
 * in wp-config.php to update by hand instead.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Facilitate_Enquiries_Updater {

    const REPO      = 'twotenstudio/Facilitate-Enquiries';
    const SLUG      = 'facilitate-enquiries';
    const ASSET     = 'facilitate-enquiries.zip';
    const TRANSIENT = 'facilitate_enquiries_release';

    /** @var string plugin_basename() of the main file */
    private static $basename = '';

    public static function init( $basename ) {
        self::$basename = $basename;
        add_filter( 'pre_set_site_transient_update_plugins', [ __CLASS__, 'inject_update' ] );
        add_filter( 'plugins_api', [ __CLASS__, 'plugin_info' ], 10, 3 );
        add_filter( 'auto_update_plugin', [ __CLASS__, 'auto_update' ], 10, 2 );
        add_action( 'upgrader_process_complete', [ __CLASS__, 'flush' ], 10, 0 );
    }

    /**
     * The latest published release, or null when GitHub can't be reached or
     * has no usable release (a failed lookup is remembered for an hour).
     *
     * @return array|null { version, package, url, notes, published }
     */
    public static function latest_release() {
        $cached = get_transient( self::TRANSIENT );
        if ( is_array( $cached ) ) {
            return $cached['version'] ? $cached : null;
        }

        $repo     = apply_filters( 'facilitate_enquiries_update_repo', self::REPO );
        $response = wp_remote_get( "https://api.github.com/repos/{$repo}/releases/latest", [
            'timeout' => 10,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'FacilitateEnquiries/' . FACILITATE_ENQUIRIES_VERSION . '; ' . home_url(),
            ],
        ] );
        $release = null;
        if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            $release = self::parse_release( is_array( $data ) ? $data : [], $repo );
        }

        set_transient(
            self::TRANSIENT,
            $release ? $release : [ 'version' => '' ],
            $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS
        );
        return $release;
    }

    /** Pull what WordPress needs out of a GitHub release. */
    public static function parse_release( array $data, $repo = self::REPO ) {
        if ( ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
            return null;
        }
        $version = ltrim( (string) ( $data['tag_name'] ?? '' ), 'vV' );
        if ( ! preg_match( '/^\d+\.\d+(\.\d+)?$/', $version ) ) {
            return null;
        }
        $package = '';
        foreach ( (array) ( $data['assets'] ?? [] ) as $asset ) {
            if ( self::ASSET === ( $asset['name'] ?? '' ) && 0 === strpos( (string) ( $asset['browser_download_url'] ?? '' ), 'https://' ) ) {
                $package = $asset['browser_download_url'];
            }
        }
        if ( '' === $package ) {
            return null;
        }
        return [
            'version'   => $version,
            'package'   => $package,
            'url'       => (string) ( $data['html_url'] ?? "https://github.com/{$repo}" ),
            'notes'     => (string) ( $data['body'] ?? '' ),
            'published' => (string) ( $data['published_at'] ?? '' ),
        ];
    }

    public static function inject_update( $transient ) {
        if ( ! is_object( $transient ) ) {
            return $transient;
        }
        $release = self::latest_release();
        $item    = (object) [
            'id'           => 'github.com/' . self::REPO,
            'slug'         => self::SLUG,
            'plugin'       => self::$basename,
            'new_version'  => $release ? $release['version'] : FACILITATE_ENQUIRIES_VERSION,
            'url'          => 'https://github.com/' . self::REPO,
            'package'      => $release ? $release['package'] : '',
            'requires_php' => '7.4',
        ];
        if ( $release && version_compare( $release['version'], FACILITATE_ENQUIRIES_VERSION, '>' ) ) {
            $transient->response[ self::$basename ] = $item;
        } else {
            // Listing it as up to date lets WordPress show the auto-update toggle.
            if ( isset( $transient->response[ self::$basename ] ) ) {
                unset( $transient->response[ self::$basename ] );
            }
            $transient->no_update[ self::$basename ] = $item;
        }
        return $transient;
    }

    /** The "View details" box on the Plugins and Updates screens. */
    public static function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || self::SLUG !== ( $args->slug ?? '' ) ) {
            return $result;
        }
        $release = self::latest_release();
        return (object) [
            'name'          => 'Facilitate Enquiries',
            'slug'          => self::SLUG,
            'version'       => $release ? $release['version'] : FACILITATE_ENQUIRIES_VERSION,
            'author'        => 'Two Ten Studio',
            'homepage'      => 'https://github.com/' . self::REPO,
            'requires'      => '6.0',
            'requires_php'  => '7.4',
            'last_updated'  => $release ? $release['published'] : '',
            'download_link' => $release ? $release['package'] : '',
            'sections'      => [
                'description' => 'Sends booking enquiries from your Gravity Forms to your Facilitate back office.',
                'changelog'   => $release && '' !== $release['notes']
                    ? '<pre>' . esc_html( $release['notes'] ) . '</pre>'
                    : 'See the releases on GitHub.',
            ],
        ];
    }

    public static function auto_update( $update, $item ) {
        if ( isset( $item->plugin ) && self::$basename === $item->plugin ) {
            return ! defined( 'FACILITATE_ENQUIRIES_DISABLE_AUTO_UPDATE' );
        }
        return $update;
    }

    public static function flush() {
        delete_transient( self::TRANSIENT );
    }
}
