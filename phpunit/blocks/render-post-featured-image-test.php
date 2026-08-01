<?php
/**
 * Post Featured Image block rendering tests.
 *
 * @package Gutenberg
 * @subpackage Blocks
 */

/**
 * Tests for the Post Featured Image block.
 *
 * @group blocks
 */
class Tests_Blocks_Render_Post_Featured_Image extends WP_UnitTestCase {
	private static $post_id;
	private static $attachment_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$post_id       = $factory->post->create();
		self::$attachment_id = $factory->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg',
			self::$post_id,
			array( 'post_mime_type' => 'image/jpeg' )
		);
		set_post_thumbnail( self::$post_id, self::$attachment_id );
	}

	public static function wpTearDownAfterClass() {
		wp_delete_post( self::$attachment_id, true );
		wp_delete_post( self::$post_id, true );
	}

	public function tear_down() {
		remove_all_filters( 'wp_theme_json_data_theme' );
		WP_Theme_JSON_Resolver::clean_cached_data();
		WP_Theme_JSON_Resolver_Gutenberg::clean_cached_data();
		delete_post_meta( self::$post_id, '_thumbnail_focal_point' );
		parent::tear_down();
	}

	public function test_renders_valid_opted_in_featured_image_focal_point() {
		$this->enable_focal_point_setting();
		update_post_meta(
			self::$post_id,
			'_thumbnail_focal_point',
			array(
				'x' => 0.25,
				'y' => 0.75,
			)
		);

		$rendered = $this->render_block( array( 'useFeaturedImageFocalPoint' => true ) );

		$this->assertStringContainsString( 'object-position:25% 75%', $rendered );
	}

	public function test_does_not_render_focal_point_without_theme_support_or_block_opt_in() {
		update_post_meta(
			self::$post_id,
			'_thumbnail_focal_point',
			array(
				'x' => 0.25,
				'y' => 0.75,
			)
		);

		$this->assertStringNotContainsString(
			'object-position',
			$this->render_block( array( 'useFeaturedImageFocalPoint' => true ) )
		);

		$this->enable_focal_point_setting();
		$this->assertStringNotContainsString( 'object-position', $this->render_block() );
	}

	/**
	 * @dataProvider data_invalid_focal_points
	 */
	public function test_ignores_invalid_focal_point_metadata( $focal_point ) {
		$this->enable_focal_point_setting();
		update_post_meta( self::$post_id, '_thumbnail_focal_point', $focal_point );

		$this->assertStringNotContainsString(
			'object-position',
			$this->render_block( array( 'useFeaturedImageFocalPoint' => true ) )
		);
	}

	public function test_does_not_apply_focal_point_to_first_image_fallback() {
		$this->enable_focal_point_setting();
		update_post_meta(
			self::$post_id,
			'_thumbnail_focal_point',
			array(
				'x' => 0.25,
				'y' => 0.75,
			)
		);
		delete_post_thumbnail( self::$post_id );
		wp_update_post(
			array(
				'ID'           => self::$post_id,
				'post_content' => '<!-- wp:image --><figure class="wp-block-image"><img src="fallback.jpg" alt="" /></figure><!-- /wp:image -->',
			)
		);

		$rendered = $this->render_block(
			array(
				'useFeaturedImageFocalPoint' => true,
				'useFirstImageFromPost'      => true,
			)
		);

		$this->assertStringContainsString( 'fallback.jpg', $rendered );
		$this->assertStringNotContainsString( 'object-position', $rendered );
		set_post_thumbnail( self::$post_id, self::$attachment_id );
	}

	public static function data_invalid_focal_points() {
		return array(
			'missing'      => array( array( 'x' => 0.5 ) ),
			'nonnumeric'   => array(
				array(
					'x' => 'left',
					'y' => 0.5,
				),
			),
			'out of range' => array(
				array(
					'x' => 0.5,
					'y' => 1.1,
				),
			),
		);
	}

	private function enable_focal_point_setting() {
		add_filter(
			'wp_theme_json_data_theme',
			static function ( $theme_json ) {
				return $theme_json->update_with(
					array(
						'version'  => 3,
						'settings' => array(
							'featuredImage' => array( 'focalPoint' => true ),
						),
					)
				);
			}
		);
		WP_Theme_JSON_Resolver::clean_cached_data();
		WP_Theme_JSON_Resolver_Gutenberg::clean_cached_data();
	}

	private function render_block( $attributes = array() ) {
		$attributes = array_merge(
			array(
				'isLink'                => false,
				'linkTarget'            => '_self',
				'useFirstImageFromPost' => false,
			),
			$attributes
		);
		$block      = new WP_Block(
			array(
				'blockName'    => 'core/post-featured-image',
				'attrs'        => $attributes,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
			array( 'postId' => self::$post_id )
		);

		return gutenberg_render_block_core_post_featured_image( $attributes, '', $block );
	}
}
