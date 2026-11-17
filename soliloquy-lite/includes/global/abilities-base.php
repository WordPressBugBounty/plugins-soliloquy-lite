<?php
/**
 * Soliloquy Abilities — shared base.
 *
 * Registers Soliloquy slider operations as WordPress Abilities so they are
 * discoverable by AI agents and MCP clients through the Abilities API.
 *
 * Holds every definition shared by Lite and Pro. Lite and Pro each provide a
 * subclass: Lite with a hardcoded list, Pro with a filterable one. This class is
 * a registration surface only — slider reads and writes go through the slider
 * service the subclass returns from sliders().
 *
 * The Lite and Pro copies of this file are kept identical apart from their
 * version tags, and the Lite/Pro parity test fails the build when they drift.
 * Lite-only or Pro-only behaviour belongs in the subclass or the slider
 * service, never here.
 *
 * Permissions are WordPress core capabilities. The soliloquy post type uses
 * capability_type 'post' with map_meta_cap, so per-slider checks are the
 * edit_post / delete_post meta caps resolved by the plugin's own map_meta_cap
 * filter (which, for example, keeps Authors off sliders they do not own).
 *
 * Requires WordPress 6.9+. Hooks are skipped when wp_register_ability() is
 * absent — a no-op on older WordPress installations.
 *
 * @since 2.9.0
 *
 * @package Soliloquy
 * @author  SoliloquyWP Team <support@soliloquywp.com>
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Soliloquy_Abilities_Base' ) ) {

	/**
	 * Soliloquy_Abilities_Base — shared Soliloquy Abilities API registration surface.
	 *
	 * @since 2.9.0
	 */
	abstract class Soliloquy_Abilities_Base {

		/**
		 * Ability namespace, also used as the category slug.
		 *
		 * Lite and Pro register the same ability names on purpose, so an MCP
		 * client or agent configured against a Lite site keeps working after an
		 * upgrade to Pro. Lite stands down when Pro is active, so the names never
		 * collide.
		 *
		 * @since 2.9.0
		 *
		 * @var string
		 */
		const ABILITY_NAMESPACE = 'soliloquy';

		/**
		 * Option holding the write opt-in. Shared by Lite and Pro.
		 *
		 * @since 2.9.0
		 *
		 * @var string
		 */
		const WRITE_SETTING_OPTION = 'soliloquy_abilities_write_enabled';

		/**
		 * Singleton instances, keyed by concrete class name.
		 *
		 * @since 2.9.0
		 *
		 * @var array<string, static>
		 */
		private static $instances = [];

		/**
		 * Ability names wp_register_ability() rejected on this request.
		 *
		 * @since 2.9.0
		 *
		 * @var string[]
		 */
		protected $failed_registrations = [];

		/**
		 * Whether wp_register_ability_category() accepted the category on this request.
		 *
		 * Null until register_category() has run.
		 *
		 * @since 2.9.0
		 *
		 * @var bool|null
		 */
		protected $category_registered = null;

		/**
		 * Return the singleton for the concrete class.
		 *
		 * @since 2.9.0
		 *
		 * @return static
		 */
		public static function instance() {
			$class = static::class;

			if ( ! isset( self::$instances[ $class ] ) ) {
				self::$instances[ $class ] = new static();
			}

			return self::$instances[ $class ];
		}

		/**
		 * Return the slider service every execute callback delegates to.
		 *
		 * The service is the single writer of slider data, shared with the
		 * wp-admin save path. It differs between Lite and Pro (their payloads
		 * and helper classes differ), so each subclass supplies its own.
		 *
		 * @since 2.9.0
		 *
		 * @return object
		 */
		abstract protected function sliders();

		/**
		 * Register WordPress hooks. No-op on WordPress < 6.9.
		 *
		 * @since 2.9.0
		 *
		 * @return void
		 */
		public function init() {
			if ( ! $this->is_abilities_api_available() ) {
				return;
			}

			add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
			add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
		}

		/**
		 * Whether the WordPress Abilities API is present.
		 *
		 * @since 2.9.0
		 *
		 * @return bool
		 */
		public function is_abilities_api_available(): bool {
			return function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' );
		}

		/**
		 * Register the soliloquy ability category.
		 *
		 * @since 2.9.0
		 *
		 * @return void
		 */
		public function register_category() {
			$this->category_registered = null !== wp_register_ability_category(
				self::ABILITY_NAMESPACE,
				[
					'label'       => __( 'Soliloquy', 'soliloquy' ),
					'description' => __( 'Manage Soliloquy sliders — list, retrieve, create, update and delete sliders, and add slides.', 'soliloquy' ),
				]
			);
		}

		/**
		 * Register all abilities.
		 *
		 * @since 2.9.0
		 *
		 * @return void
		 */
		public function register_abilities() {
			$this->failed_registrations = [];

			foreach ( $this->get_ability_definitions() as $name => $args ) {
				// wp_register_ability() returns null on a rejected registration —
				// duplicate name, malformed schema — after a _doing_it_wrong() that
				// is invisible with WP_DEBUG off. Recording it lets the settings
				// screen name the failure instead of looking like a success.
				if ( null === wp_register_ability( $name, $args ) ) {
					$this->failed_registrations[] = $name;
				}
			}
		}

		/**
		 * Ability names that are not in the registry.
		 *
		 * The registry fires wp_abilities_api_init lazily, the first time anything
		 * asks it for an ability, so a screen that never asks would see no
		 * failures at all. Asking here primes it, which makes the answer true on
		 * any request after init rather than only on the REST or MCP request that
		 * registered.
		 *
		 * @since 2.9.0
		 *
		 * @return string[]
		 */
		public function get_failed_registrations(): array {
			if ( ! $this->is_abilities_api_available() || ! did_action( 'init' ) || ! function_exists( 'wp_has_ability' ) ) {
				return $this->failed_registrations;
			}

			$failed = [];

			foreach ( array_keys( $this->get_ability_definitions() ) as $name ) {
				if ( ! wp_has_ability( $name ) ) {
					$failed[] = (string) $name;
				}
			}

			return $failed;
		}

		/**
		 * Whether the soliloquy ability category is registered.
		 *
		 * Core rejects an ability whose category is missing, so a rejected
		 * category takes every ability down. Reporting it separately names the
		 * root cause.
		 *
		 * @since 2.9.0
		 *
		 * @return bool
		 */
		public function is_category_registered(): bool {
			if ( ! $this->is_abilities_api_available() || ! did_action( 'init' ) || ! function_exists( 'wp_has_ability_category' ) ) {
				return false !== $this->category_registered;
			}

			// wp_has_ability() primes both registries, the category one first.
			wp_has_ability( self::ABILITY_NAMESPACE . '/list-sliders' );

			return wp_has_ability_category( self::ABILITY_NAMESPACE );
		}

		/**
		 * Return the ability definitions to register.
		 *
		 * Lite returns the common set unchanged; Pro runs it through a filter.
		 *
		 * @since 2.9.0
		 *
		 * @return array<string, array>
		 */
		public function get_ability_definitions(): array {
			return $this->get_common_ability_definitions();
		}

		/**
		 * Definitions shared by Lite and Pro.
		 *
		 * @since 2.9.0
		 *
		 * @return array<string, array>
		 */
		protected function get_common_ability_definitions(): array {
			$ns = self::ABILITY_NAMESPACE;

			return [
				$ns . '/list-sliders'           => $this->definition_list_sliders(),
				$ns . '/get-slider'             => $this->definition_get_slider(),
				$ns . '/describe-slider-schema' => $this->definition_describe_slider_schema(),
				$ns . '/create-slider'          => $this->definition_create_slider(),
				$ns . '/update-slider'          => $this->definition_update_slider(),
				$ns . '/delete-slider'          => $this->definition_delete_slider(),
				$ns . '/add-slides'             => $this->definition_add_slides(),
			];
		}

		// -------------------------------------------------------------------------
		// Write gate
		// -------------------------------------------------------------------------

		/**
		 * Whether the write abilities are enabled.
		 *
		 * Reads the opt-in option as the default, then runs it through the
		 * `soliloquy_abilities_allow_write` filter so it can still be overridden
		 * programmatically in either direction.
		 *
		 * Default is false: an agent cannot create, update, delete or add slides
		 * to a slider until a site administrator opts in.
		 *
		 * @since 2.9.0
		 *
		 * @return bool
		 */
		public function write_enabled(): bool {
			$default = (bool) get_option( self::WRITE_SETTING_OPTION, false );

			/**
			 * Filters whether the Soliloquy write abilities are enabled.
			 *
			 * @since 2.9.0
			 *
			 * @param bool   $enabled Whether writes are enabled. Defaults to the
			 *                        `soliloquy_abilities_write_enabled` option
			 *                        (false when absent).
			 * @param static $handler The abilities handler.
			 */
			return (bool) apply_filters( 'soliloquy_abilities_allow_write', $default, $this );
		}

		/**
		 * Check the write gate.
		 *
		 * Neither Lite nor Pro adds a license check: the abilities are not
		 * license-gated.
		 *
		 * @since 2.9.0
		 *
		 * @return true|\WP_Error True when writes are allowed.
		 */
		protected function check_write_gate() {
			if ( ! $this->write_enabled() ) {
				return new \WP_Error(
					'soliloquy_abilities_writes_disabled',
					__( 'Soliloquy slider write abilities are disabled. Enable them under Soliloquy → Settings → AI & MCP.', 'soliloquy' ),
					[ 'status' => 403 ]
				);
			}

			return true;
		}

		/**
		 * Check a capability and translate a failure into a WP_Error.
		 *
		 * A bare `false` tells a calling agent nothing, so it retries. A WP_Error
		 * carries a code and a message the agent can act on. The REST run
		 * controller replaces the status with 401 for a signed-out caller and
		 * 403 otherwise.
		 *
		 * @since 2.9.0
		 *
		 * @param string $cap Capability, or meta capability when $id is given.
		 * @param int    $id  Optional slider ID for a meta capability check.
		 * @return true|\WP_Error
		 */
		protected function check_cap( string $cap, int $id = 0 ) {
			$allowed = $id > 0 ? current_user_can( $cap, $id ) : current_user_can( $cap );

			if ( $allowed ) {
				return true;
			}

			return new \WP_Error(
				'soliloquy_abilities_forbidden',
				__( 'You do not have permission to perform this action on Soliloquy sliders.', 'soliloquy' ),
				[ 'status' => 403 ]
			);
		}

		/**
		 * Require a slider ID on abilities whose capability is per-slider.
		 *
		 * @since 2.9.0
		 *
		 * @param mixed  $input Ability input.
		 * @param string $key   Input key holding the slider ID.
		 * @return int|\WP_Error Slider ID, or WP_Error when absent.
		 */
		protected function require_slider_id( $input, string $key = 'id' ) {
			$input = $this->normalize_input( $input );
			$id    = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? (int) $input[ $key ] : 0;

			if ( $id <= 0 ) {
				return new \WP_Error(
					'soliloquy_abilities_missing_id',
					/* translators: %s: input property name. */
					sprintf( __( 'A valid slider ID is required in "%s".', 'soliloquy' ), $key ),
					[ 'status' => 400 ]
				);
			}

			return $id;
		}

		// -------------------------------------------------------------------------
		// Ability definitions
		// -------------------------------------------------------------------------

		/**
		 * Definition: list-sliders.
		 *
		 * @since 2.9.0
		 *
		 * @return array
		 */
		protected function definition_list_sliders(): array {
			return [
				'label'               => __( 'List Sliders', 'soliloquy' ),
				'description'         => __( 'Returns a paginated list of Soliloquy sliders with metadata.', 'soliloquy' ),
				'category'            => self::ABILITY_NAMESPACE,
				'execute_callback'    => [ $this, 'execute_list_sliders' ],
				'permission_callback' => [ $this, 'can_list_sliders' ],
				'input_schema'        => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'default'              => [],
					'properties'           => [
						'per_page' => [
							'type'        => 'integer',
							'description' => __( 'Sliders per page (1–100).', 'soliloquy' ),
							'default'     => 20,
							'minimum'     => 1,
							'maximum'     => 100,
						],
						'page'     => [
							'type'        => 'integer',
							'description' => __( 'Page number.', 'soliloquy' ),
							'default'     => 1,
							'minimum'     => 1,
						],
						'status'   => [
							'type'        => 'string',
							'description' => __( 'Filter by publish status.', 'soliloquy' ),
							'enum'        => [ 'publish', 'draft', 'any' ],
							'default'     => 'any',
						],
						'search'   => [
							'type'        => 'string',
							'description' => __( 'Search sliders by title.', 'soliloquy' ),
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'sliders'     => [
							'type'  => 'array',
							'items' => $this->slider_schema(),
						],
						'total'       => [
							'type'        => 'integer',
							'description' => __( 'Total sliders matching the query.', 'soliloquy' ),
						],
						'page'        => [ 'type' => 'integer' ],
						'per_page'    => [ 'type' => 'integer' ],
						'total_pages' => [ 'type' => 'integer' ],
					],
				],
				'meta'                => $this->read_meta(),
			];
		}

		/**
		 * Definition: get-slider.
		 *
		 * @since 2.9.0
		 *
		 * @return array
		 */
		protected function definition_get_slider(): array {
			return [
				'label'               => __( 'Get Slider', 'soliloquy' ),
				'description'         => __( 'Retrieves a single Soliloquy slider by ID, including its slides.', 'soliloquy' ),
				'category'            => self::ABILITY_NAMESPACE,
				'execute_callback'    => [ $this, 'execute_get_slider' ],
				'permission_callback' => [ $this, 'can_get_slider' ],
				'input_schema'        => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => [ 'id' ],
					'properties'           => [
						'id' => [
							'type'        => 'integer',
							'description' => __( 'Slider ID.', 'soliloquy' ),
							'minimum'     => 1,
						],
					],
				],
				'output_schema'       => $this->slider_schema( true ),
				'meta'                => $this->read_meta(),
			];
		}

		/**
		 * Definition: describe-slider-schema.
		 *
		 * Lets an agent ask what is writable instead of guessing at slider config
		 * keys. Hidden from MCP while writes are gated off, so agents do not see
		 * an editing surface they cannot act on. REST exposure is unaffected.
		 *
		 * @since 2.9.0
		 *
		 * @return array
		 */
		protected function definition_describe_slider_schema(): array {
			$key_label = [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'key'   => [ 'type' => 'string' ],
						'label' => [ 'type' => 'string' ],
					],
				],
			];

			return [
				'label'               => __( 'Describe Slider Schema', 'soliloquy' ),
				'description'         => __( 'Describes which slider fields can be created or edited, the valid statuses, the slider types and themes on this site, and whether writes are currently enabled.', 'soliloquy' ),
				'category'            => self::ABILITY_NAMESPACE,
				'execute_callback'    => [ $this, 'execute_describe_slider_schema' ],
				'permission_callback' => [ $this, 'can_describe_slider_schema' ],
				// No `properties` key: an empty PHP array serialises to JSON `[]` (an
				// array, not an object `{}`), which stricter Abilities API validators
				// reject as a malformed schema.
				'input_schema'        => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'default'              => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'writes_enabled'  => [
							'type'        => 'boolean',
							'description' => __( 'Whether the write abilities will currently execute.', 'soliloquy' ),
						],
						'statuses'        => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
						'types'           => $key_label,
						'themes'          => $key_label,
						'writable_fields' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'name'      => [ 'type' => 'string' ],
									'type'      => [ 'type' => 'string' ],
									'abilities' => [
										'type'  => 'array',
										'items' => [ 'type' => 'string' ],
									],
								],
							],
						],
					],
				],
				'meta'                => $this->read_meta( [ 'mcp_public' => $this->write_enabled() ] ),
			];
		}

		/**
		 * Definition: create-slider.
		 *
		 * @since 2.9.0
		 *
		 * @return array
		 */
		protected function definition_create_slider(): array {
			return [
				'label'               => __( 'Create Slider', 'soliloquy' ),
				'description'         => __( 'Creates a new, empty Soliloquy slider with the default settings.', 'soliloquy' ),
				'category'            => self::ABILITY_NAMESPACE,
				'execute_callback'    => [ $this, 'execute_create_slider' ],
				'permission_callback' => [ $this, 'can_create_slider' ],
				'input_schema'        => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => [ 'title' ],
					'properties'           => [
						'title'  => [
							'type'        => 'string',
							'description' => __( 'Slider title.', 'soliloquy' ),
							'minLength'   => 1,
							'maxLength'   => 255,
						],
						'status' => [
							'type'        => 'string',
							'description' => __( 'Initial publish status.', 'soliloquy' ),
							'enum'        => [ 'publish', 'draft' ],
							'default'     => 'draft',
						],
					],
				],
				'output_schema'       => $this->slider_schema(),
				'meta'                => $this->write_meta( [ 'idempotent' => false ] ),
			];
		}

		/**
		 * Definition: update-slider.
		 *
		 * @since 2.9.0
		 *
		 * @return array
		 */
		protected function definition_update_slider(): array {
			return [
				'label'               => __( 'Update Slider', 'soliloquy' ),
				'description'         => __( 'Updates the title or publish status of an existing Soliloquy slider.', 'soliloquy' ),
				'category'            => self::ABILITY_NAMESPACE,
				'execute_callback'    => [ $this, 'execute_update_slider' ],
				'permission_callback' => [ $this, 'can_update_slider' ],
				'input_schema'        => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => [ 'id' ],
					'properties'           => [
						'id'     => [
							'type'        => 'integer',
							'description' => __( 'Slider ID.', 'soliloquy' ),
							'minimum'     => 1,
						],
						'title'  => [
							'type'        => 'string',
							'description' => __( 'New slider title.', 'soliloquy' ),
							'minLength'   => 1,
							'maxLength'   => 255,
						],
						'status' => [
							'type'        => 'string',
							'description' => __( 'New publish status.', 'soliloquy' ),
							'enum'        => [ 'publish', 'draft' ],
						],
					],
				],
				'output_schema'       => $this->slider_schema(),
				'meta'                => $this->write_meta(),
			];
		}

		/**
		 * Definition: delete-slider.
		 *
		 * @since 2.9.0
		 *
		 * @return array
		 */
		protected function definition_delete_slider(): array {
			return [
				'label'               => __( 'Delete Slider', 'soliloquy' ),
				'description'         => __( 'Moves a Soliloquy slider to the trash, or deletes it permanently with force.', 'soliloquy' ),
				'category'            => self::ABILITY_NAMESPACE,
				'execute_callback'    => [ $this, 'execute_delete_slider' ],
				'permission_callback' => [ $this, 'can_delete_slider' ],
				'input_schema'        => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => [ 'id' ],
					'properties'           => [
						'id'    => [
							'type'        => 'integer',
							'description' => __( 'Slider ID to delete.', 'soliloquy' ),
							'minimum'     => 1,
						],
						'force' => [
							'type'        => 'boolean',
							'description' => __( 'True to permanently delete; false to move to the trash (default).', 'soliloquy' ),
							'default'     => false,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'deleted' => [ 'type' => 'boolean' ],
						'trashed' => [
							'type'        => 'boolean',
							'description' => __( 'True when the slider was moved to the trash, false when it was permanently deleted.', 'soliloquy' ),
						],
						'id'      => [ 'type' => 'integer' ],
					],
				],
				'meta'                => $this->write_meta( [ 'destructive' => true ] ),
			];
		}

		/**
		 * Definition: add-slides.
		 *
		 * @since 2.9.0
		 *
		 * @return array
		 */
		protected function definition_add_slides(): array {
			return [
				'label'               => __( 'Add Slides to Slider', 'soliloquy' ),
				'description'         => __( 'Adds one or more media library images to an existing Soliloquy slider as image slides. Added slides are active immediately.', 'soliloquy' ),
				'category'            => self::ABILITY_NAMESPACE,
				'execute_callback'    => [ $this, 'execute_add_slides' ],
				'permission_callback' => [ $this, 'can_add_slides' ],
				'input_schema'        => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => [ 'slider_id', 'attachment_ids' ],
					'properties'           => [
						'slider_id'      => [
							'type'        => 'integer',
							'description' => __( 'Slider ID to add slides to.', 'soliloquy' ),
							'minimum'     => 1,
						],
						'attachment_ids' => [
							'type'        => 'array',
							'description' => __( 'Media library image attachment IDs to add.', 'soliloquy' ),
							'items'       => [
								'type'    => 'integer',
								'minimum' => 1,
							],
							'minItems'    => 1,
							'maxItems'    => 100,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'slider'   => $this->slider_schema(),
						'added'    => [
							'type'        => 'array',
							'description' => __( 'Attachment IDs that were added as slides.', 'soliloquy' ),
							'items'       => [ 'type' => 'integer' ],
						],
						'skipped'  => [
							'type'        => 'array',
							'description' => __( 'Attachment IDs that were not added — not a media library image, or not editable by this account.', 'soliloquy' ),
							'items'       => [ 'type' => 'integer' ],
						],
						'existing' => [
							'type'        => 'array',
							'description' => __( 'Attachment IDs that were already slides in the slider and were left unchanged.', 'soliloquy' ),
							'items'       => [ 'type' => 'integer' ],
						],
					],
				],
				'meta'                => $this->write_meta(),
			];
		}

		// -------------------------------------------------------------------------
		// Execute callbacks — thin delegates to the slider service.
		// -------------------------------------------------------------------------

		/**
		 * Execute: list-sliders.
		 *
		 * @since 2.9.0
		 *
		 * @param mixed $input Validated ability input.
		 * @return array
		 */
		public function execute_list_sliders( $input = null ) {
			return $this->sliders()->list_sliders( $this->normalize_input( $input ) );
		}

		/**
		 * Execute: get-slider.
		 *
		 * @since 2.9.0
		 *
		 * @param mixed $input Validated ability input.
		 * @return array|\WP_Error
		 */
		public function execute_get_slider( $input ) {
			$id = $this->require_slider_id( $input, 'id' );

			if ( is_wp_error( $id ) ) {
				return $id;
			}

			return $this->sliders()->get( $id );
		}

		/**
		 * Execute: describe-slider-schema.
		 *
		 * @since 2.9.0
		 *
		 * @param mixed $input Validated ability input (unused).
		 * @return array
		 */
		public function execute_describe_slider_schema( $input = null ) {
			$ns = self::ABILITY_NAMESPACE;

			// Derived from what is actually registered, so a field is never
			// advertised for an ability a filter removed, and an addon adding an
			// ability does not have to edit this list.
			$registered = array_keys( $this->get_ability_definitions() );
			$candidates = [
				[
					'name'      => 'title',
					'type'      => 'string',
					'abilities' => [ $ns . '/create-slider', $ns . '/update-slider' ],
				],
				[
					'name'      => 'status',
					'type'      => 'string',
					'abilities' => [ $ns . '/create-slider', $ns . '/update-slider' ],
				],
				[
					'name'      => 'attachment_ids',
					'type'      => 'array',
					'abilities' => [ $ns . '/add-slides' ],
				],
			];

			$writable_fields = [];

			foreach ( $candidates as $field ) {
				$available = array_values( array_intersect( $field['abilities'], $registered ) );

				if ( empty( $available ) ) {
					continue;
				}

				$field['abilities'] = $available;
				$writable_fields[]  = $field;
			}

			return [
				'writes_enabled'  => $this->write_enabled(),
				'statuses'        => [ 'publish', 'draft' ],
				'types'           => $this->to_key_label( $this->sliders()->get_slider_types() ),
				'themes'          => $this->to_key_label( $this->sliders()->get_slider_themes() ),
				'writable_fields' => $writable_fields,
			];
		}

		/**
		 * Execute: create-slider.
		 *
		 * @since 2.9.0
		 *
		 * @param mixed $input Validated ability input.
		 * @return array|\WP_Error
		 */
		public function execute_create_slider( $input ) {
			$input = $this->normalize_input( $input );

			return $this->sliders()->create(
				isset( $input['title'] ) && is_string( $input['title'] ) ? $input['title'] : '',
				isset( $input['status'] ) && is_string( $input['status'] ) ? $input['status'] : 'draft'
			);
		}

		/**
		 * Execute: update-slider.
		 *
		 * @since 2.9.0
		 *
		 * @param mixed $input Validated ability input.
		 * @return array|\WP_Error
		 */
		public function execute_update_slider( $input ) {
			$id = $this->require_slider_id( $input, 'id' );

			if ( is_wp_error( $id ) ) {
				return $id;
			}

			$fields = array_intersect_key( $this->normalize_input( $input ), array_flip( [ 'title', 'status' ] ) );

			return $this->sliders()->update( $id, $fields );
		}

		/**
		 * Execute: delete-slider.
		 *
		 * @since 2.9.0
		 *
		 * @param mixed $input Validated ability input.
		 * @return array|\WP_Error
		 */
		public function execute_delete_slider( $input ) {
			$id = $this->require_slider_id( $input, 'id' );

			if ( is_wp_error( $id ) ) {
				return $id;
			}

			$input = $this->normalize_input( $input );

			return $this->sliders()->delete(
				$id,
				// A DELETE request carries its input in the query string, where a
				// boolean can arrive as the string 'false'; a plain (bool) cast
				// would read that as true and delete permanently.
				rest_sanitize_boolean( $input['force'] ?? false )
			);
		}

		/**
		 * Execute: add-slides.
		 *
		 * @since 2.9.0
		 *
		 * @param mixed $input Validated ability input.
		 * @return array|\WP_Error
		 */
		public function execute_add_slides( $input ) {
			$id = $this->require_slider_id( $input, 'slider_id' );

			if ( is_wp_error( $id ) ) {
				return $id;
			}

			$input = $this->normalize_input( $input );

			return $this->sliders()->add_slides(
				$id,
				isset( $input['attachment_ids'] ) && is_array( $input['attachment_ids'] ) ? $input['attachment_ids'] : []
			);
		}

		// -------------------------------------------------------------------------
		// Permission callbacks
		// -------------------------------------------------------------------------

		/**
		 * Permission: list-sliders.
		 *
		 * The capability wp-admin requires to open the sliders list. The service
		 * then scopes the list to the caller's own sliders unless they hold
		 * edit_others_posts.
		 *
		 * @since 2.9.0
		 *
		 * @param mixed $input Ability input (unused).
		 * @return true|\WP_Error
		 */
		public function can_list_sliders( $input = null ) {
			return $this->check_cap( 'edit_posts' );
		}

		/**
		 * Permission: get-slider.
		 *
		 * The edit_post meta cap, not read_post: the result carries the slider's
		 * full payload, which wp-admin only shows to someone who may edit it, and
		 * it keeps get-slider in step with what list-sliders returns.
		 *
		 * @since 2.9.0
		 *
		 * @param mixed $input Ability input (may be null before validation).
		 * @return true|\WP_Error
		 */
		public function can_get_slider( $input = null ) {
			$id = $this->require_slider_id( $input, 'id' );

			if ( is_wp_error( $id ) ) {
				return $id;
			}

			return $this->check_cap( 'edit_post', $id );
		}

		/**
		 * Permission: describe-slider-schema.
		 *
		 * @since 2.9.0
		 *
		 * @param mixed $input Ability input (unused).
		 * @return true|\WP_Error
		 */
		public function can_describe_slider_schema( $input = null ) {
			return $this->check_cap( 'edit_posts' );
		}

		/**
		 * Permission: create-slider.
		 *
		 * @since 2.9.0
		 *
		 * @param mixed $input Ability input.
		 * @return true|\WP_Error
		 */
		public function can_create_slider( $input = null ) {
			$gate = $this->check_write_gate();

			if ( is_wp_error( $gate ) ) {
				return $gate;
			}

			$allowed = $this->check_cap( 'edit_posts' );

			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}

			return $this->check_publish_permission( $input );
		}

		/**
		 * Permission: update-slider.
		 *
		 * @since 2.9.0
		 *
		 * @param mixed $input Ability input.
		 * @return true|\WP_Error
		 */
		public function can_update_slider( $input = null ) {
			$allowed = $this->check_write_permission_for( $input, 'id', 'edit_post' );

			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}

			return $this->check_publish_permission( $input );
		}

		/**
		 * Permission: delete-slider.
		 *
		 * @since 2.9.0
		 *
		 * @param mixed $input Ability input.
		 * @return true|\WP_Error
		 */
		public function can_delete_slider( $input = null ) {
			return $this->check_write_permission_for( $input, 'id', 'delete_post' );
		}

		/**
		 * Permission: add-slides.
		 *
		 * @since 2.9.0
		 *
		 * @param mixed $input Ability input.
		 * @return true|\WP_Error
		 */
		public function can_add_slides( $input = null ) {
			return $this->check_write_permission_for( $input, 'slider_id', 'edit_post' );
		}

		// -------------------------------------------------------------------------
		// Helpers
		// -------------------------------------------------------------------------

		/**
		 * Require publish_posts when the caller asks to publish.
		 *
		 * Neither wp_insert_post() nor wp_update_post() performs a capability
		 * check of its own, so without this a Contributor — who holds edit_posts
		 * but not publish_posts — could publish a slider through an ability,
		 * which wp-admin would refuse.
		 *
		 * @since 2.9.0
		 *
		 * @param mixed $input Ability input.
		 * @return true|\WP_Error
		 */
		protected function check_publish_permission( $input ) {
			$input = $this->normalize_input( $input );

			// Normalised the way the service saves it (sanitize_key lowercases), so
			// 'Publish' cannot skip this check and still publish.
			$status = isset( $input['status'] ) && is_string( $input['status'] ) ? sanitize_key( $input['status'] ) : '';

			if ( 'publish' !== $status ) {
				return true;
			}

			return $this->check_cap( 'publish_posts' );
		}

		/**
		 * Run the write gate, then a per-slider meta capability check.
		 *
		 * @since 2.9.0
		 *
		 * @param mixed  $input Ability input.
		 * @param string $key   Input key holding the slider ID.
		 * @param string $cap   Meta capability to check.
		 * @return true|\WP_Error
		 */
		protected function check_write_permission_for( $input, string $key, string $cap ) {
			$gate = $this->check_write_gate();

			if ( is_wp_error( $gate ) ) {
				return $gate;
			}

			$id = $this->require_slider_id( $input, $key );

			if ( is_wp_error( $id ) ) {
				return $id;
			}

			return $this->check_cap( $cap, $id );
		}

		/**
		 * Normalise ability input to an array.
		 *
		 * @since 2.9.0
		 *
		 * @param mixed $input Input data (array, object or null).
		 * @return array
		 */
		protected function normalize_input( $input ): array {
			if ( is_array( $input ) ) {
				return $input;
			}

			if ( is_object( $input ) ) {
				return (array) $input;
			}

			return [];
		}

		/**
		 * Turn a key => label map into a list of { key, label } objects.
		 *
		 * @since 2.9.0
		 *
		 * @param array $map Labels keyed by slug.
		 * @return array<int, array{key: string, label: string}>
		 */
		protected function to_key_label( array $map ): array {
			$result = [];

			foreach ( $map as $key => $label ) {
				$result[] = [
					'key'   => (string) $key,
					'label' => is_scalar( $label ) && '' !== (string) $label ? (string) $label : (string) $key,
				];
			}

			return $result;
		}

		/**
		 * Meta block for a read-only ability.
		 *
		 * @since 2.9.0
		 *
		 * @param array $overrides Optional. `mcp_public` to override MCP exposure.
		 * @return array
		 */
		protected function read_meta( array $overrides = [] ): array {
			return [
				'annotations'  => [
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				],
				'show_in_rest' => true,
				'mcp'          => [
					'public' => array_key_exists( 'mcp_public', $overrides ) ? (bool) $overrides['mcp_public'] : true,
				],
			];
		}

		/**
		 * Meta block for a write ability.
		 *
		 * Writes are hidden from MCP while the write gate is off, so an agent
		 * never sees an operation it cannot perform. REST exposure is unaffected
		 * and stays refused by the permission callback.
		 *
		 * @since 2.9.0
		 *
		 * @param array $overrides Optional. `destructive` and/or `idempotent`.
		 * @return array
		 */
		protected function write_meta( array $overrides = [] ): array {
			return [
				'annotations'  => [
					'readonly'    => false,
					'destructive' => ! empty( $overrides['destructive'] ),
					'idempotent'  => array_key_exists( 'idempotent', $overrides ) ? (bool) $overrides['idempotent'] : true,
				],
				'show_in_rest' => true,
				'mcp'          => [
					'public' => $this->write_enabled(),
				],
			];
		}

		/**
		 * JSON Schema fragment describing a single slider object.
		 *
		 * @since 2.9.0
		 *
		 * @param bool $with_slides Whether to describe the slides list too (get-slider).
		 * @return array
		 */
		protected function slider_schema( bool $with_slides = false ): array {
			$schema = [
				'type'       => 'object',
				'properties' => [
					'id'          => [
						'type'        => 'integer',
						'description' => __( 'Slider ID.', 'soliloquy' ),
					],
					'title'       => [
						'type'        => 'string',
						'description' => __( 'Slider title.', 'soliloquy' ),
					],
					'slug'        => [
						'type'        => 'string',
						'description' => __( 'Slider slug. Derived from the title until the slider is first published.', 'soliloquy' ),
					],
					'status'      => [
						'type'        => 'string',
						'description' => __( 'Publish status.', 'soliloquy' ),
					],
					'created'     => [
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Creation date (UTC, RFC 3339).', 'soliloquy' ),
					],
					'modified'    => [
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'Last modified date (UTC, RFC 3339).', 'soliloquy' ),
					],
					'edit_url'    => [
						'type'        => 'string',
						'description' => __( 'WordPress admin edit URL.', 'soliloquy' ),
					],
					'shortcode'   => [
						'type'        => 'string',
						'description' => __( 'Shortcode that embeds the slider.', 'soliloquy' ),
					],
					'type'        => [
						'type'        => 'string',
						'description' => __( 'Slider type key. See describe-slider-schema for the types on this site.', 'soliloquy' ),
					],
					'theme'       => [
						'type'        => 'string',
						'description' => __( 'Slider theme key. See describe-slider-schema for the themes on this site.', 'soliloquy' ),
					],
					'slide_count' => [
						'type'        => 'integer',
						'description' => __( 'Number of slides in the slider.', 'soliloquy' ),
					],
				],
			];

			if ( $with_slides ) {
				$schema['properties']['slides'] = [
					'type'        => 'array',
					'description' => __( 'Slides in display order.', 'soliloquy' ),
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'id'            => [ 'type' => 'string' ],
							'attachment_id' => [ 'type' => 'integer' ],
							'type'          => [ 'type' => 'string' ],
							'status'        => [ 'type' => 'string' ],
							'title'         => [ 'type' => 'string' ],
							'caption'       => [ 'type' => 'string' ],
							'alt'           => [ 'type' => 'string' ],
							'link'          => [ 'type' => 'string' ],
							'src'           => [ 'type' => 'string' ],
						],
					],
				];
			}

			return $schema;
		}
	}
}
