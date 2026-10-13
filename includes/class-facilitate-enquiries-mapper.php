<?php
/**
 * Turns a Gravity Forms entry into the enquiry payload the platform's
 * POST /api/public/enquiries expects.
 *
 * Fields are recognised by type and label, so a typical booking enquiry form
 * (name, email, phone, address, check-in/check-out dates, adults, children,
 * dogs, "how did you hear about us", a message, a hidden property field)
 * needs no setup. Every answered field is also sent verbatim in `answers`,
 * so nothing the guest typed is lost even when a field isn't recognised.
 * Sites with unusual forms can adjust the result with the
 * `facilitate_enquiries_payload` filter.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Facilitate_Enquiries_Mapper {

    /** Field types that never carry an answer worth sending. */
    const SKIP_TYPES = [ 'html', 'section', 'page', 'captcha', 'turnstile', 'honeypot', 'password', 'fileupload' ];

    /**
     * @param array $form  Gravity Forms form (fields are GF_Field objects).
     * @param array $entry Gravity Forms entry.
     * @return array Payload for the platform.
     */
    public static function map( $form, $entry ) {
        $payload = [
            'externalId' => self::external_id( $form, $entry ),
            'property'   => [ 'name' => null, 'url' => self::clean( self::value( $entry, 'source_url' ) ) ],
            'guest'      => [ 'name' => '' ],
            'answers'    => [],
            'pageUrl'    => self::clean( self::value( $entry, 'source_url' ) ),
        ];

        foreach ( (array) ( $form['fields'] ?? [] ) as $field ) {
            $type  = self::field_type( $field );
            if ( in_array( $type, self::SKIP_TYPES, true ) ) {
                continue;
            }
            $id    = (string) $field->id;
            $label = trim( wp_strip_all_tags( (string) ( $field->label ?? '' ) ) );
            $name  = strtolower( $label . ' ' . ( $field->adminLabel ?? '' ) );
            $display = self::display_value( $field, $type, $entry );

            switch ( $type ) {
                case 'name':
                    $parts = array_filter( array_map( function ( $part ) {
                        return trim( (string) $part );
                    }, [
                        self::value( $entry, $id . '.3' ),
                        self::value( $entry, $id . '.4' ),
                        self::value( $entry, $id . '.6' ),
                    ] ) );
                    $full = trim( implode( ' ', $parts ) );
                    if ( '' === $full ) {
                        $full = trim( (string) self::value( $entry, $id ) );
                    }
                    if ( '' !== $full && '' === $payload['guest']['name'] ) {
                        $payload['guest']['name'] = $full;
                    }
                    break;

                case 'email':
                    if ( empty( $payload['guest']['email'] ) ) {
                        $payload['guest']['email'] = self::clean( self::value( $entry, $id ) );
                    }
                    break;

                case 'phone':
                    if ( empty( $payload['guest']['phone'] ) ) {
                        $payload['guest']['phone'] = self::clean( self::value( $entry, $id ) );
                    }
                    break;

                case 'address':
                    $payload['guest']['addressLine1'] = self::clean( self::value( $entry, $id . '.1' ) );
                    $payload['guest']['addressLine2'] = self::clean( self::value( $entry, $id . '.2' ) );
                    $payload['guest']['city']         = self::clean( self::value( $entry, $id . '.3' ) );
                    $payload['guest']['county']       = self::clean( self::value( $entry, $id . '.4' ) );
                    $payload['guest']['postcode']     = self::clean( self::value( $entry, $id . '.5' ) );
                    $payload['guest']['country']      = self::clean( self::value( $entry, $id . '.6' ) );
                    break;

                case 'consent':
                    if ( preg_match( '/marketing|newsletter|offers|news and/', $name ) && '1' === (string) self::value( $entry, $id . '.1' ) ) {
                        $payload['marketingConsent'] = true;
                    }
                    break;

                default:
                    $raw = self::clean( self::value( $entry, $id ) );
                    if ( preg_match( '/check[\s-]*in|arriv/', $name ) ) {
                        $payload['arrival'] = $raw;
                    } elseif ( preg_match( '/check[\s-]*out|depart|leaving/', $name ) ) {
                        $payload['departure'] = $raw;
                    } elseif ( preg_match( '/adult/', $name ) ) {
                        $payload['adults'] = $raw;
                    } elseif ( preg_match( '/child|kids/', $name ) ) {
                        $payload['children'] = $raw;
                    } elseif ( preg_match( '/infant|bab(y|ies)/', $name ) ) {
                        $payload['infants'] = $raw;
                    } elseif ( preg_match( '/\bdogs?\b|\bpets?\b/', $name ) ) {
                        $payload['pets'] = $raw;
                    } elseif ( preg_match( '/hear about|find us|referr/', $name ) ) {
                        $payload['referral'] = $display;
                    } elseif ( preg_match( '/property|retreat|cottage|accommodation|lodge/', $name ) && ( 'hidden' === $type || 'select' === $type || 'radio' === $type ) ) {
                        $payload['property']['name'] = $display;
                    } elseif ( 'textarea' === $type && empty( $payload['message'] ) ) {
                        $payload['message'] = $display;
                    } elseif ( 'checkbox' === $type && preg_match( '/marketing|newsletter|offers/', $name ) && '' !== (string) $display ) {
                        $payload['marketingConsent'] = true;
                    }
                    break;
            }

            if ( null !== $display && '' !== $display ) {
                $payload['answers'][] = [
                    'label' => '' !== $label ? $label : ( 'Field ' . $id ),
                    'value' => (string) $display,
                ];
            }
        }

        if ( '' === $payload['guest']['name'] ) {
            $email                    = (string) ( $payload['guest']['email'] ?? '' );
            $payload['guest']['name'] = '' !== $email ? strstr( $email, '@', true ) : 'Website enquiry';
        }

        $payload['answers'] = array_slice( $payload['answers'], 0, 100 );

        return apply_filters( 'facilitate_enquiries_payload', $payload, $form, $entry );
    }

    /** Unique per site, form and entry, so the platform can ignore resends. */
    public static function external_id( $form, $entry ) {
        $host = wp_parse_url( home_url(), PHP_URL_HOST );
        return sprintf( 'gf:%s:%d:%d', $host ? $host : 'site', (int) ( $form['id'] ?? 0 ), (int) self::value( $entry, 'id' ) );
    }

    private static function field_type( $field ) {
        $input = $field->inputType ?? '';
        return '' !== (string) $input ? (string) $input : (string) ( $field->type ?? '' );
    }

    /** What the guest saw and answered, as one line of text. */
    private static function display_value( $field, $type, $entry ) {
        $id = (string) $field->id;
        if ( 'consent' === $type ) {
            return '1' === (string) self::value( $entry, $id . '.1' ) ? self::clean( self::value( $entry, $id . '.2' ) ) : null;
        }
        if ( ! empty( $field->inputs ) && is_array( $field->inputs ) ) {
            $values = [];
            foreach ( $field->inputs as $input ) {
                $value = self::clean( self::value( $entry, (string) ( $input['id'] ?? '' ) ) );
                if ( null !== $value ) {
                    $values[] = $value;
                }
            }
            return $values ? implode( ', ', $values ) : null;
        }
        $value = self::value( $entry, $id );
        if ( 'multiselect' === $type && is_string( $value ) && '' !== $value && '[' === $value[0] ) {
            $decoded = json_decode( $value, true );
            $value   = is_array( $decoded ) ? implode( ', ', $decoded ) : $value;
        }
        if ( 'date' === $type ) {
            // Gravity Forms stores dates as Y-m-d whatever the display format.
            return self::clean( $value );
        }
        return self::clean( $value );
    }

    private static function value( $entry, $key ) {
        return is_array( $entry ) && array_key_exists( $key, $entry ) ? $entry[ $key ] : null;
    }

    private static function clean( $value ) {
        if ( null === $value || is_array( $value ) ) {
            return null;
        }
        $value = trim( html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES, 'UTF-8' ) );
        return '' === $value ? null : $value;
    }
}
