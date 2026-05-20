<?php
/**
 * PostType Class.
 *
 * @since 2.5.0
 * @package SoliloquyWP Lite
 * @author SoliloquyWP Team <support@soliloquywp.com>
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Soliloquy PostType
 *
 * @since 2.5.0
 */
class Soliloquy_Posttype_Lite {

	/**
	 * Holds the class object.
	 *
	 * @since 1.0.0
	 *
	 * @var object
	 */
	public static $instance;

	/**
	 * Path to the file.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $file = __FILE__;

	/**
	 * Holds the base class object.
	 *
	 * @since 1.0.0
	 *
	 * @var object
	 */
	public $base;

	/**
	 * Primary class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		// Load the base class object.
		$this->base = Soliloquy_Lite::get_instance();

		// Build the labels for the post type.
		$labels = apply_filters(
			'soliloquy_post_type_labels',
			[
				'name'               => __( 'Soliloquy Sliders', 'soliloquy' ),
				'singular_name'      => __( 'Soliloquy', 'soliloquy' ),
				'add_new'            => __( 'Add New', 'soliloquy' ),
				'add_new_item'       => __( 'Add New Soliloquy Slider', 'soliloquy' ),
				'edit_item'          => __( 'Edit Soliloquy Slider', 'soliloquy' ),
				'new_item'           => __( 'New Soliloquy Slider', 'soliloquy' ),
				'view_item'          => __( 'View Soliloquy Slider', 'soliloquy' ),
				'search_items'       => __( 'Search Soliloquy Sliders', 'soliloquy' ),
				'not_found'          => __( 'No Soliloquy sliders found.', 'soliloquy' ),
				'not_found_in_trash' => __( 'No Soliloquy sliders found in trash.', 'soliloquy' ),
				'parent_item_colon'  => '',
				'menu_name'          => __( 'Soliloquy', 'soliloquy' ),
			]
		);

		// Build out the post type arguments.
		$args = apply_filters(
			'soliloquy_post_type_args',
			[
				'labels'              => $labels,
				'public'              => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => true,
				'show_ui'             => true,
				'show_in_admin_bar'   => false,
				'rewrite'             => false,
				'query_var'           => false,
				'show_in_rest'        => true,
				'rest_base'           => 'soliloquy',
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'menu_position'       => apply_filters( 'soliloquy_post_type_menu_position', 248 ),
				'menu_icon'           => plugins_url( 'assets/css/images/menu-icon@2x.png', $this->base->file ),
				'supports'            => [ 'title', 'author' ],
			]
		);

		// Register the post type with WordPress.
		register_post_type( 'soliloquy', $args );

		// Add Soliloquy meta data to REST API.
		add_filter( 'rest_prepare_soliloquy', [ $this, 'prepare_meta' ], 10, 3 );

		// Map meta capabilities for proper ownership checks.
		add_filter( 'map_meta_cap', [ $this, 'map_meta_cap' ], 10, 4 );
	}

	/**
	 * Helper Method to add Soliloquy Meta data to the Rest API
	 *
	 * @param [type] $data Rest Data.
	 * @param [type] $post Post Object.
	 * @param [type] $context Context.
	 * @return array
	 */
	public function prepare_meta( $data, $post, $context ) {

		// Belt-and-suspenders gate: only expose slider_data when the post is published or the current user can read it.
		// REST controller already enforces this via map_meta_cap; this guards against future regressions in cap mapping.
		if ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $post->ID ) ) {
			return $data;
		}

		$slider_data = get_post_meta( $post->ID, '_sol_slider_data', true );

		if ( $slider_data ) {
			$data->data['slider_data'] = $slider_data;
		}

		return $data;
	}

	/**
	 * Map meta capabilities for Soliloquy post type.
	 *
	 * @since 2.8.1
	 * @param array  $caps    Required capabilities.
	 * @param string $cap     Capability being checked.
	 * @param int    $user_id User ID.
	 * @param array  $args    Additional arguments (post ID).
	 * @return array Modified capabilities.
	 */
	public function map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, [ 'edit_post', 'delete_post', 'read_post' ], true ) ) {
			return $caps;
		}

		$post_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( ! $post_id ) {
			return $caps;
		}

		$post = get_post( $post_id );
		if ( ! $post || 'soliloquy' !== $post->post_type ) {
			return $caps;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return $caps;
		}

		$post_author = (int) $post->post_author;

		// Block authors from accessing sliders they don't own.
		if ( in_array( 'author', $user->roles, true ) && $user_id !== $post_author ) {
			return [ 'do_not_allow' ];
		}

		if ( 'edit_post' === $cap ) {
			$caps = ( $user_id === $post_author ) ? [ 'edit_posts' ] : [ 'edit_others_posts' ];
		}

		if ( 'delete_post' === $cap ) {
			$caps = ( $user_id === $post_author ) ? [ 'delete_posts' ] : [ 'delete_others_posts' ];
		}

		if ( 'read_post' === $cap ) {
			if ( 'private' === $post->post_status ) {
				$caps = [ 'read_private_posts' ];
			} elseif ( 'publish' === $post->post_status ) {
				$caps = [ 'read' ];
			} else {
				// Non-public statuses (draft, pending, future, auto-draft, trash, inherit, etc.) require edit caps.
				$caps = ( $user_id === $post_author ) ? [ 'edit_posts' ] : [ 'edit_others_posts' ];
			}
		}

		return $caps;
	}

	/**
	 * Returns the singleton instance of the class.
	 *
	 * @since 1.0.0
	 *
	 * @return object The Soliloquy_Posttype_Lite object.
	 */
	public static function get_instance() {

		if ( ! isset( self::$instance ) && ! ( self::$instance instanceof Soliloquy_Posttype_Lite ) ) {
			self::$instance = new Soliloquy_Posttype_Lite();
		}

		return self::$instance;
	}
}

// Load the posttype class.
$soliloquy_posttype_lite = Soliloquy_Posttype_Lite::get_instance();
