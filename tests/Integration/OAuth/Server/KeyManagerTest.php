<?php
/**
 * Integration tests for the OAuth KeyManager.
 *
 * KeyManager touches both WP options and OpenSSL. These tests run against a
 * real WP environment and require OpenSSL to be available (it is on every
 * CI platform we target; a skip guards local environments without it).
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\OAuth\Server;

use Albert\OAuth\Server\KeyManager;
use Albert\Tests\TestCase;

/**
 * KeyManager integration tests.
 *
 * @covers \Albert\OAuth\Server\KeyManager
 */
class KeyManagerTest extends TestCase {

	/**
	 * Clear every KeyManager option before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			$this->markTestSkipped( 'OpenSSL is not available.' );
		}

		KeyManager::delete_keys();
	}

	/**
	 * Restore a clean state so other OAuth tests that need keys start fresh.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		KeyManager::delete_keys();
		parent::tear_down();
	}

	// ─── encryption key ──────────────────────────────────────────────

	/**
	 * Generates and caches a non-empty encryption key on first access.
	 *
	 * @return void
	 */
	public function test_get_encryption_key_generates_and_caches(): void {
		$this->assertFalse( get_option( KeyManager::ENCRYPTION_KEY_OPTION ) );

		$first = KeyManager::get_encryption_key();

		$this->assertNotEmpty( $first );
		$this->assertSame( $first, get_option( KeyManager::ENCRYPTION_KEY_OPTION ) );

		// Second call returns the cached value, not a new one.
		$this->assertSame( $first, KeyManager::get_encryption_key() );
	}

	// ─── RSA key pair ────────────────────────────────────────────────

	/**
	 * Generates a valid RSA private/public pair on first access.
	 *
	 * @return void
	 */
	public function test_get_private_key_generates_valid_pem(): void {
		$private = KeyManager::get_private_key();
		$public  = KeyManager::get_public_key();

		$this->assertStringContainsString( '-----BEGIN PRIVATE KEY-----', $private );
		$this->assertStringContainsString( '-----BEGIN PUBLIC KEY-----', $public );

		// The public key parses under OpenSSL — i.e., it is actually valid.
		$this->assertNotFalse( openssl_pkey_get_public( $public ) );
		$this->assertNotFalse( openssl_pkey_get_private( $private ) );
	}

	/**
	 * The generated private and public key are from the same pair.
	 *
	 * @return void
	 */
	public function test_private_and_public_key_match(): void {
		$private = KeyManager::get_private_key();
		$public  = KeyManager::get_public_key();

		$data      = 'albert-key-pair-roundtrip';
		$signature = '';
		openssl_sign( $data, $signature, $private, OPENSSL_ALGO_SHA256 );

		$this->assertSame( 1, openssl_verify( $data, $signature, $public, OPENSSL_ALGO_SHA256 ) );
	}

	/**
	 * Generating the private key twice does not produce a new pair.
	 *
	 * @return void
	 */
	public function test_get_private_key_is_cached(): void {
		$first  = KeyManager::get_private_key();
		$second = KeyManager::get_private_key();

		$this->assertSame( $first, $second );
	}

	// ─── regenerate_keys() ──────────────────────────────────────────

	/**
	 * Regenerating produces new encryption + RSA keys.
	 *
	 * Existing tokens signed with the previous keys would stop validating —
	 * the docblock on regenerate_keys() calls this out. The test locks it in.
	 *
	 * @return void
	 */
	public function test_regenerate_keys_replaces_all_keys(): void {
		$old_encryption = KeyManager::get_encryption_key();
		$old_private    = KeyManager::get_private_key();

		KeyManager::regenerate_keys();

		$this->assertNotSame( $old_encryption, KeyManager::get_encryption_key() );
		$this->assertNotSame( $old_private, KeyManager::get_private_key() );
	}

	// ─── delete_keys() ──────────────────────────────────────────────

	/**
	 * Removes every key option so a fresh install reaches the generator path.
	 *
	 * @return void
	 */
	public function test_delete_keys_removes_all_options(): void {
		KeyManager::get_encryption_key();
		KeyManager::get_private_key();

		KeyManager::delete_keys();

		$this->assertFalse( get_option( KeyManager::ENCRYPTION_KEY_OPTION ) );
		$this->assertFalse( get_option( KeyManager::PRIVATE_KEY_OPTION ) );
		$this->assertFalse( get_option( KeyManager::PUBLIC_KEY_OPTION ) );
	}
}
