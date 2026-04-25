<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── dizrupt/media-list ───────────────────────────────────────────────────────

wp_register_ability(
	'dizrupt/media-list',
	array(
		'label'       => __( 'List media library attachments', 'dizrupt-mcp-abilities' ),
		'category'    => 'dizrupt-media',
		'description' => 'Browse the WordPress media library. Filter by MIME type, search term, or the post an attachment belongs to. Returns attachment ID, URL, alt text, dimensions, and file size.',
		'input_schema' => array(
			'type'       => 'object',
			'default'    => array(),
			'properties' => array(
				'post_id'   => array( 'type' => 'integer', 'description' => 'Filter to attachments of a specific post. Also accepted as "id".' ),
				'id'        => array( 'type' => 'integer', 'description' => 'Alias for post_id.' ),
				'mime_type' => array( 'type' => 'string', 'description' => 'Filter by MIME type prefix, e.g. "image", "image/jpeg", "video".' ),
				'search'    => array( 'type' => 'string', 'description' => 'Search by attachment title or filename.' ),
				'per_page'  => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100, 'description' => 'Results per page (max 100).' ),
				'page'      => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1, 'description' => 'Page number.' ),
			),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'attachments' => array( 'type' => 'array' ),
				'total'       => array( 'type' => 'integer' ),
				'pages'       => array( 'type' => 'integer' ),
			),
		),
		'execute_callback' => function ( $input ) {
			$per_page = min( max( absint( $input['per_page'] ?? 20 ), 1 ), 100 );
			$page     = max( absint( $input['page'] ?? 1 ), 1 );

			$args = array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'no_found_rows'  => false,
				'orderby'        => 'date',
				'order'          => 'DESC',
			);

			$post_id = absint( $input['post_id'] ?? $input['id'] ?? 0 );
			if ( $post_id ) {
				$args['post_parent'] = $post_id;
			}

			if ( ! empty( $input['mime_type'] ) ) {
				$args['post_mime_type'] = sanitize_text_field( $input['mime_type'] );
			}

			if ( ! empty( $input['search'] ) ) {
				$args['s'] = sanitize_text_field( $input['search'] );
			}

			$query       = new WP_Query( $args );
			$attachments = array();

			foreach ( $query->posts as $attachment ) {
				$url      = wp_get_attachment_url( $attachment->ID );
				$metadata = wp_get_attachment_metadata( $attachment->ID );
				$alt_text = get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true );
				$thumb    = wp_get_attachment_image_src( $attachment->ID, 'thumbnail' );

				$item = array(
					'id'        => $attachment->ID,
					'title'     => $attachment->post_title,
					'url'       => $url ?: '',
					'mime_type' => $attachment->post_mime_type,
					'alt_text'  => $alt_text ?: '',
					'caption'   => $attachment->post_excerpt,
					'date'      => $attachment->post_date,
				);

				if ( is_array( $metadata ) ) {
					if ( ! empty( $metadata['width'] ) ) {
						$item['width']  = (int) $metadata['width'];
						$item['height'] = (int) $metadata['height'];
					}
					if ( ! empty( $metadata['filesize'] ) ) {
						$item['file_size'] = (int) $metadata['filesize'];
					}
				}

				if ( $thumb ) {
					$item['thumbnail_url'] = $thumb[0];
				}

				$attachments[] = $item;
			}

			return array(
				'attachments' => $attachments,
				'total'       => (int) $query->found_posts,
				'pages'       => max( (int) $query->max_num_pages, 1 ),
			);
		},
		'permission_callback' => function () {
			return current_user_can( 'read' );
		},
		'meta' => array( 'mcp' => array( 'public' => true ) ),
	)
);

// ─── dizrupt/media-upload-from-url ────────────────────────────────────────────

wp_register_ability(
	'dizrupt/media-upload-from-url',
	array(
		'label'       => __( 'Upload an image from a URL to the media library', 'dizrupt-mcp-abilities' ),
		'category'    => 'dizrupt-media',
		'description' => 'Download an image or file from a URL and add it to the WordPress media library. The filename is sanitised automatically — provide a clean descriptive name without extension (e.g. "cloudsmith-ceo-belfast-2026"). Provide descriptive alt text for accessibility and SEO (e.g. "Cloudsmith CEO Glenn Weinstein speaking at a Belfast tech event" — not "featured image").',
		'input_schema' => array(
			'type'       => 'object',
			'default'    => array(),
			'required'   => array( 'url' ),
			'properties' => array(
				'url'      => array( 'type' => 'string', 'description' => 'Full URL of the image to download and upload.' ),
				'filename' => array( 'type' => 'string', 'description' => 'Clean descriptive filename without extension (e.g. "cloudsmith-series-c-2026"). Auto-generated from URL if omitted. The current year is appended if not already present.' ),
				'alt_text' => array( 'type' => 'string', 'description' => 'Descriptive alt text for accessibility and SEO. Be specific: describe what is shown in the image. Do not use generic descriptions like "featured image".' ),
				'caption'  => array( 'type' => 'string', 'description' => 'Optional image caption.' ),
				'post_id'  => array( 'type' => 'integer', 'description' => 'If provided, the uploaded file is attached to this post. Does not set it as the featured image — use dizrupt/media-set-featured-image for that.' ),
			),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'success'       => array( 'type' => 'boolean' ),
				'attachment_id' => array( 'type' => 'integer' ),
				'url'           => array( 'type' => 'string' ),
				'filename'      => array( 'type' => 'string' ),
				'message'       => array( 'type' => 'string' ),
			),
		),
		'execute_callback' => function ( $input ) {
			$url = esc_url_raw( $input['url'] ?? '' );

			if ( empty( $url ) ) {
				return array( 'success' => false, 'message' => 'url is required.' );
			}

			if ( ! wp_http_validate_url( $url ) ) {
				return array( 'success' => false, 'message' => 'Invalid URL provided.' );
			}

			// Load required admin functions.
			if ( ! function_exists( 'media_handle_sideload' ) ) {
				require_once ABSPATH . 'wp-admin/includes/media.php';
			}
			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}

			// Strip query string from URL before downloading.
			$parsed_url  = wp_parse_url( $url );
			$clean_url   = ( $parsed_url['scheme'] ?? 'https' ) . '://' . ( $parsed_url['host'] ?? '' ) . ( $parsed_url['path'] ?? '' );

			$tmp_file = download_url( $clean_url, 60 );

			if ( is_wp_error( $tmp_file ) ) {
				return array( 'success' => false, 'message' => 'Failed to download file: ' . $tmp_file->get_error_message() );
			}

			// Determine filename.
			$custom_filename = trim( $input['filename'] ?? '' );
			if ( ! empty( $custom_filename ) ) {
				$base_name = dizrupt_sanitize_filename( $custom_filename, 'upload' );
			} else {
				$base_name = dizrupt_sanitize_filename( $parsed_url['path'] ?? '', 'upload' );
			}

			// Detect extension from the original URL path or temp file.
			$url_path  = $parsed_url['path'] ?? '';
			$filetype  = wp_check_filetype( basename( $url_path ) );
			if ( empty( $filetype['ext'] ) ) {
				$filetype = wp_check_filetype( $tmp_file );
			}

			if ( empty( $filetype['type'] ) || ! dizrupt_is_allowed_mime( $filetype['type'] ) ) {
				@unlink( $tmp_file );
				return array( 'success' => false, 'message' => 'File type not allowed: ' . ( $filetype['type'] ?? 'unknown' ) );
			}

			$filename   = $base_name . '.' . $filetype['ext'];
			$post_id    = absint( $input['post_id'] ?? 0 );
			$caption    = sanitize_text_field( $input['caption'] ?? '' );

			$file_array = array(
				'name'     => $filename,
				'tmp_name' => $tmp_file,
			);

			$post_data = array();
			if ( ! empty( $caption ) ) {
				$post_data['post_excerpt'] = $caption;
			}

			$attachment_id = media_handle_sideload( $file_array, $post_id, '', $post_data );

			if ( is_wp_error( $attachment_id ) ) {
				@unlink( $tmp_file );
				return array( 'success' => false, 'message' => 'Upload failed: ' . $attachment_id->get_error_message() );
			}

			// Set alt text.
			if ( ! empty( $input['alt_text'] ) ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt_text'] ) );
			}

			return array(
				'success'       => true,
				'attachment_id' => $attachment_id,
				'url'           => wp_get_attachment_url( $attachment_id ),
				'filename'      => $filename,
				'message'       => sprintf( 'Media uploaded successfully (ID: %d, filename: %s).', $attachment_id, $filename ),
			);
		},
		'permission_callback' => function () {
			return current_user_can( 'upload_files' );
		},
		'meta' => array( 'mcp' => array( 'public' => true ) ),
	)
);

// ─── dizrupt/media-set-featured-image ────────────────────────────────────────

wp_register_ability(
	'dizrupt/media-set-featured-image',
	array(
		'label'       => __( 'Set the featured image on a post', 'dizrupt-mcp-abilities' ),
		'category'    => 'dizrupt-media',
		'description' => 'Set or remove the featured image (thumbnail) on a post. Pass attachment_id = 0 to remove the current featured image.',
		'input_schema' => array(
			'type'       => 'object',
			'default'    => array(),
			'required'   => array( 'post_id', 'attachment_id' ),
			'properties' => array(
				'post_id'       => array( 'type' => 'integer', 'description' => 'The post ID. Also accepted as "id".' ),
				'id'            => array( 'type' => 'integer', 'description' => 'Alias for post_id.' ),
				'attachment_id' => array( 'type' => 'integer', 'description' => 'The attachment ID to set as featured image. Pass 0 to remove the current featured image.' ),
			),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'success'       => array( 'type' => 'boolean' ),
				'post_id'       => array( 'type' => 'integer' ),
				'attachment_id' => array( 'type' => 'integer' ),
				'thumbnail_url' => array( 'type' => 'string' ),
				'message'       => array( 'type' => 'string' ),
			),
		),
		'execute_callback' => function ( $input ) {
			$post_id       = absint( $input['post_id'] ?? $input['id'] ?? 0 );
			$attachment_id = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : -1;

			if ( ! $post_id || $attachment_id === -1 ) {
				return array( 'success' => false, 'message' => 'post_id and attachment_id are required.' );
			}

			if ( ! get_post( $post_id ) ) {
				return array( 'success' => false, 'message' => "Post {$post_id} not found." );
			}

			if ( $attachment_id === 0 ) {
				delete_post_thumbnail( $post_id );
				return array(
					'success'       => true,
					'post_id'       => $post_id,
					'attachment_id' => 0,
					'thumbnail_url' => '',
					'message'       => sprintf( 'Featured image removed from post %d.', $post_id ),
				);
			}

			if ( ! get_post( $attachment_id ) ) {
				return array( 'success' => false, 'message' => "Attachment {$attachment_id} not found." );
			}

			set_post_thumbnail( $post_id, $attachment_id );

			$thumbnail_url = wp_get_attachment_image_url( $attachment_id, 'full' ) ?: '';

			return array(
				'success'       => true,
				'post_id'       => $post_id,
				'attachment_id' => $attachment_id,
				'thumbnail_url' => $thumbnail_url,
				'message'       => sprintf( 'Featured image set to attachment %d on post %d.', $attachment_id, $post_id ),
			);
		},
		'permission_callback' => function () {
			return current_user_can( 'upload_files' );
		},
		'meta' => array( 'mcp' => array( 'public' => true ) ),
	)
);

// ─── MIME type allowlist helper ───────────────────────────────────────────────

function dizrupt_is_allowed_mime( string $mime_type ): bool {
	static $allowed_prefixes = array(
		'image/',
		'video/',
		'audio/',
		'application/pdf',
		'application/zip',
		'application/x-zip-compressed',
		'application/msword',
		'application/vnd.openxmlformats-officedocument',
		'application/vnd.ms-excel',
		'application/vnd.ms-powerpoint',
		'text/csv',
		'text/plain',
	);
	foreach ( $allowed_prefixes as $prefix ) {
		if ( str_starts_with( $mime_type, $prefix ) ) {
			return true;
		}
	}
	return false;
}
