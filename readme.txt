=== Dizrupt MCP Abilities ===
Contributors: dizrupt
Tags: mcp, claude, ai, content-management, abilities
Requires at least: 6.9
Tested up to: 6.9.4
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

MCP Abilities for WordPress content management via Claude Desktop.

== Description ==

Dizrupt MCP Abilities registers 21 MCP-compatible Abilities using the WordPress Abilities API. Once installed alongside the WordPress/mcp-adapter plugin, these abilities expose full content management functionality to Claude Desktop via the Model Context Protocol.

**No settings UI. No OAuth. No chat interface.** This is a single-purpose abilities provider.

= Abilities included =

**Posts (6)**
* Create a post
* Read a post
* Update a post
* Publish a post
* List posts
* Move a post to trash

**Taxonomy (5)**
* List taxonomy terms
* Assign terms to a post
* Create a taxonomy term
* Update a taxonomy term
* Delete a taxonomy term

**ACF Fields (3)** — requires Advanced Custom Fields
* Get ACF field schema for a post type
* Get ACF field values for a post
* Update an ACF field value

**Post Meta (2)**
* Get post meta values
* Set a post meta value

**Media (3)**
* List media library attachments
* Upload an image from a URL to the media library
* Set the featured image on a post

**Site (2)**
* Discover site structure (all CPTs, taxonomies, ACF groups, registered meta in one call)
* Gutenberg block markup reference

= Requirements =

* WordPress 6.9 or later (Abilities API is built into core)
* [WordPress/mcp-adapter](https://github.com/WordPress/mcp-adapter) plugin — required, must be active
* Advanced Custom Fields (ACF) — optional; ACF abilities degrade gracefully if ACF is not installed

= Connecting Claude Desktop =

Claude Desktop connects to your site via the `@automattic/mcp-wordpress-remote` npm proxy using WordPress Application Password authentication.

Add the following to `~/Library/Application Support/Claude/claude_desktop_config.json` (macOS):

```json
{
  "mcpServers": {
    "wordpress": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "https://yoursite.com/wp-json/mcp/mcp-adapter-default-server",
        "WP_API_USERNAME": "your-username",
        "WP_API_PASSWORD": "your-application-password"
      }
    }
  }
}
```

Generate an Application Password at: WordPress Admin → Users → Your Profile → Application Passwords.

== Installation ==

1. Upload the `dizrupt-mcp-abilities` folder to the `/wp-content/plugins/` directory.
2. Ensure the [WordPress/mcp-adapter](https://github.com/WordPress/mcp-adapter) plugin is installed and activated.
3. Activate **Dizrupt MCP Abilities** through the Plugins menu in WordPress.
4. Configure Claude Desktop as described above.

== Frequently Asked Questions ==

= Do I need ACF installed? =

No. The plugin works without ACF. The three ACF-specific abilities (`dizrupt/acf-get-schema`, `dizrupt/acf-get-fields`, `dizrupt/acf-update-field`) return a graceful error message if ACF is not active.

= What authentication method is used? =

WordPress Application Passwords (built into WordPress core since 5.6). No OAuth or additional authentication plugins are required.

= Why does my content look different in the block editor? =

Claude sends content as plain HTML. The plugin automatically converts it to Gutenberg block markup. If the conversion produces unexpected results, ask Claude to call `dizrupt/gutenberg-reference` and provide content using the block markup format directly.

= Is there a settings page? =

No. This plugin has no settings UI. It is a pure abilities provider.

== Changelog ==

= 1.0.0 =
* Initial release. 21 abilities across 7 categories.
