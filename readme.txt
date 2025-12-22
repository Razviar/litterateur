=== Litterateur API ===
Contributors: litterateur
Tags: api, rest, content-management, automation
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 1.0.8
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

REST API integration for Litterateur content management service. Allows remote content publishing and management.

== Description ==

Litterateur API provides a secure REST API for integrating your WordPress site with the Litterateur content management service. This plugin enables:

* Remote content publishing (posts, pages, custom post types)
* Category and tag management
* Author management
* Structured data publishing
* Multisite support

**Features:**

* Secure API key authentication
* Easy key rotation for security
* Full WordPress REST API integration
* Support for custom post types and ACF fields
* Multisite network support

== Installation ==

1. Upload the `litterateur-api` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > Litterateur API to view your API credentials
4. Copy the API URL and API Key to your Litterateur service configuration

== Frequently Asked Questions ==

= How do I get my API key? =

After activating the plugin, go to Settings > Litterateur API. Your API key will be displayed there.

= Can I regenerate my API key? =

Yes, go to Settings > Litterateur API and click "Regenerate API Key". Note that this will immediately invalidate the old key.

= Does this work with WordPress Multisite? =

Yes, the plugin fully supports WordPress Multisite installations. The API can list and manage content across all sites in your network.

= Is the API secure? =

Yes, all API requests require a valid API key sent via the X-API-Key header. We recommend using HTTPS for all API communications.

== Changelog ==

= 1.0.0 =
* Initial release
* Health check endpoint
* Websites listing endpoint
* API key rotation
* Categories management
* Topics (posts) publishing
* Authors management
* Structured data (custom post types) support

== Upgrade Notice ==

= 1.0.0 =
Initial release of Litterateur API plugin.

== API Documentation ==

All API endpoints are available at: `https://your-site.com/wp-json/litterateur/v1/`

**Authentication:**
Include your API key in the `X-API-Key` header with every request.

**Available Endpoints:**

* `GET /health` - Check API status (no authentication required)
* `GET /websites` - List all websites
* `POST /keys/rotate` - Rotate API key
* `GET /categories` - Get all categories
* `POST /categories` - Create/update categories
* `POST /topics` - Create a new post
* `GET /topics/{id}` - Get a post
* `PUT /topics/{id}` - Update a post
* `DELETE /topics/{id}` - Delete a post
* `GET /authors` - List all authors
* `POST /authors` - Create an author
* `GET /authors/{id}` - Get an author
* `PUT /authors/{id}` - Update an author
* `GET /structured` - Get available custom post types
* `POST /structured` - Create structured content
* `GET /structured/{id}` - Get structured content
* `PUT /structured/{id}` - Update structured content
* `DELETE /structured/{id}` - Delete structured content
