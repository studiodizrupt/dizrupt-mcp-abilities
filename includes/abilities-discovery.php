<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── dizrupt/discover-schema ──────────────────────────────────────────────────

wp_register_ability(
	'dizrupt/discover-schema',
	array(
		'label'       => __( 'Discover site structure', 'dizrupt-mcp-abilities' ),
		'category'    => 'dizrupt-site',
		'description' => 'Returns the complete content structure of this WordPress site in one call: all public post types (with supported features), all public taxonomies (with which post types they apply to), ACF field groups (if ACF is active), and registered meta keys. Call this first when working on an unfamiliar site to understand what post types, taxonomies, and custom fields are available.',
		'input_schema' => array(
			'type'       => 'object',
			'default'    => array(),
			'properties' => array(),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'post_types'      => array( 'type' => 'array', 'description' => 'All public post types with their labels and supported features.' ),
				'taxonomies'      => array( 'type' => 'array', 'description' => 'All public taxonomies with their labels and the post types they apply to.' ),
				'acf_groups'      => array( 'type' => 'array', 'description' => 'ACF field groups with field names and types. Empty if ACF is not active.' ),
				'registered_meta' => array( 'type' => 'object', 'description' => 'Meta keys registered via register_meta(), keyed by post type.' ),
				'acf_active'      => array( 'type' => 'boolean' ),
			),
		),
		'execute_callback' => function ( $input ) {
			// ── Post types ──────────────────────────────────────────────────
			$raw_post_types = get_post_types( array( 'public' => true ), 'objects' );
			$post_types     = array();

			foreach ( $raw_post_types as $pt ) {
				$post_types[] = array(
					'name'     => $pt->name,
					'label'    => $pt->label,
					'singular' => $pt->labels->singular_name ?? $pt->label,
					'supports' => array_keys( (array) get_all_post_type_supports( $pt->name ) ),
					'has_archive' => (bool) $pt->has_archive,
				);
			}

			// ── Taxonomies ──────────────────────────────────────────────────
			$raw_taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
			$taxonomies     = array();

			foreach ( $raw_taxonomies as $tax ) {
				$taxonomies[] = array(
					'name'         => $tax->name,
					'label'        => $tax->label,
					'singular'     => $tax->labels->singular_name ?? $tax->label,
					'hierarchical' => (bool) $tax->hierarchical,
					'post_types'   => array_values( $tax->object_type ),
				);
			}

			// ── ACF field groups ────────────────────────────────────────────
			$acf_active = function_exists( 'acf_get_field_groups' );
			$acf_groups = array();

			if ( $acf_active ) {
				try {
					$groups = acf_get_field_groups();
					foreach ( $groups as $group ) {
						$raw_fields = acf_get_fields( $group['key'] );
						$fields     = array();

						if ( $raw_fields && is_array( $raw_fields ) ) {
							foreach ( $raw_fields as $field ) {
								if ( empty( $field['name'] ) ) {
									continue;
								}
								$fields[] = array(
									'name'     => $field['name'],
									'label'    => $field['label'] ?? $field['name'],
									'type'     => $field['type'] ?? 'text',
									'required' => (bool) ( $field['required'] ?? false ),
								);
							}
						}

						// Summarise location rules.
						$locations = array();
						foreach ( (array) ( $group['location'] ?? array() ) as $rule_group ) {
							foreach ( (array) $rule_group as $rule ) {
								if ( ( $rule['param'] ?? '' ) === 'post_type' ) {
									$locations[] = $rule['value'];
								}
							}
						}

						$acf_groups[] = array(
							'key'       => $group['key'],
							'title'     => $group['title'],
							'post_types' => array_unique( $locations ),
							'fields'    => $fields,
						);
					}
				} catch ( \Throwable $e ) {
					// ACF inspection failed — return empty groups rather than crashing.
					$acf_groups = array();
				}
			}

			// ── Registered meta keys ────────────────────────────────────────
			$registered_meta = array();

			foreach ( $raw_post_types as $pt ) {
				try {
					$keys = get_registered_meta_keys( 'post', $pt->name );
					if ( ! empty( $keys ) ) {
						$registered_meta[ $pt->name ] = array();
						foreach ( $keys as $key => $schema ) {
							if ( str_starts_with( $key, '_' ) ) {
								continue;
							}
							$registered_meta[ $pt->name ][] = array(
								'key'         => $key,
								'type'        => $schema['type'] ?? 'string',
								'description' => $schema['description'] ?? '',
							);
						}
					}
				} catch ( \Throwable $e ) {
					// Skip this post type if meta inspection fails.
				}
			}

			// Also include subtype-agnostic meta.
			$global_keys = get_registered_meta_keys( 'post' );
			if ( ! empty( $global_keys ) ) {
				$registered_meta['_all'] = array();
				foreach ( $global_keys as $key => $schema ) {
					if ( str_starts_with( $key, '_' ) ) {
						continue;
					}
					$registered_meta['_all'][] = array(
						'key'         => $key,
						'type'        => $schema['type'] ?? 'string',
						'description' => $schema['description'] ?? '',
					);
				}
			}

			return array(
				'post_types'      => $post_types,
				'taxonomies'      => $taxonomies,
				'acf_groups'      => $acf_groups,
				'registered_meta' => $registered_meta,
				'acf_active'      => $acf_active,
			);
		},
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'meta' => array( 'mcp' => array( 'public' => true ) ),
	)
);
