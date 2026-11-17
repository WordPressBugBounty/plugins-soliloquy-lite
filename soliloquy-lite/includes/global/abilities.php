<?php
/**
 * Soliloquy Abilities — Lite implementation.
 *
 * Every definition, execute callback and permission callback lives on
 * Soliloquy_Abilities_Base. Lite adds only its slider service. The ability
 * list is deliberately not filterable here — Pro owns the addon seam
 * (`soliloquy_register_abilities`).
 *
 * Requires WordPress 6.9+. Hooks are skipped when wp_register_ability() is
 * absent — a no-op on older WordPress installations.
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

if ( ! class_exists( 'Soliloquy_Abilities_Lite' ) ) {

	/**
	 * Soliloquy_Abilities_Lite — Lite registration surface for the WordPress Abilities API.
	 *
	 * @since 2.9.0
	 */
	class Soliloquy_Abilities_Lite extends Soliloquy_Abilities_Base {

		/**
		 * Slider service.
		 *
		 * @since 2.9.0
		 *
		 * @var Soliloquy_Slider_Service_Lite|null
		 */
		private $service;

		/**
		 * Return the slider service.
		 *
		 * @since 2.9.0
		 *
		 * @return Soliloquy_Slider_Service_Lite
		 */
		protected function sliders() {
			if ( ! $this->service instanceof Soliloquy_Slider_Service_Lite ) {
				$this->service = new Soliloquy_Slider_Service_Lite();
			}

			return $this->service;
		}
	}
}
