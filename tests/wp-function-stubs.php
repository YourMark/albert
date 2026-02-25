<?php
/**
 * Minimal WordPress function stubs for unit tests.
 *
 * Provides lightweight implementations of WordPress core functions
 * used by the plugin. Only loaded when the real functions are not available
 * (i.e. in unit tests that run without a full WordPress environment).
 *
 * These are intentionally minimal stubs, not full WordPress re-implementations.
 *
 * @package Albert\Tests
 */

// -- Block serialisation (wp-includes/blocks.php) --

if ( ! function_exists( 'strip_core_block_namespace' ) ) {
	function strip_core_block_namespace( $block_name = null ) {
		if ( is_string( $block_name ) && str_starts_with( $block_name, 'core/' ) ) {
			return substr( $block_name, 5 );
		}
		return $block_name;
	}
}

if ( ! function_exists( 'serialize_block_attributes' ) ) {
	function serialize_block_attributes( $block_attributes ) {
		$encoded = json_encode( $block_attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$encoded = preg_replace( '/--/', '\\u002d\\u002d', $encoded );
		$encoded = preg_replace( '/</', '\\u003c', $encoded );
		$encoded = preg_replace( '/>/', '\\u003e', $encoded );
		$encoded = preg_replace( '/&/', '\\u0026', $encoded );
		$encoded = preg_replace( '/\\\\\"/', '\\u0022', $encoded );
		return $encoded;
	}
}

if ( ! function_exists( 'get_comment_delimited_block_content' ) ) {
	function get_comment_delimited_block_content( $block_name, $block_attributes, $block_content ) {
		if ( is_null( $block_name ) ) {
			return $block_content;
		}

		$serialized_block_name = strip_core_block_namespace( $block_name );
		$serialized_attributes = empty( $block_attributes )
			? ''
			: serialize_block_attributes( $block_attributes ) . ' ';

		if ( empty( $block_content ) ) {
			return sprintf( '<!-- wp:%s %s/-->', $serialized_block_name, $serialized_attributes );
		}

		return sprintf(
			'<!-- wp:%s %s-->%s<!-- /wp:%s -->',
			$serialized_block_name,
			$serialized_attributes,
			$block_content,
			$serialized_block_name
		);
	}
}

// -- Escaping (wp-includes/formatting.php) --

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		// Simplified stub — real WP version does more validation.
		return filter_var( $url, FILTER_SANITIZE_URL );
	}
}

// -- Sanitisation helpers --

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		return strtolower( trim( preg_replace( '/[^a-zA-Z0-9-]+/', '-', $title ), '-' ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}

// -- URL helpers --

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

// -- oEmbed stubs (controllable via globals) --

if ( ! function_exists( 'wp_oembed_get' ) ) {
	/**
	 * Stub for wp_oembed_get.
	 *
	 * Set $GLOBALS['albert_test_oembed_urls'][ $url ] = '<html>' to make
	 * a URL return embed HTML, or leave unset to return false.
	 */
	function wp_oembed_get( $url ) {
		if ( isset( $GLOBALS['albert_test_oembed_urls'][ $url ] ) ) {
			return $GLOBALS['albert_test_oembed_urls'][ $url ];
		}
		return false;
	}
}

if ( ! function_exists( '_wp_oembed_get_object' ) ) {
	/**
	 * Stub for _wp_oembed_get_object.
	 *
	 * Set $GLOBALS['albert_test_oembed_data'][ $url ] to a stdClass with
	 * type, provider_name, width, height to control oEmbed metadata.
	 */
	function _wp_oembed_get_object() {
		return new class() {
			public function get_data( $url, $args ) {
				if ( isset( $GLOBALS['albert_test_oembed_data'][ $url ] ) ) {
					return $GLOBALS['albert_test_oembed_data'][ $url ];
				}
				return false;
			}
		};
	}
}
