<?php
/**
 * Dashboard add-on recommendations
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.4.0
 */

namespace Albert\Admin\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Add-ons worth mentioning, based on what this site actually runs.
 *
 * A shop owner learning that Albert speaks WooCommerce is being told something
 * useful. Everybody else seeing the same card is being advertised at. The
 * difference is entirely whether the host plugin is present, so that is the
 * only thing that decides whether this renders.
 *
 * Four rules keep it a feature rather than an advert, and all four are enforced
 * here rather than left to the caller:
 *
 * - The host plugin must genuinely be active, detected by a symbol it defines.
 * - The add-on must not already be installed. Selling somebody what they own is
 *   the fastest way to make a screen untrustworthy.
 * - One at a time. A stack of recommendations is a shop, not a dashboard.
 *
 * There is deliberately no "no thanks" control. The card already answers to the
 * only thing that should silence it: install the add-on, or stop running the
 * plugin it is about, and it goes. A dismiss link adds a control whose whole
 * job is to hide something the screen should not be showing in the first place.
 *
 * Entries are data, so Elementor becomes another row here rather than another
 * block of markup somewhere.
 *
 * @since 1.4.0
 */
class Recommendations {

	/**
	 * The most cards to show at once.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	private const LIMIT = 2;

	/**
	 * The recommendations worth making right now.
	 *
	 * @since 1.4.0
	 *
	 * @param int $user_id User the card is for. 0 for the current user.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function current( int $user_id = 0 ): array {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		$found   = [];

		foreach ( $this->all() as $addon ) {
			if ( ! $this->is_relevant( $addon ) ) {
				continue;
			}

			$addon['state'] = AddonState::of(
				isset( $addon['addon_symbol'] ) ? (string) $addon['addon_symbol'] : '',
				isset( $addon['plugin_file'] ) ? (string) $addon['plugin_file'] : ''
			);

			$found[] = $addon;
		}

		// Capped, because a column of add-on cards is a shop rather than a
		// dashboard. Two is the honest maximum: the one that applies to every
		// site, and the one that applies to this one.
		return array_slice( $found, 0, self::LIMIT );
	}

	/**
	 * Every registered recommendation, relevant or not.
	 *
	 * @since 1.4.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		/**
		 * Filters the add-ons Albert may recommend.
		 *
		 * Each entry is `slug`, `name`, `because` (why this site is being shown
		 * it), `detail` (what the add-on does), `url`, `icon` (a dashicon name
		 * without the prefix), `plugin_file` (so an installed-but-deactivated
		 * add-on can be told apart from a missing one), and the detection pair
		 * `host_symbol` / `addon_symbol`: a class, function or constant that
		 * exists when the host plugin is active, and one that exists when the
		 * add-on is already installed.
		 *
		 * Detection is by symbol rather than plugin path because a plugin can
		 * be installed under any directory name.
		 *
		 * @since 1.4.0
		 *
		 * @param array<int, array<string, mixed>> $addons Registered recommendations.
		 */
		$addons = apply_filters( 'albert/dashboard/recommendations', $this->defaults() );

		return is_array( $addons ) ? $addons : [];
	}

	/**
	 * Whether this site should be shown this recommendation.
	 *
	 * @since 1.4.0
	 *
	 * @param array<string, mixed> $addon Recommendation.
	 *
	 * @return bool
	 */
	private function is_relevant( array $addon ): bool {
		if ( empty( $addon['slug'] ) || empty( $addon['name'] ) ) {
			return false;
		}

		$host  = isset( $addon['host_symbol'] ) ? (string) $addon['host_symbol'] : '';
		$owned = isset( $addon['addon_symbol'] ) ? (string) $addon['addon_symbol'] : '';

		// An empty host means the add-on applies to every site. Premium is the
		// only one of those: WooCommerce's add-on is worth mentioning to a shop
		// and nobody else, but there is no site that cannot use more history.
		$host_present = $host === '' || $this->symbol_exists( $host );

		// Silent only once the add-on is actually running. Installed-but-off is
		// still worth a card, and it is the one case where the right words are
		// "switch it on" rather than "buy it".
		return $host_present && ! ( $owned !== '' && $this->symbol_exists( $owned ) );
	}

	/**
	 * Whether a class, interface, function or constant of this name exists.
	 *
	 * @since 1.4.0
	 *
	 * @param string $symbol Symbol name.
	 *
	 * @return bool
	 */
	private function symbol_exists( string $symbol ): bool {
		return class_exists( $symbol ) || interface_exists( $symbol ) || function_exists( $symbol ) || defined( $symbol );
	}

	/**
	 * What Free knows how to recommend.
	 *
	 * @since 1.4.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function defaults(): array {
		return [
			[
				'slug'         => 'albert-premium-service',
				'name'         => __( 'Albert Premium Service', 'albert-ai-butler' ),
				'icon'         => 'backup',
				'because'      => __( 'Get more from Albert', 'albert-ai-butler' ),
				'detail'       => __( 'Keeps every action for as long as you choose, with filters and the full detail of what was sent and returned.', 'albert-ai-butler' ),
				'url'          => 'https://albertwp.com/add-ons/premium-service/',
				// No host: there is no site this does not apply to.
				'host_symbol'  => '',
				'addon_symbol' => 'AlbertPremium\\AlbertPremiumService',
				'plugin_file'  => 'albert-premium-service/albert-premium-service.php',
			],
			[
				'slug'         => 'albert-woocommerce',
				'name'         => __( 'Albert for WooCommerce', 'albert-ai-butler' ),
				// The add-on's own subject, not a generic plug. A recommendation
				// that looks like every other recommendation is an advert.
				'icon'         => 'cart',
				'because'      => __( 'Because you run WooCommerce', 'albert-ai-butler' ),
				'detail'       => __( 'Lets an assistant read orders, products and customers, with payment details stripped before they ever leave your site.', 'albert-ai-butler' ),
				'url'          => 'https://albertwp.com/add-ons/woocommerce/',
				'host_symbol'  => 'WooCommerce',
				'plugin_file'  => 'albert-woocommerce/albert-woocommerce.php',
				// Verified against the add-on itself: albert-woocommerce declares
				// `namespace AlbertWooCommerce;`, not `Albert\WooCommerce`. Guessing
				// it wrong is not a harmless typo, it means the card keeps selling
				// the add-on to people who already installed it.
				'addon_symbol' => 'AlbertWooCommerce\AlbertWooCommerceService',
			],
		];
	}
}
