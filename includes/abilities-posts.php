<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── dizrupt/posts-create ─────────────────────────────────────────────────────

wp_register_ability(
	'dizrupt/posts-create',
	array(
		'label'       => __( 'Create a post', 'dizrupt-mcp-abilities' ),
		'category'    => 'dizrupt-posts',
		'description' => 'Create a new WordPress post or custom post type. Content can be plain HTML or plain text — it will be converted to Gutenberg block markup automatically. Returns the new post ID and URL.',
		'input_schema' => array(
			'type'       => 'object',
			'default'    => array(),
			'properties' => array(
				'title'       => array( 'type' => 'string', 'description' => 'Post title.' ),
				'content'     => array( 'type' => 'string', 'description' => 'Post content. Plain HTML is accepted and will be converted to Gutenberg blocks. Use dizrupt/gutenberg-reference if you need block markup examples.' ),
				'excerpt'     => array( 'type' => 'string', 'description' => 'Post excerpt / summary.' ),
				'post_status' => array( 'type' => 'string', 'enum' => array( 'draft', 'publish', 'pending', 'private' ), 'default' => 'draft', 'description' => 'Post status. Defaults to draft.' ),
				'post_type'   => array( 'type' => 'string', 'default' => 'post', 'description' => 'Post type slug. Defaults to post. Use dizrupt/discover-schema to find available post types.' ),
				'slug'        => array( 'type' => 'string', 'description' => 'Custom URL slug. Auto-generated from title if omitted.' ),
				'categories'  => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Array of category IDs to assign. Only applies to post post type.' ),
				'tags'        => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Array of tag names to assign. Tags are created if they do not exist.' ),
			),
			'required' => array( 'title' ),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'success'  => array( 'type' => 'boolean' ),
				'post_id'  => array( 'type' => 'integer' ),
				'post_url' => array( 'type' => 'string' ),
				'message'  => array( 'type' => 'string' ),
				'warning'  => array( 'type' => 'string', 'description' => 'Present if content was modified during sanitisation.' ),
			),
		),
		'execute_callback' => function ( $input ) {
			$title       = sanitize_text_field( $input['title'] ?? '' );
			$post_type   = sanitize_key( $input['post_type'] ?? 'post' );
			$post_status = sanitize_key( $input['post_status'] ?? 'draft' );
			$slug        = sanitize_title( $input['slug'] ?? '' );
			$categories  = array_map( 'absint', (array) ( $input['categories'] ?? array() ) );
			$tags        = array_map( 'sanitize_text_field', (array) ( $input['tags'] ?? array() ) );

			if ( empty( $title ) ) {
				return array( 'success' => false, 'message' => 'title is required.' );
			}

			$post_data = array(
				'post_title'   => $title,
				'post_status'  => in_array( $post_status, array( 'draft', 'publish', 'pending', 'private' ), true ) ? $post_status : 'draft',
				'post_type'    => $post_type,
				'post_excerpt' => sanitize_textarea_field( $input['excerpt'] ?? '' ),
			);

			if ( ! empty( $slug ) ) {
				$post_data['post_name'] = $slug;
			}

			// Categories use post_category key in wp_insert_post for the 'post' type.
			if ( 'post' === $post_type && ! empty( $categories ) ) {
				$post_data['post_category'] = $categories;
			}

			$warning = null;
			if ( ! empty( $input['content'] ) ) {
				$sanitized = dizrupt_sanitize_content( (string) $input['content'], $post_type );
				$post_data['post_content'] = $sanitized['content'];
				if ( isset( $sanitized['warning'] ) ) {
					$warning = $sanitized['warning'];
				}
			}

			$post_id = wp_insert_post( $post_data, true );

			if ( is_wp_error( $post_id ) ) {
				return array( 'success' => false, 'message' => $post_id->get_error_message() );
			}

			// Tags — wp_set_object_terms for post_tag.
			if ( ! empty( $tags ) ) {
				wp_set_object_terms( $post_id, $tags, 'post_tag' );
			}

			// Non-post post types: assign categories via wp_set_object_terms if taxonomy applies.
			if ( 'post' !== $post_type && ! empty( $categories ) ) {
				wp_set_object_terms( $post_id, $categories, 'category' );
			}

			$result = array(
				'success'  => true,
				'post_id'  => $post_id,
				'post_url' => get_permalink( $post_id ),
				'message'  => sprintf( 'Post created successfully (ID: %d).', $post_id ),
			);
			if ( $warning ) {
				$result['warning'] = $warning;
			}
			return $result;
		},
		'permission_callback' => function () {
			return current_user_can( 'publish_posts' );
		},
		'meta' => array( 'mcp' => array( 'public' => true ) ),
	)
);

// ─── dizrupt/posts-read ───────────────────────────────────────────────────────

wp_register_ability(
	'dizrupt/posts-read',
	array(
		'label'       => __( 'Read a post', 'dizrupt-mcp-abilities' ),
		'category'    => 'dizrupt-posts',
		'description' => 'Read the full content and metadata of a post by its ID. Returns title, content, excerpt, status, post type, slug, date, URL, categories, tags, meta fields, and featured image.',
		'input_schema' => array(
			'type'       => 'object',
			'default'    => array(),
			'required'   => array( 'post_id' ),
			'properties' => array(
				'post_id' => array( 'type' => 'integer', 'description' => 'The post ID. Also accepted as "id".' ),
				'id'      => array( 'type' => 'integer', 'description' => 'Alias for post_id.' ),
			),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'ID'                  => array( 'type' => 'integer' ),
				'title'               => array( 'type' => 'string' ),
				'content'             => array( 'type' => 'string' ),
				'excerpt'             => array( 'type' => 'string' ),
				'status'              => array( 'type' => 'string' ),
				'post_type'           => array( 'type' => 'string' ),
				'slug'                => array( 'type' => 'string' ),
				'date'                => array( 'type' => 'string' ),
				'modified'            => array( 'type' => 'string' ),
				'url'                 => array( 'type' => 'string' ),
				'categories'          => array( 'type' => 'array' ),
				'tags'                => array( 'type' => 'array' ),
				'meta'                => array( 'type' => 'object' ),
				'featured_image_id'   => array( 'type' => 'integer' ),
				'featured_image_url'  => array( 'type' => 'string' ),
			),
		),
		'execute_callback' => function ( $input ) {
			$post_id = absint( $input['post_id'] ?? $input['id'] ?? 0 );

			if ( ! $post_id ) {
				return array( 'success' => false, 'message' => 'post_id is required.' );
			}

			$post = get_post( $post_id );
			if ( ! $post ) {
				return array( 'success' => false, 'message' => "Post {$post_id} not found." );
			}

			$categories = array();
			foreach ( get_the_category( $post_id ) as $cat ) {
				$categories[] = array( 'id' => $cat->term_id, 'name' => $cat->name, 'slug' => $cat->slug );
			}

			$tags = array();
			foreach ( (array) get_the_tags( $post_id ) as $tag ) {
				if ( $tag ) {
					$tags[] = array( 'id' => $tag->term_id, 'name' => $tag->name, 'slug' => $tag->slug );
				}
			}

			$meta    = array();
			$all_meta = get_post_meta( $post_id );
			foreach ( $all_meta as $key => $values ) {
				if ( str_starts_with( $key, '_' ) ) {
					continue;
				}
				$meta[ $key ] = count( $values ) === 1 ? $values[0] : $values;
			}

			$thumbnail_id  = (int) get_post_thumbnail_id( $post_id );
			$thumbnail_url = $thumbnail_id ? wp_get_attachment_image_url( $thumbnail_id, 'full' ) : '';

			return array(
				'ID'                 => $post->ID,
				'title'              => $post->post_title,
				'content'            => $post->post_content,
				'excerpt'            => $post->post_excerpt,
				'status'             => $post->post_status,
				'post_type'          => $post->post_type,
				'slug'               => $post->post_name,
				'date'               => $post->post_date,
				'modified'           => $post->post_modified,
				'url'                => get_permalink( $post_id ),
				'categories'         => $categories,
				'tags'               => $tags,
				'meta'               => $meta,
				'featured_image_id'  => $thumbnail_id,
				'featured_image_url' => $thumbnail_url ?: '',
			);
		},
		'permission_callback' => function () {
			return current_user_can( 'read' );
		},
		'meta' => array( 'mcp' => array( 'public' => true ) ),
	)
);

// ─── dizrupt/posts-update ─────────────────────────────────────────────────────

wp_register_ability(
	'dizrupt/posts-update',
	array(
		'label'       => __( 'Update a post', 'dizrupt-mcp-abilities' ),
		'category'    => 'dizrupt-posts',
		'description' => 'Update one or more fields on an existing post. All fields except post_id are optional — only the fields you provide will be changed. Content is automatically converted to Gutenberg blocks if plain HTML is provided.',
		'input_schema' => array(
			'type'       => 'object',
			'default'    => array(),
			'required'   => array( 'post_id' ),
			'properties' => array(
				'post_id'     => array( 'type' => 'integer', 'description' => 'The post ID to update. Also accepted as "id".' ),
				'id'          => array( 'type' => 'integer', 'description' => 'Alias for post_id.' ),
				'title'       => array( 'type' => 'string', 'description' => 'New post title.' ),
				'content'     => array( 'type' => 'string', 'description' => 'New post content. Plain HTML will be converted to Gutenberg blocks.' ),
				'excerpt'     => array( 'type' => 'string', 'description' => 'New post excerpt.' ),
				'post_status' => array( 'type' => 'string', 'enum' => array( 'draft', 'publish', 'pending', 'private' ), 'description' => 'New post status.' ),
				'slug'        => array( 'type' => 'string', 'description' => 'New URL slug.' ),
			),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'success'  => array( 'type' => 'boolean' ),
				'post_id'  => array( 'type' => 'integer' ),
				'post_url' => array( 'type' => 'string' ),
				'message'  => array( 'type' => 'string' ),
				'warning'  => array( 'type' => 'string' ),
			),
		),
		'execute_callback' => function ( $input ) {
			$post_id = absint( $input['post_id'] ?? $input['id'] ?? 0 );

			if ( ! $post_id ) {
				return array( 'success' => false, 'message' => 'post_id is required.' );
			}

			$post = get_post( $post_id );
			if ( ! $post ) {
				return array( 'success' => false, 'message' => "Post {$post_id} not found." );
			}

			$post_data = array( 'ID' => $post_id );

			if ( isset( $input['title'] ) ) {
				$post_data['post_title'] = sanitize_text_field( $input['title'] );
			}
			if ( isset( $input['excerpt'] ) ) {
				$post_data['post_excerpt'] = sanitize_textarea_field( $input['excerpt'] );
			}
			if ( isset( $input['post_status'] ) ) {
				$status = sanitize_key( $input['post_status'] );
				if ( in_array( $status, array( 'draft', 'publish', 'pending', 'private' ), true ) ) {
					$post_data['post_status'] = $status;
				}
			}
			if ( isset( $input['slug'] ) ) {
				$post_data['post_name'] = sanitize_title( $input['slug'] );
			}

			$warning = null;
			if ( isset( $input['content'] ) ) {
				$sanitized = dizrupt_sanitize_content( (string) $input['content'], $post->post_type );
				$post_data['post_content'] = $sanitized['content'];
				if ( isset( $sanitized['warning'] ) ) {
					$warning = $sanitized['warning'];
				}
			}

			$result_id = wp_update_post( $post_data, true );

			if ( is_wp_error( $result_id ) ) {
				return array( 'success' => false, 'message' => $result_id->get_error_message() );
			}

			$result = array(
				'success'  => true,
				'post_id'  => $post_id,
				'post_url' => get_permalink( $post_id ),
				'message'  => sprintf( 'Post %d updated successfully.', $post_id ),
			);
			if ( $warning ) {
				$result['warning'] = $warning;
			}
			return $result;
		},
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'meta' => array( 'mcp' => array( 'public' => true ) ),
	)
);

// ─── dizrupt/posts-publish ────────────────────────────────────────────────────

wp_register_ability(
	'dizrupt/posts-publish',
	array(
		'label'       => __( 'Publish a post', 'dizrupt-mcp-abilities' ),
		'category'    => 'dizrupt-posts',
		'description' => 'Set a post status to publish, making it live. Returns the live URL on success.',
		'input_schema' => array(
			'type'       => 'object',
			'default'    => array(),
			'required'   => array( 'post_id' ),
			'properties' => array(
				'post_id' => array( 'type' => 'integer', 'description' => 'The post ID to publish. Also accepted as "id".' ),
				'id'      => array( 'type' => 'integer', 'description' => 'Alias for post_id.' ),
			),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'success'  => array( 'type' => 'boolean' ),
				'post_id'  => array( 'type' => 'integer' ),
				'post_url' => array( 'type' => 'string' ),
				'message'  => array( 'type' => 'string' ),
			),
		),
		'execute_callback' => function ( $input ) {
			$post_id = absint( $input['post_id'] ?? $input['id'] ?? 0 );

			if ( ! $post_id ) {
				return array( 'success' => false, 'message' => 'post_id is required.' );
			}

			$post = get_post( $post_id );
			if ( ! $post ) {
				return array( 'success' => false, 'message' => "Post {$post_id} not found." );
			}

			$result_id = wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ), true );

			if ( is_wp_error( $result_id ) ) {
				return array( 'success' => false, 'message' => $result_id->get_error_message() );
			}

			return array(
				'success'  => true,
				'post_id'  => $post_id,
				'post_url' => get_permalink( $post_id ),
				'message'  => sprintf( 'Post %d published successfully.', $post_id ),
			);
		},
		'permission_callback' => function () {
			return current_user_can( 'publish_posts' );
		},
		'meta' => array( 'mcp' => array( 'public' => true ) ),
	)
);

// ─── dizrupt/posts-list ───────────────────────────────────────────────────────

wp_register_ability(
	'dizrupt/posts-list',
	array(
		'label'       => __( 'List posts', 'dizrupt-mcp-abilities' ),
		'category'    => 'dizrupt-posts',
		'description' => 'List posts with optional filters. Supports any post type, status, search, and pagination. Returns a summary of each post (ID, title, status, date, URL, featured image ID).',
		'input_schema' => array(
			'type'       => 'object',
			'default'    => array(),
			'properties' => array(
				'post_status' => array( 'type' => 'string', 'default' => 'any', 'description' => 'Filter by status: publish, draft, pending, private, or any.' ),
				'post_type'   => array( 'type' => 'string', 'default' => 'post', 'description' => 'Post type slug. Defaults to post.' ),
				'per_page'    => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100, 'description' => 'Results per page (max 100).' ),
				'page'        => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1, 'description' => 'Page number.' ),
				'search'      => array( 'type' => 'string', 'description' => 'Keyword search.' ),
				'category_id' => array( 'type' => 'integer', 'description' => 'Filter by category ID.' ),
				'orderby'     => array( 'type' => 'string', 'enum' => array( 'date', 'title', 'modified' ), 'default' => 'date', 'description' => 'Sort field.' ),
				'order'       => array( 'type' => 'string', 'enum' => array( 'ASC', 'DESC' ), 'default' => 'DESC', 'description' => 'Sort direction.' ),
			),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'posts' => array( 'type' => 'array' ),
				'total' => array( 'type' => 'integer' ),
				'pages' => array( 'type' => 'integer' ),
			),
		),
		'execute_callback' => function ( $input ) {
			$per_page = min( absint( $input['per_page'] ?? 20 ), 100 );
			$per_page = max( $per_page, 1 );
			$page     = max( absint( $input['page'] ?? 1 ), 1 );
			$orderby  = in_array( $input['orderby'] ?? 'date', array( 'date', 'title', 'modified' ), true )
				? $input['orderby']
				: 'date';
			$order    = in_array( strtoupper( $input['order'] ?? 'DESC' ), array( 'ASC', 'DESC' ), true )
				? strtoupper( $input['order'] ?? 'DESC' )
				: 'DESC';

			$args = array(
				'post_type'      => sanitize_key( $input['post_type'] ?? 'post' ),
				'post_status'    => sanitize_key( $input['post_status'] ?? 'any' ),
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => $orderby,
				'order'          => $order,
				'no_found_rows'  => false,
			);

			if ( ! empty( $input['search'] ) ) {
				$args['s'] = sanitize_text_field( $input['search'] );
			}
			if ( ! empty( $input['category_id'] ) ) {
				$args['cat'] = absint( $input['category_id'] );
			}

			$query = new WP_Query( $args );
			$posts = array();

			foreach ( $query->posts as $post ) {
				$posts[] = array(
					'ID'               => $post->ID,
					'title'            => $post->post_title,
					'status'           => $post->post_status,
					'date'             => $post->post_date,
					'modified'         => $post->post_modified,
					'url'              => get_permalink( $post->ID ),
					'post_type'        => $post->post_type,
					'featured_image_id' => (int) get_post_thumbnail_id( $post->ID ),
				);
			}

			$total = (int) $query->found_posts;
			$pages = (int) $query->max_num_pages;

			return array(
				'posts' => $posts,
				'total' => $total,
				'pages' => max( $pages, 1 ),
			);
		},
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'meta' => array( 'mcp' => array( 'public' => true ) ),
	)
);

// ─── dizrupt/posts-trash ──────────────────────────────────────────────────────

wp_register_ability(
	'dizrupt/posts-trash',
	array(
		'label'       => __( 'Move a post to trash', 'dizrupt-mcp-abilities' ),
		'category'    => 'dizrupt-posts',
		'description' => 'Move a post to the WordPress trash. The post is NOT permanently deleted — it can be restored from the trash. To permanently delete, use the WordPress admin.',
		'input_schema' => array(
			'type'       => 'object',
			'default'    => array(),
			'required'   => array( 'post_id' ),
			'properties' => array(
				'post_id' => array( 'type' => 'integer', 'description' => 'The post ID to trash. Also accepted as "id".' ),
				'id'      => array( 'type' => 'integer', 'description' => 'Alias for post_id.' ),
			),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'message' => array( 'type' => 'string' ),
			),
		),
		'execute_callback' => function ( $input ) {
			$post_id = absint( $input['post_id'] ?? $input['id'] ?? 0 );

			if ( ! $post_id ) {
				return array( 'success' => false, 'message' => 'post_id is required.' );
			}

			$post = get_post( $post_id );
			if ( ! $post ) {
				return array( 'success' => false, 'message' => "Post {$post_id} not found." );
			}

			if ( 'trash' === $post->post_status ) {
				return array( 'success' => true, 'message' => "Post {$post_id} is already in trash." );
			}

			$result = wp_trash_post( $post_id );

			if ( ! $result ) {
				return array( 'success' => false, 'message' => "Failed to trash post {$post_id}." );
			}

			return array(
				'success' => true,
				'message' => sprintf( 'Post %d moved to trash.', $post_id ),
			);
		},
		'permission_callback' => function () {
			return current_user_can( 'delete_posts' );
		},
		'meta' => array( 'mcp' => array( 'public' => true ) ),
	)
);
