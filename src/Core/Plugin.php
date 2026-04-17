<?php
/**
 * Main Plugin Class
 *
 * @package Albert
 * @subpackage Core
 * @since      1.0.0
 */

namespace Albert\Core;

defined( 'ABSPATH' ) || exit;

use Albert\Abilities\WordPress\Posts\FindPosts;
use Albert\Abilities\WordPress\Posts\ViewPost;
use Albert\Abilities\WordPress\Posts\Create as CreatePost;
use Albert\Abilities\WordPress\Posts\Update as UpdatePost;
use Albert\Abilities\WordPress\Posts\Delete as DeletePost;
use Albert\Abilities\WordPress\Pages\FindPages;
use Albert\Abilities\WordPress\Pages\ViewPage;
use Albert\Abilities\WordPress\Pages\Create as CreatePage;
use Albert\Abilities\WordPress\Pages\Update as UpdatePage;
use Albert\Abilities\WordPress\Pages\Delete as DeletePage;
use Albert\Abilities\WordPress\Users\FindUsers;
use Albert\Abilities\WordPress\Users\ViewUser;
use Albert\Abilities\WordPress\Users\Create as CreateUser;
use Albert\Abilities\WordPress\Users\Update as UpdateUser;
use Albert\Abilities\WordPress\Users\Delete as DeleteUser;
use Albert\Abilities\WordPress\Media\FindMedia;
use Albert\Abilities\WordPress\Media\ViewMedia;
use Albert\Abilities\WordPress\Media\SetFeaturedImage;
use Albert\Abilities\WordPress\Media\UploadMedia;
use Albert\Abilities\WordPress\Taxonomies\FindTaxonomies;
use Albert\Abilities\WordPress\Taxonomies\FindTerms;
use Albert\Abilities\WordPress\Taxonomies\ViewTerm;
use Albert\Abilities\WordPress\Taxonomies\CreateTerm;
use Albert\Abilities\WordPress\Taxonomies\UpdateTerm;
use Albert\Abilities\WordPress\Taxonomies\DeleteTerm;
use Albert\Abilities\WooCommerce\FindCustomers;
use Albert\Abilities\WooCommerce\FindOrders;
use Albert\Abilities\WooCommerce\FindProducts;
use Albert\Abilities\WooCommerce\ViewCustomer;
use Albert\Abilities\WooCommerce\ViewOrder;
use Albert\Abilities\WooCommerce\ViewProduct;
use Albert\Admin\AbilitiesPage;
use Albert\Admin\Connections;
use Albert\Admin\Dashboard;
use Albert\Admin\Settings;
use Albert\Logging\Installer as LoggingInstaller;
use Albert\Logging\Logger;
use Albert\Logging\Repository as LoggingRepository;
use Albert\MCP\Server as McpServer;
use Albert\OAuth\Database\Installer as OAuthInstaller;
use Albert\OAuth\Endpoints\AuthorizationPage;
use Albert\OAuth\Endpoints\ClientRegistration;
use Albert\OAuth\Endpoints\OAuthController;
use Albert\OAuth\Endpoints\OAuthDiscovery;
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
	 * Default REST API namespace.
	 *
	 * Use {@see self::rest_namespace()} to get the (potentially filtered) value.
	 *
	 * @since 1.0.1
	 * @var string
	 */
	const REST_NAMESPACE = 'albert/v1';

	/**
	 * Get the REST API namespace, allowing override via filter.
	 *
	 * Sites that have a namespace collision with another plugin can change
	 * the value via the `albert/rest_namespace` filter. The result is cached
	 * for the duration of the request so the filter only fires once.
	 *
	 * @since 1.0.1
	 *
	 * @return string
	 */
	public static function rest_namespace(): string {
		static $namespace = null;

		if ( $namespace === null ) {
			/**
			 * Filters the REST API namespace used by all Albert endpoints.
			 *
			 * @since 1.0.1
			 *
			 * @param string $namespace Default namespace ('albert/v1').
			 */
			$namespace = (string) apply_filters( 'albert/rest_namespace', self::REST_NAMESPACE );
		}

		return $namespace;
	}

	/**
	 * The single instance of the plugin.
	 *
	 * @since 1.0.0
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

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
		if ( self::$instance === null ) {
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
		// Check for database updates (handles upgrades without re-activation).
		OAuthInstaller::install();
		LoggingInstaller::install();

		// One-time cleanup of legacy options on upgrade from pre-1.1.0 installs.
		$this->maybe_cleanup_legacy_options();

		// Initialize the logging system (hooks wp_after_execute_ability).
		$logging_repository = new LoggingRepository();
		$logger             = new Logger( $logging_repository );
		$logger->register_hooks();

		// Register admin components.
		if ( is_admin() ) {
			// Dashboard page (creates top-level menu and first submenu).
			( new Dashboard( $logging_repository ) )->register_hooks();

			// Unified abilities page (toggle abilities on/off).
			( new AbilitiesPage() )->register_hooks();

			// Connections page (allowed users + active sessions).
			( new Connections() )->register_hooks();

			// Settings page (MCP endpoint, developer options, licenses).
			( new Settings() )->register_hooks();

			// Addon submenu pages (registered via filter at priority 15).
			add_action( 'admin_menu', [ $this, 'register_addon_admin_pages' ], 15 );
		}

		// Register OAuth controller (REST API endpoints for token exchange).
		( new OAuthController() )->register_hooks();

		// Register OAuth authorization page (HTML-based consent flow).
		( new AuthorizationPage() )->register_hooks();

		// Register OAuth dynamic client registration (RFC 7591).
		( new ClientRegistration() )->register_hooks();

		// Register OAuth discovery endpoint (.well-known/oauth-authorization-server).
		( new OAuthDiscovery() )->register_hooks();

		// Register MCP server (uses OAuth for authentication).
		( new McpServer() )->register_hooks();

		// Initialize the MCP adapter, but not on admin pages.
		//
		// McpAdapter::instance() hooks the adapter's init() to rest_api_init, which
		// fires mcp_adapter_init — the hook Albert's Server listens on to create its
		// MCP server and register REST routes.
		//
		// On admin pages, WooCommerce preloads REST data (triggering rest_api_init),
		// and the adapter's DefaultServerFactory calls wp_get_ability() for tools that
		// aren't registered yet (wp_abilities_api_init already fired during admin page
		// render). Skipping initialization on admin pages avoids this timing conflict.
		// REST API requests (is_admin() === false) are unaffected.
		if ( class_exists( McpAdapter::class ) && ! is_admin() ) {
			McpAdapter::instance();
		}

		add_action( 'init', [ $this, 'register_abilities' ] );
	}

	/**
	 * Register built-in abilities.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_abilities(): void {
		// Initialize abilities manager.
		$this->abilities_manager = new AbilitiesManager();

		// Posts abilities.
		$this->abilities_manager->add_ability( new FindPosts() );
		$this->abilities_manager->add_ability( new ViewPost() );
		$this->abilities_manager->add_ability( new CreatePost() );
		$this->abilities_manager->add_ability( new UpdatePost() );
		$this->abilities_manager->add_ability( new DeletePost() );

		// Pages abilities.
		$this->abilities_manager->add_ability( new FindPages() );
		$this->abilities_manager->add_ability( new ViewPage() );
		$this->abilities_manager->add_ability( new CreatePage() );
		$this->abilities_manager->add_ability( new UpdatePage() );
		$this->abilities_manager->add_ability( new DeletePage() );

		// Users abilities.
		$this->abilities_manager->add_ability( new FindUsers() );
		$this->abilities_manager->add_ability( new ViewUser() );
		$this->abilities_manager->add_ability( new CreateUser() );
		$this->abilities_manager->add_ability( new UpdateUser() );
		$this->abilities_manager->add_ability( new DeleteUser() );

		// Media abilities.
		$this->abilities_manager->add_ability( new FindMedia() );
		$this->abilities_manager->add_ability( new ViewMedia() );
		$this->abilities_manager->add_ability( new UploadMedia() );
		$this->abilities_manager->add_ability( new SetFeaturedImage() );

		// Taxonomy abilities.
		$this->abilities_manager->add_ability( new FindTaxonomies() );
		$this->abilities_manager->add_ability( new FindTerms() );
		$this->abilities_manager->add_ability( new ViewTerm() );
		$this->abilities_manager->add_ability( new CreateTerm() );
		$this->abilities_manager->add_ability( new UpdateTerm() );
		$this->abilities_manager->add_ability( new DeleteTerm() );

		// WooCommerce abilities (only when WooCommerce is active).
		if ( class_exists( 'WooCommerce' ) ) {
			$this->abilities_manager->add_ability( new FindProducts() );
			$this->abilities_manager->add_ability( new ViewProduct() );
			$this->abilities_manager->add_ability( new FindOrders() );
			$this->abilities_manager->add_ability( new ViewOrder() );
			$this->abilities_manager->add_ability( new FindCustomers() );
			$this->abilities_manager->add_ability( new ViewCustomer() );
		}

		/**
		 * Fires after built-in abilities are registered.
		 *
		 * Addon plugins hook here to register their own abilities by calling
		 * $manager->add_ability() with a BaseAbility subclass.
		 *
		 * @since 1.1.0
		 *
		 * @param AbilitiesManager $manager The abilities manager instance.
		 */
		do_action( 'albert/abilities/register', $this->abilities_manager );

		// Register abilities manager hooks.
		$this->abilities_manager->register_hooks();
	}

	/**
	 * Register addon admin submenu pages.
	 *
	 * Addon plugins can add pages to the Albert admin menu via the
	 * 'albert_admin_submenu_pages' filter. Each page definition must
	 * include a 'slug' and a callable 'callback'.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function register_addon_admin_pages(): void {
		/**
		 * Filters the list of addon admin submenu page definitions.
		 *
		 * @since 1.1.0
		 *
		 * @param array[] $pages Array of page definitions. Each should have:
		 *                       - string   'slug'       Page slug (required).
		 *                       - callable 'callback'   Render callback (required).
		 *                       - string   'page_title' Browser/page title (optional).
		 *                       - string   'menu_title' Sidebar menu title (optional).
		 *                       - string   'capability' Required capability (optional, default 'manage_options').
		 *                       - int      'position'   Menu position (optional, default 100).
		 */
		$pages = apply_filters( 'albert/admin/submenu_pages', [] );

		if ( ! is_array( $pages ) || empty( $pages ) ) {
			return;
		}

		// Validate and set defaults.
		$valid_pages = [];
		foreach ( $pages as $page ) {
			if ( empty( $page['slug'] ) || ! is_callable( $page['callback'] ?? null ) ) {
				continue;
			}

			$page['position'] = (int) ( $page['position'] ?? 100 );
			$valid_pages[]    = $page;
		}

		// Sort by position.
		usort(
			$valid_pages,
			function ( $a, $b ) {
				return $a['position'] <=> $b['position'];
			}
		);

		foreach ( $valid_pages as $page ) {
			add_submenu_page(
				'albert',
				$page['page_title'] ?? $page['slug'],
				$page['menu_title'] ?? $page['slug'],
				$page['capability'] ?? 'manage_options',
				$page['slug'],
				$page['callback']
			);
		}
	}

	/**
	 * Run one-time cleanup of legacy options when the plugin upgrades.
	 *
	 * Tracks the last-seen plugin version in the `albert_installed_version`
	 * option. When the stored version is lower than the current
	 * {@see ALBERT_VERSION} constant, removes options that no longer drive
	 * any behaviour:
	 *
	 *  - `albert_external_url` — replaced by the `albert/mcp/external_url` filter.
	 *
	 * The stored version is bumped after cleanup so the block only runs once
	 * per upgrade.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	private function maybe_cleanup_legacy_options(): void {
		if ( ! defined( 'ALBERT_VERSION' ) ) {
			return;
		}

		$current_version = (string) ALBERT_VERSION;
		$stored_version  = (string) get_option( 'albert_installed_version', '0.0.0' );

		if ( version_compare( $stored_version, $current_version, '>=' ) ) {
			return;
		}

		// Legacy options removed in 1.1.0 — delete unconditionally, `delete_option()`
		// is a no-op if the option doesn't exist.
		if ( version_compare( $stored_version, '1.1.0', '<' ) ) {
			delete_option( 'albert_external_url' );
		}

		update_option( 'albert_installed_version', $current_version, false );
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
		// Install OAuth database tables.
		OAuthInstaller::install();

		// Install logging database table.
		LoggingInstaller::install();

		// Register OAuth discovery rewrite rules.
		OAuthDiscovery::activate();

		/**
		 * Fires when the plugin is activated.
		 *
		 * @since 1.0.0
		 */
		do_action( 'albert/activated' );
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
		// Clean up OAuth discovery rewrite rules.
		OAuthDiscovery::deactivate();

		/**
		 * Fires when the plugin is deactivated.
		 *
		 * @since 1.0.0
		 */
		do_action( 'albert/deactivated' );
	}
}
