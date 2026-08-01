<?php
/**
 * Featured image focal point metadata tests.
 *
 * @package Gutenberg
 */

/**
 * Tests featured image focal point metadata registration and REST validation.
 */
class Gutenberg_Featured_Image_Focal_Point_Test extends WP_UnitTestCase {
	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private static $admin_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	public function set_up() {
		parent::set_up();
		gutenberg_register_featured_image_focal_point_meta();
		wp_set_current_user( self::$admin_id );
	}

	public function test_registers_and_persists_valid_featured_image_focal_point_metadata() {
		$post_id = self::factory()->post->create();
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_body_params(
			array(
				'meta' => array(
					'_thumbnail_focal_point' => array(
						'x' => 0.25,
						'y' => 0.75,
					),
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'x' => 0.25,
				'y' => 0.75,
			),
			get_post_meta( $post_id, '_thumbnail_focal_point', true )
		);
	}

	/**
	 * @dataProvider data_invalid_focal_points
	 */
	public function test_rejects_invalid_featured_image_focal_point_metadata( $focal_point ) {
		$post_id = self::factory()->post->create();
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_body_params(
			array(
				'meta' => array( '_thumbnail_focal_point' => $focal_point ),
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( '', get_post_meta( $post_id, '_thumbnail_focal_point', true ) );
	}

	public static function data_invalid_focal_points() {
		return array(
			'missing coordinate'    => array( array( 'x' => 0.5 ) ),
			'additional property'   => array(
				array(
					'x' => 0.5,
					'y' => 0.5,
					'z' => 0.5,
				),
			),
			'nonnumeric coordinate' => array(
				array(
					'x' => 'left',
					'y' => 0.5,
				),
			),
			'coordinate below zero' => array(
				array(
					'x' => -0.1,
					'y' => 0.5,
				),
			),
			'coordinate above one'  => array(
				array(
					'x' => 0.5,
					'y' => 1.1,
				),
			),
		);
	}
}
