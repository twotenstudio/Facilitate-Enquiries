# Facilitate Enquiries

WordPress plugin that sends booking enquiries from Gravity Forms (for example
the booking form on each property page) to an estate's Facilitate back
office, where each one is filed on the guest's profile with live
availability and pricing. See [readme.txt](readme.txt) for what it does and
how to set it up on a site.

## Installing

Download `facilitate-enquiries.zip` from the
[latest release](https://github.com/twotenstudio/Facilitate-Enquiries/releases/latest)
and upload it under Plugins, Add New, Upload Plugin. After that, sites update
themselves from new releases (auto-updates are on by default).

## Releasing a new version

1. Make the change and run `php tests/run.php`.
2. Set the new version in three places: the `Version:` header and
   `FACILITATE_ENQUIRIES_VERSION` in `facilitate-enquiries.php`, and
   `Stable tag:` in `readme.txt`. Add a changelog entry to `readme.txt`.
3. Commit, then tag and push: `git tag v1.2.0 && git push origin main v1.2.0`.

The Release workflow checks the versions match, runs the tests, builds the
zip and publishes the release. Every site running the plugin sees it within
about 12 hours (WordPress checks twice a day; the plugin caches GitHub's
answer for six hours) and installs it automatically.

## Tests

`php tests/run.php` runs without WordPress. With `FE_URL` and `FE_KEY` set to
a Facilitate address and an estate's API key, it also sends a test enquiry.
