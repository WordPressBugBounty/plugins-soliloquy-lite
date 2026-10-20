<?php
/**
 * Slider lifecycle class.
 *
 * Keeps a slider's caches honest when the slider is trashed, restored or deleted.
 *
 * This lives in includes/global/ rather than includes/admin/ on purpose. The admin
 * bundle is only required when is_admin() is true (soliloquy-lite.php), and neither
 * a REST request nor WP-CLI is -- so handlers registered from there never fire for
 * DELETE /wp-json/wp/v2/soliloquy/<id>?force=true, which the slider post type
 * supports ('show_in_rest' => true) and which is the route the block editor uses,
 * nor for `wp post delete <id> --force`. Removing a slider has to clear its caches
 * whatever route the removal arrived by.
 *
 * @since 2.8.5
 *
 * @package Soliloquy
 * @author  SoliloquyWP Team <support@soliloquywp.com>
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Soliloquy_Lifecycle_Lite' ) ) {

	/**
	 * Slider lifecycle class.
	 *
	 * @since 2.8.5
	 */
	class Soliloquy_Lifecycle_Lite {

		/**
		 * Slugs of sliders that are mid-deletion, keyed by post ID.
		 *
		 * Read out of the post meta on before_delete_post and used again once the
		 * post is gone, because by then the meta that carried the slug has gone
		 * with it.
		 *
		 * @since 2.8.5
		 *
		 * @var array
		 */
		private $deleting_slugs = [];

		/**
		 * Register the post lifecycle hooks.
		 *
		 * @since 2.8.5
		 *
		 * @return void
		 */
		public function hooks() {

			// Ensure slider display is correct when trashing/untrashing sliders.
			add_action( 'wp_trash_post', [ $this, 'trash_slider' ] );
			add_action( 'untrash_post', [ $this, 'untrash_slider' ] );

			// A slider deleted outright never passes through the trash path at all, so
			// its caches have to be cleared here as well. Both ends of the delete are
			// needed: the slug is only readable before, and only after is the post
			// actually gone -- see flush_deleted_slider_caches().
			add_action( 'before_delete_post', [ $this, 'delete_slider' ] );
			add_action( 'after_delete_post', [ $this, 'flush_deleted_slider_caches' ] );
		}

		/**
		 * Mark a slider inactive and clear its caches when it is trashed.
		 *
		 * @since 2.8.5
		 *
		 * @param int $id The post ID being trashed.
		 * @return void
		 */
		public function trash_slider( $id ) {

			// Return early if not an Soliloquy slider.
			if ( ! $this->is_slider_post_type( get_post( $id ) ) ) {
				return;
			}

			// Set the slider status to inactive.
			$slider_data = get_post_meta( $id, '_sol_slider_data', true );
			if ( empty( $slider_data ) ) {
				Soliloquy_Common_Lite::get_instance()->flush_slider_caches( $id );
				return;
			}

			$slider_data['status'] = 'inactive';
			update_post_meta( $id, '_sol_slider_data', $slider_data );

			// Flush necessary slider caches to ensure trashed sliders are not showing.
			// This runs after the status write, and with the slug, because
			// _sol_cache_<slug> is a separate day-long transient that
			// [soliloquy slug="..."] reads -- flushing only the ID key would leave a
			// slug embed serving the pre-trash data.
			$this->flush_slider_caches_for( $id, $slider_data );
		}

		/**
		 * Clear a slider's inactive status and caches when it is restored.
		 *
		 * @since 2.8.5
		 *
		 * @param int $id The post ID being untrashed.
		 * @return void
		 */
		public function untrash_slider( $id ) {

			// Return early if not an Soliloquy slider.
			if ( ! $this->is_slider_post_type( get_post( $id ) ) ) {
				return;
			}

			// Clear the inactive status so the slider displays again.
			$slider_data = get_post_meta( $id, '_sol_slider_data', true );
			if ( empty( $slider_data ) ) {
				Soliloquy_Common_Lite::get_instance()->flush_slider_caches( $id );
				return;
			}

			if ( isset( $slider_data['status'] ) ) {
				unset( $slider_data['status'] );
			}

			update_post_meta( $id, '_sol_slider_data', $slider_data );

			// Flush necessary slider caches to ensure untrashed sliders are showing again.
			$this->flush_slider_caches_for( $id, $slider_data );
		}

		/**
		 * Flush a slider's caches before it is deleted for good.
		 *
		 * Trashing marks a slider inactive and the shortcode honours that, but a
		 * delete removes the post and its meta outright and leaves the transients
		 * behind. Nothing downstream can then tell that the slider is gone:
		 * get_slider() and get_slider_by_slug() keep serving whatever the transient
		 * still holds for up to DAY_IN_SECONDS, so a deleted slider carries on
		 * rendering wherever it was embedded.
		 *
		 * This is also the only path that runs when a site has the trash disabled.
		 * Core's wp_trash_post() returns wp_delete_post() before firing the
		 * wp_trash_post action when EMPTY_TRASH_DAYS is 0, so trash_slider() never
		 * runs on those sites and pressing Trash reaches this instead.
		 *
		 * Hooked on before_delete_post rather than only on the later hooks because
		 * the slug has to be read out of the post meta, which is already gone by
		 * the time they fire.
		 *
		 * @since 2.8.5
		 *
		 * @param int $id The post ID being deleted.
		 * @return void
		 */
		public function delete_slider( $id ) {

			// Return early if not an Soliloquy slider.
			if ( ! $this->is_slider_post_type( get_post( $id ) ) ) {
				return;
			}

			$slider_data = get_post_meta( $id, '_sol_slider_data', true );

			$this->deleting_slugs[ $id ] = ! empty( $slider_data['config']['slug'] )
				? $slider_data['config']['slug']
				: '';

			$this->flush_slider_caches_for( $id, $slider_data );
		}

		/**
		 * Flush a deleted slider's caches a second time, once the post is really gone.
		 *
		 * The flush in delete_slider() happens on before_delete_post, while the post
		 * and its meta are still present and still published. Every lookup re-warms
		 * its own transient for DAY_IN_SECONDS on a miss, so a front-end request
		 * landing in the window between that flush and the end of wp_delete_post()
		 * would repopulate the caches with fully renderable data and pin the deleted
		 * slider for a day. The trash path has the `status => inactive` sentinel to
		 * fall back on; a slider deleted straight from publish has nothing.
		 *
		 * @since 2.8.5
		 *
		 * @param int $id The post ID that was deleted.
		 * @return void
		 */
		public function flush_deleted_slider_caches( $id ) {

			// Only sliders recorded by delete_slider() reach this, which also keeps
			// it from doing pointless work on every other post type's deletion.
			if ( ! isset( $this->deleting_slugs[ $id ] ) ) {
				return;
			}

			$slug = $this->deleting_slugs[ $id ];
			unset( $this->deleting_slugs[ $id ] );

			Soliloquy_Common_Lite::get_instance()->flush_slider_caches( $id, $slug );
		}

		/**
		 * Flush every cache that can serve a slider, including the slug keyed transient.
		 *
		 * The flush_slider_caches() helper only clears _sol_cache_<slug> when given a
		 * slug, and the slug lives inside the slider's own config, so every caller
		 * has to dig it out. The trash, untrash and delete paths all need it:
		 * get_slider_by_slug() caches under the slug for a day, so leaving that key
		 * behind lets a [soliloquy slug="..."] embed keep rendering stale data.
		 *
		 * @since 2.8.5
		 *
		 * @param int   $id          The slider post ID.
		 * @param array $slider_data The slider data the caller just read or wrote.
		 * @return void
		 */
		private function flush_slider_caches_for( $id, $slider_data ) {

			$slug = ! empty( $slider_data['config']['slug'] ) ? $slider_data['config']['slug'] : '';

			Soliloquy_Common_Lite::get_instance()->flush_slider_caches( $id, $slug );
		}

		/**
		 * Whether a post is one of the post types Soliloquy keeps sliders under.
		 *
		 * Lite registers 'soliloquy' (includes/global/posttype.php). 'soliloquyv2' is
		 * accepted too because a botched v1 to v2 upgrade can strand sliders under
		 * that type -- it is what maybe_fix_soliloquyv2_cpts() exists to migrate
		 * back, and since that routine self-disables after a single pass, stragglers
		 * remain possible. Treating both as sliders means a stranded one is still
		 * hidden on trash and still flushed on delete.
		 *
		 * @since 2.8.5
		 *
		 * @param WP_Post|null $slider The post being trashed, untrashed or deleted.
		 * @return bool True if the post is a Soliloquy slider.
		 */
		private function is_slider_post_type( $slider ) {

			return $slider instanceof WP_Post
				&& in_array( $slider->post_type, [ 'soliloquy', 'soliloquyv2' ], true );
		}
	}

}
