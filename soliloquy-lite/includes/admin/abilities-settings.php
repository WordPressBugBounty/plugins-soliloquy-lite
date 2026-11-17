<?php
/**
 * Soliloquy Lite — AI & MCP settings tab.
 *
 * Two jobs:
 *
 * 1. Own the write opt-in. Soliloquy's write abilities refuse to execute, and
 *    are hidden from MCP entirely, until an administrator turns them on here.
 * 2. Tell the administrator whether an MCP bridge is installed. Abilities with
 *    no bridge are registered but unreachable by any AI agent, which is the
 *    most common reason "the abilities do nothing".
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

/**
 * Soliloquy_Abilities_Settings_Lite — the AI & MCP tab under Soliloquy → Settings.
 *
 * @since 2.9.0
 */
class Soliloquy_Abilities_Settings_Lite {

	/**
	 * Tab ID.
	 *
	 * @since 2.9.0
	 *
	 * @var string
	 */
	const TAB = 'ai_mcp';

	/**
	 * Nonce action and field name for the write toggle form.
	 *
	 * @since 2.9.0
	 *
	 * @var string
	 */
	const NONCE = 'soliloquy-abilities-nonce';

	/**
	 * Known MCP bridge plugins, keyed by wp.org slug.
	 *
	 * @since 2.9.0
	 *
	 * @var array<string, array{basename: string, label: string}>
	 */
	const BRIDGES = [
		'vibe-ai'     => [
			'basename' => 'vibe-ai/vibe-ai.php',
			'label'    => 'WPVibe',
		],
		'mcp-adapter' => [
			'basename' => 'mcp-adapter/mcp-adapter.php',
			'label'    => 'MCP Adapter',
		],
	];

	/**
	 * Notice to show above the tab on this request: saved, expired, forbidden or failed.
	 *
	 * @since 2.9.0
	 *
	 * @var string
	 */
	private $notice = '';

	/**
	 * Register hooks.
	 *
	 * @since 2.9.0
	 *
	 * @return void
	 */
	public function hooks() {
		add_filter( 'soliloquy_lite_settings_tab_nav', [ $this, 'add_tab' ] );
		add_action( 'soliloquy_lite_settings_tab_' . self::TAB, [ $this, 'render_tab' ] );
		add_action( 'admin_init', [ $this, 'maybe_save' ] );
	}

	/**
	 * Add the AI & MCP tab.
	 *
	 * Hidden on WordPress < 6.9, where there is no Abilities API to configure.
	 *
	 * @since 2.9.0
	 *
	 * @param array $tabs Existing settings tabs.
	 * @return array
	 */
	public function add_tab( $tabs ) {
		if ( ! is_array( $tabs ) || ! Soliloquy_Abilities_Lite::instance()->is_abilities_api_available() ) {
			return $tabs;
		}

		$tabs[ self::TAB ] = __( 'AI & MCP', 'soliloquy' );

		return $tabs;
	}

	/**
	 * Persist the write toggle.
	 *
	 * @since 2.9.0
	 *
	 * @return void
	 */
	public function maybe_save() {
		if ( ! isset( $_POST['soliloquy-abilities-submit'], $_POST[ self::NONCE ] ) ) {
			return;
		}

		// Both rejections below fail closed, which is correct — but say so.
		// Returning silently would redisplay the stored value, so a submission
		// discarded by an expired nonce would look identical to one that saved.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			$this->notice = 'expired';

			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			$this->notice = 'forbidden';

			return;
		}

		$value = empty( $_POST['soliloquy_abilities_write_enabled'] ) ? 0 : 1;

		update_option( Soliloquy_Abilities_Base::WRITE_SETTING_OPTION, $value, false );

		// update_option() returns false both when the write fails and when the
		// value is unchanged, so read it back. This option is the write gate:
		// reporting a revoke as saved when it was not would leave agents writing.
		$this->notice = (int) get_option( Soliloquy_Abilities_Base::WRITE_SETTING_OPTION, 0 ) === $value ? 'saved' : 'failed';
	}

	/**
	 * Render the tab.
	 *
	 * @since 2.9.0
	 *
	 * @return void
	 */
	public function render_tab() {
		$handler       = Soliloquy_Abilities_Lite::instance();
		$write_enabled = $handler->write_enabled();
		$bridge        = $this->get_active_bridge();
		$abilities     = $this->get_registered_abilities();
		$failed        = $handler->get_failed_registrations();
		$category_ok   = $handler->is_category_registered();
		?>
		<div id="soliloquy-settings-ai-mcp">
			<?php $this->render_notice(); ?>

			<p class="description">
				<?php esc_html_e( 'Soliloquy registers your sliders with the WordPress Abilities API so AI assistants can list, read and edit them. Reading is always available to users who can already edit sliders. Editing is off until you turn it on below.', 'soliloquy' ); ?>
			</p>

			<form action="<?php echo esc_url( Soliloquy_Settings::get_tab_url( self::TAB ) ); ?>" method="post">
				<table class="form-table">
					<tbody>
						<tr id="soliloquy-settings-abilities-write">
							<th scope="row">
								<label for="soliloquy_abilities_write_enabled"><?php esc_html_e( 'Allow AI to edit sliders', 'soliloquy' ); ?></label>
							</th>
							<td>
								<input type="checkbox" id="soliloquy_abilities_write_enabled" name="soliloquy_abilities_write_enabled" value="1" <?php checked( $write_enabled ); ?> />
								<p class="description">
									<?php esc_html_e( 'When off, AI assistants can read sliders but cannot create, update, delete or add slides to them, and the editing tools are hidden from them entirely. Users still need the matching WordPress permission either way.', 'soliloquy' ); ?>
								</p>
							</td>
						</tr>
						<tr class="no-bottom-border">
							<th scope="row">
								<?php wp_nonce_field( self::NONCE, self::NONCE ); ?>
								<?php submit_button( __( 'Save', 'soliloquy' ), 'primary', 'soliloquy-abilities-submit', false ); ?>
							</th>
							<td>&nbsp;</td>
						</tr>
					</tbody>
				</table>
			</form>

			<h3><?php esc_html_e( 'AI assistant connection', 'soliloquy' ); ?></h3>

			<?php if ( $bridge ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: bridge plugin name. */
						esc_html__( '%s is active. Your sliders are reachable by any AI assistant connected through it.', 'soliloquy' ),
						'<strong>' . esc_html( $bridge['label'] ) . '</strong>'
					);
					?>
				</p>
			<?php else : ?>
				<p><?php esc_html_e( 'Abilities on their own are not reachable by an AI assistant — a bridge plugin has to expose them. None is active on this site, so nothing below is visible to any assistant yet.', 'soliloquy' ); ?></p>
				<?php $this->render_bridge_actions(); ?>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Slider abilities', 'soliloquy' ); ?></h3>

			<?php if ( ! $category_ok ) : ?>
				<div class="notice notice-error inline">
					<p>
						<?php
						printf(
							/* translators: %s: ability category name. */
							esc_html__( 'WordPress rejected the %s ability category, so none of the abilities below could be registered. Check the debug log for details.', 'soliloquy' ),
							'<code>' . esc_html( Soliloquy_Abilities_Base::ABILITY_NAMESPACE ) . '</code>'
						);
						?>
					</p>
				</div>
			<?php elseif ( $failed ) : ?>
				<div class="notice notice-error inline">
					<p>
						<?php
						printf(
							/* translators: %s: comma-separated ability names. */
							esc_html__( 'WordPress rejected these abilities, so no assistant can use them: %s. Check the debug log for details.', 'soliloquy' ),
							'<code>' . implode( '</code>, <code>', array_map( 'esc_html', $failed ) ) . '</code>'
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Ability', 'soliloquy' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Type', 'soliloquy' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Visible to AI', 'soliloquy' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $abilities as $ability ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $ability['label'] ); ?></strong><br />
								<code><?php echo esc_html( $ability['name'] ); ?></code>
							</td>
							<td>
								<?php
								if ( $ability['destructive'] ) {
									esc_html_e( 'Deletes data', 'soliloquy' );
								} elseif ( $ability['readonly'] ) {
									esc_html_e( 'Read only', 'soliloquy' );
								} else {
									esc_html_e( 'Edits data', 'soliloquy' );
								}
								?>
							</td>
							<td><?php echo $ability['mcp_public'] ? esc_html__( 'Yes', 'soliloquy' ) : esc_html__( 'Hidden', 'soliloquy' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Output the notice for this request's save attempt, if any.
	 *
	 * @since 2.9.0
	 *
	 * @return void
	 */
	private function render_notice() {
		$notices = [
			'saved'     => [ 'success', __( 'AI & MCP settings saved.', 'soliloquy' ) ],
			'expired'   => [ 'error', __( 'Your session expired, so the AI & MCP settings were not saved. Please try again.', 'soliloquy' ) ],
			'forbidden' => [ 'error', __( 'You do not have permission to change the AI & MCP settings.', 'soliloquy' ) ],
			'failed'    => [ 'error', __( 'The AI & MCP settings could not be saved, so the previous setting is still in effect. Please try again.', 'soliloquy' ) ],
		];

		if ( ! isset( $notices[ $this->notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s inline"><p>%2$s</p></div>',
			esc_attr( $notices[ $this->notice ][0] ),
			esc_html( $notices[ $this->notice ][1] )
		);
	}

	/**
	 * Output install/activate links for the known bridge plugins.
	 *
	 * Uses the core plugin install and activate screens rather than fetching or
	 * unpacking anything directly.
	 *
	 * @since 2.9.0
	 *
	 * @return void
	 */
	private function render_bridge_actions() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = (array) get_plugins();

		echo '<p>';

		foreach ( self::BRIDGES as $slug => $bridge ) {
			$is_installed = isset( $installed[ $bridge['basename'] ] );

			if ( $is_installed && current_user_can( 'activate_plugins' ) ) {
				printf(
					'<a class="button" href="%1$s">%2$s</a> ',
					esc_url( wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $bridge['basename'] ) ), 'activate-plugin_' . $bridge['basename'] ) ),
					/* translators: %s: bridge plugin name. */
					esc_html( sprintf( __( 'Activate %s', 'soliloquy' ), $bridge['label'] ) )
				);

				continue;
			}

			if ( ! $is_installed && current_user_can( 'install_plugins' ) ) {
				printf(
					'<a class="button" href="%1$s">%2$s</a> ',
					esc_url( self_admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . rawurlencode( $slug ) ) ),
					/* translators: %s: bridge plugin name. */
					esc_html( sprintf( __( 'Get %s', 'soliloquy' ), $bridge['label'] ) )
				);
			}
		}

		echo '</p>';
	}

	/**
	 * Return the first active bridge plugin, if any.
	 *
	 * @since 2.9.0
	 *
	 * @return array{basename: string, label: string}|null
	 */
	private function get_active_bridge() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( self::BRIDGES as $bridge ) {
			if ( is_plugin_active( $bridge['basename'] ) ) {
				return $bridge;
			}
		}

		// An adapter may also be loaded as a library rather than a plugin.
		if ( class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) {
			return self::BRIDGES['mcp-adapter'];
		}

		return null;
	}

	/**
	 * Summarise the abilities Soliloquy declares.
	 *
	 * Reads the definitions from the handler rather than the registry: the
	 * registry is only populated on requests where `wp_abilities_api_init` has
	 * already fired, which is not guaranteed on a settings screen.
	 *
	 * @since 2.9.0
	 *
	 * @return array<int, array{name: string, label: string, readonly: bool, destructive: bool, mcp_public: bool}>
	 */
	private function get_registered_abilities() {
		$rows = [];

		foreach ( Soliloquy_Abilities_Lite::instance()->get_ability_definitions() as $name => $args ) {
			$annotations = isset( $args['meta']['annotations'] ) && is_array( $args['meta']['annotations'] ) ? $args['meta']['annotations'] : [];

			$rows[] = [
				'name'        => (string) $name,
				'label'       => isset( $args['label'] ) ? (string) $args['label'] : (string) $name,
				'readonly'    => ! empty( $annotations['readonly'] ),
				'destructive' => ! empty( $annotations['destructive'] ),
				'mcp_public'  => ! empty( $args['meta']['mcp']['public'] ),
			];
		}

		return $rows;
	}
}
