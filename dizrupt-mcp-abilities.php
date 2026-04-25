<?php
/**
 * Plugin Name: Dizrupt MCP Abilities
 * Description: MCP Abilities for WordPress content management via Claude Desktop.
 * Version:     1.0.0
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Text Domain: dizrupt-mcp-abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DIZRUPT_MCP_VERSION', '1.0.0' );
define( 'DIZRUPT_MCP_DIR', plugin_dir_path( __FILE__ ) );

// ─── Dependency check ────────────────────────────────────────────────────────

add_action( 'admin_notices', function () {
	if ( defined( 'WP_MCP_DIR' ) ) {
		return;
	}

	$adapter_path = WP_PLUGIN_DIR . '/mcp-adapter/mcp-adapter.php';
	$is_installed = file_exists( $adapter_path );

	if ( $is_installed ) {
		$activate_url = wp_nonce_url(
			admin_url( 'plugins.php?action=activate&plugin=mcp-adapter/mcp-adapter.php' ),
			'activate-plugin_mcp-adapter/mcp-adapter.php'
		);
		$message = sprintf(
			/* translators: 1: plugin name, 2: closing strong, 3: link open, 4: link close */
			__( '%1$sMCP Adapter%2$s is installed but not active. %3$sActivate it now%4$s to enable MCP abilities.', 'dizrupt-mcp-abilities' ),
			'<strong>',
			'</strong>',
			'<a href="' . esc_url( $activate_url ) . '">',
			'</a>'
		);
	} else {
		$download_url = 'https://github.com/WordPress/mcp-adapter/releases/latest';
		$message      = sprintf(
			/* translators: 1: plugin name, 2: closing strong, 3: link open, 4: link close */
			__( '%1$sMCP Adapter%2$s is required for Dizrupt MCP Abilities to work. %3$sDownload and install it from GitHub%4$s.', 'dizrupt-mcp-abilities' ),
			'<strong>',
			'</strong>',
			'<a href="' . esc_url( $download_url ) . '" target="_blank" rel="noopener noreferrer">',
			'</a>'
		);
	}

	printf(
		'<div class="notice notice-warning" style="display:flex;align-items:center;gap:10px;padding:12px 16px;">'
		. '<span class="dashicons dashicons-warning" style="font-size:24px;color:#dba617;"></span>'
		. '<p style="margin:0;"><strong>Dizrupt MCP Abilities:</strong> %s</p>'
		. '</div>',
		wp_kses(
			$message,
			array(
				'strong' => array(),
				'a'      => array( 'href' => array(), 'target' => array(), 'rel' => array() ),
			)
		)
	);
} );

// ─── Ability category registration ───────────────────────────────────────────

add_action( 'wp_abilities_api_categories_init', function () {
	wp_register_ability_category(
		'dizrupt-posts',
		array(
			'label'       => __( 'Posts', 'dizrupt-mcp-abilities' ),
			'description' => __( 'Create, read, update, publish, list, and trash posts and custom post types.', 'dizrupt-mcp-abilities' ),
		)
	);
	wp_register_ability_category(
		'dizrupt-taxonomy',
		array(
			'label'       => __( 'Taxonomy', 'dizrupt-mcp-abilities' ),
			'description' => __( 'Manage terms in any taxonomy — list, create, update, delete, and assign to posts.', 'dizrupt-mcp-abilities' ),
		)
	);
	wp_register_ability_category(
		'dizrupt-acf',
		array(
			'label'       => __( 'ACF Fields', 'dizrupt-mcp-abilities' ),
			'description' => __( 'Read and update Advanced Custom Fields on posts. Requires ACF to be active.', 'dizrupt-mcp-abilities' ),
		)
	);
	wp_register_ability_category(
		'dizrupt-meta',
		array(
			'label'       => __( 'Post Meta', 'dizrupt-mcp-abilities' ),
			'description' => __( 'Read and write standard WordPress post meta fields.', 'dizrupt-mcp-abilities' ),
		)
	);
	wp_register_ability_category(
		'dizrupt-media',
		array(
			'label'       => __( 'Media', 'dizrupt-mcp-abilities' ),
			'description' => __( 'List attachments, upload images from URLs, and set featured images.', 'dizrupt-mcp-abilities' ),
		)
	);
	wp_register_ability_category(
		'dizrupt-site',
		array(
			'label'       => __( 'Site', 'dizrupt-mcp-abilities' ),
			'description' => __( 'Discover site structure and reference Gutenberg block markup.', 'dizrupt-mcp-abilities' ),
		)
	);
} );

// ─── Load abilities ───────────────────────────────────────────────────────────

add_action( 'wp_abilities_api_init', function () {
	require_once DIZRUPT_MCP_DIR . 'includes/abilities-posts.php';
	require_once DIZRUPT_MCP_DIR . 'includes/abilities-taxonomy.php';
	require_once DIZRUPT_MCP_DIR . 'includes/abilities-acf.php';
	require_once DIZRUPT_MCP_DIR . 'includes/abilities-meta.php';
	require_once DIZRUPT_MCP_DIR . 'includes/abilities-media.php';
	require_once DIZRUPT_MCP_DIR . 'includes/abilities-discovery.php';
	require_once DIZRUPT_MCP_DIR . 'includes/abilities-gutenberg.php';
} );

// ─── Shared utility functions ─────────────────────────────────────────────────

/**
 * Sanitise post content and convert plain HTML to Gutenberg block markup.
 * Returns array with 'content' key (string) and optional 'warning' key (string).
 */
function dizrupt_sanitize_content( string $raw, string $post_type = 'post' ): array {
	$raw = trim( $raw );

	if ( empty( $raw ) ) {
		return array( 'content' => '' );
	}

	// Already block markup — sanitise but skip conversion.
	if ( str_contains( $raw, '<!-- wp:' ) ) {
		if ( function_exists( 'filter_block_content' ) ) {
			$content = filter_block_content( $raw, 'post' );
		} else {
			$content = wp_kses_post( $raw );
		}
		$result = array( 'content' => $content );
		if ( ! empty( $raw ) && empty( $content ) ) {
			$result['warning'] = 'Content was not empty but became empty after sanitisation. Use Gutenberg block markup or plain HTML. Call dizrupt/gutenberg-reference for block syntax examples.';
		}
		return $result;
	}

	// Plain HTML — sanitise then convert to blocks.
	$clean   = wp_kses_post( $raw );
	$content = dizrupt_content_to_blocks( $clean, $post_type );

	$result = array( 'content' => $content );
	if ( ! empty( $raw ) && empty( $content ) ) {
		$result['warning'] = 'Content was not empty but became empty after sanitisation. Use Gutenberg block markup or plain HTML. Call dizrupt/gutenberg-reference for block syntax examples.';
	}
	return $result;
}

/**
 * Convert plain HTML to Gutenberg block markup.
 */
function dizrupt_content_to_blocks( string $html, string $post_type = 'post' ): string {
	if ( str_contains( $html, '<!-- wp:' ) ) {
		return $html;
	}

	// Post type does not use block editor — keep raw HTML.
	if ( function_exists( 'use_block_editor_for_post_type' )
		&& ! use_block_editor_for_post_type( $post_type ) ) {
		return $html;
	}

	$html = trim( $html );
	if ( empty( $html ) ) {
		return '';
	}

	// Plain text with no HTML tags — wrap paragraphs in blocks.
	if ( ! preg_match( '/<[a-z]/i', $html ) ) {
		$paragraphs = preg_split( '/\n{2,}/', $html );
		$blocks     = array();
		foreach ( $paragraphs as $para ) {
			$para = trim( $para );
			if ( '' !== $para ) {
				$blocks[] = "<!-- wp:paragraph -->\n<p>" . nl2br( esc_html( $para ) ) . "</p>\n<!-- /wp:paragraph -->";
			}
		}
		return implode( "\n\n", $blocks );
	}

	$result = $html;

	// Headings h1–h6 (must run before <p> to avoid conflicts).
	for ( $i = 1; $i <= 6; $i++ ) {
		$attrs  = ( 2 === $i ) ? '' : ' {"level":' . $i . '}';
		$result = preg_replace(
			'#<h' . $i . '(\s[^>]*)?>(.+?)</h' . $i . '>#si',
			'<!-- wp:heading' . $attrs . " -->\n<h" . $i . ' class="wp-block-heading">$2</h' . $i . ">\n<!-- /wp:heading -->",
			$result
		);
	}

	// Paragraphs.
	$result = preg_replace(
		'#<p(\s[^>]*)?>(.+?)</p>#si',
		"<!-- wp:paragraph -->\n<p\$1>\$2</p>\n<!-- /wp:paragraph -->",
		$result
	);

	// Unordered lists.
	$result = preg_replace_callback(
		'#<ul(\s[^>]*)?>([\s\S]*?)</ul>#si',
		function ( $m ) {
			$inner = dizrupt_wrap_list_items( $m[2] );
			return "<!-- wp:list -->\n<ul class=\"wp-block-list\">" . $inner . "</ul>\n<!-- /wp:list -->";
		},
		$result
	);

	// Ordered lists.
	$result = preg_replace_callback(
		'#<ol(\s[^>]*)?>([\s\S]*?)</ol>#si',
		function ( $m ) {
			$inner = dizrupt_wrap_list_items( $m[2] );
			return "<!-- wp:list {\"ordered\":true} -->\n<ol class=\"wp-block-list\">" . $inner . "</ol>\n<!-- /wp:list -->";
		},
		$result
	);

	// Blockquotes.
	$result = preg_replace(
		'#<blockquote(\s[^>]*)?>([\s\S]*?)</blockquote>#si',
		"<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">\$2</blockquote>\n<!-- /wp:quote -->",
		$result
	);

	// Pre/code blocks.
	$result = preg_replace(
		'#<pre(\s[^>]*)?>([\s\S]*?)</pre>#si',
		"<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>\$2</code></pre>\n<!-- /wp:code -->",
		$result
	);

	// Tables.
	$result = preg_replace(
		'#<table(\s[^>]*)?>([\s\S]*?)</table>#si',
		"<!-- wp:table -->\n<figure class=\"wp-block-table\"><table\$1>\$2</table></figure>\n<!-- /wp:table -->",
		$result
	);

	// Horizontal rules.
	$result = preg_replace(
		'#<hr(\s[^>]*)?\s*/?\s*>#si',
		"<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->",
		$result
	);

	// Standalone images (not already inside a figure).
	$result = preg_replace(
		'#(?<!</figure>)\s*<img(\s[^>]+?)\s*/?\s*>\s*#si',
		"\n<!-- wp:image -->\n<figure class=\"wp-block-image\"><img\$1/></figure>\n<!-- /wp:image -->\n",
		$result
	);

	return trim( $result );
}

/**
 * Wrap each <li> in wp:list-item block delimiters.
 */
function dizrupt_wrap_list_items( string $html ): string {
	return preg_replace(
		'#<li(\s[^>]*)?>([\s\S]*?)</li>#si',
		"\n<!-- wp:list-item -->\n<li\$1>\$2</li>\n<!-- /wp:list-item -->",
		$html
	);
}

/**
 * Sanitise a filename for the media library.
 * Steps: strip query/fragment → basename → remove extension → lowercase + hyphens
 *        → strip non-alphanumeric/non-hyphen → append -YYYY if no year → trim to 80 chars.
 */
function dizrupt_sanitize_filename( string $input, string $fallback = '' ): string {
	// Strip query string and fragment.
	$parsed = wp_parse_url( $input );
	$path   = $parsed['path'] ?? $input;
	$base   = basename( $path );

	// Remove extension.
	$base = preg_replace( '/\.[^.]+$/', '', $base );

	if ( empty( $base ) ) {
		$base = $fallback ?: 'upload';
	}

	// Lowercase; spaces and underscores → hyphens.
	$base = strtolower( $base );
	$base = preg_replace( '/[\s_]+/', '-', $base );

	// Strip anything that is not alphanumeric or a hyphen.
	$base = preg_replace( '/[^a-z0-9\-]/', '', $base );

	// Collapse multiple hyphens.
	$base = preg_replace( '/-{2,}/', '-', $base );
	$base = trim( $base, '-' );

	if ( empty( $base ) ) {
		$base = $fallback ?: 'upload';
	}

	// Append current year if no 4-digit year is present.
	if ( ! preg_match( '/\b\d{4}\b/', $base ) ) {
		$base .= '-' . gmdate( 'Y' );
	}

	// Trim to 80 characters.
	if ( strlen( $base ) > 80 ) {
		$base = substr( $base, 0, 80 );
		$base = rtrim( $base, '-' );
	}

	return $base;
}
