=== Facilitate Enquiries ===
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later

Sends booking enquiries from Gravity Forms to your Facilitate back office.

== Description ==

When a guest fills in a booking enquiry form on your website (for example the
form on each property page), the enquiry appears in Facilitate under
Properties, Enquiries:

* filed on the guest's profile, so a returning guest is recognised by their
  email address (or name and phone number) instead of becoming a new contact;
* matched to the property, by the page it was sent from or the property name;
* with a live check of whether the requested dates are free, and the price;
* ready to turn into a booking in one step.

The form doesn't need changing. Fields are recognised by type and label:
name, email, phone, address, check-in and check-out dates, adults, children,
infants, dogs or pets, "how did you hear about us", a message, a property
field, and a marketing consent checkbox. Every answered field is also sent
exactly as the guest wrote it, so nothing is lost.

Spam entries are never sent. If Facilitate can't be reached, the plugin
retries in the background (after 5, 10, 20, 40 and 80 minutes). Each entry
gets a note saying whether it was sent, and an entry is never sent twice.

== Installation ==

1. Upload the plugin and activate it. Gravity Forms must be active.
2. In Facilitate, go to Settings in your admin area and copy the API key
   (generate one if there isn't one yet).
3. In WordPress, go to Settings, Facilitate Enquiries and enter:
   * your estate's Facilitate address, e.g. https://yourestate.facilitate.app
   * the API key
   * the ID of each booking enquiry form to send (shown on the same page)
4. Press "Test connection".
5. Optionally press "Send the last 30 days of entries" to bring in recent
   enquiries.

For properties to match, give each property in Facilitate the same slug as
the last part of its page address on your website (for /retreats/new-cottage/
use "new-cottage"), or the same name as the form's property field.

== Updates ==

New versions are published as releases on
https://github.com/twotenstudio/Facilitate-Enquiries and arrive through
WordPress's normal plugin updates. Auto-updates are on by default; to update
by hand instead, add this to wp-config.php:

    define( 'FACILITATE_ENQUIRIES_DISABLE_AUTO_UPDATE', true );

== Developers ==

Adjust what is sent with the `facilitate_enquiries_payload` filter:

    add_filter( 'facilitate_enquiries_payload', function ( $payload, $form, $entry ) {
        $payload['property']['name'] = rgar( $entry, '15' );
        return $payload;
    }, 10, 3 );

== Changelog ==

= 1.1.0 =
* Updates itself from GitHub releases, with auto-updates on by default.

= 1.0.0 =
* First release.
