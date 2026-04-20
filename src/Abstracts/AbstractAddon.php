<?php
/**
 * Abstract Addon Base Class
 *
 * Every premium Albert addon extends this class. It provides:
 *  - Static addon registry (keyed by slug)
 *  - License helpers
 *  - Singleton pattern
 *
 * The addon's own boot() method handles EDD SL SDK registration for
 * update delivery and license management.
 *
 * @package Albert
 * @subpackage Abstracts
 * @since   1.1.0
 */

namespace Albert\Abstracts;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AbstractAddon class
 *
 * @phpstan-consistent-constructor
 * @since 1.1.0
 */
abstract class AbstractAddon {

	/**
	 * Global addon registry: slug => addon data.
	 *
	 * @since 1.1.0
	 * @var array<string, array{item_id: int, name: string, slug: string, option_slug: string, version: string, file: string, store_url: string}>
	 */
	private static array $registry = [];

	/**
	 * Singleton instances, keyed by class name.
	 *
	 * @since 1.1.0
	 * @var array<class-string, static>
	 */
	private static array $instances = [];

	/**
	 * Plugin data that each addon must define.
	 *
	 * Required keys:
	 *  - item_id   (int)    EDD download ID on the store.
	 *  - name      (string) Human-readable addon name.
	 *  - slug      (string) Plugin slug (e.g. 'extended-service').
	 *  - version   (string) Current version.
	 *  - file      (string) Absolute path to the main plugin file.
	 *  - store_url (string) EDD store URL.
	 *
	 * @since 1.1.0
	 * @var array<string, mixed>
	 */
	protected array $plugin_data = [];

	/**
	 * Get or create the singleton instance.
	 *
	 * @since 1.1.0
	 *
	 * @return static
	 */
	final public static function instance(): static {
		if ( ! isset( self::$instances[ static::class ] ) ) {
			self::$instances[ static::class ] = new static();
		}

		return self::$instances[ static::class ];
	}

	/**
	 * Constructor.
	 *
	 * Registers the addon in the static registry so Albert's
	 * Licenses table and smart activation loop can read it.
	 *
	 * @since 1.1.0
	 */
	protected function __construct() {
		$data = $this->plugin_data;

		self::$registry[ $data['slug'] ] = [
			'item_id'     => (int) $data['item_id'],
			'name'        => $data['name'],
			'slug'        => $data['slug'],
			'option_slug' => basename( dirname( $data['file'] ) ),
			'version'     => $data['version'],
			'file'        => $data['file'],
			'store_url'   => $data['store_url'],
		];
	}

	/**
	 * Hook called after the constructor.
	 *
	 * Addons override this to register their own hooks, abilities,
	 * and EDD SL SDK integration for update delivery.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	abstract public function boot(): void;

	/**
	 * Get all registered addons.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, array{item_id: int, name: string, slug: string, option_slug: string, version: string, file: string, store_url: string}>
	 */
	public static function get_registered_addons(): array {
		return self::$registry;
	}

	/**
	 * Check if this addon has a valid license.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	public function has_valid_license(): bool {
		return albert_has_valid_license( $this->plugin_data['slug'] );
	}
}
