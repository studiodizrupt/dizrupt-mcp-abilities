<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── dizrupt/taxonomy-list-terms ──────────────────────────────────────────────

wp_register_ability(
	'dizrupt/taxonomy-list-terms',
	array(
		'label'       => __( 'List taxonomy terms', 'dizrupt-mcp-abilities' ),
		'category'    => 'dizrupt-taxonomy',
		'description' => 'List all terms in a taxonomy. Pass the taxonomy slug (e.g. "category", "post_tag", or any custom taxonomy). Use dizrupt/discover-schema to find all available taxonomies on this site.',
		'input_schema' => array(
			'type'       => 'object',
			'default'    => array(),
			'required'   => array( 'taxonomy' ),
			'properties' => array(
				'taxonomy'   => array( 'type' => 'string', 'description' => 'Taxonomy slug, e.g. category, post_tag, or a custom taxonomy.' ),
				'search'     => array( 'type' => 'string', 'description' => 'Filter terms by name.' ),
				'hide_empty' => array( 'type' => 'boolean', 'default' => false, 'description' => 'If true, only terms with at least one post are returned.' ),
			),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'terms' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'     => array( 'type' => 'integer' ),
							'name'   => array( 'type' => 'string' ),
							'slug'   => array( 'type' => 'string' ),
							'count'  => array( 'type' => 'integer' ),
							'parent' => array( 'type' => 'integer' ),
						),
					),
				),
			),
		),
		'execute_callback' => function ( $input ) {
			$taxonomy = sanitize_key( $input['taxonomy'] ?? '' );

			if ( empty( $taxonomy ) ) {
				return array( 'success' => false, 'message' => 'taxonomy is required.' );
			}

			if ( ! taxonomy_exists( $taxonomy ) ) {
				return array( 'success' => false, 'message' => "Taxonomy '{$taxonomy}' does not exist." );
			}

			$args = array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => (bool) ( $input['hide_empty'] ?? false ),
				'number'     => 0,
			);

			if ( ! empty( $input['search'] ) ) {
				$args['search'] = sanitize_text_field( $input['search'] );
			}

			$raw_terms = get_terms( $args );

			if ( is_wp_error( $raw_terms ) ) {
				return array( 'success' => false, 'message' => $raw_terms->get_error_message() );
			}

			$terms = array();
			foreach ( $raw_terms as $term ) {
				$terms[] = array(
					'id'     => $term->term_id,
					'name'   => $term->name,
					'slug'   => $term->slug,
					'count'  => (int) $term->count,
					'parent' => (int) $term->parent,
				);
			}

			return array( 'terms' => $terms );
		},
		'permission_callback' => function () {
			return current_user_can( 'read' );
		},
		'meta' => array( 'mcp' => array( 'public' => true ) ),
	)
);

// ─── dizrupt/taxonomy-set-terms ───────────────────────────────────────────────

wp_register_ability(
	'dizrupt/taxonomy-set-terms',
	array(
		'label'       => __( 'Assign terms to a post', 'dizrupt-mcp-abilities' ),
		'category'    => 'dizrupt-taxonomy',
		'description' => 'Assign taxonomy terms to a post. Terms can be passed as integer IDs or string names (mixed in the same array). String names will be created if they do not exist. Set append to true to add to existing terms rather than replacing them.',
		'input_schema' => array(
			'type'       => 'object',
			'default'    => array(),
			'required'   => array( 'post_id', 'taxonomy', 'terms' ),
			'properties' => array(
				'post_id'  => array( 'type' => 'integer', 'description' => 'The post ID. Also accepted as "id".' ),
				'id'       => array( 'type' => 'integer', 'description' => 'Alias for post_id.' ),
				'taxonomy' => array( 'type' => 'string', 'description' => 'Taxonomy slug.' ),
				'terms'    => array( 'type' => 'array', 'description' => 'Array of term IDs (integers) or term names (strings). Mixed types are accepted.' ),
				'append'   => array( 'type' => 'boolean', 'default' => false, 'description' => 'If false (default), replaces all existing terms. If true, adds to existing terms.' ),
			),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'success'        => array( 'type' => 'boolean' ),
				'post_id'        => array( 'type' => 'integer' ),
				'taxonomy'       => array( 'type' => 'string' ),
				'assigned_terms' => array( 'type' => 'array' ),
				'message'        => array( 'type' => 'string' ),
			),
		),
		'execute_callback' => function ( $input ) {
			$post_id  = absint( $input['post_id'] ?? $input['id'] ?? 0 );
			$taxonomy = sanitize_key( $input['taxonomy'] ?? '' );
			$terms    = (array) ( $input['terms'] ?? array() );
			$append   = (bool) ( $input['append'] ?? false );

			if ( ! $post_id || empty( $taxonomy ) ) {
				return array( 'success' => false, 'message' => 'post_id and taxonomy are required.' );
			}

			if ( ! get_post( $post_id ) ) {
				return array( 'success' => false, 'message' => "Post {$post_id} not found." );
			}

			if ( ! taxonomy_exists( $taxonomy ) ) {
				return array( 'success' => false, 'message' => "Taxonomy '{$taxonomy}' does not exist." );
			}

			// Normalise: integer items stay as ints; strings become names to resolve/create.
			$term_ids = array();
			foreach ( $terms as $term ) {
				if ( is_numeric( $term ) ) {
					$term_ids[] = absint( $term );
				} else {
					$name   = sanitize_text_field( (string) $term );
					$exists = get_term_by( 'name', $name, $taxonomy );
					if ( $exists ) {
						$term_ids[] = $exists->term_id;
					} else {
						$inserted = wp_insert_term( $name, $taxonomy );
						if ( ! is_wp_error( $inserted ) ) {
							$term_ids[] = $inserted['term_id'];
						}
					}
				}
			}

			$result = wp_set_object_terms( $post_id, $term_ids, $taxonomy, $append );

			if ( is_wp_error( $result ) ) {
				return array( 'success' => false, 'message' => $result->get_error_message() );
			}

			$assigned = array();
			foreach ( (array) $result as $tid ) {
				$t = get_term( $tid, $taxonomy );
				if ( $t && ! is_wp_error( $t ) ) {
					$assigned[] = array( 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug );
				}
			}

			return array(
				'success'        => true,
				'post_id'        => $post_id,
				'taxonomy'       => $taxonomy,
				'assigned_terms' => $assigned,
				'message'        => sprintf( 'Assigned %d term(s) to post %d.', count( $assigned ), $post_id ),
			);
		},
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'meta' => array( 'mcp' => array( 'public' => true ) ),
	)
);

// ─── dizrupt/taxonomy-create-term ─────────────────────────────────────────────

wp_register_ability(
	'dizrupt/taxonomy-create-term',
	array(
		'label'       => __( 'Create a taxonomy term', 'dizrupt-mcp-abilities' ),
		'category'    => 'dizrupt-taxonomy',
		'description' => 'Create a new term in any taxonomy. If a term with the same name already exists, the existing term ID is returned with a note.',
		'input_schema' => array(
			'type'       => 'object',
			'default'    => array(),
			'required'   => array( 'taxonomy', 'name' ),
			'properties' => array(
				'taxonomy'    => array( 'type' => 'string', 'description' => 'Taxonomy slug.' ),
				'name'        => array( 'type' => 'string', 'description' => 'Term name.' ),
				'slug'        => array( 'type' => 'string', 'description' => 'URL slug. Auto-generated from name if omitted.' ),
				'description' => array( 'type' => 'string', 'description' => 'Term description.' ),
				'parent'      => array( 'type' => 'integer', 'description' => 'Parent term ID for hierarchical taxonomies.' ),
			),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'term_id' => array( 'type' => 'integer' ),
				'name'    => array( 'type' => 'string' ),
				'slug'    => array( 'type' => 'string' ),
				'message' => array( 'type' => 'string' ),
			),
		),
		'execute_callback' => function ( $input ) {
			$taxonomy = sanitize_key( $input['taxonomy'] ?? '' );
			$name     = sanitize_text_field( $input['name'] ?? '' );

			if ( empty( $taxonomy ) || empty( $name ) ) {
				return array( 'success' => false, 'message' => 'taxonomy and name are required.' );
			}

			if ( ! taxonomy_exists( $taxonomy ) ) {
				return array( 'success' => false, 'message' => "Taxonomy '{$taxonomy}' does not exist." );
			}

			$args = array();
			if ( ! empty( $input['slug'] ) ) {
				$args['slug'] = sanitize_title( $input['slug'] );
			}
			if ( ! empty( $input['description'] ) ) {
				$args['description'] = sanitize_textarea_field( $input['description'] );
			}
			if ( ! empty( $input['parent'] ) ) {
				$args['parent'] = absint( $input['parent'] );
			}

			$result = wp_insert_term( $name, $taxonomy, $args );

			if ( is_wp_error( $result ) ) {
				// Term may already exist — return the existing term.
				if ( $result->get_error_code() === 'term_exists' ) {
					$existing_id = (int) $result->get_error_data();
					$existing    = get_term( $existing_id, $taxonomy );
					return array(
						'success' => true,
						'term_id' => $existing_id,
						'name'    => $existing ? $existing->name : $name,
						'slug'    => $existing ? $existing->slug : '',
						'message' => "Term '{$name}' already exists in '{$taxonomy}' (ID: {$existing_id}).",
					);
				}
				return array( 'success' => false, 'message' => $result->get_error_message() );
			}

			$term = get_term( $result['term_id'], $taxonomy );

			return array(
				'success' => true,
				'term_id' => $result['term_id'],
				'name'    => $term ? $term->name : $name,
				'slug'    => $term ? $term->slug : '',
				'message' => sprintf( "Term '%s' created in '%s' (ID: %d).", $name, $taxonomy, $result['term_id'] ),
			);
		},
		'permission_callback' => function () {
			return current_user_can( 'manage_categories' );
		},
		'meta' => array( 'mcp' => array( 'public' => true ) ),
	)
);

// ─── dizrupt/taxonomy-update-term ────────────────────────────────────────────

wp_register_ability(
	'dizrupt/taxonomy-update-term',
	array(
		'label'       => __( 'Update a taxonomy term', 'dizrupt-mcp-abilities' ),
		'category'    => 'dizrupt-taxonomy',
		'description' => 'Update the name, slug, description, or parent of an existing taxonomy term. Only provided fields are changed.',
		'input_schema' => array(
			'type'       => 'object',
			'default'    => array(),
			'required'   => array( 'taxonomy', 'term_id' ),
			'properties' => array(
				'taxonomy'    => array( 'type' => 'string', 'description' => 'Taxonomy slug.' ),
				'term_id'     => array( 'type' => 'integer', 'description' => 'ID of the term to update.' ),
				'name'        => array( 'type' => 'string', 'description' => 'New term name.' ),
				'slug'        => array( 'type' => 'string', 'description' => 'New URL slug.' ),
				'description' => array( 'type' => 'string', 'description' => 'New description.' ),
				'parent'      => array( 'type' => 'integer', 'description' => 'New parent term ID. Pass 0 to remove parent.' ),
			),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'term_id' => array( 'type' => 'integer' ),
				'name'    => array( 'type' => 'string' ),
				'slug'    => array( 'type' => 'string' ),
				'message' => array( 'type' => 'string' ),
			),
		),
		'execute_callback' => function ( $input ) {
			$taxonomy = sanitize_key( $input['taxonomy'] ?? '' );
			$term_id  = absint( $input['term_id'] ?? 0 );

			if ( empty( $taxonomy ) || ! $term_id ) {
				return array( 'success' => false, 'message' => 'taxonomy and term_id are required.' );
			}

			if ( ! taxonomy_exists( $taxonomy ) ) {
				return array( 'success' => false, 'message' => "Taxonomy '{$taxonomy}' does not exist." );
			}

			$term = get_term( $term_id, $taxonomy );
			if ( ! $term || is_wp_error( $term ) ) {
				return array( 'success' => false, 'message' => "Term {$term_id} not found in '{$taxonomy}'." );
			}

			$args = array();
			if ( isset( $input['name'] ) ) {
				$args['name'] = sanitize_text_field( $input['name'] );
			}
			if ( isset( $input['slug'] ) ) {
				$args['slug'] = sanitize_title( $input['slug'] );
			}
			if ( isset( $input['description'] ) ) {
				$args['description'] = sanitize_textarea_field( $input['description'] );
			}
			if ( isset( $input['parent'] ) ) {
				$args['parent'] = absint( $input['parent'] );
			}

			if ( empty( $args ) ) {
				return array( 'success' => false, 'message' => 'No fields provided to update.' );
			}

			$result = wp_update_term( $term_id, $taxonomy, $args );

			if ( is_wp_error( $result ) ) {
				return array( 'success' => false, 'message' => $result->get_error_message() );
			}

			$updated = get_term( $result['term_id'], $taxonomy );

			return array(
				'success' => true,
				'term_id' => $result['term_id'],
				'name'    => $updated ? $updated->name : '',
				'slug'    => $updated ? $updated->slug : '',
				'message' => sprintf( 'Term %d updated successfully.', $term_id ),
			);
		},
		'permission_callback' => function () {
			return current_user_can( 'manage_categories' );
		},
		'meta' => array( 'mcp' => array( 'public' => true ) ),
	)
);

// ─── dizrupt/taxonomy-delete-term ────────────────────────────────────────────

wp_register_ability(
	'dizrupt/taxonomy-delete-term',
	array(
		'label'       => __( 'Delete a taxonomy term', 'dizrupt-mcp-abilities' ),
		'category'    => 'dizrupt-taxonomy',
		'description' => 'Permanently delete a taxonomy term. WARNING: This cannot be undone. Posts assigned to this term will have the term removed from them. Child terms are not deleted — they are reassigned to no parent.',
		'input_schema' => array(
			'type'       => 'object',
			'default'    => array(),
			'required'   => array( 'taxonomy', 'term_id' ),
			'properties' => array(
				'taxonomy' => array( 'type' => 'string', 'description' => 'Taxonomy slug.' ),
				'term_id'  => array( 'type' => 'integer', 'description' => 'ID of the term to permanently delete.' ),
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
			$taxonomy = sanitize_key( $input['taxonomy'] ?? '' );
			$term_id  = absint( $input['term_id'] ?? 0 );

			if ( empty( $taxonomy ) || ! $term_id ) {
				return array( 'success' => false, 'message' => 'taxonomy and term_id are required.' );
			}

			if ( ! taxonomy_exists( $taxonomy ) ) {
				return array( 'success' => false, 'message' => "Taxonomy '{$taxonomy}' does not exist." );
			}

			$term = get_term( $term_id, $taxonomy );
			if ( ! $term || is_wp_error( $term ) ) {
				return array( 'success' => false, 'message' => "Term {$term_id} not found in '{$taxonomy}'." );
			}

			$term_name = $term->name;
			$result    = wp_delete_term( $term_id, $taxonomy );

			if ( is_wp_error( $result ) ) {
				return array( 'success' => false, 'message' => $result->get_error_message() );
			}

			if ( false === $result ) {
				return array( 'success' => false, 'message' => "Failed to delete term {$term_id}." );
			}

			return array(
				'success' => true,
				'message' => sprintf( "Term '%s' (ID: %d) permanently deleted from '%s'.", $term_name, $term_id, $taxonomy ),
			);
		},
		'permission_callback' => function () {
			return current_user_can( 'manage_categories' );
		},
		'meta' => array( 'mcp' => array( 'public' => true ) ),
	)
);
