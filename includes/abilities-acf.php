<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── ACF type → JSON Schema type map ─────────────────────────────────────────

function dizrupt_acf_type_to_schema( string $acf_type ): string {
	static $map = array(
		'text'             => 'string',
		'textarea'         => 'string',
		'number'           => 'number',
		'range'            => 'number',
		'email'            => 'string',
		'url'              => 'string',
		'password'         => 'string',
		'image'            => 'integer',
		'file'             => 'integer',
		'wysiwyg'          => 'string',
		'oembed'           => 'string',
		'gallery'          => 'array',
		'select'           => 'string',
		'checkbox'         => 'array',
		'radio'            => 'string',
		'button_group'     => 'string',
		'true_false'       => 'boolean',
		'link'             => 'object',
		'post_object'      => 'integer',
		'page_link'        => 'string',
		'relationship'     => 'array',
		'taxonomy'         => 'array',
		'user'             => 'integer',
		'google_map'       => 'object',
		'date_picker'      => 'string',
		'date_time_picker' => 'string',
		'time_picker'      => 'string',
		'color_picker'     => 'string',
		'message'          => 'string',
		'tab'              => 'string',
		'group'            => 'object',
		'repeater'         => 'array',
		'flexible_content' => 'array',
		'clone'            => 'object',
	);
	return $map[ $acf_type ] ?? 'string';
}

// ─── dizrupt/acf-get-schema ───────────────────────────────────────────────────

wp_register_ability(
	'dizrupt/acf-get-schema',
	array(
		'label'       => __( 'Get ACF field schema for a post type', 'dizrupt-mcp-abilities' ),
		'category'    => 'dizrupt-acf',
		'description' => 'Returns all ACF field groups and their fields registered for a given post type. Call this before using dizrupt/acf-update-field to discover available field names and types. Requires ACF to be active.',
		'input_schema' => array(
			'type'       => 'object',
			'default'    => array(),
			'required'   => array( 'post_type' ),
			'properties' => array(
				'post_type' => array( 'type' => 'string', 'description' => 'Post type slug, e.g. post, page, or a custom post type.' ),
			),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'success'      => array( 'type' => 'boolean' ),
				'field_groups' => array( 'type' => 'array' ),
				'message'      => array( 'type' => 'string' ),
			),
		),
		'execute_callback' => function ( $input ) {
			if ( ! function_exists( 'acf_get_field_groups' ) ) {
				return array( 'success' => false, 'message' => 'ACF is not active on this site.' );
			}

			$post_type = sanitize_key( $input['post_type'] ?? '' );
			if ( empty( $post_type ) ) {
				return array( 'success' => false, 'message' => 'post_type is required.' );
			}

			try {
				$groups        = acf_get_field_groups( array( 'post_type' => $post_type ) );
				$field_groups  = array();

				foreach ( $groups as $group ) {
					$raw_fields = acf_get_fields( $group['key'] );
					if ( ! $raw_fields || ! is_array( $raw_fields ) ) {
						continue;
					}

					$fields = array();
					foreach ( $raw_fields as $field ) {
						if ( empty( $field['name'] ) ) {
							continue;
						}
						$entry = array(
							'key'      => $field['key'] ?? '',
							'name'     => $field['name'],
							'label'    => $field['label'] ?? $field['name'],
							'type'     => $field['type'] ?? 'text',
							'schema_type' => dizrupt_acf_type_to_schema( $field['type'] ?? 'text' ),
							'required' => (bool) ( $field['required'] ?? false ),
						);
						if ( ! empty( $field['choices'] ) ) {
							$entry['choices'] = $field['choices'];
						}
						if ( isset( $field['instructions'] ) && '' !== $field['instructions'] ) {
							$entry['instructions'] = $field['instructions'];
						}
						$fields[] = $entry;
					}

					$field_groups[] = array(
						'key'    => $group['key'],
						'title'  => $group['title'],
						'fields' => $fields,
					);
				}

				return array(
					'success'      => true,
					'field_groups' => $field_groups,
					'message'      => sprintf( 'Found %d field group(s) for post type "%s".', count( $field_groups ), $post_type ),
				);
			} catch ( \Throwable $e ) {
				return array( 'success' => false, 'message' => 'ACF field inspection failed: ' . $e->getMessage() );
			}
		},
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'meta' => array( 'mcp' => array( 'public' => true ) ),
	)
);

// ─── dizrupt/acf-get-fields ───────────────────────────────────────────────────

wp_register_ability(
	'dizrupt/acf-get-fields',
	array(
		'label'       => __( 'Get ACF field values for a post', 'dizrupt-mcp-abilities' ),
		'category'    => 'dizrupt-acf',
		'description' => 'Returns all ACF field values for a post. Image fields return URLs, relationship fields return arrays of post objects. Requires ACF to be active.',
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
				'success' => array( 'type' => 'boolean' ),
				'post_id' => array( 'type' => 'integer' ),
				'fields'  => array( 'type' => 'object' ),
				'message' => array( 'type' => 'string' ),
			),
		),
		'execute_callback' => function ( $input ) {
			if ( ! function_exists( 'get_fields' ) ) {
				return array( 'success' => false, 'message' => 'ACF is not active on this site.' );
			}

			$post_id = absint( $input['post_id'] ?? $input['id'] ?? 0 );
			if ( ! $post_id ) {
				return array( 'success' => false, 'message' => 'post_id is required.' );
			}

			if ( ! get_post( $post_id ) ) {
				return array( 'success' => false, 'message' => "Post {$post_id} not found." );
			}

			try {
				$fields = get_fields( $post_id );
				return array(
					'success' => true,
					'post_id' => $post_id,
					'fields'  => $fields ?: array(),
					'message' => $fields ? sprintf( 'Retrieved %d ACF field(s).', count( $fields ) ) : 'No ACF fields found for this post.',
				);
			} catch ( \Throwable $e ) {
				return array( 'success' => false, 'message' => 'ACF field retrieval failed: ' . $e->getMessage() );
			}
		},
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'meta' => array( 'mcp' => array( 'public' => true ) ),
	)
);

// ─── dizrupt/acf-update-field ─────────────────────────────────────────────────

wp_register_ability(
	'dizrupt/acf-update-field',
	array(
		'label'       => __( 'Update an ACF field value', 'dizrupt-mcp-abilities' ),
		'category'    => 'dizrupt-acf',
		'description' => 'Update a single ACF field value on a post. Use dizrupt/acf-get-schema first to discover field names. Uses ACF\'s update_field() when ACF is active (preserves field formatting and validation), with fallback to update_post_meta(). Requires ACF to be active for reliable operation.',
		'input_schema' => array(
			'type'       => 'object',
			'default'    => array(),
			'required'   => array( 'post_id', 'field_name', 'value' ),
			'properties' => array(
				'post_id'    => array( 'type' => 'integer', 'description' => 'The post ID. Also accepted as "id".' ),
				'id'         => array( 'type' => 'integer', 'description' => 'Alias for post_id.' ),
				'field_name' => array( 'type' => 'string', 'description' => 'ACF field name (not the field key). Use dizrupt/acf-get-schema to discover field names.' ),
				'value'      => array( 'description' => 'The new value. Type must match the field type.' ),
			),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'success'    => array( 'type' => 'boolean' ),
				'post_id'    => array( 'type' => 'integer' ),
				'field_name' => array( 'type' => 'string' ),
				'message'    => array( 'type' => 'string' ),
			),
		),
		'execute_callback' => function ( $input ) {
			$post_id    = absint( $input['post_id'] ?? $input['id'] ?? 0 );
			$field_name = sanitize_text_field( $input['field_name'] ?? '' );
			$value      = $input['value'] ?? null;

			if ( ! $post_id || empty( $field_name ) ) {
				return array( 'success' => false, 'message' => 'post_id and field_name are required.' );
			}

			if ( ! get_post( $post_id ) ) {
				return array( 'success' => false, 'message' => "Post {$post_id} not found." );
			}

			try {
				if ( function_exists( 'update_field' ) && function_exists( 'acf_get_field' ) ) {
					$acf_field = acf_get_field( $field_name );
					if ( $acf_field ) {
						update_field( $field_name, $value, $post_id );
						return array(
							'success'    => true,
							'post_id'    => $post_id,
							'field_name' => $field_name,
							'message'    => sprintf( 'ACF field "%s" updated on post %d.', $field_name, $post_id ),
						);
					}
				}

				// Fallback: standard post meta.
				update_post_meta( $post_id, $field_name, $value );
				return array(
					'success'    => true,
					'post_id'    => $post_id,
					'field_name' => $field_name,
					'message'    => sprintf( 'Field "%s" updated via post meta on post %d (ACF field not found — used update_post_meta fallback).', $field_name, $post_id ),
				);
			} catch ( \Throwable $e ) {
				return array( 'success' => false, 'message' => 'Field update failed: ' . $e->getMessage() );
			}
		},
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'meta' => array( 'mcp' => array( 'public' => true ) ),
	)
);
