<?php
/**
 * Shortcode Class.
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
 * Shortcode Settings
 *
 * @since 2.5.0
 */
class Soliloquy_Shortcode_Lite {

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
	 * Holds the slider data.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	public $data;

	/**
	 * Holds slider IDs for init firing checks.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	public $done = [];

	/**
	 * Iterator for sliders on the page.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	public $counter = 1;

	/**
	 * Flag for YouTube videos.
	 *
	 * @since 1.0.0
	 *
	 * @var bool
	 */
	public $youtube = false;

	/**
	 * Flag for Vimeo videos.
	 *
	 * @since 1.0.0
	 *
	 * @var bool
	 */
	public $vimeo = false;

	/**
	 * Flag for Wistia videos.
	 *
	 * @since 1.0.0
	 *
	 * @var bool
	 */
	public $wistia = false;

	/**
	 * Flag for HTML slides.
	 *
	 * @since 1.0.0
	 *
	 * @var bool
	 */
	public $html = false;

	/**
	 * Holds Slider Config Data.
	 *
	 * @var array
	 */
	public $slider_data;
	/**
	 * Primary class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		// Load the base class object.
		$this->base = Soliloquy_Lite::get_instance();

		// Register main slider style.
		wp_register_style( $this->base->plugin_slug . '-style', plugins_url( 'assets/css/soliloquy.css', $this->base->file ), [], $this->base->version );

		// Register main slider script.
		wp_register_script( $this->base->plugin_slug . '-script', plugins_url( 'assets/js/min/soliloquy-min.js', $this->base->file ), [ 'jquery' ], $this->base->version, true );

		// Load hooks and filters.
		add_shortcode( 'soliloquy', [ $this, 'shortcode' ] );
		add_filter( 'widget_text', 'do_shortcode' );
	}

	/**
	 * Creates the shortcode for the plugin.
	 *
	 * @since 1.0.0
	 *
	 * @global object $post The current post object.
	 *
	 * @param array $atts Array of shortcode attributes.
	 * @return string     The slider output.
	 */
	public function shortcode( $atts ) {

		global $post;

		// If no attributes have been passed, the slider should be pulled from the current post.
		// $slider_post_id is the post the data below was read from. Every built-in lookup reads
		// one post's own meta -- a slug is resolved to its post first -- so that ID identifies
		// the slider being rendered, unlike the 'id' stored inside the data, which a duplicated
		// slider shares with its original. Only a custom data source leaves it at 0.
		$slider_id      = false;
		$slider_post_id = 0;
		if ( empty( $atts ) ) {
			$slider_id      = $post->ID;
			$slider_post_id = (int) $post->ID;
			$data           = is_preview() ? $this->base->_get_slider( $slider_id ) : $this->base->get_slider( $slider_id );
		} elseif ( isset( $atts['id'] ) ) {
			$slider_id      = (int) $atts['id'];
			$slider_post_id = (int) $atts['id'];
			$data           = is_preview() ? $this->base->_get_slider( $slider_id ) : $this->base->get_slider( $slider_id );
		} elseif ( isset( $atts['slug'] ) ) {
			$slider_id      = $atts['slug'];
			$slider_post_id = $this->base->get_slider_post_id_by_slug( $slider_id );
			$data           = $this->get_slider_data_by_post_id( $slider_post_id );
		} else {
			// A custom attribute must have been passed. Allow it to be filtered to grab data from a custom source.
			$data = apply_filters( 'soliloquy_custom_slider_data', false, $atts, $post );
		}

		// If there is no data and the attribute used is an ID, try slug as well.
		if ( ! $data && isset( $atts['id'] ) ) {
			$slider_id      = $atts['id'];
			$slider_post_id = $this->base->get_slider_post_id_by_slug( $slider_id );
			$data           = $this->get_slider_data_by_post_id( $slider_post_id );
		}

		// Allow the data to be filtered before it is stored and used to create the slider output.
		$this->slider_data = apply_filters( 'soliloquy_pre_data', $data, $slider_id );

		// A slider in the Trash must never render, whatever the stored or cached data
		// says. The 'inactive' sentinel below is only written at the moment a slider is
		// trashed, so a slider that was already in the Trash before that write existed --
		// or one trashed by anything that bypasses the hooks, such as a direct database
		// edit or an import -- carries no sentinel at all. Reading the post status is the
		// only check that covers those, and it is read fresh rather than from the day-long
		// _sol_cache_* transients the data above may have come out of. See #123.
		//
		// This runs before the visibility check below and whoever is viewing: a user who may
		// read the trashed post still never sees it rendered.
		if ( $this->is_trashed_slider( $slider_post_id ) ) {
			return false;
		}

		// Likewise a slider that is not published -- private, scheduled, draft or pending --
		// renders only to a user who may read it. Its meta and the cached copies of it are
		// unaffected by a status change, so the status is read here, from the post the data
		// was read from. Previews get no exemption: an editor previewing a page passes
		// read_post, a logged-out ?preview=true does not. See #125.
		if ( ! $this->can_view_slider( $slider_post_id ) ) {
			return false;
		}

		// If there is no data to output or the slider is inactive, do nothing.
		if ( ! $this->slider_data || empty( $this->slider_data['slider'] ) || ( isset( $this->slider_data['status'] ) && 'inactive' === $this->slider_data['status'] && ! is_preview() ) ) {
			return false;
		}

		// If the data is to be randomized, do it now.
		if ( $this->get_config( 'random', $this->slider_data ) ) {
			$this->slider_data = $this->shuffle( $this->slider_data );
		}

		// Prepare variables.
		$created_unique_slider_id = $this->slider_data['id'] . '_' . $this->counter;

		$this->slider_data['id'] = $created_unique_slider_id;

		$this->data[ $created_unique_slider_id ] = $this->slider_data;

		$slider = '';
		$i      = 1;

		// If this is a feed view, customize the output and return early.
		if ( is_feed() ) {
			return $this->do_feed_output( $this->slider_data );
		}

		// Load scripts and styles.
		wp_enqueue_style( $this->base->plugin_slug . '-style' );
		wp_enqueue_script( $this->base->plugin_slug . '-script' );

		// Load custom slider themes if necessary.
		if ( 'base' !== $this->get_config( 'slider_theme', $this->slider_data ) ) {
			$this->load_slider_theme( $this->get_config( 'slider_theme', $this->slider_data ) );
		}

		// Load slider init code in the footer.
		add_action( 'wp_footer', [ $this, 'slider_init' ], 1000 );

		// Run a hook before the slider output begins but after scripts and inits have been set.
		do_action( 'soliloquy_before_output', $this->slider_data );

		// Apply a filter before starting the slider HTML.
		$slider = apply_filters( 'soliloquy_output_start', $slider, $this->slider_data );

		// Build out the slider HTML.
		$slider .= '<div aria-live="' . esc_attr( $this->get_config( 'aria_live', $this->slider_data ) ) . '" id="soliloquy-container-' . sanitize_html_class( $this->slider_data['id'] ) . '" class="' . $this->get_slider_classes( $this->slider_data ) . '" style="max-width:' . absint( $this->get_config( 'slider_width', $data ) ) . 'px;max-height:' . absint( $this->get_config( 'slider_height', $this->slider_data ) ) . 'px;' . esc_attr( apply_filters( 'soliloquy_output_container_style', '', $this->slider_data ) ) . '"' . apply_filters( 'soliloquy_output_container_attr', '', $this->slider_data ) . '>'; // Security: esc_attr() on aria_live config, absint() on slider dimensions, esc_attr() on filtered inline style to prevent XSS
		$slider .= '<ul id="soliloquy-' . sanitize_html_class( $this->slider_data['id'] ) . '" class="soliloquy-slider soliloquy-slides soliloquy-wrap soliloquy-clear">';
		$slider  = apply_filters( 'soliloquy_output_before_container', $slider, $this->slider_data );

		foreach ( (array) $this->slider_data['slider'] as $id => $item ) {
			// Skip over images that are pending (ignore if in Preview mode).
			if ( isset( $item['status'] ) && 'pending' === $item['status'] && ! is_preview() ) {
				continue;
			}

			// Allow filtering of individual items.
			$item = apply_filters( 'soliloquy_output_item_data', $item, $id, $this->slider_data, $i );

			$slider  = apply_filters( 'soliloquy_output_before_item', $slider, $id, $item, $this->slider_data, $i );
			$output  = '<li aria-hidden="true" class="' . $this->get_slider_item_classes( $item, $i, $this->slider_data ) . '"' . apply_filters( 'soliloquy_output_item_attr', '', $id, $item, $this->slider_data, $i ) . ' draggable="false" style="list-style:none">';
			$output .= $this->get_slide( $id, $item, $this->slider_data, $i );
			$output .= '</li>';
			$output  = apply_filters( 'soliloquy_output_single_item', $output, $id, $item, $this->slider_data, $i );
			$slider .= $output;
			$slider  = apply_filters( 'soliloquy_output_after_item', $slider, $id, $item, $this->slider_data, $i );

			// Increment the iterator.
			++$i;
		}

		$slider  = apply_filters( 'soliloquy_output_after_container', $slider, $this->slider_data );
		$slider .= '</ul>';
		$slider  = apply_filters( 'soliloquy_output_end', $slider, $this->slider_data );
		$slider .= '</div>';

		// Increment the counter.
		++$this->counter;

		// Add no JS fallback support.
		$no_js   = apply_filters( 'soliloquy_output_no_js', '<noscript><style type="text/css">#soliloquy-container-' . sanitize_html_class( $this->slider_data['id'] ) . '{opacity:1}</style></noscript>', $this->slider_data );
		$slider .= $no_js;

		// Return the slider HTML.
		return apply_filters( 'soliloquy_output', $slider, $this->slider_data );
	}

	/**
	 * Retrieves an individual slide for the slider.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $id The ID for the slide.
	 * @param array      $item    Array of data for the slide.
	 * @param array      $data    Array of data for the slider.
	 * @param int        $i         The number of the slide in the slider.
	 * @return string        HTML markup for the slide.
	 */
	public function get_slide( $id, $item, $data, $i ) {

		$type = ! empty( $item['type'] ) ? $item['type'] : 'image';
		switch ( $type ) {
			case 'image':
				$slide = $this->get_image_slide( $id, $item, $data, $i );
				break;
			case 'video':
				$slide = '';
				break;
			case 'html':
				$slide = '';
				break;
		}

		return apply_filters( 'soliloquy_output_slide', $slide, $id, $item, $data, $i );
	}

	/**
	 * Retrieves an individual image slide for the slider.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $id The ID for the slide.
	 * @param array      $item    Array of data for the slide.
	 * @param array      $data    Array of data for the slider.
	 * @param int        $i         The number of the slide in the slider.
	 * @return string        HTML markup for the image slide.
	 */
	public function get_image_slide( $id, $item, $data, $i ) {

		// Grab our image src and prepare our output.
		$imagesrc = $this->get_image_src( $id, $item, $data );
		$output   = '';

		// If our image is linked, link it.
		if ( ! empty( $item['link'] ) ) {
			$output = apply_filters( 'soliloquy_output_before_link', $output, $id, $item, $data, $i );
			if ( ! empty( $item['linktab'] ) && $item['linktab'] ) {
				$output .= '<a href="' . esc_url( $item['link'] ) . '" class="soliloquy-link" title="' . esc_attr( $item['title'] ) . '" target="_blank"' . apply_filters( 'soliloquy_output_link_attr', '', $id, $item, $data, $i ) . '>';
			} else {
				$output .= '<a href="' . esc_url( $item['link'] ) . '" class="soliloquy-link" title="' . esc_attr( $item['title'] ) . '"' . apply_filters( 'soliloquy_output_link_attr', '', $id, $item, $data, $i ) . '>';
			}
		}

		$output  = apply_filters( 'soliloquy_output_before_image', $output, $id, $item, $data, $i );
		$output .= '<img id="soliloquy-image-' . sanitize_html_class( $id ) . '" class="soliloquy-image soliloquy-image-' . $i . '" src="' . esc_url( $imagesrc ) . '" alt="' . ( isset( $item['alt'] ) ? esc_attr( $item['alt'] ) : '' ) . '"' . apply_filters( 'soliloquy_output_image_attr', '', $id, $item, $data, $i ) . ' />';
		$output  = apply_filters( 'soliloquy_output_after_image', $output, $id, $item, $data, $i );

		// If our image is linked, close the link.
		if ( ! empty( $item['link'] ) ) {
			$output .= '</a>';
		}

		$output = apply_filters( 'soliloquy_output_after_link', $output, $id, $item, $data, $i );

		// If we have a caption, output the caption.
		if ( ! empty( $item['caption'] ) ) {
			$output  = apply_filters( 'soliloquy_output_before_caption', $output, $id, $item, $data, $i );
			$output .= '<div class="soliloquy-caption"><div class="soliloquy-caption-inside">';
			$caption = apply_filters( 'soliloquy_output_caption', $item['caption'], $id, $item, $data, $i );
			$output .= wp_kses_post( $caption ); // Security: escape caption output to prevent XSS from stored or filtered content
			$output .= '</div></div>';
			$output  = apply_filters( 'soliloquy_output_after_caption', $output, $id, $item, $data, $i );
		}

		// Return our inner image slide HTML.
		return apply_filters( 'soliloquy_output_image_slide', $output, $id, $item, $data, $i );
	}

	/**
	 * Outputs the slider init script in the footer.
	 *
	 * @since 1.0.0
	 */
	public function slider_init() {

		foreach ( $this->data as $id => $data ) {
			// Prevent multiple init scripts for the same slider ID.
			if ( in_array( $data['id'], $this->done, true ) ) {
				continue;
			}
			$this->done[] = $data['id'];

			?>
			<script type="text/javascript">
			<?php
			ob_start();
			do_action( 'soliloquy_api_start_global', $data );
			?>
				if ( typeof soliloquy_slider === 'undefined' || false === soliloquy_slider ) {
					soliloquy_slider = {};
				}

				<?php if ( ! empty( $data['youtube'] ) ) : ?>
				if ( typeof soliloquy_youtube === 'undefined' || false === soliloquy_youtube ) {
					soliloquy_youtube = {};
				}
				<?php endif; ?>

				<?php if ( ! empty( $data['vimeo'] ) ) : ?>
				if ( typeof soliloquy_vimeo === 'undefined' || false === soliloquy_vimeo ) {
					soliloquy_vimeo = {};
				}
				<?php endif; ?>

				<?php if ( ! empty( $data['wistia'] ) ) : ?>
				if ( typeof soliloquy_wistia === 'undefined' || false === soliloquy_wistia ) {
					soliloquy_wistia = {};
				}
				<?php endif; ?>

				jQuery('#soliloquy-container-<?php echo sanitize_html_class( $data['id'] ); ?>').css('height', Math.round(jQuery('#soliloquy-container-<?php echo sanitize_html_class( $data['id'] ); ?>').width()/(<?php echo esc_js( $this->get_config( 'slider_width', $data ) ); ?>/<?php echo esc_js( $this->get_config( 'slider_height', $data ) ); ?>)));

				jQuery(window).load(function(){
					var $ = jQuery;
					<?php do_action( 'soliloquy_api_start', $data ); ?>

					var soliloquy_container_<?php echo sanitize_html_class( $data['id'] ); ?> = $('#soliloquy-container-<?php echo sanitize_html_class( $data['id'] ); ?>'),
						soliloquy_<?php echo sanitize_html_class( $data['id'] ); ?> = $('#soliloquy-<?php echo sanitize_html_class( $data['id'] ); ?>');

					soliloquy_slider['<?php echo sanitize_html_class( $data['id'] ); ?>'] = soliloquy_<?php echo sanitize_html_class( $data['id'] ); ?>.soliloquy({
						<?php do_action( 'soliloquy_api_config_start', $data ); ?>
						slideSelector: '.soliloquy-item',
						speed: <?php echo esc_js( $this->get_config( 'speed', $data ) ); ?>,
						pause: <?php echo esc_js( $this->get_config( 'duration', $data ) ); ?>,
						auto: 1,
						useCSS: 0,
						keyboard: true,
						adaptiveHeight: 1,
						adaptiveHeightSpeed: <?php echo intval( apply_filters( 'soliloquy_adaptive_height_speed', 400, $data ) ); ?>,
						infiniteLoop: 1,
						mode: '<?php echo esc_js( $this->get_config( 'transition', $data ) ); ?>',
						pager: 1,
						controls: 1,
						nextText: '<?php echo esc_js( apply_filters( 'soliloquy_next_text', '', $data ) ); ?>',
						prevText: '<?php echo esc_js( apply_filters( 'soliloquy_prev_text', '', $data ) ); ?>',
						startText: '<?php echo esc_js( apply_filters( 'soliloquy_start_text', '', $data ) ); ?>',
						stopText: '<?php echo esc_js( apply_filters( 'soliloquy_stop_text', '', $data ) ); ?>',
						<?php do_action( 'soliloquy_api_config_callback', $data ); ?>
						onSliderLoad: function(currentIndex){
							soliloquy_container_<?php echo sanitize_html_class( $data['id'] ); ?>.find('.soliloquy-active-slide').removeClass('soliloquy-active-slide').attr('aria-hidden','true');
							soliloquy_container_<?php echo sanitize_html_class( $data['id'] ); ?>.css({'height':'auto','background-image':'none'});
							if ( soliloquy_container_<?php echo sanitize_html_class( $data['id'] ); ?>.find('.soliloquy-slider li').length > 1 ) {
								soliloquy_container_<?php echo sanitize_html_class( $data['id'] ); ?>.find('.soliloquy-controls').fadeTo(300, 1);
							}
							soliloquy_<?php echo sanitize_html_class( $data['id'] ); ?>.find('.soliloquy-item:not(.soliloquy-clone):eq(' + currentIndex + ')').addClass('soliloquy-active-slide').attr('aria-hidden','false');
							// Purge all cloned items of IDs to avoid duplicate ID issues.
							soliloquy_container_<?php echo sanitize_html_class( $data['id'] ); ?>.find('.soliloquy-clone').find('*').removeAttr('id');

							soliloquy_container_<?php echo sanitize_html_class( $data['id'] ); ?>.find('.soliloquy-controls-direction').attr('aria-label','carousel buttons').attr('aria-controls', '<?php echo 'soliloquy-container-' . sanitize_html_class( $data['id'] ); ?>');
							soliloquy_container_<?php echo sanitize_html_class( $data['id'] ); ?>.find('.soliloquy-controls-direction a.soliloquy-prev').attr('aria-label','previous');
							soliloquy_container_<?php echo sanitize_html_class( $data['id'] ); ?>.find('.soliloquy-controls-direction a.soliloquy-next').attr('aria-label','next');

						<?php do_action( 'soliloquy_api_on_load', $data ); ?>
						},
						onSlideBefore: function(element, oldIndex, newIndex){
							soliloquy_container_<?php echo sanitize_html_class( $data['id'] ); ?>.find('.soliloquy-active-slide').removeClass('soliloquy-active-slide').attr('aria-hidden','true');
							$(element).addClass('soliloquy-active-slide').attr('aria-hidden','false');
							<?php do_action( 'soliloquy_api_before_transition', $data ); ?>
						},
						onSlideAfter: function(element, oldIndex, newIndex){
							<?php do_action( 'soliloquy_api_after_transition', $data ); ?>
						},
						<?php do_action( 'soliloquy_api_config_end', $data ); ?>
					});

					<?php do_action( 'soliloquy_api_slider', $data ); ?>

					<?php do_action( 'soliloquy_api_end', $data ); ?>
				});

			// Minify before outputting to improve page load time.
			<?php
			do_action( 'soliloquy_api_end_global', $data );
			// User inputs are sanitized aboved.
			// @codingStandardsIgnoreStart
			echo $this->minify( ob_get_clean() );
			// @codingStandardsIgnoreEnd
			?>
			</script>
			<?php
		}
	}

	/**
	 * Loads a custom slider display theme.
	 *
	 * @since 1.0.0
	 *
	 * @param string $theme The custom theme slug to load.
	 */
	public function load_slider_theme( $theme ) {

		// Loop through the available themes and enqueue the one called.
		foreach ( Soliloquy_Common_Lite::get_instance()->get_slider_themes() as $array => $data ) {
			if ( $theme !== $data['value'] ) {
				continue;
			}

			wp_enqueue_style( $this->base->plugin_slug . $theme . '-theme', plugins_url( 'themes/' . $theme . '/style.css', $data['file'] ), [ $this->base->plugin_slug . '-style' ], $this->base->version );
			break;
		}
	}

	/**
	 * Helper method for adding custom slider classes.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data The slider data to use for retrieval.
	 * @return string     String of space separated slider classes.
	 */
	public function get_slider_classes( $data ) {

		// Set default class.
		$classes   = [];
		$classes[] = 'soliloquy-container';

		// Add custom class based on the transition.
		$classes[] = 'soliloquy-transition-' . $this->get_config( 'transition', $data );

		// If we have custom classes defined for this slider, output them now.
		foreach ( (array) $this->get_config( 'classes', $data ) as $class ) {
			$classes[] = $class;
		}

		// Allow filtering of classes and then return what's left.
		$classes = apply_filters( 'soliloquy_output_classes', $classes, $data );

		// Add custom class based on the theme.
		$classes[] = 'soliloquy-theme-' . $this->get_config( 'slider_theme', $data );

		// If the slider has RTL support, add a class for it.
		if ( $this->get_config( 'rtl', $data ) ) {
			$classes[] = 'soliloquy-rtl';
		}

		return trim( implode( ' ', array_map( 'trim', array_map( 'sanitize_html_class', array_unique( $classes ) ) ) ) );
	}

	/**
	 * Helper method for adding custom slider classes.
	 *
	 * @since 1.0.4
	 *
	 * @param array $item Array of item data.
	 * @param int   $i      The current position in the slider.
	 * @param array $data The slider data to use for retrieval.
	 * @return string     String of space separated slider item classes.
	 */
	public function get_slider_item_classes( $item, $i, $data ) {

		// Set default class.
		$classes   = [];
		$classes[] = 'soliloquy-item';
		$classes[] = 'soliloquy-item-' . $i;

		// Set the type of slide as a class.
		$classes[] = ! empty( $item['type'] ) ? 'soliloquy-' . $item['type'] . '-slide' : 'soliloquy-image-slide';

		// Allow filtering of classes and then return what's left.
		$classes = apply_filters( 'soliloquy_output_item_classes', $classes, $item, $i, $data );
		return trim( implode( ' ', array_map( 'trim', array_map( 'sanitize_html_class', array_unique( $classes ) ) ) ) );
	}

	/**
	 * Helper method to retrieve the proper image src attribute based on slider settings.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $id      The image attachment ID to use.
	 * @param array  $item  Slider item data.
	 * @param array  $data  The slider data to use for retrieval.
	 * @param string $type The type of cropped image to retrieve.
	 * @return string      The proper image src attribute for the image.
	 */
	public function get_image_src( $id, $item, $data, $type = 'slider' ) {

		// Get the full image src. If it does not return the data we need, return the image link instead.
		$src   = wp_get_attachment_image_src( $id, 'full' );
		$image = ! empty( $src[0] ) ? $src[0] : false;

		// Allow image to be filtered to use a different thumbnail than the main image.
		if ( 'thumbnails' === $type ) {
			$image = apply_filters( 'soliloquy_cropped_image', $image, $id, $item, $data, $data['id'] );
		}

		// If no image, return with the base link.
		if ( ! $image ) {
			$image = ! empty( $item['src'] ) ? $item['src'] : false;
			if ( ! $image ) {
				return apply_filters( 'soliloquy_no_image_src', isset( $item['link'] ) ? $item['link'] : '', $id, $item, $data );
			}
		}

		// Generate the cropped image if necessary.
		$type = 'thumbnails' !== $type ? apply_filters( 'soliloquy_crop_type', $type, $id, $item, $data ) : $type;
		if ( empty( $type ) ) {
			return apply_filters( 'soliloquy_no_image_type', isset( $item['link'] ) ? $item['link'] : '', $id, $item, $data );
		}

		// If the setting exists, go onward with cropping.
		if ( isset( $data['config']['slider'] ) && $data['config']['slider'] ) {
			if ( isset( $data['config'][ $type ] ) && $data['config'][ $type ] ) {
				$common        = Soliloquy_Common_Lite::get_instance();
				$args          = apply_filters(
					'soliloquy_crop_image_args',
					[
						'position' => 'c',
						'width'    => $this->get_config( $type . '_width', $data ),
						'height'   => $this->get_config( $type . '_height', $data ),
						'quality'  => 82,
						'retina'   => false,
					]
				);
				$cropped_image = $common->resize_image( $image, $args['width'], $args['height'], true, $args['position'], $args['quality'], $args['retina'] );

				// If there is an error, possibly output error message and return the default image src.
				if ( is_wp_error( $cropped_image ) ) {
					// If WP_DEBUG is enabled, and we're logged in, output an error to the user.

					// Return the non-cropped image as a fallback.
					return apply_filters( 'soliloquy_image_src', $image, $id, $item, $data );
				} else {
					return apply_filters( 'soliloquy_image_src', $cropped_image, $id, $item, $data );
				}
			} else {
				return apply_filters( 'soliloquy_image_src', $image, $id, $item, $data );
			}
		} else {
			return apply_filters( 'soliloquy_image_src', $image, $id, $item, $data );
		}
	}

	/**
	 * Helper method for positioning the slider.
	 *
	 * @since 1.0.0
	 *
	 * @param string $style  String of slider container styles.
	 * @param array  $data    Array of slider data.
	 * @return string $style Amended string of slider container styles.
	 */
	public function position_slider( $style, $data ) {

		$gutter   = $this->get_config( 'gutter', $data );
		$position = '';
		switch ( $this->get_config( 'position', $data ) ) {
			case 'center':
				$position .= 'margin:0 auto ' . $gutter . 'px;';
				break;
			case 'left':
				$position .= 'float:left;margin:0 ' . $gutter . 'px ' . $gutter . 'px 0;';
				break;
			case 'right':
				$position .= 'float:right;margin:0 0 ' . $gutter . 'px ' . $gutter . 'px;';
				break;
		}

		$position = apply_filters( 'soliloquy_position_style', $position, $data );
		return $style . $position;
	}

	/**
	 * Helper method for retrieving the mobile image src attribute.
	 *
	 * @since 1.0.0
	 *
	 * @param string $attr  String of image attributes.
	 * @param int    $id       The ID of the image attachment.
	 * @param array  $item   The array of date for the image.
	 * @param array  $data   Array of slider data.
	 * @return string $attr Amended string of image attributes.
	 */
	public function mobile_image( $attr, $id, $item, $data ) {

		$mobile_image = $this->get_image_src( $id, $item, $data, 'mobile' );
		return $attr . ' data-soliloquy-src-mobile="' . esc_url( $mobile_image ) . '"';
	}

	/**
	 * Shuffles and randomizes images in a slider.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data  The slider data to use for retrieval.
	 * @return array $data Shuffled slider data.
	 */
	public function shuffle( $data ) {

		// Return early there are no items to shuffle.
		if ( ! is_array( $data['slider'] ) ) {
			return $data;
		}

		// Prepare variables.
		$random = [];
		$keys   = array_keys( $data['slider'] );

		// Shuffle the keys and loop through them to create a new, randomized array of images.
		shuffle( $keys );
		foreach ( $keys as $key ) {
			$random[ $key ] = $data['slider'][ $key ];
		}

		// Return the randomized image array.
		$data['slider'] = $random;
		return $data;
	}

	/**
	 * Helper method for retrieving config values.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key The config key to retrieve.
	 * @param array  $data The slider data to use for retrieval.
	 * @return string     Key value on success, default if not set.
	 */
	public function get_config( $key, $data ) {

		$instance = Soliloquy_Common_Lite::get_instance();
		return isset( $data['config'][ $key ] ) ? $data['config'][ $key ] : $instance->get_config_default( $key );
	}

	/**
	 * Helper method to minify a string of data.
	 *
	 * @since 1.0.4
	 *
	 * @param string $string_data  String of data to minify.
	 * @return string $string_data Minified string of data.
	 */
	public function minify( $string_data ) {

		$clean = preg_replace( '/((?:\/\*(?:[^*]|(?:\*+[^*\/]))*\*+\/)|(?:\/\/.*))/', '', $string_data );
		$clean = str_replace( [ "\r\n", "\r", "\t", "\n", '  ', '    ', '     ' ], '', $clean );
		return apply_filters( 'soliloquy_minified_string', $clean, $string_data );
	}

	/**
	 * Outputs only the first image of the slider inside a regular <div> tag
	 * to avoid styling issues with feeds.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data     Array of slider data.
	 * @return string $slider Custom slider output for feeds.
	 */
	public function do_feed_output( $data ) {

		$slider = '<div class="soliloquy-feed-output">';
		foreach ( $data['slider'] as $id => $item ) {
			// Skip over images that are pending (ignore if in Preview mode).
			if ( isset( $item['status'] ) && 'pending' === $item['status'] && ! is_preview() ) {
				continue;
			}

			$imagesrc = $this->get_image_src( $id, $item, $data );
			$slider  .= '<img class="soliloquy-feed-image" src="' . esc_url( $imagesrc ) . '" title="' . esc_attr( $item['title'] ) . '" alt="' . esc_attr( $item['alt'] ) . '" />';
			break;
		}
		$slider .= '</div>';

		return apply_filters( 'soliloquy_feed_output', $slider, $data );
	}

	/**
	 * Returns the query args to be passed to YouTube videos.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Array of slider data.
	 */
	public function get_youtube_args( $data ) {

		return apply_filters(
			'soliloquy_youtube_args',
			[
				'enablejsapi'    => 1,
				'version'        => 3,
				'wmode'          => 'transparent',
				'rel'            => 0,
				'showinfo'       => 0,
				'modestbranding' => 1,
				'autoplay'       => 1,
				'origin'         => get_home_url(),
			],
			$data
		);
	}

	/**
	 * Returns the query args to be passed to Vimeo videos.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Array of slider data.
	 */
	public function get_vimeo_args( $data ) {

		return apply_filters(
			'soliloquy_vimeo_args',
			[
				'api'        => 1,
				'wmode'      => 'transparent',
				'byline'     => 0,
				'title'      => 0,
				'portrait'   => 0,
				'autoplay'   => 1,
				'badge'      => 0,
				'fullscreen' => 1,
			],
			$data
		);
	}

	/**
	 * Returns the query args to be passed to Wistia videos.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Array of slider data.
	 */
	public function get_wistia_args( $data ) {

		return apply_filters(
			'soliloquy_wistia_args',
			[
				'version'               => 'v1',
				'wmode'                 => 'opaque',
				'volumeControl'         => 1,
				'controlsVisibleOnLoad' => 1,
				'videoFoam'             => 1,
			],
			$data
		);
	}

	/**
	 * Flag for detecting a mobile device server-side.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if on a mobile device, false otherwise.
	 */
	public function is_mobile() {

		// Return wp_is_mobile for the final check.
		return wp_is_mobile();
	}

	/**
	 * Returns the singleton instance of the class.
	 *
	 * @since 1.0.0
	 *
	 * @return object The Soliloquy_Shortcode_Lite object.
	 */
	public static function get_instance() {

		if ( ! isset( self::$instance ) && ! ( self::$instance instanceof Soliloquy_Shortcode_Lite ) ) {
			self::$instance = new Soliloquy_Shortcode_Lite();
		}

		return self::$instance;
	}

	/**
	 * Loads a slider's data by the ID of the post it lives on, as the ID route does.
	 *
	 * Used by the slug route once the slug has been resolved to a post, so the data
	 * rendered is the data of the post can_view_slider() checks, never a cached list
	 * entry that may belong to another post or predate a status change.
	 *
	 * @since 2.8.7
	 *
	 * @param int $slider_post_id The slider's post ID, or 0 when none was found.
	 * @return array|false        The slider data, or false.
	 */
	private function get_slider_data_by_post_id( $slider_post_id ) {

		if ( $slider_post_id < 1 ) {
			return false;
		}

		return is_preview() ? $this->base->_get_slider( $slider_post_id ) : $this->base->get_slider( $slider_post_id );
	}

	/**
	 * Whether the current visitor may see the slider being rendered.
	 *
	 * A slider whose status is public ('publish') renders for everyone, and no capability
	 * is consulted for it: Soliloquy_Posttype_Lite::map_meta_cap() denies authors
	 * read_post on sliders they do not own, so asking would hide published sliders from
	 * them. Any other status needs read_post on the slider's post, which that mapping
	 * resolves to read_private_posts for a private slider and to the edit capabilities
	 * for a scheduled, draft or pending one -- so a logged-out visitor never passes. The
	 * slider's own author is let through whatever the status, as core does for any post.
	 *
	 * An ID of 0 means no post is behind the data: only a custom data source, or data a
	 * soliloquy_pre_data callback supplied where no slider was found, gets here with it,
	 * because every built-in lookup that finds data records the post it read. A positive
	 * ID whose post no longer exists is not let through: its data can only be coming out
	 * of a stale _sol_cache_* transient.
	 *
	 * @since 2.8.7
	 *
	 * @param int $slider_post_id The post the slider data was read from, or 0 when the
	 *                            lookup could not identify one.
	 * @return bool True if the slider may be rendered for the current visitor.
	 */
	private function can_view_slider( $slider_post_id ) {

		$post_id = (int) $slider_post_id;

		// Never ask get_post_status( 0 ): it reads the global post, which is the page the
		// slider is embedded in, not the slider.
		if ( $post_id < 1 ) {
			return true;
		}

		$status = get_post_status( $post_id );

		if ( false === $status ) {
			return false;
		}

		$status_object = get_post_status_object( $status );

		if ( $status_object && $status_object->public ) {
			return true;
		}

		// Soliloquy_Posttype_Lite::map_meta_cap() asks an author for read_private_posts even
		// on their own private slider, which the author role lacks.
		$user_id = get_current_user_id();

		if ( $user_id && (int) get_post_field( 'post_author', $post_id ) === $user_id ) {
			return true;
		}

		// A status nothing has registered (left behind by a deactivated workflow plugin, say)
		// makes core's map_meta_cap() raise _doing_it_wrong() for read_post and fall back to
		// edit_others_posts. Ask for that directly instead.
		if ( ! $status_object ) {
			return current_user_can( 'edit_others_posts' );
		}

		return current_user_can( 'read_post', $post_id );
	}

	/**
	 * Whether the slider being rendered is in the Trash.
	 *
	 * Takes the post ID the caller resolved the slider data from and reads that post's
	 * status. Only 'trash' is checked here; the other non-public statuses are handled
	 * by can_view_slider(), which unlike this check lets a user who may read the post
	 * see it.
	 *
	 * The ID the embed resolved to is used in preference to the one inside the slider
	 * data, which is not reliably the slider's own post ID: a slider duplicated by a
	 * duplication plugin or by WPML keeps the original's stored ID until it is re-saved
	 * in the admin, which is why includes/admin/metaboxes.php force-corrects it on save.
	 * Trusting the stored ID would make this gate read the wrong post -- letting a
	 * trashed duplicate through when the original is published, and hiding a published
	 * slider when the original is in the Trash. The stored ID is still the only thing
	 * available for a custom data source, so it remains the fallback, guarded on being
	 * numeric because a custom source may use a non-numeric one.
	 *
	 * @since 2.8.6
	 *
	 * @param int $slider_post_id The post the slider data was read from, or 0 when the
	 *                            lookup could not identify one.
	 * @return bool True if the slider's post is in the Trash.
	 */
	private function is_trashed_slider( $slider_post_id ) {

		$post_id = (int) $slider_post_id;

		if ( $post_id < 1 && ! empty( $this->slider_data['id'] ) && is_numeric( $this->slider_data['id'] ) ) {
			$post_id = (int) $this->slider_data['id'];
		}

		return $post_id > 0 && 'trash' === get_post_status( $post_id );
	}
}

// Load the shortcode class.
$soliloquy_shortcode_lite = Soliloquy_Shortcode_Lite::get_instance();
