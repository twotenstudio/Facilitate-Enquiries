<?php
/**
 * Sends Gravity Forms entries to the platform and keeps track of each one.
 *
 * - Each chosen form's real submissions (not spam) are sent as they arrive.
 * - The platform's enquiry id is saved on the entry, with an entry note, so
 *   an entry is never sent twice from here (and the platform ignores resends
 *   of the same entry anyway).
 * - If the platform can't be reached, or is busy, the send is retried in the
 *   background with growing gaps, up to MAX_ATTEMPTS times. A rejection
 *   (bad API key, invalid data) is noted on the entry and not retried.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Facilitate_Enquiries_Sender {

    const META_ENQUIRY_ID = 'facilitate_enquiry_id';
    const META_ATTEMPTS   = 'facilitate_attempts';
    const RETRY_HOOK      = 'facilitate_enquiries_retry';
    const MAX_ATTEMPTS    = 6;

    public static function init() {
        add_action( 'gform_after_submission', [ __CLASS__, 'on_submission' ], 20, 2 );
        add_action( self::RETRY_HOOK, [ __CLASS__, 'retry' ] );
    }

    public static function on_submission( $entry, $form ) {
        if ( ! Facilitate_Enquiries_Settings::sends_form( (int) ( $form['id'] ?? 0 ) ) ) {
            return;
        }
        self::send( $form, $entry );
    }

    public static function retry( $entry_id ) {
        if ( ! class_exists( 'GFAPI' ) ) {
            return;
        }
        $entry = GFAPI::get_entry( (int) $entry_id );
        if ( is_wp_error( $entry ) ) {
            return;
        }
        $form = GFAPI::get_form( (int) $entry['form_id'] );
        if ( $form ) {
            self::send( $form, $entry );
        }
    }

    /**
     * @return string One of: sent, duplicate, skipped, retrying, failed.
     */
    public static function send( $form, $entry ) {
        $entry_id = (int) ( $entry['id'] ?? 0 );
        if ( ! $entry_id || 'spam' === ( $entry['status'] ?? '' ) || 'trash' === ( $entry['status'] ?? '' ) ) {
            return 'skipped';
        }
        if ( gform_get_meta( $entry_id, self::META_ENQUIRY_ID ) ) {
            return 'duplicate';
        }
        $options = Facilitate_Enquiries_Settings::get_options();
        if ( '' === $options['platform_url'] || '' === $options['api_key'] ) {
            return 'skipped';
        }

        $payload  = Facilitate_Enquiries_Mapper::map( $form, $entry );
        $response = wp_remote_post(
            $options['platform_url'] . '/api/public/enquiries',
            [
                'timeout' => 10,
                'headers' => [
                    'Authorization' => 'Bearer ' . $options['api_key'],
                    'Content-Type'  => 'application/json',
                    'User-Agent'    => 'FacilitateEnquiries/' . FACILITATE_ENQUIRIES_VERSION . '; ' . home_url(),
                ],
                'body'    => wp_json_encode( $payload ),
            ]
        );

        $code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
        $body = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 === $code || 201 === $code ) {
            $enquiry_id = is_array( $body ) && ! empty( $body['id'] ) ? (string) $body['id'] : 'sent';
            gform_update_meta( $entry_id, self::META_ENQUIRY_ID, $enquiry_id );
            gform_delete_meta( $entry_id, self::META_ATTEMPTS );
            self::note( $entry_id, sprintf( 'Sent to Facilitate as enquiry %s.', $enquiry_id ) );
            return 201 === $code ? 'sent' : 'duplicate';
        }

        $error = is_wp_error( $response )
            ? $response->get_error_message()
            : ( is_array( $body ) && ! empty( $body['error'] ) ? (string) $body['error'] : 'HTTP ' . $code );

        // Network trouble, rate limiting or a server error: try again later.
        if ( 0 === $code || 429 === $code || $code >= 500 ) {
            $attempts = (int) gform_get_meta( $entry_id, self::META_ATTEMPTS ) + 1;
            gform_update_meta( $entry_id, self::META_ATTEMPTS, $attempts );
            if ( $attempts < self::MAX_ATTEMPTS ) {
                $delay = 300 * ( 2 ** ( $attempts - 1 ) ); // 5, 10, 20, 40, 80 minutes
                wp_schedule_single_event( time() + $delay, self::RETRY_HOOK, [ $entry_id ] );
                if ( 1 === $attempts ) {
                    self::note( $entry_id, sprintf( 'Could not reach Facilitate (%s). Retrying automatically.', $error ) );
                }
                return 'retrying';
            }
            self::note( $entry_id, sprintf( 'Gave up sending to Facilitate after %d attempts: %s', $attempts, $error ) );
            return 'failed';
        }

        self::note( $entry_id, sprintf( 'Facilitate rejected this entry: %s', $error ) );
        return 'failed';
    }

    /**
     * Send entries from the last $days days that haven't been sent yet.
     *
     * @return array Counts by outcome.
     */
    public static function send_recent( $days = 30 ) {
        $counts = [ 'sent' => 0, 'duplicate' => 0, 'skipped' => 0, 'retrying' => 0, 'failed' => 0 ];
        if ( ! class_exists( 'GFAPI' ) ) {
            return $counts;
        }
        foreach ( Facilitate_Enquiries_Settings::form_ids() as $form_id ) {
            $form = GFAPI::get_form( $form_id );
            if ( ! $form ) {
                continue;
            }
            $entries = GFAPI::get_entries(
                $form_id,
                [
                    'status'     => 'active',
                    'start_date' => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS * (int) $days ),
                ],
                [ 'key' => 'date_created', 'direction' => 'ASC' ],
                [ 'offset' => 0, 'page_size' => 500 ]
            );
            if ( is_wp_error( $entries ) ) {
                continue;
            }
            foreach ( $entries as $entry ) {
                $counts[ self::send( $form, $entry ) ]++;
            }
        }
        return $counts;
    }

    private static function note( $entry_id, $text ) {
        if ( class_exists( 'GFAPI' ) && method_exists( 'GFAPI', 'add_note' ) ) {
            GFAPI::add_note( $entry_id, 0, 'Facilitate', $text );
        }
    }
}
