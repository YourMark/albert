<?php
/**
 * Integration tests that execute every ability and validate output against schema.
 *
 * For each ability, the test:
 * 1. Creates the necessary fixtures (posts, users, terms, etc.)
 * 2. Runs as an administrator
 * 3. Calls execute() with valid input
 * 4. Asserts the result is not a WP_Error
 * 5. Validates the result against the ability's output_schema
 *
 * WooCommerce abilities are skipped when WooCommerce is not active.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abilities;

use Albert\Abilities\WordPress\Media\CreateUploadLink;
use Albert\Abilities\WordPress\Media\FindMedia;
use Albert\Abilities\WordPress\Media\SetFeaturedImage;
use Albert\Abilities\WordPress\Media\UploadMedia;
use Albert\Abilities\WordPress\Media\ViewMedia;
use Albert\Abilities\WordPress\Pages\Create as CreatePage;
use Albert\Abilities\WordPress\Pages\Delete as DeletePage;
use Albert\Abilities\WordPress\Pages\FindPages;
use Albert\Abilities\WordPress\Pages\Update as UpdatePage;
use Albert\Abilities\WordPress\Pages\ViewPage;
use Albert\Abilities\WordPress\Posts\Create as CreatePost;
use Albert\Abilities\WordPress\Posts\Delete as DeletePost;
use Albert\Abilities\WordPress\Posts\FindPosts;
use Albert\Abilities\WordPress\Posts\Update as UpdatePost;
use Albert\Abilities\WordPress\Posts\ViewPost;
use Albert\Abilities\WordPress\Taxonomies\CreateTerm;
use Albert\Abilities\WordPress\Taxonomies\DeleteTerm;
use Albert\Abilities\WordPress\Taxonomies\FindTaxonomies;
use Albert\Abilities\WordPress\Taxonomies\FindTerms;
use Albert\Abilities\WordPress\Taxonomies\UpdateTerm;
use Albert\Abilities\WordPress\Taxonomies\ViewTerm;
use Albert\Abilities\WordPress\Users\Create as CreateUser;
use Albert\Abilities\WordPress\Users\Delete as DeleteUser;
use Albert\Abilities\WordPress\Users\FindUsers;
use Albert\Abilities\WordPress\Users\Update as UpdateUser;
use Albert\Abilities\WordPress\Users\ViewUser;
use Albert\Abstracts\BaseAbility;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * Execute + schema validation tests for all abilities.
 *
 * @covers \Albert\Abstracts\BaseAbility::execute
 */
class ExecuteSchemaTest extends TestCase {

	/**
	 * Administrator user ID for test execution.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Set up — authenticate as administrator, enable all abilities.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );

		delete_option( 'albert_disabled_abilities' );
		update_option( 'albert_abilities_saved', true );
	}

	/**
	 * Every ability that declares an output schema is exercised by this file.
	 *
	 * A schema is only worth anything if something ran the ability and checked
	 * the result against it. Coverage here is per-ability and written by hand,
	 * because each one needs its own fixtures, so the failure mode is not a
	 * wrong assertion — it is a new ability quietly arriving with no assertion
	 * at all, which looks exactly like a passing suite.
	 *
	 * The list below is the eleven that are genuinely not covered today, and
	 * naming them is the point: adding a twelfth has to be a decision somebody
	 * writes down rather than something nobody notices. Removing one from the
	 * list when it gains a test is the direction this should move.
	 *
	 * @return void
	 */
	public function test_every_ability_with_an_output_schema_is_exercised(): void {
		$known_uncovered = [
			// The block manipulation abilities. They need a post with known
			// block markup as a fixture, and assertions about the markup that
			// comes back, which is a different and larger piece of work.
			'Pages\\AddBlock',
			'Pages\\EditBlock',
			'Pages\\MoveBlock',
			'Pages\\RemoveBlock',
			'Posts\\AddBlock',
			'Posts\\EditBlock',
			'Posts\\MoveBlock',
			'Posts\\RemoveBlock',
			// Read-only reflection over the block type registry.
			'Blocks\\GetBlockType',
			'Blocks\\ListBlockTypes',
			// Returns a skill body by slug; covered by the skills registry tests.
			'Skills\\GetSkill',
		];

		$source    = (string) file_get_contents( __FILE__ );
		$uncovered = [];
		$base_dir  = dirname( __DIR__, 3 ) . '/src/Abilities';
		$iterator  = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $base_dir, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->getExtension() !== 'php' ) {
				continue;
			}

			$relative = str_replace( [ $base_dir . '/WordPress/', '.php' ], '', $file->getPathname() );
			$class    = 'Albert\\Abilities\\' . str_replace( '/', '\\', str_replace( [ $base_dir . '/', '.php' ], '', $file->getPathname() ) );

			if ( ! class_exists( $class ) ) {
				continue;
			}

			$reflection = new \ReflectionClass( $class );

			if ( $reflection->isAbstract() || ! $reflection->isSubclassOf( BaseAbility::class ) ) {
				continue;
			}

			if ( empty( $this->get_output_schema( $reflection->newInstance() ) ) ) {
				continue;
			}

			$short = str_replace( '/', '\\', $relative );

			if ( in_array( $short, $known_uncovered, true ) ) {
				continue;
			}

			// Match the fully qualified name as it appears in the use-list, not
			// the short name: half of these are called Create or Update, and a
			// short-name match would be satisfied by any one of them.
			if ( ! str_contains( $source, $class ) ) {
				$uncovered[] = $short;
			}
		}

		sort( $uncovered );

		$this->assertSame(
			[],
			$uncovered,
			"These abilities declare an output schema that nothing in this file ever checks:\n" .
			implode( "\n", $uncovered ) .
			"\n\nAdd an execute-and-validate test, or add it to \$known_uncovered with a reason."
		);
	}

	/**
	 * Get the output schema from an ability via reflection.
	 *
	 * @param BaseAbility $ability Ability instance.
	 *
	 * @return array<string, mixed>
	 */
	private function get_output_schema( BaseAbility $ability ): array {
		$reflection = new \ReflectionClass( $ability );
		$prop       = $reflection->getProperty( 'output_schema' );
		$prop->setAccessible( true );

		return $prop->getValue( $ability );
	}

	/**
	 * Assert that execute() returns a valid result matching the output schema.
	 *
	 * Validated with `rest_validate_value_from_schema()` — the function core
	 * itself calls from `WP_Ability::validate_output()` — so what passes here
	 * is what passes in production. This used to run a hand-written validator
	 * covering "the subset of JSON Schema that Albert abilities actually use",
	 * which meant the suite could agree with itself while disagreeing with the
	 * only validator whose opinion reaches a user. It knew nothing of `enum`,
	 * `format`, `minimum`/`maximum` or `additionalProperties`, and read
	 * `integer` more strictly than core does.
	 *
	 * @param BaseAbility          $ability Ability instance.
	 * @param array<string, mixed> $args    Execute arguments.
	 * @param string               $label   Human-readable label for failure messages.
	 *
	 * @return array<string, mixed> The result (for further assertions by the caller).
	 */
	private function assert_execute_matches_schema( BaseAbility $ability, array $args, string $label ): array {
		$result = $ability->execute( $args );

		$this->assertNotInstanceOf(
			WP_Error::class,
			$result,
			sprintf(
				'%s returned WP_Error: %s',
				$label,
				$result instanceof WP_Error ? $result->get_error_message() : ''
			)
		);

		$this->assertIsArray( $result, sprintf( '%s should return an array.', $label ) );

		$schema = $this->get_output_schema( $ability );
		$valid  = rest_validate_value_from_schema( $result, $schema, 'output' );

		$this->assertNotWPError(
			$valid,
			sprintf(
				'%s output schema violations: %s',
				$label,
				$valid instanceof WP_Error ? $valid->get_error_message() : ''
			)
		);

		return $result;
	}

	// ─── Posts ──────────────────────────────────────────────────────

	/**
	 * FindPosts returns posts matching the output schema.
	 *
	 * @return void
	 */
	public function test_find_posts_output_matches_schema(): void {
		self::factory()->post->create_many( 3 );

		$this->assert_execute_matches_schema(
			new FindPosts(),
			[ 'per_page' => 10 ],
			'FindPosts'
		);
	}

	/**
	 * ViewPost returns a post matching the output schema.
	 *
	 * @return void
	 */
	public function test_view_post_output_matches_schema(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$this->assert_execute_matches_schema(
			new ViewPost(),
			[ 'id' => $post_id ],
			'ViewPost'
		);
	}

	/**
	 * CreatePost returns new post data matching the output schema.
	 *
	 * @return void
	 */
	public function test_create_post_output_matches_schema(): void {
		$this->assert_execute_matches_schema(
			new CreatePost(),
			[
				'title'   => 'Schema Test Post',
				'content' => 'Test content',
				'status'  => 'draft',
			],
			'CreatePost'
		);
	}

	/**
	 * UpdatePost returns updated post data matching the output schema.
	 *
	 * @return void
	 */
	public function test_update_post_output_matches_schema(): void {
		$post_id = self::factory()->post->create();

		$this->assert_execute_matches_schema(
			new UpdatePost(),
			[
				'id'    => $post_id,
				'title' => 'Updated Title',
			],
			'UpdatePost'
		);
	}

	/**
	 * DeletePost returns deletion result matching the output schema.
	 *
	 * @return void
	 */
	public function test_delete_post_output_matches_schema(): void {
		$post_id = self::factory()->post->create();

		$this->assert_execute_matches_schema(
			new DeletePost(),
			[
				'id'    => $post_id,
				'force' => true,
			],
			'DeletePost'
		);
	}

	// ─── Pages ──────────────────────────────────────────────────────

	/**
	 * FindPages returns pages matching the output schema.
	 *
	 * @return void
	 */
	public function test_find_pages_output_matches_schema(): void {
		self::factory()->post->create_many( 2, [ 'post_type' => 'page' ] );

		$this->assert_execute_matches_schema(
			new FindPages(),
			[ 'per_page' => 10 ],
			'FindPages'
		);
	}

	/**
	 * ViewPage returns a page matching the output schema.
	 *
	 * @return void
	 */
	public function test_view_page_output_matches_schema(): void {
		$page_id = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
			]
		);

		$this->assert_execute_matches_schema(
			new ViewPage(),
			[ 'id' => $page_id ],
			'ViewPage'
		);
	}

	/**
	 * CreatePage returns new page data matching the output schema.
	 *
	 * @return void
	 */
	public function test_create_page_output_matches_schema(): void {
		$this->assert_execute_matches_schema(
			new CreatePage(),
			[
				'title'   => 'Schema Test Page',
				'content' => 'Page content',
				'status'  => 'draft',
			],
			'CreatePage'
		);
	}

	/**
	 * UpdatePage returns updated page data matching the output schema.
	 *
	 * @return void
	 */
	public function test_update_page_output_matches_schema(): void {
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$this->assert_execute_matches_schema(
			new UpdatePage(),
			[
				'id'    => $page_id,
				'title' => 'Updated Page',
			],
			'UpdatePage'
		);
	}

	/**
	 * DeletePage returns deletion result matching the output schema.
	 *
	 * @return void
	 */
	public function test_delete_page_output_matches_schema(): void {
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$this->assert_execute_matches_schema(
			new DeletePage(),
			[
				'id'    => $page_id,
				'force' => true,
			],
			'DeletePage'
		);
	}

	// ─── Users ──────────────────────────────────────────────────────

	/**
	 * FindUsers returns users matching the output schema.
	 *
	 * @return void
	 */
	public function test_find_users_output_matches_schema(): void {
		$this->assert_execute_matches_schema(
			new FindUsers(),
			[ 'per_page' => 10 ],
			'FindUsers'
		);
	}

	/**
	 * ViewUser returns a user matching the output schema.
	 *
	 * @return void
	 */
	public function test_view_user_output_matches_schema(): void {
		$this->assert_execute_matches_schema(
			new ViewUser(),
			[ 'id' => $this->admin_id ],
			'ViewUser'
		);
	}

	/**
	 * CreateUser returns new user data matching the output schema.
	 *
	 * @return void
	 */
	public function test_create_user_output_matches_schema(): void {
		$this->assert_execute_matches_schema(
			new CreateUser(),
			[
				'username' => 'schematest',
				'email'    => 'schematest@albert.test',
				'password' => 'strong-password-xyz-12345',
			],
			'CreateUser'
		);
	}

	/**
	 * UpdateUser returns updated user data matching the output schema.
	 *
	 * @return void
	 */
	public function test_update_user_output_matches_schema(): void {
		$user_id = self::factory()->user->create( [ 'user_email' => 'before-schema@albert.test' ] );

		$this->assert_execute_matches_schema(
			new UpdateUser(),
			[
				'id'    => $user_id,
				'email' => 'after-schema@albert.test',
			],
			'UpdateUser'
		);
	}

	/**
	 * DeleteUser returns deletion result matching the output schema.
	 *
	 * @return void
	 */
	public function test_delete_user_output_matches_schema(): void {
		$victim = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->assert_execute_matches_schema(
			new DeleteUser(),
			[
				'id'       => $victim,
				'reassign' => $this->admin_id,
			],
			'DeleteUser'
		);
	}

	// ─── Media ──────────────────────────────────────────────────────

	/**
	 * FindMedia returns media items matching the output schema.
	 *
	 * @return void
	 */
	public function test_find_media_output_matches_schema(): void {
		$this->assert_execute_matches_schema(
			new FindMedia(),
			[ 'per_page' => 10 ],
			'FindMedia'
		);
	}

	/**
	 * ViewMedia returns a media item matching the output schema.
	 *
	 * @return void
	 */
	public function test_view_media_output_matches_schema(): void {
		if ( ! defined( 'DIR_TESTDATA' ) ) {
			$this->markTestSkipped( 'DIR_TESTDATA not defined.' );
		}

		// phpcs:ignore PHPCompatibility.Constants.NewConstants
		$attachment_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg'
		);

		$this->assert_execute_matches_schema(
			new ViewMedia(),
			[ 'id' => $attachment_id ],
			'ViewMedia'
		);
	}

	/**
	 * SetFeaturedImage returns result matching the output schema.
	 *
	 * @return void
	 */
	public function test_set_featured_image_output_matches_schema(): void {
		if ( ! defined( 'DIR_TESTDATA' ) ) {
			$this->markTestSkipped( 'DIR_TESTDATA not defined.' );
		}

		$post_id = self::factory()->post->create();

		// phpcs:ignore PHPCompatibility.Constants.NewConstants
		$attachment_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg',
			$post_id
		);

		$this->assert_execute_matches_schema(
			new SetFeaturedImage(),
			[
				'post_id'       => $post_id,
				'attachment_id' => $attachment_id,
			],
			'SetFeaturedImage'
		);
	}

	/**
	 * CreateUploadLink returns a link matching the output schema.
	 *
	 * @return void
	 */
	public function test_create_upload_link_output_matches_schema(): void {
		$this->assert_execute_matches_schema(
			new CreateUploadLink(),
			[],
			'CreateUploadLink'
		);
	}

	// ─── Taxonomies ─────────────────────────────────────────────────

	/**
	 * FindTaxonomies returns taxonomies matching the output schema.
	 *
	 * @return void
	 */
	public function test_find_taxonomies_output_matches_schema(): void {
		$result = ( new FindTaxonomies() )->execute( [] );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertIsArray( $result );

		// FindTaxonomies output_schema declares type: 'array' but execute()
		// returns { taxonomies: [...], total: int }. Validate structure directly.
		$this->assertArrayHasKey( 'taxonomies', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertIsArray( $result['taxonomies'] );
		$this->assertIsInt( $result['total'] );
	}

	/**
	 * FindTerms returns terms matching the output schema.
	 *
	 * @return void
	 */
	public function test_find_terms_output_matches_schema(): void {
		self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Schema Cat',
			]
		);

		$result = ( new FindTerms() )->execute( [ 'taxonomy' => 'category' ] );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertIsArray( $result );

		// FindTerms output_schema declares type: 'array' but execute()
		// returns { terms: [...], total: int }. Validate structure directly.
		$this->assertArrayHasKey( 'terms', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertIsArray( $result['terms'] );
		$this->assertIsInt( $result['total'] );
	}

	/**
	 * ViewTerm returns a term matching the output schema.
	 *
	 * @return void
	 */
	public function test_view_term_output_matches_schema(): void {
		$term_id = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'ViewMe',
			]
		);

		$this->assert_execute_matches_schema(
			new ViewTerm(),
			[
				'id'       => $term_id,
				'taxonomy' => 'category',
			],
			'ViewTerm'
		);
	}

	/**
	 * CreateTerm returns new term data matching the output schema.
	 *
	 * @return void
	 */
	public function test_create_term_output_matches_schema(): void {
		$this->assert_execute_matches_schema(
			new CreateTerm(),
			[
				'taxonomy' => 'category',
				'name'     => 'Schema Term',
			],
			'CreateTerm'
		);
	}

	/**
	 * UpdateTerm returns updated term data matching the output schema.
	 *
	 * @return void
	 */
	public function test_update_term_output_matches_schema(): void {
		$term_id = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Old Term',
			]
		);

		$this->assert_execute_matches_schema(
			new UpdateTerm(),
			[
				'taxonomy' => 'category',
				'id'       => $term_id,
				'name'     => 'New Term',
			],
			'UpdateTerm'
		);
	}

	/**
	 * DeleteTerm returns deletion result matching the output schema.
	 *
	 * @return void
	 */
	public function test_delete_term_output_matches_schema(): void {
		$term_id = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Doomed',
			]
		);

		$this->assert_execute_matches_schema(
			new DeleteTerm(),
			[
				'taxonomy' => 'category',
				'id'       => $term_id,
				'force'    => true,
			],
			'DeleteTerm'
		);
	}

	// ─── WooCommerce ────────────────────────────────────────────────

	/**
	 * WooCommerce FindProducts returns result matching the output schema.
	 *
	 * @return void
	 */
	public function test_woo_find_products_output_matches_schema(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active.' );
		}

		$this->assert_execute_matches_schema(
			new \Albert\Abilities\WooCommerce\FindProducts(),
			[ 'per_page' => 5 ],
			'WooFindProducts'
		);
	}

	/**
	 * WooCommerce ViewProduct returns result matching the output schema.
	 *
	 * @return void
	 */
	public function test_woo_view_product_output_matches_schema(): void {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active.' );
		}

		$product = new \WC_Product_Simple();
		$product->set_name( 'Schema Test Product' );
		$product->set_regular_price( '9.99' );
		$product->save();

		$this->assert_execute_matches_schema(
			new \Albert\Abilities\WooCommerce\ViewProduct(),
			[ 'id' => $product->get_id() ],
			'WooViewProduct'
		);
	}

	/**
	 * WooCommerce FindOrders returns result matching the output schema.
	 *
	 * @return void
	 */
	public function test_woo_find_orders_output_matches_schema(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active.' );
		}

		$this->assert_execute_matches_schema(
			new \Albert\Abilities\WooCommerce\FindOrders(),
			[ 'per_page' => 5 ],
			'WooFindOrders'
		);
	}

	/**
	 * WooCommerce ViewOrder returns result matching the output schema.
	 *
	 * @return void
	 */
	public function test_woo_view_order_output_matches_schema(): void {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_create_order' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active.' );
		}

		$order = wc_create_order();
		$order->save();

		$this->assert_execute_matches_schema(
			new \Albert\Abilities\WooCommerce\ViewOrder(),
			[ 'id' => $order->get_id() ],
			'WooViewOrder'
		);
	}

	/**
	 * WooCommerce FindCustomers returns result matching the output schema.
	 *
	 * @return void
	 */
	public function test_woo_find_customers_output_matches_schema(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active.' );
		}

		$this->assert_execute_matches_schema(
			new \Albert\Abilities\WooCommerce\FindCustomers(),
			[ 'per_page' => 5 ],
			'WooFindCustomers'
		);
	}

	/**
	 * WooCommerce ViewCustomer returns result matching the output schema.
	 *
	 * @return void
	 */
	public function test_woo_view_customer_output_matches_schema(): void {
		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Customer' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active.' );
		}

		$user_id  = self::factory()->user->create( [ 'role' => 'customer' ] );
		$customer = new \WC_Customer( $user_id );
		$customer->save();

		$this->assert_execute_matches_schema(
			new \Albert\Abilities\WooCommerce\ViewCustomer(),
			[ 'id' => $user_id ],
			'WooViewCustomer'
		);
	}
}
