<?php
/**
 * Settings > Facilitate Enquiries: where to send, the estate's API key,
 * which forms to send, plus "Test connection" and "Send recent entries".
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Facilitate_Enquiries_Settings {

    const OPTION_NAME  = 'facilitate_enquiries_settings';
    const OPTION_GROUP = 'facilitate_enquiries';
    const PAGE_SLUG    = 'facilitate-enquiries';

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
        add_action( 'admin_post_facilitate_enquiries_test', [ __CLASS__, 'handle_test' ] );
        add_action( 'admin_post_facilitate_enquiries_backfill', [ __CLASS__, 'handle_backfill' ] );
        add_action( 'admin_notices', [ __CLASS__, 'notices' ] );
    }

    public static function get_options() {
        return wp_parse_args(
            get_option( self::OPTION_NAME, [] ),
            [ 'platform_url' => '', 'api_key' => '', 'form_ids' => '' ]
        );
    }

    /** @return int[] */
    public static function form_ids() {
        $ids = array_map( 'absint', preg_split( '/[\s,]+/', (string) self::get_options()['form_ids'], -1, PREG_SPLIT_NO_EMPTY ) );
        return array_values( array_unique( array_filter( $ids ) ) );
    }

    public static function sends_form( $form_id ) {
        return in_array( (int) $form_id, self::form_ids(), true );
    }

    public static function add_menu() {
        add_options_page( 'Facilitate Enquiries', 'Facilitate Enquiries', 'manage_options', self::PAGE_SLUG, [ __CLASS__, 'render_page' ] );
    }

    public static function register_settings() {
        register_setting( self::OPTION_GROUP, self::OPTION_NAME, [
            'type'              => 'array',
            'sanitize_callback' => [ __CLASS__, 'sanitize' ],
        ] );
    }

    public static function sanitize( $input ) {
        $current = self::get_options();
        $url     = untrailingslashit( esc_url_raw( trim( (string) ( $input['platform_url'] ?? '' ) ) ) );
        if ( '' !== $url && 0 !== strpos( $url, 'https://' ) ) {
            add_settings_error( self::OPTION_NAME, 'https', 'The platform address must start with https://' );
            $url = $current['platform_url'];
        }
        // A blank key field keeps the saved key (it is never shown again).
        $key = trim( (string) ( $input['api_key'] ?? '' ) );
        return [
            'platform_url' => $url,
            'api_key'      => '' !== $key ? sanitize_text_field( $key ) : $current['api_key'],
            'form_ids'     => implode( ', ', array_map( 'absint', preg_split( '/[\s,]+/', (string) ( $input['form_ids'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY ) ) ),
        ];
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $options = self::get_options();
        $forms   = class_exists( 'GFAPI' ) ? GFAPI::get_forms() : [];
        ?>
        <div class="wrap">
            <h1>Facilitate Enquiries</h1>
            <p>Sends booking enquiries from your Gravity Forms to your Facilitate back office, where each one is filed on the guest's profile with live availability and pricing.</p>
            <?php if ( ! class_exists( 'GFAPI' ) ) : ?>
                <div class="notice notice-warning inline"><p>Gravity Forms is not active. This plugin sends Gravity Forms entries.</p></div>
            <?php endif; ?>
            <form method="post" action="options.php">
                <?php settings_fields( self::OPTION_GROUP ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="fe-url">Facilitate address</label></th>
                        <td>
                            <input id="fe-url" type="url" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[platform_url]" value="<?php echo esc_attr( $options['platform_url'] ); ?>" placeholder="https://yourestate.facilitate.app" />
                            <p class="description">Your estate's Facilitate address.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="fe-key">API key</label></th>
                        <td>
                            <input id="fe-key" type="password" class="regular-text" autocomplete="new-password" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[api_key]" value="" placeholder="<?php echo $options['api_key'] ? esc_attr__( 'Saved. Leave blank to keep it.', 'facilitate-enquiries' ) : ''; ?>" />
                            <p class="description">Find it in Facilitate under Settings in your admin area. Keep it secret.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="fe-forms">Forms to send</label></th>
                        <td>
                            <input id="fe-forms" type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[form_ids]" value="<?php echo esc_attr( $options['form_ids'] ); ?>" placeholder="2" />
                            <p class="description">Gravity Forms form IDs, separated by commas.
                                <?php if ( $forms ) : ?>
                                    Your forms:
                                    <?php echo esc_html( implode( ', ', array_map( function ( $f ) {
                                        return $f['id'] . ' (' . $f['title'] . ')';
                                    }, $forms ) ) ); ?>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2>Check and catch up</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
                <input type="hidden" name="action" value="facilitate_enquiries_test" />
                <?php wp_nonce_field( 'facilitate_enquiries_test' ); ?>
                <?php submit_button( 'Test connection', 'secondary', 'submit', false ); ?>
            </form>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
                <input type="hidden" name="action" value="facilitate_enquiries_backfill" />
                <?php wp_nonce_field( 'facilitate_enquiries_backfill' ); ?>
                <?php submit_button( 'Send the last 30 days of entries', 'secondary', 'submit', false ); ?>
            </form>
            <p class="description">Entries already sent are skipped, so both buttons are safe to press again.</p>
        </div>
        <?php
    }

    /** An empty enquiry is refused as invalid (400) only once the key is accepted. */
    public static function handle_test() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'facilitate_enquiries_test' ) ) {
            wp_die( 'Not allowed' );
        }
        $options = self::get_options();
        if ( '' === $options['platform_url'] || '' === $options['api_key'] ) {
            self::flash( 'error', 'Save the Facilitate address and API key first.' );
        } else {
            $response = wp_remote_post( $options['platform_url'] . '/api/public/enquiries', [
                'timeout' => 10,
                'headers' => [ 'Authorization' => 'Bearer ' . $options['api_key'], 'Content-Type' => 'application/json' ],
                'body'    => '{}',
            ] );
            $code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
            if ( 400 === $code ) {
                self::flash( 'success', 'Connected. Facilitate accepted the API key.' );
            } elseif ( 401 === $code ) {
                self::flash( 'error', 'Facilitate did not accept that API key.' );
            } elseif ( 403 === $code ) {
                self::flash( 'error', 'Connected, but property booking is not switched on for this estate in Facilitate.' );
            } else {
                $detail = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $code;
                self::flash( 'error', 'Could not reach Facilitate: ' . $detail );
            }
        }
        wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) );
        exit;
    }

    public static function handle_backfill() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'facilitate_enquiries_backfill' ) ) {
            wp_die( 'Not allowed' );
        }
        $counts = Facilitate_Enquiries_Sender::send_recent( 30 );
        self::flash(
            $counts['failed'] ? 'warning' : 'success',
            sprintf(
                'Sent %d. Already sent: %d. Retrying later: %d. Failed: %d (see the entry notes).',
                $counts['sent'],
                $counts['duplicate'],
                $counts['retrying'],
                $counts['failed']
            )
        );
        wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) );
        exit;
    }

    private static function flash( $type, $message ) {
        set_transient( 'facilitate_enquiries_notice_' . get_current_user_id(), [ $type, $message ], 60 );
    }

    public static function notices() {
        $key    = 'facilitate_enquiries_notice_' . get_current_user_id();
        $notice = get_transient( $key );
        if ( ! $notice ) {
            return;
        }
        delete_transient( $key );
        printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
    }
}
