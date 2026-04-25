<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── dizrupt/gutenberg-reference ─────────────────────────────────────────────

wp_register_ability(
	'dizrupt/gutenberg-reference',
	array(
		'label'       => __( 'Gutenberg block markup reference', 'dizrupt-mcp-abilities' ),
		'category'    => 'dizrupt-site',
		'description' => 'Returns a complete reference guide for Gutenberg block markup with copy-paste examples. Call this when you need to compose post content with specific block types, or when dizrupt/posts-create warns that content was modified during sanitisation.',
		'input_schema' => array(
			'type'       => 'object',
			'default'    => array(),
			'properties' => array(
				'block_type' => array(
					'type'        => 'string',
					'description' => 'Optional. Filter the reference to a specific block type (e.g. "paragraph", "heading", "list", "image", "table", "code", "quote"). If omitted, the full reference is returned.',
				),
			),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'reference' => array( 'type' => 'string', 'description' => 'Markdown-formatted block markup reference.' ),
			),
		),
		'execute_callback' => function ( $input ) {
			$blocks = array(

				'paragraph' => <<<'EOT'
## Paragraph
```
<!-- wp:paragraph -->
<p>Your paragraph text here.</p>
<!-- /wp:paragraph -->
```
EOT,

				'heading' => <<<'EOT'
## Heading
H2 (default — no level attribute needed):
```
<!-- wp:heading -->
<h2 class="wp-block-heading">Your Heading</h2>
<!-- /wp:heading -->
```
H3:
```
<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Your Heading</h3>
<!-- /wp:heading -->
```
H4, H5, H6: use `{"level":4}`, `{"level":5}`, `{"level":6}` respectively.
EOT,

				'list' => <<<'EOT'
## List
Unordered:
```
<!-- wp:list -->
<ul class="wp-block-list">
<!-- wp:list-item -->
<li>First item</li>
<!-- /wp:list-item -->
<!-- wp:list-item -->
<li>Second item</li>
<!-- /wp:list-item -->
</ul>
<!-- /wp:list -->
```
Ordered:
```
<!-- wp:list {"ordered":true} -->
<ol class="wp-block-list">
<!-- wp:list-item -->
<li>First item</li>
<!-- /wp:list-item -->
<!-- wp:list-item -->
<li>Second item</li>
<!-- /wp:list-item -->
</ol>
<!-- /wp:list -->
```
EOT,

				'image' => <<<'EOT'
## Image
```
<!-- wp:image -->
<figure class="wp-block-image"><img src="https://example.com/image.jpg" alt="Description of image"/></figure>
<!-- /wp:image -->
```
With alignment:
```
<!-- wp:image {"align":"center"} -->
<figure class="wp-block-image aligncenter"><img src="https://example.com/image.jpg" alt="Description"/></figure>
<!-- /wp:image -->
```
Note: Use dizrupt/media-upload-from-url to upload the image first, then reference its URL here.
EOT,

				'quote' => <<<'EOT'
## Blockquote / Pull Quote
```
<!-- wp:quote -->
<blockquote class="wp-block-quote">
<!-- wp:paragraph -->
<p>The quoted text goes here.</p>
<!-- /wp:paragraph -->
<cite>Attribution Name</cite>
</blockquote>
<!-- /wp:quote -->
```
EOT,

				'separator' => <<<'EOT'
## Separator / Horizontal Rule
```
<!-- wp:separator -->
<hr class="wp-block-separator has-alpha-channel-opacity"/>
<!-- /wp:separator -->
```
EOT,

				'table' => <<<'EOT'
## Table
```
<!-- wp:table -->
<figure class="wp-block-table"><table><thead><tr><th>Column 1</th><th>Column 2</th></tr></thead><tbody><tr><td>Row 1, Cell 1</td><td>Row 1, Cell 2</td></tr><tr><td>Row 2, Cell 1</td><td>Row 2, Cell 2</td></tr></tbody></table></figure>
<!-- /wp:table -->
```
EOT,

				'code' => <<<'EOT'
## Code Block
```
<!-- wp:code -->
<pre class="wp-block-code"><code>your code here</code></pre>
<!-- /wp:code -->
```
For inline code within a paragraph, use a standard `<code>` tag inside a paragraph block.
EOT,

				'preformatted' => <<<'EOT'
## Preformatted Text
```
<!-- wp:preformatted -->
<pre class="wp-block-preformatted">Preformatted text preserves whitespace and line breaks.</pre>
<!-- /wp:preformatted -->
```
EOT,

				'buttons' => <<<'EOT'
## Buttons
Single button:
```
<!-- wp:buttons -->
<div class="wp-block-buttons">
<!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://example.com">Button Label</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
```
EOT,

				'columns' => <<<'EOT'
## Columns (Two column layout)
```
<!-- wp:columns -->
<div class="wp-block-columns">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:paragraph -->
<p>Left column content.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:paragraph -->
<p>Right column content.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
```
EOT,

			);

			$filter = strtolower( trim( $input['block_type'] ?? '' ) );

			if ( ! empty( $filter ) && isset( $blocks[ $filter ] ) ) {
				$reference = "# Gutenberg Block Reference: {$filter}\n\n" . $blocks[ $filter ];
			} elseif ( ! empty( $filter ) ) {
				$reference = "Block type '{$filter}' not found in reference. Available types: " . implode( ', ', array_keys( $blocks ) ) . '.';
			} else {
				$reference  = "# Gutenberg Block Markup Reference\n\n";
				$reference .= "Always wrap content in the correct block delimiters. The opening delimiter is `<!-- wp:block-name -->` and closing is `<!-- /wp:block-name -->`.\n\n";
				$reference .= "After composing content using these examples, pass it to `dizrupt/posts-create` or `dizrupt/posts-update`.\n\n";
				$reference .= "---\n\n";
				$reference .= implode( "\n\n---\n\n", $blocks );
			}

			return array( 'reference' => $reference );
		},
		'permission_callback' => function () {
			return current_user_can( 'read' );
		},
		'meta' => array( 'mcp' => array( 'public' => true ) ),
	)
);
