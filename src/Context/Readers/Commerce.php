<?php
/**
 * Commerce reader: the shop's own rules.
 *
 * @package Albert
 * @subpackage Context\Readers
 * @since      1.4.0
 */

namespace Albert\Context\Readers;

defined( 'ABSPATH' ) || exit;

/**
 * Reads WooCommerce's resolved configuration.
 *
 * Currency and tax display are the two facts a model gets wrong most expensively
 * on a shop: quoting a price without its currency, or quoting an ex-VAT figure
 * to a consumer on a shop that displays inclusive prices. Order statuses matter
 * because "mark it complete" means a specific status on this install, including
 * any the shop added itself.
 *
 * Returns null when WooCommerce is not running, which is what lets the Context
 * screen drop the Commerce row entirely rather than show an empty one.
 *
 * @since 1.4.0
 */
class Commerce {

	/**
	 * Build the commerce section, or null when there is no shop.
	 *
	 * @return array<string, mixed>|null
	 * @since 1.4.0
	 */
	public function read(): ?array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return null;
		}

		$commerce = [ 'plugin' => 'WooCommerce' ];

		if ( defined( 'WC_VERSION' ) ) {
			$commerce['version'] = (string) WC_VERSION;
		}

		if ( function_exists( 'get_woocommerce_currency' ) ) {
			$commerce['currency'] = (string) get_woocommerce_currency();
		}

		if ( function_exists( 'wc_prices_include_tax' ) ) {
			$commerce['prices_include_tax'] = (bool) wc_prices_include_tax();
		}

		$catalogue = $this->catalogue_size();
		if ( $catalogue !== null ) {
			$commerce['products'] = $catalogue;
		}

		$statuses = $this->order_statuses();
		if ( $statuses !== [] ) {
			$commerce['order_statuses'] = $statuses;
		}

		return $commerce;
	}

	/**
	 * Whether this site sells anything.
	 *
	 * @return bool True when WooCommerce is running.
	 * @since 1.4.0
	 */
	public function is_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * How many published products the catalogue holds.
	 *
	 * Order of magnitude is the useful signal: a nine-product shop and a
	 * nine-thousand-product shop want different search strategies from the
	 * assistant, so the exact figure is fine and costs one number.
	 *
	 * @return int|null Published product count, or null when there is no product type.
	 * @since 1.4.0
	 */
	private function catalogue_size(): ?int {
		if ( ! post_type_exists( 'product' ) ) {
			return null;
		}

		$counts = wp_count_posts( 'product' );

		return isset( $counts->publish ) ? (int) $counts->publish : null;
	}

	/**
	 * The order statuses this shop actually has.
	 *
	 * Read from WooCommerce rather than hardcoded, so a shop that registered its
	 * own status gets it named. The `wc-` prefix is stripped: it is an internal
	 * storage detail, and an assistant that echoes it back into a filter is
	 * doing the right thing with the wrong string.
	 *
	 * @return list<string> Status slugs without the internal `wc-` prefix.
	 * @since 1.4.0
	 */
	private function order_statuses(): array {
		if ( ! function_exists( 'wc_get_order_statuses' ) ) {
			return [];
		}

		$statuses = wc_get_order_statuses();

		if ( ! is_array( $statuses ) ) {
			return [];
		}

		$slugs = [];
		foreach ( array_keys( $statuses ) as $slug ) {
			$slugs[] = (string) preg_replace( '/^wc-/', '', (string) $slug );
		}

		return $slugs;
	}
}
