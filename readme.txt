=== Mauka Meta Pixel ===
Contributors: mauka
Tags: meta pixel, facebook pixel, conversions api, capi, woocommerce, tracking
Requires at least: 5.0
Tested up to: 6.5
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Professional Meta Pixel integration with server-side CAPI, perfect deduplication and comprehensive WooCommerce support.

== Description ==

A modern, professional WordPress plugin for Meta Pixel integration that follows all WordPress and Meta best practices. This plugin provides both client-side pixel tracking and server-side Conversions API (CAPI) with perfect deduplication.

= Key Features =

* **Dual Tracking**: Both Meta Pixel (client-side) and Conversions API (server-side)
* **Perfect Deduplication**: Consistent event_id, fbp, and fbc across browser and server
* **GDPR Compliant**: All user data is properly hashed
* **Automatic Cookie Management**: Handles fbp and fbc cookies automatically
* **Test Mode**: Easy testing with test event codes
* **Individual Event Control**: Enable/disable each event type via admin interface
* **Complete WooCommerce Support**: All major e-commerce events
* **Comprehensive Logging**: All server-side requests logged for debugging
* **Security First**: Secure AJAX, proper nonces, admin-only access
* **Cache Friendly**: Compatible with WP Rocket, cache plugins, and CDNs

= Supported Events =

**Standard Events:**
* PageView - Page visits
* ViewContent - Product page views
* AddToCart - Add to cart actions
* InitiateCheckout - Checkout page visits
* Purchase - Completed purchases
* Search - Search actions

**Lead Events:**
* Lead - Contact form submissions (Contact Form 7, Gravity Forms)
* CompleteRegistration - User registrations

= WooCommerce Integration =

Perfect integration with WooCommerce including:
* Product view tracking with product details
* Add to cart events with product information
* Checkout initiation tracking
* Purchase events with order details
* User registration tracking
* Search result tracking

= Technical Features =

* Modern PHP codebase following WordPress standards
* No direct echo statements in main plugin file
* Proper plugin structure and organization
* Comprehensive error handling and logging
* Automatic deduplication between pixel and CAPI
* GDPR-compliant user data hashing
* Secure cookie management
* Test mode for development and debugging

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/mauka-meta-pixel/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to Settings > Meta Pixel to configure the plugin
4. Enter your Meta Pixel ID and Access Token
5. Enable the tracking methods you want to use
6. Configure which events to track
7. Test the connection using the "Test Connection" button

== Configuration ==

= Basic Setup =

1. **Meta Pixel ID**: Get this from your Meta Events Manager (15-16 digit number)
2. **Access Token**: Generate in Meta Developer Console for Conversions API
3. **Enable Tracking**: Choose between Pixel only, CAPI only, or both (recommended)

= Test Mode =

* Enable test mode for development and testing
* Add your test event code from Meta Events Manager
* All CAPI events will include the test event code when enabled
* Remember to disable test mode on production sites

= Event Configuration =

Enable or disable individual events based on your needs:
* Standard e-commerce events (recommended for WooCommerce sites)
* Lead tracking for contact forms
* Registration tracking for user sign-ups

== Frequently Asked Questions ==

= Do I need both Pixel and CAPI enabled? =

While you can use either individually, we recommend enabling both for maximum data reliability. The plugin ensures perfect deduplication between them.

= Is this plugin GDPR compliant? =

Yes, all user data is properly hashed using SHA256 before being sent to Meta. The plugin also respects user privacy preferences.

= Does this work with caching plugins? =

Yes, the plugin is designed to work with all major caching solutions including WP Rocket, W3 Total Cache, and CDNs.

= How do I know if events are being sent correctly? =

Use the Meta Events Manager to monitor incoming events. Enable logging in the plugin settings to see detailed server-side request logs.

= Can I test the setup before going live? =

Yes, use the test mode feature with a test event code from Meta Events Manager. This allows you to test all functionality without affecting your live data.

== Screenshots ==

1. Main settings page with basic configuration
2. Event tracking configuration options
3. Status dashboard showing plugin health
4. Log viewer for debugging

== Changelog ==

= 1.0.0 =
* Initial release
* Complete Meta Pixel and CAPI integration
* WooCommerce support for all major events
* GDPR-compliant user data handling
* Test mode and comprehensive logging
* Admin interface with Hungarian localization

== Upgrade Notice ==

= 1.0.0 =
Initial release of the professional Meta Pixel plugin.

== Technical Requirements ==

* WordPress 5.0 or higher
* PHP 7.4 or higher
* WooCommerce 5.0+ (optional, for e-commerce features)
* Meta Pixel ID and Access Token

== Support ==

For support and documentation, please visit our website or contact our support team.

== Privacy Policy ==

This plugin sends data to Meta (Facebook) for advertising and analytics purposes. All user data is hashed before transmission to comply with privacy regulations. Please ensure your privacy policy reflects the use of Meta Pixel and Conversions API.

== Credits ==

Developed by Mauka Digital Marketing Agency
Website: https://mauka.hu