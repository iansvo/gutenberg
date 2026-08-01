<?php
/**
 * Featured image focal point compatibility for WordPress 7.1.
 *
 * @package gutenberg
 */

/**
 * Registers featured image focal point post metadata.
 */
function gutenberg_register_featured_image_focal_point_meta() {
	$post_types = get_post_types( array( 'show_in_rest' => true ) );

	foreach ( $post_types as $post_type ) {
		if ( ! post_type_supports( $post_type, 'thumbnail' ) || ! post_type_supports( $post_type, 'custom-fields' ) ) {
			continue;
		}

		register_post_meta(
			$post_type,
			'_thumbnail_focal_point',
			array(
				'single'        => true,
				'type'          => 'object',
				'auth_callback' => static function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
				'show_in_rest'  => array(
					'schema' => array(
						'type'                 => 'object',
						'properties'           => array(
							'x' => array(
								'type'    => 'number',
								'minimum' => 0,
								'maximum' => 1,
							),
							'y' => array(
								'type'    => 'number',
								'minimum' => 0,
								'maximum' => 1,
							),
						),
						'required'             => array( 'x', 'y' ),
						'additionalProperties' => false,
					),
				),
			)
		);
	}
}
add_action( 'init', 'gutenberg_register_featured_image_focal_point_meta' );
