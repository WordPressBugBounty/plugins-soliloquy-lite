<?php
/**
 * Soliloquy Lite slider service — slider reads and mutations.
 *
 * The single writer of `_sol_slider_data` for the Abilities API, and the
 * writer the wp-admin save path persists its payload through
 * (write_slider_data()), so the abilities never become a second source of
 * truth for slider meta.
 *
 * Loaded globally: abilities run on REST and MCP requests, where is_admin() is
 * false and none of the admin classes (metaboxes, AJAX) are loaded. The slide
 * shape built here therefore mirrors Soliloquy_Ajax_Lite::prepare_slider_data()
 * rather than calling it.
 *
 * Not identical to the Pro copy: Pro also mirrors the post status into the
 * payload's top-level `status` key, which Pro's get_slider() reads and Lite's
 * does not.
 *
 * @since 2.9.0
 *
 * @package Soliloquy Lite
 * @author  SoliloquyWP Team <support@soliloquywp.com>
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Soliloquy_Slider_Service_Lite' ) ) {

	/**
	 * Soliloquy_Slider_Service_Lite — slider CRUD used by the Abilities layer.
	 *
	 * Every method returns a normalised slider array (see format()) or a
	 * WP_Error carrying an HTTP status, so callers can hand the result straight
	 * back to REST or MCP without translating.
	 *
	 * @since 2.9.0
	 */
	class Soliloquy_Slider_Service_Lite {

		/**
		 * Slider post type.
		 *
		 * @since 2.9.0
		 *
		 * @var string
		 */
		const POST_TYPE = 'soliloquy';

		/**
		 * Meta key holding the slider payload.
		 *
		 * @since 2.9.0
		 *
		 * @var string
		 */
		const META_KEY = '_sol_slider_data';

		// -------------------------------------------------------------------------
		// Shared writer
		// -------------------------------------------------------------------------

		/**
		 * Store a slider payload and confirm it landed.
		 *
		 * The one place `_sol_slider_data` is written for the abilities and the
		 * wp-admin save. wp_slash(): update_post_meta() unslashes its value, which
		 * stripped every backslash from titles and captions (`C:\photos` became
		 * `C:photos`). update_post_meta() also returns false for an unchanged
		 * value, so the value is read back rather than trusting that return.
		 *
		 * @since 2.9.0
		 *
		 * @param int   $slider_id   Slider ID.
		 * @param array $slider_data Payload to store.
		 * @return bool True when the stored payload now matches.
		 */
		public static function write_slider_data( $slider_id, array $slider_data ) {
			$slider_id = (int) $slider_id;

			update_post_meta( $slider_id, self::META_KEY, wp_slash( $slider_data ) );

			return get_post_meta( $slider_id, self::META_KEY, true ) === $slider_data;
		}

		// -------------------------------------------------------------------------
		// Reads
		// -------------------------------------------------------------------------

		/**
		 * Return a paginated list of sliders.
		 *
		 * Without edit_others_posts a caller only sees their own sliders, which
		 * is what the edit_post meta cap behind get-slider allows them to open.
		 *
		 * @since 2.9.0
		 *
		 * @param array $args {
		 *     Optional. Query arguments.
		 *
		 *     @type int    $per_page Sliders per page. Default 20.
		 *     @type int    $page     Page number. Default 1.
		 *     @type string $status   Publish status, or 'any'. Default 'any'.
		 *     @type string $search   Title search term.
		 * }
		 * @return array
		 */
		public function list_sliders( array $args = [] ) {
			$per_page = isset( $args['per_page'] ) ? max( 1, min( 100, (int) $args['per_page'] ) ) : 20;
			$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
			$status   = isset( $args['status'] ) && is_string( $args['status'] ) && 'any' !== $args['status'] ? sanitize_key( $args['status'] ) : 'any';

			// 'any' spelled out as the searchable statuses it stands for: WP_Query
			// applies `perm => readable` to private posts only when the statuses
			// are listed, so a bare 'any' would list other authors' private
			// sliders to a caller without read_private_posts.
			if ( 'any' === $status ) {
				$status = array_values( get_post_stati( [ 'exclude_from_search' => false ] ) );
			}

			$query_args = [
				'post_type'      => self::POST_TYPE,
				'post_status'    => $status,
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => false,
				'perm'           => 'readable',
			];

			if ( ! empty( $args['search'] ) && is_string( $args['search'] ) ) {
				$query_args['s'] = sanitize_text_field( $args['search'] );
			}

			if ( ! current_user_can( 'edit_others_posts' ) ) {
				$query_args['author'] = get_current_user_id();
			}

			$query = new WP_Query( $query_args );

			return [
				'sliders'     => array_map( [ $this, 'format' ], $query->posts ),
				'total'       => (int) $query->found_posts,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => (int) $query->max_num_pages,
			];
		}

		/**
		 * Return a single slider, with its slides.
		 *
		 * @since 2.9.0
		 *
		 * @param int $id Slider ID.
		 * @return array|WP_Error
		 */
		public function get( int $id ) {
			$post = $this->get_slider_post( $id );

			if ( is_wp_error( $post ) ) {
				return $post;
			}

			// Refuse an unreadable payload here too, so a read never reports a
			// corrupt slider as empty while every write refuses it.
			$readable = $this->read_data( $id );

			if ( is_wp_error( $readable ) ) {
				return $readable;
			}

			return $this->format( $post, true );
		}

		/**
		 * Slider types a slider can be created as.
		 *
		 * Only the default (image) type. The other types belong to addons whose
		 * settings only load in wp-admin, so an ability cannot set them up.
		 *
		 * @since 2.9.0
		 *
		 * @return array<string, string> Labels keyed by type.
		 */
		public function get_slider_types() {
			return [
				'default' => __( 'Default', 'soliloquy' ),
			];
		}

		/**
		 * Slider themes available on this site.
		 *
		 * @since 2.9.0
		 *
		 * @return array<string, string> Labels keyed by theme.
		 */
		public function get_slider_themes() {
			$themes = [];

			foreach ( (array) Soliloquy_Common_Lite::get_instance()->get_slider_themes() as $theme ) {
				if ( is_array( $theme ) && ! empty( $theme['value'] ) && is_string( $theme['value'] ) ) {
					$themes[ $theme['value'] ] = isset( $theme['name'] ) && is_string( $theme['name'] ) ? $theme['name'] : $theme['value'];
				}
			}

			return $themes;
		}

		// -------------------------------------------------------------------------
		// Mutations
		// -------------------------------------------------------------------------

		/**
		 * Create an empty slider with the default settings.
		 *
		 * @since 2.9.0
		 *
		 * @param string $title  Slider title.
		 * @param string $status Initial publish status.
		 * @return array|WP_Error
		 */
		public function create( string $title, string $status = 'draft' ) {
			$title  = sanitize_text_field( $title );
			$status = sanitize_key( $status );

			// wp_slash(): wp_insert_post() unslashes its input, which would strip
			// backslashes from the title.
			$post_id = wp_insert_post(
				wp_slash(
					[
						'post_type'   => self::POST_TYPE,
						'post_status' => $status,
						'post_title'  => $title,
						'post_author' => get_current_user_id(),
					]
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				$post_id->add_data( [ 'status' => 500 ] );

				return $post_id;
			}

			$post = get_post( $post_id );

			if ( ! $post ) {
				return $this->discard_created( (int) $post_id, $this->not_retrievable() );
			}

			$slider_data = $this->bootstrap_data( $post );

			if ( ! self::write_slider_data( (int) $post_id, $slider_data ) || ! $this->write_meta( (int) $post_id, '_sol_in_slider', [] ) ) {
				return $this->discard_created(
					(int) $post_id,
					new WP_Error(
						'soliloquy_save_failed',
						__( 'The slider could not be saved.', 'soliloquy' ),
						[ 'status' => 500 ]
					)
				);
			}

			$this->flush_caches( (int) $post_id, $slider_data['config']['slug'] );

			return $this->format( $post );
		}

		/**
		 * Update a slider's title and/or publish status.
		 *
		 * @since 2.9.0
		 *
		 * @param int   $id     Slider ID.
		 * @param array $fields {
		 *     Fields to change. Omitted keys are left alone.
		 *
		 *     @type string $title  New title.
		 *     @type string $status New publish status.
		 * }
		 * @return array|WP_Error
		 */
		public function update( int $id, array $fields ) {
			$post = $this->get_editable_slider_post( $id );

			if ( is_wp_error( $post ) ) {
				return $post;
			}

			$data = [ 'ID' => $id ];

			if ( isset( $fields['title'] ) && is_string( $fields['title'] ) ) {
				$data['post_title'] = sanitize_text_field( $fields['title'] );
			}

			if ( isset( $fields['status'] ) && is_string( $fields['status'] ) ) {
				$data['post_status'] = sanitize_key( $fields['status'] );
			}

			if ( count( $data ) <= 1 ) {
				return $this->format( $post );
			}

			// Read the payload before writing anything, so an unreadable row is
			// refused with the post untouched. A status-only change is refused
			// too: publishing a slider whose data cannot be read would put a
			// slider live that renders nothing.
			$slider_data = $this->read_data( $id );

			if ( is_wp_error( $slider_data ) ) {
				return $slider_data;
			}

			$old_slug = $this->resolve_slug( $post, $slider_data['config'] );
			$result   = wp_update_post( wp_slash( $data ), true );

			if ( is_wp_error( $result ) ) {
				$result->add_data( [ 'status' => 500 ] );

				return $result;
			}

			$updated = get_post( $id );

			if ( ! $updated ) {
				return $this->not_retrievable();
			}

			$new_data = $this->sync_config( $slider_data, $updated );

			if ( $new_data !== $slider_data && ! self::write_slider_data( $id, $new_data ) ) {
				return $this->revert_post( $post, $slider_data );
			}

			// Both slugs: [soliloquy slug="..."] caches under the slug for a day,
			// and a rename must not leave the old slug serving the old payload.
			$this->flush_caches( $id, $old_slug );
			$this->flush_caches( $id, $this->resolve_slug( $updated, $new_data['config'] ) );

			return $this->format( $updated );
		}

		/**
		 * Trash a slider, or delete it permanently.
		 *
		 * The trash and delete side effects (the `inactive` flag, cache flushes)
		 * are the lifecycle handlers' job. They are hooked globally, so they run
		 * on REST and MCP requests as well.
		 *
		 * @since 2.9.0
		 *
		 * @param int  $id    Slider ID.
		 * @param bool $force True to delete permanently, false to trash.
		 * @return array|WP_Error
		 */
		public function delete( int $id, bool $force = false ) {
			$post = $this->get_slider_post( $id );

			if ( is_wp_error( $post ) ) {
				return $post;
			}

			// Refuse a slider whose data cannot be read: the caller cannot know
			// what it is destroying, and every other write path refuses it too.
			$slider_data = $this->read_data( $id );

			if ( is_wp_error( $slider_data ) ) {
				return $slider_data;
			}

			$slug = $this->resolve_slug( $post, $slider_data['config'] );

			// wp_delete_post() only routes to the trash for posts and pages, so for
			// this post type `force: false` would delete permanently. Trash it
			// explicitly. wp_trash_post() deletes outright when EMPTY_TRASH_DAYS
			// is 0, so report that as the permanent delete it is.
			$permanent = $force || ! EMPTY_TRASH_DAYS;

			if ( ! $permanent && 'trash' === $post->post_status ) {
				$this->flush_caches( $id, $slug );

				return [
					'deleted' => true,
					'trashed' => true,
					'id'      => $id,
				];
			}

			$removed = $permanent ? $this->remove_memberships( $id, $slider_data ) : [];

			if ( false === $removed ) {
				return new WP_Error(
					'soliloquy_delete_failed',
					__( 'The slider was not deleted: its slide membership records could not be updated.', 'soliloquy' ),
					[ 'status' => 500 ]
				);
			}

			$result = $permanent ? wp_delete_post( $id, true ) : wp_trash_post( $id );

			if ( ! $result ) {
				// The slider is still there, so its images must point at it again.
				$restored = $this->restore_meta( $removed, '_sol_has_slider' );

				return new WP_Error(
					'soliloquy_delete_failed',
					$restored
						? __( 'Failed to delete the slider.', 'soliloquy' )
						: __( 'Failed to delete the slider, and its slide membership records could not be fully restored. Re-save the slider in wp-admin to repair them.', 'soliloquy' ),
					[ 'status' => 500 ]
				);
			}

			$this->flush_caches( $id, $slug );

			return [
				'deleted' => true,
				'trashed' => ! $permanent,
				'id'      => $id,
			];
		}

		/**
		 * Add media library images to a slider as image slides.
		 *
		 * Slides are written `active`. The wp-admin flow adds them as the
		 * "New Slide Status" option says (pending by default) and activates them
		 * when the slider is saved; an ability has no save step, so pending
		 * slides would never render.
		 *
		 * @since 2.9.0
		 *
		 * @param int   $id             Slider ID.
		 * @param int[] $attachment_ids Attachment IDs to add.
		 * @return array|WP_Error
		 */
		public function add_slides( int $id, array $attachment_ids ) {
			$post = $this->get_editable_slider_post( $id );

			if ( is_wp_error( $post ) ) {
				return $post;
			}

			$attachment_ids = array_values( array_unique( array_filter( array_map( 'absint', array_filter( $attachment_ids, 'is_scalar' ) ) ) ) );

			if ( empty( $attachment_ids ) ) {
				return new WP_Error(
					'soliloquy_no_images',
					__( 'No valid attachment IDs provided.', 'soliloquy' ),
					[ 'status' => 400 ]
				);
			}

			$slider_data = $this->read_data( $id );

			if ( is_wp_error( $slider_data ) ) {
				return $slider_data;
			}

			$new_data    = $slider_data;
			$added       = [];
			$skipped     = [];
			$existing    = [];
			$memberships = [];

			foreach ( $attachment_ids as $attachment_id ) {
				// Never rewrite a slide that is already in the slider: its record
				// holds the title, caption, alt and link the site owner set.
				if ( isset( $new_data['slider'][ $attachment_id ] ) ) {
					$existing[] = $attachment_id;
					continue;
				}

				// The image must be one this account may edit: the edit_post meta
				// cap passes for your own uploads, and for anyone's with
				// edit_others_posts.
				if ( ! wp_attachment_is_image( $attachment_id ) || ! current_user_can( 'edit_post', $attachment_id ) ) {
					$skipped[] = $attachment_id;
					continue;
				}

				$new_data['slider'][ $attachment_id ] = $this->build_image_slide( $attachment_id );

				$has_slider = get_post_meta( $attachment_id, '_sol_has_slider', true );
				$has_slider = is_array( $has_slider ) ? array_map( 'intval', $has_slider ) : [];

				if ( ! in_array( $id, $has_slider, true ) ) {
					$has_slider[]                  = $id;
					$memberships[ $attachment_id ] = $has_slider;
				}

				$added[] = $attachment_id;
			}

			if ( ! $added ) {
				if ( $existing ) {
					// Nothing new to add; ids already present are not an error.
					return [
						'slider'   => $this->format( $post ),
						'added'    => [],
						'skipped'  => $skipped,
						'existing' => $existing,
					];
				}

				return new WP_Error(
					'soliloquy_no_images',
					__( 'None of the supplied IDs are media library images this account may use.', 'soliloquy' ),
					[ 'status' => 400 ]
				);
			}

			$previous_in = get_post_meta( $id, '_sol_in_slider', true );
			$in_slider   = is_array( $previous_in ) ? $previous_in : [];

			foreach ( $added as $attachment_id ) {
				if ( ! in_array( $attachment_id, array_map( 'intval', $in_slider ), true ) ) {
					$in_slider[] = $attachment_id;
				}
			}

			// Membership records first, then the slider, and every step undone on
			// failure, so the slider and its images never end up out of step:
			// delete_slider_association() reads _sol_has_slider to clean up when an
			// image is deleted.
			$written = [];
			$failed  = ! $this->write_meta( $id, '_sol_in_slider', $in_slider );

			foreach ( $failed ? [] : $memberships as $attachment_id => $has_slider ) {
				$previous = get_post_meta( $attachment_id, '_sol_has_slider', true );

				if ( ! $this->write_meta( $attachment_id, '_sol_has_slider', $has_slider ) ) {
					$failed = true;
					break;
				}

				$written[ $attachment_id ] = $previous;
			}

			if ( $failed || ! self::write_slider_data( $id, $new_data ) ) {
				$restored = $this->restore_meta( $written, '_sol_has_slider' )
					&& $this->restore_meta( [ $id => $previous_in ], '_sol_in_slider' );

				return new WP_Error(
					'soliloquy_save_failed',
					$restored
						? __( 'The slides could not be added, so nothing was changed.', 'soliloquy' )
						: __( 'The slides could not be added, and the slide membership records could not be fully restored. Re-save the slider in wp-admin to repair them.', 'soliloquy' ),
					[ 'status' => 500 ]
				);
			}

			$this->flush_caches( $id, $this->resolve_slug( $post, $new_data['config'] ) );

			/** This action is documented in includes/admin/ajax.php */
			do_action( 'soliloquy_ajax_insert_slides', $added, [], [], $id );

			// Report the partial case explicitly, so a caller that asked for five
			// images and got three can tell.
			return [
				'slider'   => $this->format( $post ),
				'added'    => $added,
				'skipped'  => $skipped,
				'existing' => $existing,
			];
		}

		// -------------------------------------------------------------------------
		// Formatting
		// -------------------------------------------------------------------------

		/**
		 * Normalise a slider WP_Post into a consistent array.
		 *
		 * @since 2.9.0
		 *
		 * @param WP_Post $post        Slider post object.
		 * @param bool    $with_slides Whether to include the slides.
		 * @return array
		 */
		public function format( WP_Post $post, bool $with_slides = false ) {
			$slider_data = get_post_meta( $post->ID, self::META_KEY, true );
			// Only arrays are counted or passed on: one partly corrupt slider would
			// otherwise throw a TypeError and take down the whole list response.
			$slides = is_array( $slider_data ) && isset( $slider_data['slider'] ) && is_array( $slider_data['slider'] ) ? $slider_data['slider'] : [];
			$config = is_array( $slider_data ) && isset( $slider_data['config'] ) && is_array( $slider_data['config'] ) ? $slider_data['config'] : [];

			$slider = [
				'id'          => (int) $post->ID,
				'title'       => (string) $post->post_title,
				'slug'        => $this->resolve_slug( $post, $config ),
				'status'      => (string) $post->post_status,
				'created'     => $this->to_rfc3339( $post->post_date_gmt, $post->post_date ),
				'modified'    => $this->to_rfc3339( $post->post_modified_gmt, $post->post_modified ),
				// get_edit_post_link() returns null for a caller who cannot edit the
				// post, and the output schema declares a string.
				'edit_url'    => (string) get_edit_post_link( $post->ID, 'raw' ),
				'shortcode'   => '[soliloquy id="' . (int) $post->ID . '"]',
				'type'        => isset( $config['type'] ) && is_string( $config['type'] ) && '' !== $config['type'] ? $config['type'] : 'default',
				'theme'       => isset( $config['slider_theme'] ) && is_string( $config['slider_theme'] ) && '' !== $config['slider_theme'] ? $config['slider_theme'] : 'base',
				'slide_count' => count( $slides ),
			];

			// Omitted rather than emitted empty when unresolvable: '' is not a valid
			// date-time and would fail the ability's own output schema.
			foreach ( [ 'created', 'modified' ] as $field ) {
				if ( '' === $slider[ $field ] ) {
					unset( $slider[ $field ] );
				}
			}

			if ( $with_slides ) {
				$slider['slides'] = [];

				foreach ( $slides as $slide_id => $slide ) {
					if ( is_array( $slide ) ) {
						$slider['slides'][] = $this->format_slide( $slide_id, $slide );
					}
				}
			}

			return $slider;
		}

		// -------------------------------------------------------------------------
		// Internals
		// -------------------------------------------------------------------------

		/**
		 * Normalise one stored slide for output.
		 *
		 * @since 2.9.0
		 *
		 * @param int|string $slide_id Slide key in the payload.
		 * @param array      $slide    Stored slide.
		 * @return array
		 */
		private function format_slide( $slide_id, array $slide ) {
			$string = static function ( $key ) use ( $slide ) {
				return isset( $slide[ $key ] ) && is_scalar( $slide[ $key ] ) ? (string) $slide[ $key ] : '';
			};

			return [
				'id'            => (string) $slide_id,
				'attachment_id' => isset( $slide['attachment_id'] ) && is_numeric( $slide['attachment_id'] ) ? (int) $slide['attachment_id'] : 0,
				'type'          => '' !== $string( 'type' ) ? $string( 'type' ) : 'image',
				'status'        => $string( 'status' ),
				'title'         => $string( 'title' ),
				'caption'       => $string( 'caption' ),
				'alt'           => $string( 'alt' ),
				'link'          => $string( 'link' ),
				'src'           => $string( 'src' ),
			];
		}

		/**
		 * Build an image slide in the shape the AJAX insert writes.
		 *
		 * @since 2.9.0
		 *
		 * @param int $attachment_id Attachment ID.
		 * @return array
		 */
		private function build_image_slide( int $attachment_id ) {
			$attachment = get_post( $attachment_id );
			$url        = wp_get_attachment_image_src( $attachment_id, 'full' );
			$alt_text   = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			$title      = sanitize_text_field( get_the_title( $attachment_id ) );

			return [
				'status'        => 'active',
				'id'            => $attachment_id,
				'attachment_id' => $attachment_id,
				'src'           => isset( $url[0] ) ? esc_url_raw( $url[0] ) : '',
				'title'         => $title,
				'link'          => '',
				'alt'           => ! empty( $alt_text ) && is_string( $alt_text ) ? sanitize_text_field( $alt_text ) : $title,
				'caption'       => $attachment && ! empty( $attachment->post_excerpt ) ? wp_kses_post( $attachment->post_excerpt ) : '',
				'type'          => 'image',
			];
		}

		/**
		 * Keep the payload's config title and slug in step with the post.
		 *
		 * The wp-admin save fills config.title and config.slug from the post when
		 * they are empty. A title change through the ability replaces the config
		 * title, and a slider published for the first time gets the slug
		 * WordPress just assigned.
		 *
		 * @since 2.9.0
		 *
		 * @param array   $slider_data Current payload.
		 * @param WP_Post $post        The post after the update.
		 * @return array
		 */
		private function sync_config( array $slider_data, WP_Post $post ) {
			$slider_data['config']['title'] = trim( wp_strip_all_tags( $post->post_title ) );

			if ( empty( $slider_data['config']['slug'] ) && ! empty( $post->post_name ) ) {
				$slider_data['config']['slug'] = sanitize_text_field( $post->post_name );
			}

			return $slider_data;
		}

		/**
		 * Put a post back after its payload write failed.
		 *
		 * @since 2.9.0
		 *
		 * @param WP_Post $post        The post as it was before the update.
		 * @param array   $slider_data The payload as it was.
		 * @return WP_Error
		 */
		private function revert_post( WP_Post $post, array $slider_data ) {
			$reverted = wp_update_post(
				wp_slash(
					[
						'ID'            => $post->ID,
						'post_title'    => $post->post_title,
						'post_status'   => $post->post_status,
						// Publishing assigns a slug and a publish date; put those back too.
						'post_name'     => $post->post_name,
						'post_date'     => $post->post_date,
						'post_date_gmt' => $post->post_date_gmt,
						'edit_date'     => true,
					]
				),
				true
			);

			$this->flush_caches( (int) $post->ID, $this->resolve_slug( $post, $slider_data['config'] ) );

			return new WP_Error(
				'soliloquy_save_failed',
				is_wp_error( $reverted ) || ! $reverted
					? __( 'The slider settings could not be saved, and its title and status could not be put back. Check the slider in wp-admin.', 'soliloquy' )
					: __( 'The slider settings could not be saved, so nothing was changed.', 'soliloquy' ),
				[ 'status' => 500 ]
			);
		}

		/**
		 * Take a permanently deleted slider off its images' _sol_has_slider.
		 *
		 * @since 2.9.0
		 *
		 * @param int   $id          Slider ID.
		 * @param array $slider_data The slider's payload.
		 * @return array|false Previous `_sol_has_slider` values keyed by attachment ID, for
		 *                     putting back; false when a record could not be updated (the ones
		 *                     already changed are put back).
		 */
		private function remove_memberships( int $id, array $slider_data ) {
			$in_slider = get_post_meta( $id, '_sol_in_slider', true );
			$images    = array_merge( array_keys( $slider_data['slider'] ), is_array( $in_slider ) ? $in_slider : [] );
			$images    = array_values( array_unique( array_filter( array_map( 'intval', $images ) ) ) );
			$changed   = [];

			foreach ( $images as $attachment_id ) {
				$has_slider = get_post_meta( $attachment_id, '_sol_has_slider', true );

				if ( ! is_array( $has_slider ) || ! in_array( $id, array_map( 'intval', $has_slider ), true ) ) {
					continue;
				}

				$remaining = array_values( array_diff( array_map( 'intval', $has_slider ), [ $id ] ) );

				if ( ! $this->write_meta( $attachment_id, '_sol_has_slider', $remaining ? $remaining : '' ) ) {
					$this->restore_meta( $changed, '_sol_has_slider' );

					return false;
				}

				$changed[ $attachment_id ] = $has_slider;
			}

			return $changed;
		}

		/**
		 * Write a post meta value and confirm it landed. An empty string deletes the key.
		 *
		 * @since 2.9.0
		 *
		 * @param int    $object_id Post ID.
		 * @param string $key       Meta key.
		 * @param mixed  $value     Value, or '' to delete.
		 * @return bool True when the stored value now matches.
		 */
		private function write_meta( int $object_id, string $key, $value ) {
			if ( '' === $value ) {
				delete_post_meta( $object_id, $key );

				return '' === get_post_meta( $object_id, $key, true );
			}

			update_post_meta( $object_id, $key, wp_slash( $value ) );

			return get_post_meta( $object_id, $key, true ) === $value;
		}

		/**
		 * Put back meta values recorded before a multi-record write.
		 *
		 * @since 2.9.0
		 *
		 * @param array  $previous Previous values keyed by post ID ('' = was absent).
		 * @param string $key      Meta key.
		 * @return bool True when every value was restored.
		 */
		private function restore_meta( array $previous, string $key ) {
			$ok = true;

			foreach ( $previous as $object_id => $value ) {
				$ok = $this->write_meta( (int) $object_id, $key, $value ) && $ok;
			}

			return $ok;
		}

		/**
		 * Remove a slider create() could not finish, and say if that failed too.
		 *
		 * @since 2.9.0
		 *
		 * @param int      $post_id The partly created slider.
		 * @param WP_Error $error   Why create() stopped.
		 * @return WP_Error
		 */
		private function discard_created( int $post_id, WP_Error $error ) {
			if ( wp_delete_post( $post_id, true ) ) {
				return $error;
			}

			return new WP_Error(
				$error->get_error_code(),
				sprintf(
					/* translators: 1: original error message, 2: slider ID. */
					__( '%1$s The partly created slider (ID %2$d) could not be removed.', 'soliloquy' ),
					$error->get_error_message(),
					$post_id
				),
				[ 'status' => 500 ]
			);
		}

		/**
		 * Error for a slider that was written but cannot be read back.
		 *
		 * @since 2.9.0
		 *
		 * @return WP_Error
		 */
		private function not_retrievable() {
			return new WP_Error(
				'soliloquy_save_failed',
				__( 'The slider was saved but could not be retrieved.', 'soliloquy' ),
				[ 'status' => 500 ]
			);
		}

		/**
		 * Fetch a slider that may be edited: not in the trash.
		 *
		 * The wp-admin editor refuses a trashed post. Editing one here would
		 * bypass the untrash routine: update could move it out of the trash while
		 * the payload kept `status => inactive`, so the slider reported "publish"
		 * and rendered nothing. Restoring is wp-admin's job.
		 *
		 * @since 2.9.0
		 *
		 * @param int $id Slider ID.
		 * @return WP_Post|WP_Error
		 */
		private function get_editable_slider_post( int $id ) {
			$post = $this->get_slider_post( $id );

			if ( ! is_wp_error( $post ) && 'trash' === $post->post_status ) {
				return new WP_Error(
					'soliloquy_slider_trashed',
					__( 'This slider is in the trash. Restore it in wp-admin before editing it.', 'soliloquy' ),
					[ 'status' => 409 ]
				);
			}

			return $post;
		}

		/**
		 * Fetch a post and confirm it is a slider.
		 *
		 * @since 2.9.0
		 *
		 * @param int $id Slider ID.
		 * @return WP_Post|WP_Error
		 */
		private function get_slider_post( int $id ) {
			$post = $id > 0 ? get_post( $id ) : null;

			if ( ! $post || self::POST_TYPE !== $post->post_type ) {
				return new WP_Error(
					'soliloquy_not_found',
					__( 'Slider not found.', 'soliloquy' ),
					[ 'status' => 404 ]
				);
			}

			return $post;
		}

		/**
		 * Read the slider payload, repairing any missing structure.
		 *
		 * Returns a WP_Error when the stored value exists but cannot be read, so
		 * no caller can overwrite an unreadable row with empty defaults.
		 *
		 * @since 2.9.0
		 *
		 * @param int $id Slider ID.
		 * @return array|WP_Error
		 */
		private function read_data( int $id ) {
			$slider_data = get_post_meta( $id, self::META_KEY, true );

			// Genuinely absent (a slider created in wp-admin and never saved): only
			// this case may be bootstrapped.
			if ( '' === $slider_data || null === $slider_data || [] === $slider_data ) {
				$post = get_post( $id );

				return $post ? $this->bootstrap_data( $post ) : $this->unreadable();
			}

			// Present but unreadable — get_post_meta() hands back the raw string
			// when the stored value fails to unserialize. Bootstrapping here would
			// then be written straight back over the real row, destroying every
			// slide. Refuse instead, so the row stays recoverable.
			if ( ! is_array( $slider_data ) ) {
				return $this->unreadable();
			}

			$slider_data['id'] = $id;

			foreach ( [ 'slider', 'config' ] as $key ) {
				if ( ! isset( $slider_data[ $key ] ) ) {
					$slider_data[ $key ] = [];
					continue;
				}

				if ( ! is_array( $slider_data[ $key ] ) ) {
					return $this->unreadable();
				}
			}

			return $slider_data;
		}

		/**
		 * Error for a payload that exists but cannot be read.
		 *
		 * @since 2.9.0
		 *
		 * @return WP_Error
		 */
		private function unreadable() {
			return new WP_Error(
				'soliloquy_unreadable_slider_data',
				__( 'This slider’s stored data could not be read, so it was left unchanged. The record may be corrupt and needs repairing before the slider can be edited.', 'soliloquy' ),
				[ 'status' => 500 ]
			);
		}

		/**
		 * Build a fresh slider payload: the default config, titled after the post.
		 *
		 * @since 2.9.0
		 *
		 * @param WP_Post $post Slider post.
		 * @return array
		 */
		private function bootstrap_data( WP_Post $post ) {
			$config = Soliloquy_Common_Lite::get_instance()->get_config_defaults( $post->ID );
			$config = is_array( $config ) ? $config : [];

			$config['type']  = 'default';
			$config['title'] = trim( wp_strip_all_tags( $post->post_title ) );
			$config['slug']  = sanitize_text_field( $post->post_name );

			return [
				'id'     => (int) $post->ID,
				'config' => $config,
				'slider' => [],
			];
		}

		/**
		 * Flush a slider's caches.
		 *
		 * @since 2.9.0
		 *
		 * @param int    $id   Slider ID.
		 * @param string $slug Slider slug, when known.
		 * @return void
		 */
		private function flush_caches( int $id, string $slug = '' ) {
			Soliloquy_Common_Lite::get_instance()->flush_slider_caches( $id, $slug );
		}

		/**
		 * Resolve a usable slug for a slider.
		 *
		 * `post_name` is empty until a post is first published, so fall back to
		 * the config slug and then to the slug WordPress would assign, rather
		 * than returning an empty string for every draft.
		 *
		 * @since 2.9.0
		 *
		 * @param WP_Post $post   Slider post.
		 * @param array   $config Slider config.
		 * @return string
		 */
		private function resolve_slug( WP_Post $post, array $config ) {
			if ( ! empty( $config['slug'] ) && is_string( $config['slug'] ) ) {
				return $config['slug'];
			}

			if ( ! empty( $post->post_name ) ) {
				return $post->post_name;
			}

			return sanitize_title( $post->post_title );
		}

		/**
		 * Format a stored date as RFC 3339 UTC.
		 *
		 * WordPress leaves the `_gmt` columns at '0000-00-00 00:00:00' until a
		 * post is published, so drafts are converted from the local column.
		 *
		 * @since 2.9.0
		 *
		 * @param string $gmt   Value of the `_gmt` column.
		 * @param string $local Value of the local column.
		 * @return string Empty when both columns are unusable.
		 */
		private function to_rfc3339( $gmt, $local ) {
			$zeroed = '0000-00-00 00:00:00';
			$utc    = '';

			if ( ! empty( $gmt ) && $zeroed !== $gmt ) {
				$utc = $gmt;
			} elseif ( ! empty( $local ) && $zeroed !== $local ) {
				$utc = get_gmt_from_date( $local );
			}

			if ( '' === $utc ) {
				return '';
			}

			$timestamp = strtotime( $utc . ' UTC' );

			return false === $timestamp ? '' : gmdate( 'Y-m-d\TH:i:s', $timestamp ) . '+00:00';
		}
	}
}
