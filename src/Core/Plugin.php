<?php
/**
 * Main Plugin Class
 *
 * @package    ExtendedAbilities
 * @subpackage Core
 * @since      1.0.0
 */

namespace ExtendedAbilities\Core;

use ExtendedAbilities\Abilities\WordPress\Posts\ListPosts;
use ExtendedAbilities\Abilities\WordPress\Posts\Create as CreatePost;
use ExtendedAbilities\Abilities\WordPress\Posts\Update as UpdatePost;
use ExtendedAbilities\Abilities\WordPress\Posts\Delete as DeletePost;
use ExtendedAbilities\Abilities\WordPress\Pages\ListPages;
use ExtendedAbilities\Abilities\WordPress\Pages\Create as CreatePage;
use ExtendedAbilities\Abilities\WordPress\Pages\Update as UpdatePage;
use ExtendedAbilities\Abilities\WordPress\Pages\Delete as DeletePage;
use ExtendedAbilities\Abilities\WordPress\Users\ListUsers;
use ExtendedAbilities\Abilities\WordPress\Users\Create as CreateUser;
use ExtendedAbilities\Abilities\WordPress\Users\Update as UpdateUser;
use ExtendedAbilities\Abilities\WordPress\Users\Delete as DeleteUser;
use ExtendedAbilities\Admin\Settings;
use ExtendedAbilities\Contracts\Interfaces\Hookable;
use WP\MCP\Core\McpAdapter;

/**
 * Main Plugin Class
 *
 * This is the core plugin class that initializes all functionality.
 * Uses singleton pattern to ensure only one instance exists.
 *
 * @since 1.0.0
 */
class Plugin {
	/**
	 * The single instance of the plugin.
	 *
	 * @since 1.0.0
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Array of registered hookable components.
	 *
	 * @since 1.0.0
	 * @var Hookable[]
	 */
	private array $components = [];

	/**
	 * The abilities manager instance.
	 *
	 * @since 1.0.0
	 * @var AbilitiesManager|null
	 */
	private ?AbilitiesManager $abilities_manager = null;

	/**
	 * Get the singleton instance of the plugin.
	 *
	 * @return Plugin The plugin instance.
	 * @since 1.0.0
	 */
	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize the plugin.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function init(): void {
		// Register admin components.
		if ( is_admin() ) {
			$settings = new Settings();
			$settings->register_hooks();
		}

		// Try and run the McpAdapter. Without this, it's useless.
		if ( ! class_exists( McpAdapter::class ) ) {
			/**
			 * @ToDo: If the class does not exist, for some reason, we need to handle this gracefully.
			 */
			return;
		}

		// Initialize the adapter.
		McpAdapter::instance();

		// Initialize abilities manager.
		$this->abilities_manager = new AbilitiesManager();

		// Add abilities to the manager.
		$this->abilities_manager->add_ability( new ListPosts() );
		$this->abilities_manager->add_ability( new CreatePost() );
		$this->abilities_manager->add_ability( new UpdatePost() );
		$this->abilities_manager->add_ability( new DeletePost() );
		$this->abilities_manager->add_ability( new ListPages() );
		$this->abilities_manager->add_ability( new CreatePage() );
		$this->abilities_manager->add_ability( new UpdatePage() );
		$this->abilities_manager->add_ability( new DeletePage() );
		$this->abilities_manager->add_ability( new ListUsers() );
		$this->abilities_manager->add_ability( new CreateUser() );
		$this->abilities_manager->add_ability( new UpdateUser() );
		$this->abilities_manager->add_ability( new DeleteUser() );

		// Register abilities manager hooks.
		$this->abilities_manager->register_hooks();
	}

	/**
	 * Load plugin text domain for translations.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'extended-abilities',
			false,
			dirname( plugin_basename( EXTENDED_ABILITIES_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Register built-in abilities.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function register_abilities(): void {
		// Register WordPress abilities.
		$this->abilities_manager->add_ability( new CreatePostAbility() );

		/**
		 * Allows additional abilities to be registered.
		 *
		 * @param AbilitiesManager $abilities_manager The abilities manager instance.
		 *
		 * @since 1.0.0
		 */
		do_action( 'extended_abilities_register_abilities', $this->abilities_manager );
	}

	/**
	 * Add a component to the plugin.
	 *
	 * @param Hookable $component The component to add.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add_component( Hookable $component ): void {
		$this->components[] = $component;
	}

	/**
	 * Register hooks for all registered components.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_component_hooks(): void {
		foreach ( $this->components as $component ) {
			$component->register_hooks();
		}
	}

	/**
	 * Plugin activation hook callback.
	 *
	 * Runs when the plugin is activated.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function activate(): void {
		// Set default options if they don't exist.
		// Flush rewrite rules if needed.
		// Create custom database tables if needed.

		/**
		 * Fires when the plugin is activated.
		 *
		 * @since 1.0.0
		 */
		do_action( 'extended_abilities_activated' );
	}

	/**
	 * Plugin deactivation hook callback.
	 *
	 * Runs when the plugin is deactivated.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function deactivate(): void {
		// Flush rewrite rules if needed.
		// Clean up temporary data if needed.

		/**
		 * Fires when the plugin is deactivated.
		 *
		 * @since 1.0.0
		 */
		do_action( 'extended_abilities_deactivated' );
	}
}
