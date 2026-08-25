<?php
/**
 * Skill: one task guide, with the conditions under which it is worth reading.
 *
 * @package Albert
 * @subpackage MCP\Skills
 * @since      1.4.0
 */

namespace Albert\MCP\Skills;

defined( 'ABSPATH' ) || exit;

/**
 * A single registered skill.
 *
 * A skill is a playbook: a Markdown body that teaches an assistant how to do one
 * kind of work on this site properly. Two things make it more than a file.
 *
 * **Preconditions.** A skill declares when it is relevant, and the discovery
 * response lists it only when that holds. The WooCommerce skill on a site with no shop is
 * not merely useless but misleading, because it tells the model about tools
 * that are not there. Conditions are declared, not computed at registration time, because
 * a registration that ran on `plugins_loaded` would be asking questions before
 * the answers exist.
 *
 * **Lazy body.** The index costs one line per skill; the body costs hundreds of
 * tokens and is read only when the assistant asks for it through
 * `albert/get-skill`. So the body is a path resolved on demand, never a string
 * held in memory for every request that only ever lists the names.
 *
 * @since 1.4.0
 */
class Skill {

	/**
	 * Largest skill body we will read from disk, in bytes.
	 *
	 * Bundled skills are a few kilobytes. This guards against a registered path
	 * pointing at something that is not a skill at all.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	public const MAX_BODY_BYTES = 524288;

	/**
	 * Preconditions this class knows how to answer itself.
	 *
	 * A vocabulary rather than a callback for the common cases, so a skill can
	 * be registered from a data array, which is what lets an add-on ship one
	 * without writing a closure, and what lets a bundled skill declare its
	 * condition in its own frontmatter.
	 *
	 * Must list exactly the keys {@see self::condition_definitions()} defines.
	 * A literal array, not derived from that method, because PHP class
	 * constants cannot be initialised from a method call; nothing in this
	 * codebase reads this constant to validate a condition name (that
	 * happens in {@see self::condition_holds()} against the method instead),
	 * so it exists purely as the documented vocabulary and must be kept in
	 * sync by hand when a condition is added or removed.
	 *
	 * @since 1.4.0
	 * @var list<string>
	 */
	public const KNOWN_CONDITIONS = [ 'woocommerce', 'block_editor', 'classic_editor', 'multisite' ];

	/**
	 * Construct a skill.
	 *
	 * Promoted properties, and the only class in `src/` that uses them: six
	 * immutable fields with no behaviour of their own is exactly the shape
	 * promotion is for, and `readonly` is what lets the registry hand the same
	 * instance to three callers without any of them being able to change it.
	 *
	 * @param string        $slug     Stable identifier, and the argument `albert/get-skill` takes.
	 * @param string        $summary  One sentence for the discovery response's skills index.
	 * @param string        $file     Absolute path to the Markdown body. Empty when `$body` is given.
	 * @param string        $body     Literal body, for skills not backed by a file.
	 * @param list<string>  $requires Named preconditions from {@see self::KNOWN_CONDITIONS}.
	 * @param callable|null $when     Extra precondition for anything the vocabulary cannot express.
	 * @param string        $source   Who ships this skill, for the Skills screen's source badge.
	 *                                Empty when the registration did not say, the Skills screen falls
	 *                                back to a generic "Add-on" label rather than guessing a name.
	 *
	 * @since 1.4.0
	 */
	public function __construct(
		private readonly string $slug,
		private readonly string $summary = '',
		private readonly string $file = '',
		private readonly string $body = '',
		private readonly array $requires = [],
		private readonly mixed $when = null,
		private readonly string $source = ''
	) {
	}

	/**
	 * Build a skill from a plain array, as an add-on registers it.
	 *
	 * Returns null when the array cannot make a usable skill: no slug, or
	 * neither a body nor a file, so one malformed registration is skipped
	 * rather than breaking the whole index.
	 *
	 * @param array<string, mixed> $definition Registration array.
	 *
	 * @return self|null The skill, or null when the definition cannot make a usable one.
	 * @since 1.4.0
	 */
	public static function from_array( array $definition ): ?self {
		$slug = isset( $definition['slug'] ) ? (string) $definition['slug'] : '';

		if ( $slug === '' ) {
			return null;
		}

		$file = isset( $definition['file'] ) ? (string) $definition['file'] : '';
		$body = isset( $definition['body'] ) ? (string) $definition['body'] : '';

		if ( $file === '' && trim( $body ) === '' ) {
			return null;
		}

		$requires = [];
		if ( isset( $definition['requires'] ) ) {
			$declared = is_array( $definition['requires'] ) ? $definition['requires'] : [ $definition['requires'] ];
			$requires = array_values( array_map( 'strval', $declared ) );
		}

		$when = isset( $definition['when'] ) && is_callable( $definition['when'] ) ? $definition['when'] : null;

		return new self(
			$slug,
			isset( $definition['summary'] ) ? (string) $definition['summary'] : '',
			$file,
			$body,
			$requires,
			$when,
			isset( $definition['source'] ) ? (string) $definition['source'] : ''
		);
	}

	/**
	 * The skill's stable identifier.
	 *
	 * @return string The slug, as `albert/get-skill` takes it.
	 * @since 1.4.0
	 */
	public function slug(): string {
		return $this->slug;
	}

	/**
	 * The one-line summary shown in the discovery response.
	 *
	 * @return string The summary, or an empty string.
	 * @since 1.4.0
	 */
	public function summary(): string {
		return $this->summary;
	}

	/**
	 * The declared preconditions.
	 *
	 * @return list<string> Declared precondition names, unvalidated.
	 * @since 1.4.0
	 */
	public function requires(): array {
		return $this->requires;
	}

	/**
	 * Who ships this skill, for the Skills screen's source badge.
	 *
	 * Bundled skills declare `'Albert'` explicitly (see {@see
	 * SkillRegistry::bundled()}); a skill added through the registry filter
	 * without a `source` falls back to a generic label rather than being
	 * mislabelled as one Albert did not ship.
	 *
	 * @return string The source label.
	 * @since 1.4.0
	 */
	public function source(): string {
		return $this->source !== '' ? $this->source : __( 'Add-on', 'albert-ai-butler' );
	}

	/**
	 * Whether this skill is worth listing on this site right now.
	 *
	 * @return bool True when every declared precondition holds.
	 * @since 1.4.0
	 */
	public function is_available(): bool {
		return $this->status()['available'];
	}

	/**
	 * The live precondition status, for the Skills screen.
	 *
	 * The single place that turns "does this apply here" into both the boolean
	 * {@see self::is_available()} relies on and the reason a site owner reads
	 * as the enabled toggle's help text ("WooCommerce is active" / "Requires
	 * the block editor"), so the two can never drift apart.
	 *
	 * The label states only the reason, not the on/off state itself: the
	 * toggle it sits under already shows enabled or disabled, so repeating
	 * that in words would say the same thing twice, once visually and once as
	 * text a screen reader would read right after the switch's own state.
	 *
	 * An unknown condition name fails closed. A skill that declares
	 * `requires: shopify` is asking a question this vocabulary cannot answer,
	 * and treating it as met anyway would be answering "yes" to a question
	 * nobody understood.
	 *
	 * Every unmet reason is reported at once, `requires` entries and a failing
	 * `when` alike: a skill declaring two preconditions, or one named
	 * precondition plus a `when`, that all fail should not need a second look
	 * after the site owner fixes only the first one named.
	 *
	 * "Always enabled." is reserved for a skill with no `requires` and no
	 * `when` at all. One gated only by a currently-passing `when` callable can
	 * still flip to disabled on the next request, so it gets a label that
	 * says so rather than a promise the class cannot keep.
	 *
	 * @return array{available: bool, label: string}
	 * @since 1.4.0
	 */
	public function status(): array {
		$unmet = [];

		foreach ( $this->requires as $condition ) {
			if ( ! $this->condition_holds( $condition ) ) {
				$unmet[] = self::requirement_label( $condition );
			}
		}

		$when_unmet = is_callable( $this->when ) && ! call_user_func( $this->when );

		if ( $unmet !== [] || $when_unmet ) {
			$label = $unmet !== []
				? sprintf(
					/* translators: %s: the unmet preconditions, comma-separated, e.g. "WooCommerce, the block editor". */
					__( 'Requires %s.', 'albert-ai-butler' ),
					implode( ', ', $unmet )
				)
				: '';

			if ( $when_unmet ) {
				$label = $label !== ''
					? $label . ' ' . __( "A site condition isn't met.", 'albert-ai-butler' )
					: __( "A site condition isn't met.", 'albert-ai-butler' );
			}

			return [
				'available' => false,
				'label'     => $label,
			];
		}

		if ( $this->requires === [] ) {
			return [
				'available' => true,
				'label'     => is_callable( $this->when )
					? __( 'A site condition is currently met.', 'albert-ai-butler' )
					: __( 'Always enabled.', 'albert-ai-butler' ),
			];
		}

		return [
			'available' => true,
			'label'     => sprintf(
				/* translators: %s: the met preconditions, e.g. "WooCommerce is active". */
				__( '%s.', 'albert-ai-butler' ),
				implode( ', ', array_map( [ self::class, 'active_label' ], $this->requires ) )
			),
		];
	}

	/**
	 * The full Markdown body.
	 *
	 * Read from disk on demand. Returns an empty string when the file is
	 * missing, unreadable or implausibly large, the caller turns that into an
	 * error the assistant can act on, rather than a blank success.
	 *
	 * @return string The Markdown body, or an empty string when it cannot be read.
	 * @since 1.4.0
	 */
	public function body(): string {
		if ( $this->body !== '' ) {
			return trim( $this->body );
		}

		if ( $this->file === '' || ! is_file( $this->file ) || ! is_readable( $this->file ) ) {
			return '';
		}

		$size = filesize( $this->file );
		if ( $size === false || $size > self::MAX_BODY_BYTES ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a registered local skill file, not a remote resource.
		$contents = file_get_contents( $this->file );

		if ( $contents === false ) {
			return '';
		}

		return trim( ( new SkillFileParser() )->parse( $contents )['body'] );
	}

	/**
	 * Per-request memo of {@see self::condition_definitions()}.
	 *
	 * @since 1.4.1
	 * @var array<string, array{check: callable(): bool, requirement: string, active: string}>|null
	 */
	private static ?array $condition_definitions = null;

	/**
	 * Everything this class knows about each named precondition: how to check
	 * it, and its two phrasings (a requirement, "Requires %s.", and the
	 * reason it currently holds, "%s.").
	 *
	 * One map instead of three parallel switches over the same vocabulary, so
	 * a fifth condition (the vocabulary is expected to grow, see {@see
	 * self::KNOWN_CONDITIONS}) is one array entry to add, not three switch
	 * statements to keep in sync by hand. Built once per request: `status()`
	 * calls into this map up to three times per skill, once per requires
	 * entry evaluated, and every skill's status is computed on every Skills
	 * screen load and every MCP discovery call.
	 *
	 * @return array<string, array{check: callable(): bool, requirement: string, active: string}>
	 * @since 1.4.1
	 */
	private static function condition_definitions(): array {
		if ( self::$condition_definitions !== null ) {
			return self::$condition_definitions;
		}

		self::$condition_definitions = [
			'woocommerce'    => [
				'check'       => static fn (): bool => class_exists( 'WooCommerce' ),
				'requirement' => __( 'WooCommerce', 'albert-ai-butler' ),
				'active'      => __( 'WooCommerce is active', 'albert-ai-butler' ),
			],
			// Site-wide rather than per-post: the index is assembled once at
			// discovery, before any target is known.
			'block_editor'   => [
				'check'       => static fn (): bool => \Albert\Blocks\EditorMode::editor( 'post' ) === 'block'
					|| \Albert\Blocks\EditorMode::editor( 'page' ) === 'block',
				'requirement' => __( 'the block editor', 'albert-ai-butler' ),
				'active'      => __( 'the block editor is active', 'albert-ai-butler' ),
			],
			'classic_editor' => [
				'check'       => static fn (): bool => \Albert\Blocks\EditorMode::editor( 'post' ) === 'classic'
					|| \Albert\Blocks\EditorMode::editor( 'page' ) === 'classic',
				'requirement' => __( 'the classic editor', 'albert-ai-butler' ),
				'active'      => __( 'the classic editor is active', 'albert-ai-butler' ),
			],
			'multisite'      => [
				'check'       => static fn (): bool => function_exists( 'is_multisite' ) && is_multisite(),
				'requirement' => __( 'a multisite network', 'albert-ai-butler' ),
				'active'      => __( 'this is a multisite network', 'albert-ai-butler' ),
			],
		];

		return self::$condition_definitions;
	}

	/**
	 * Answer one named precondition.
	 *
	 * @param string $condition Condition name.
	 *
	 * @return bool True when the condition holds. False for a name this class does not know.
	 * @since 1.4.0
	 */
	private function condition_holds( string $condition ): bool {
		$definition = self::condition_definitions()[ $condition ] ?? null;

		return $definition !== null && ( $definition['check'] )();
	}

	/**
	 * A condition's name, phrased as a requirement ("Requires %s.").
	 *
	 * @param string $condition Condition name.
	 *
	 * @return string Human-readable phrase. The condition name itself for one this class does not know.
	 * @since 1.4.0
	 */
	private static function requirement_label( string $condition ): string {
		return self::condition_definitions()[ $condition ]['requirement'] ?? $condition;
	}

	/**
	 * A condition's name, phrased as the reason it currently holds ("%s.").
	 *
	 * @param string $condition Condition name.
	 *
	 * @return string Human-readable phrase. The condition name itself for one this class does not know.
	 * @since 1.4.0
	 */
	private static function active_label( string $condition ): string {
		return self::condition_definitions()[ $condition ]['active'] ?? $condition;
	}
}
