<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── dizrupt/meta-get ─────────────────────────────────────────────────────────

wp_register_ability(
	'dizrupt/meta-get',
	array(
		'label'       => __( 'Get post meta values', 'dizrupt-mcp-abilities' ),
		'category'    => 'dizrupt-meta',
		'description' => 'Read post meta fields. Provide meta_key to retrieve a specific field, or omit it to retrieve all non-private meta fields (keys not starting with "_"). Private/internal WordPress meta keys are excluded from the all-fields response.',
		'input_schema' => array(
			'type'       => 'object',
			'default'    => array(),
			'required'   => array( 'post_id' ),
			'properties' => array(
				'post_id'  => array( 'type' => 'integer', 'description' => 'The post ID. Also accepted as "id".' ),
				'id'       => array( 'type' => 'integer', 'description' => 'Alias for post_id.' ),
				'meta_key' => array( 'type' => 'string', 'description' => 'Specific meta key to retrieve. If omitted, all non-private meta is returned.' ),
			),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array( 'type' => 'integer' ),
				'meta'    => array( 'type' => 'object' ),
			),
		),
		'execute_callback' => function ( $input ) {
			$post_id = absint( $input['post_id'] ?? $input['id'] ?? 0 );

			if ( ! $post_id ) {
				return array( 'success' => false, 'message' => 'post_id is required.' );
			}

			if ( ! get_post( $post_id ) ) {
				return array( 'success' => false, 'message' => "Post {$post_id} not found." );
			}

			if ( ! empty( $input['meta_key'] ) ) {
				$key   = sanitize_text_field( $input['meta_key'] );
				$value = get_post_meta( $post_id, $key, true );
				return array(
					'post_id' => $post_id,
					'meta'    => array( $key => $value ),
				);
			}

			// Return all non-private meta.
			$all_meta = get_post_meta( $post_id );
			$meta     = array();
			foreach ( $all_meta as $key => $values ) {
				if ( str_starts_with( $key, '_' ) ) {
					continue;
				}
				$meta[ $key ] = count( $values ) === 1 ? $values[0] : $values;
			}

			return array(
				'post_id' => $post_id,
				'meta'    => $meta,
			);
		},
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'meta' => array( 'mcp' => array( 'public' => true ) ),
	)
);

// ─── dizrupt/meta-set ─────────────────────────────────────────────────────────

wp_register_ability(
	'dizrupt/meta-set',
	array(
		'label'       => __( 'Set a post meta value', 'dizrupt-mcp-abilities' ),
		'category'    => 'dizrupt-meta',
		'description' => 'Set (create or update) a post meta field. Use dizrupt/acf-update-field instead if the field is an ACF field, as ACF provides additional validation and formatting.',
		'input_schema' => array(
			'type'       => 'object',
			'default'    => array(),
			'required'   => array( 'post_id', 'meta_key', 'meta_value' ),
			'properties' => array(
				'post_id'    => array( 'type' => 'integer', 'description' => 'The post ID. Also accepted as "id".' ),
				'id'         => array( 'type' => 'integer', 'description' => 'Alias for post_id.' ),
				'meta_key'   => array( 'type' => 'string', 'description' => 'The meta key to set.' ),
				'meta_value' => array( 'description' => 'The value to store.' ),
			),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'success'    => array( 'type' => 'boolean' ),
				'post_id'    => array( 'type' => 'integer' ),
				'meta_key'   => array( 'type' => 'string' ),
				'message'    => array( 'type' => 'string' ),
			),
		),
		'execute_callback' => function ( $input ) {
			$post_id    = absint( $input['post_id'] ?? $input['id'] ?? 0 );
			$meta_key   = sanitize_text_field( $input['meta_key'] ?? '' );
			$meta_value = $input['meta_value'] ?? null;

			if ( ! $post_id || empty( $meta_key ) ) {
				return array( 'success' => false, 'message' => 'post_id and meta_key are required.' );
			}

			if ( ! get_post( $post_id ) ) {
				return array( 'success' => false, 'message' => "Post {$post_id} not found." );
			}

			update_post_meta( $post_id, $meta_key, $meta_value );

			return array(
				'success'  => true,
				'post_id'  => $post_id,
				'meta_key' => $meta_key,
				'message'  => sprintf( 'Meta field "%s" set on post %d.', $meta_key, $post_id ),
			);
		},
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'meta' => array( 'mcp' => array( 'public' => true ) ),
	)
);
