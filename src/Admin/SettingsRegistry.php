<?php
/**
 * Settings Registry
 *
 * Singleton that collects settings sections registered by Free and addons.
 *
 * @package    Albert
 * @subpackage Admin
 * @since      1.1.0
 */

declare(strict_types=1);

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * SettingsRegistry class.
 *
 * Holds the set of unified settings sections rendered on the Albert
 * Settings page. Sections are validated at registration time; invalid
 * sections or fields are dropped with `_doing_it_wrong()` so addon
 * authors get loud feedback during development.
 *
 * @since 1.1.0
 */
class SettingsRegistry {

	/**
	 * Singleton instance.
	 *
	 * @since 1.1.0
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Registered sections, keyed by section id.
	 *
	 * Insertion order is preserved so it can be used as a tiebreaker when
	 * sorting by priority.
	 *
	 * @since 1.1.0
	 * @var array<string, array<string, mixed>>
	 */
	private array $sections = [];

	/**
	 * Monotonic counter to record registration order per section.
	 *
	 * Used as the tiebreaker when two sections share the same priority.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	private int $sequence = 0;

	/**
	 * Private constructor — use {@see self::instance()}.
	 *
	 * @since 1.1.0
	 */
	private function __construct() {
	}

	/**
	 * Get the singleton instance.
	 *
	 * @since 1.1.0
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Reset the registry. Intended for tests only.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * Register a settings section.
	 *
	 * Validates the section schema and drops invalid fields. If the section
	 * id collides with an already-registered section, the new registration
	 * overwrites the previous one (last-write-wins) — this makes it possible
	 * for an addon to replace a Free-registered section by re-registering
	 * with the same id.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $section Section configuration.
	 *
	 * @return void
	 */
	public function register_section( array $section ): void {
		$id = isset( $section['id'] ) && is_string( $section['id'] ) ? $section['id'] : '';
		if ( $id === '' ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html__( 'Settings section requires a non-empty string "id".', 'albert-ai-butler' ),
				'1.1.0'
			);
			return;
		}

		// Namespacing requirement — keeps Free / addon sections distinct.
		if ( strpos( $id, '/' ) === false ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: %s: section id */
					esc_html__( 'Settings section id "%s" must be namespaced (contain a "/"), e.g. "albert/mcp".', 'albert-ai-butler' ),
					esc_html( $id )
				),
				'1.1.0'
			);
			return;
		}

		$title = isset( $section['title'] ) && is_string( $section['title'] ) ? $section['title'] : '';
		if ( $title === '' ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: %s: section id */
					esc_html__( 'Settings section "%s" requires a non-empty "title".', 'albert-ai-butler' ),
					esc_html( $id )
				),
				'1.1.0'
			);
			return;
		}

		if ( ! isset( $section['fields'] ) || ! is_array( $section['fields'] ) || empty( $section['fields'] ) ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: %s: section id */
					esc_html__( 'Settings section "%s" requires a non-empty "fields" array.', 'albert-ai-butler' ),
					esc_html( $id )
				),
				'1.1.0'
			);
			return;
		}

		$valid_fields = [];
		foreach ( $section['fields'] as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$validated = $this->validate_field( $id, $field );
			if ( $validated !== null ) {
				$valid_fields[] = $validated;
			}
		}

		if ( empty( $valid_fields ) ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: %s: section id */
					esc_html__( 'Settings section "%s" was registered with no valid fields.', 'albert-ai-butler' ),
					esc_html( $id )
				),
				'1.1.0'
			);
			return;
		}

		$normalised = [
			'id'          => $id,
			'title'       => $title,
			'description' => isset( $section['description'] ) && is_string( $section['description'] ) ? $section['description'] : '',
			'priority'    => isset( $section['priority'] ) && is_int( $section['priority'] ) ? $section['priority'] : 10,
			'show_if'     => isset( $section['show_if'] ) && is_callable( $section['show_if'] ) ? $section['show_if'] : null,
			'icon'        => isset( $section['icon'] ) && is_string( $section['icon'] ) ? $section['icon'] : '',
			'badge'       => isset( $section['badge'] ) && is_string( $section['badge'] ) ? $section['badge'] : '',
			'capability'  => isset( $section['capability'] ) && is_string( $section['capability'] ) && $section['capability'] !== ''
				? $section['capability']
				: 'manage_options',
			'fields'      => $valid_fields,
			'_sequence'   => $this->sequence++,
		];

		// Last-write-wins on id collision — allows addons to replace a Free section.
		$this->sections[ $id ] = $normalised;
	}

	/**
	 * Register a section only if no section with this id exists yet.
	 *
	 * Used by {@see albert_register_setting()} to lazily create the synthetic
	 * `albert/settings` section on first call. Calling with a section id that
	 * already exists is a no-op.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $section Section configuration (see register_section()).
	 *
	 * @return void
	 */
	public function ensure_section_exists( array $section ): void {
		$id = isset( $section['id'] ) && is_string( $section['id'] ) ? $section['id'] : '';
		if ( $id === '' ) {
			return;
		}
		if ( isset( $this->sections[ $id ] ) ) {
			return;
		}

		// Register expects a non-empty fields array — seed with a placeholder that
		// will be replaced the first time append_field_to_section() is called.
		// We bypass register_section() because it enforces that invariant, and use
		// the normalised internal shape directly.
		$this->sections[ $id ] = [
			'id'          => $id,
			'title'       => isset( $section['title'] ) && is_string( $section['title'] ) ? $section['title'] : '',
			'description' => isset( $section['description'] ) && is_string( $section['description'] ) ? $section['description'] : '',
			'priority'    => isset( $section['priority'] ) && is_int( $section['priority'] ) ? $section['priority'] : 10,
			'show_if'     => isset( $section['show_if'] ) && is_callable( $section['show_if'] ) ? $section['show_if'] : null,
			'icon'        => isset( $section['icon'] ) && is_string( $section['icon'] ) ? $section['icon'] : '',
			'badge'       => isset( $section['badge'] ) && is_string( $section['badge'] ) ? $section['badge'] : '',
			'capability'  => isset( $section['capability'] ) && is_string( $section['capability'] ) && $section['capability'] !== ''
				? $section['capability']
				: 'manage_options',
			'fields'      => [],
			'_sequence'   => $this->sequence++,
		];
	}

	/**
	 * Append a field to an existing section.
	 *
	 * Used by the simplified add-on API (see {@see albert_register_setting()}).
	 * The field is validated via the same path used for `register_section()`
	 * and invalid fields are dropped with `_doing_it_wrong()` so add-on
	 * authors get loud feedback during development.
	 *
	 * @since 1.1.0
	 *
	 * @param string               $section_id Target section id (must already exist).
	 * @param array<string, mixed> $field      Raw field definition.
	 *
	 * @return void
	 */
	public function append_field_to_section( string $section_id, array $field ): void {
		if ( ! isset( $this->sections[ $section_id ] ) ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: %s: section id */
					esc_html__( 'Cannot append field: settings section "%s" is not registered.', 'albert-ai-butler' ),
					esc_html( $section_id )
				),
				'1.1.0'
			);
			return;
		}

		$validated = $this->validate_field( $section_id, $field );
		if ( $validated === null ) {
			return;
		}

		$this->sections[ $section_id ]['fields'][] = $validated;
	}

	/**
	 * Get all registered sections, sorted by priority then registration order.
	 *
	 * @since 1.1.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_sections(): array {
		$sections = array_values( $this->sections );

		usort(
			$sections,
			static function ( array $a, array $b ): int {
				$pa = isset( $a['priority'] ) && is_int( $a['priority'] ) ? $a['priority'] : 10;
				$pb = isset( $b['priority'] ) && is_int( $b['priority'] ) ? $b['priority'] : 10;
				if ( $pa !== $pb ) {
					return $pa <=> $pb;
				}
				$sa = isset( $a['_sequence'] ) && is_int( $a['_sequence'] ) ? $a['_sequence'] : 0;
				$sb = isset( $b['_sequence'] ) && is_int( $b['_sequence'] ) ? $b['_sequence'] : 0;
				return $sa <=> $sb;
			}
		);

		return $sections;
	}

	/**
	 * Compute the wp_options key for a field.
	 *
	 * Replaces "/" with "_" in the section id so the resulting option name is
	 * a safe wp_options key (e.g. section "albert/mcp" + field "external_url"
	 * yields "albert_mcp_external_url"). Pass an explicit `$override` to keep
	 * compatibility with legacy option names — see the field schema's
	 * `option_name` key.
	 *
	 * @since 1.1.0
	 *
	 * @param string      $section_id Section id (must contain "/").
	 * @param string      $field_id   Field id.
	 * @param string|null $override   Optional explicit option name to use verbatim.
	 *
	 * @return string
	 */
	public static function get_option_name( string $section_id, string $field_id, ?string $override = null ): string {
		if ( $override !== null && $override !== '' ) {
			return $override;
		}

		$prefix = str_replace( '/', '_', $section_id );

		return $prefix . '_' . $field_id;
	}

	/**
	 * Validate and normalise a single field definition.
	 *
	 * @since 1.1.0
	 *
	 * @param string               $section_id Owning section id (used for error messages only).
	 * @param array<string, mixed> $field      Raw field definition.
	 *
	 * @return array<string, mixed>|null Normalised field, or null if invalid.
	 */
	private function validate_field( string $section_id, array $field ): ?array {
		$id = isset( $field['id'] ) && is_string( $field['id'] ) ? $field['id'] : '';
		if ( $id === '' ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: %s: section id */
					esc_html__( 'Settings field in section "%s" requires a non-empty "id".', 'albert-ai-butler' ),
					esc_html( $section_id )
				),
				'1.1.0'
			);
			return null;
		}

		$type          = isset( $field['type'] ) && is_string( $field['type'] ) ? $field['type'] : '';
		$allowed_types = [ 'text', 'url', 'number', 'textarea', 'select', 'checkbox', 'custom' ];
		if ( ! in_array( $type, $allowed_types, true ) ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: 1: field id, 2: section id */
					esc_html__( 'Settings field "%1$s" in section "%2$s" has an invalid or missing "type".', 'albert-ai-butler' ),
					esc_html( $id ),
					esc_html( $section_id )
				),
				'1.1.0'
			);
			return null;
		}

		$label = isset( $field['label'] ) && is_string( $field['label'] ) ? $field['label'] : '';
		if ( $label === '' && $type !== 'custom' ) {
			// Allow empty label for custom (e.g. licenses table where the section title is enough).
			_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: 1: field id, 2: section id */
					esc_html__( 'Settings field "%1$s" in section "%2$s" requires a non-empty "label".', 'albert-ai-butler' ),
					esc_html( $id ),
					esc_html( $section_id )
				),
				'1.1.0'
			);
			return null;
		}

		if ( $type === 'custom' ) {
			if ( ! isset( $field['render_callback'] ) || ! is_callable( $field['render_callback'] ) ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: 1: field id, 2: section id */
						esc_html__( 'Custom settings field "%1$s" in section "%2$s" requires a callable "render_callback".', 'albert-ai-butler' ),
						esc_html( $id ),
						esc_html( $section_id )
					),
					'1.1.0'
				);
				return null;
			}
			if ( ! isset( $field['sanitize_callback'] ) || ! is_callable( $field['sanitize_callback'] ) ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: 1: field id, 2: section id */
						esc_html__( 'Custom settings field "%1$s" in section "%2$s" requires a callable "sanitize_callback".', 'albert-ai-butler' ),
						esc_html( $id ),
						esc_html( $section_id )
					),
					'1.1.0'
				);
				return null;
			}
		}

		if ( $type === 'select' ) {
			if ( ! isset( $field['options'] ) || ! is_array( $field['options'] ) || empty( $field['options'] ) ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: 1: field id, 2: section id */
						esc_html__( 'Select field "%1$s" in section "%2$s" requires a non-empty "options" array.', 'albert-ai-butler' ),
						esc_html( $id ),
						esc_html( $section_id )
					),
					'1.1.0'
				);
				return null;
			}
		}

		$normalised = [
			'id'                => $id,
			'type'              => $type,
			'label'             => $label,
			'description'       => isset( $field['description'] ) && is_string( $field['description'] ) ? $field['description'] : '',
			'default'           => array_key_exists( 'default', $field ) ? $field['default'] : null,
			'show_if'           => isset( $field['show_if'] ) && is_callable( $field['show_if'] ) ? $field['show_if'] : null,
			'render_callback'   => isset( $field['render_callback'] ) && is_callable( $field['render_callback'] ) ? $field['render_callback'] : null,
			'sanitize_callback' => isset( $field['sanitize_callback'] ) && is_callable( $field['sanitize_callback'] ) ? $field['sanitize_callback'] : null,
			'options'           => isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : [],
			'attributes'        => isset( $field['attributes'] ) && is_array( $field['attributes'] ) ? $field['attributes'] : [],
			'badge'             => isset( $field['badge'] ) && is_string( $field['badge'] ) ? $field['badge'] : '',
			'option_name'       => isset( $field['option_name'] ) && is_string( $field['option_name'] ) && $field['option_name'] !== ''
				? $field['option_name']
				: null,
		];

		return $normalised;
	}
}
