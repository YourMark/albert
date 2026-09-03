<?php
/**
 * The consent form must carry every parameter the POST branch reads back.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\OAuth;

use Albert\OAuth\Endpoints\AuthorizationPage;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Guards the defect that stopped every authorisation.
 *
 * `handle_authorization()` reads its OAuth parameters through
 * `read_oauth_param()`, which takes them from `$_POST` when the consent
 * decision is submitted. The consent form therefore has to resubmit all of
 * them as hidden fields. It carried six of the seven: `response_type` was
 * missing, so on submission it read as an empty string, the handler's
 * `!== 'code'` guard fired, and both Authorize and Deny rendered "Missing or
 * invalid OAuth parameters". No assistant could be connected at all.
 *
 * It got through because the two halves are written independently and nothing
 * compared them. That is what this test does.
 *
 * **Why it reads the source rather than the rendered page.** Every render
 * method on this class ends in `exit`, and the suite has no process isolation,
 * so the form cannot be produced in-process. That is the same reason this path
 * had no coverage when the defect shipped. Comparing the two lists is weaker
 * than rendering, and it is strictly stronger than nothing, which is what was
 * here before.
 *
 * @covers \Albert\OAuth\Endpoints\AuthorizationPage
 */
class ConsentFormContractTest extends TestCase {

	/**
	 * The class source, read once.
	 *
	 * @return string
	 */
	private function source(): string {
		$file = ( new ReflectionClass( AuthorizationPage::class ) )->getFileName();

		$this->assertIsString( $file, 'The class file must be resolvable.' );

		$source = file_get_contents( (string) $file );

		$this->assertIsString( $source, 'The class file must be readable.' );

		return (string) $source;
	}

	/**
	 * Every parameter the handler reads is resubmitted by the form.
	 *
	 * @return void
	 */
	public function test_the_form_resubmits_every_parameter_the_handler_reads(): void {
		$source = $this->source();

		preg_match_all( "/read_oauth_param\(\s*'([a-z_]+)'/", $source, $reads );
		preg_match_all( '/<input type="hidden" name="([a-z_]+)"/', $source, $fields );

		$read     = array_values( array_unique( $reads[1] ) );
		$rendered = array_values( array_unique( $fields[1] ) );

		$this->assertNotEmpty( $read, 'The handler must read some parameters.' );

		$missing = array_diff( $read, $rendered );

		$this->assertSame(
			[],
			array_values( $missing ),
			sprintf(
				'The consent form does not resubmit: %s. On the POST branch these read as empty, '
					. 'and the handler rejects the submission, so no assistant can be connected. '
					. 'Add a matching hidden input to the form.',
				implode( ', ', $missing )
			)
		);
	}

	/**
	 * `response_type` specifically, named so the failure is unmistakable.
	 *
	 * It is a constant: Albert implements only the authorisation-code flow.
	 *
	 * @return void
	 */
	public function test_the_form_carries_response_type_as_code(): void {
		$this->assertStringContainsString(
			'<input type="hidden" name="response_type" value="code">',
			$this->source(),
			'The consent form must post response_type=code. Without it every authorisation fails.'
		);
	}
}
