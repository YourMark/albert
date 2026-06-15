<?php
/**
 * Tests for the FrontmatterParser.
 *
 * @package Albert\Tests\Unit\MCP\Skills
 */

namespace Albert\Tests\Unit\MCP\Skills;

use Albert\MCP\Skills\FrontmatterParser;
use PHPUnit\Framework\TestCase;

/**
 * FrontmatterParser unit tests.
 *
 * Verifies the parser splits a skill file into its frontmatter fields and body.
 */
class FrontmatterParserTest extends TestCase {

	/**
	 * Parser instance under test.
	 *
	 * @var FrontmatterParser
	 */
	private FrontmatterParser $parser;

	/**
	 * Set up a fresh parser for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->parser = new FrontmatterParser();
	}

	/**
	 * Frontmatter name and description are parsed and the body is returned.
	 *
	 * @return void
	 */
	public function test_parses_name_description_and_body(): void {
		$contents = "---\nname: block-editor\ndescription: A playbook.\n---\n# Body heading\n\nSome content.";

		$result = $this->parser->parse( $contents );

		$this->assertSame( 'block-editor', $result['name'] );
		$this->assertSame( 'A playbook.', $result['description'] );
		$this->assertSame( "# Body heading\n\nSome content.", $result['body'] );
		$this->assertSame( [], $result['arguments'] );
	}

	/**
	 * Quoted frontmatter values have their surrounding quotes stripped.
	 *
	 * @return void
	 */
	public function test_strips_surrounding_quotes(): void {
		$contents = "---\nname: \"block-editor\"\ndescription: 'Quoted desc.'\n---\nBody.";

		$result = $this->parser->parse( $contents );

		$this->assertSame( 'block-editor', $result['name'] );
		$this->assertSame( 'Quoted desc.', $result['description'] );
	}

	/**
	 * Carriage-return line endings are normalised before parsing.
	 *
	 * @return void
	 */
	public function test_normalises_crlf_line_endings(): void {
		$contents = "---\r\nname: skill\r\ndescription: Desc.\r\n---\r\nBody line.";

		$result = $this->parser->parse( $contents );

		$this->assertSame( 'skill', $result['name'] );
		$this->assertSame( 'Body line.', $result['body'] );
	}

	/**
	 * Files with no frontmatter fence return the whole input as the body.
	 *
	 * @return void
	 */
	public function test_no_frontmatter_returns_body_only(): void {
		$contents = 'Just a plain body with no fence.';

		$result = $this->parser->parse( $contents );

		$this->assertNull( $result['name'] );
		$this->assertNull( $result['description'] );
		$this->assertSame( 'Just a plain body with no fence.', $result['body'] );
	}

	/**
	 * Inline JSON arguments are parsed into a list of definitions.
	 *
	 * @return void
	 */
	public function test_parses_inline_json_arguments(): void {
		$contents = "---\nname: skill\narguments: [{\"name\":\"slug\",\"required\":true}]\n---\nBody.";

		$result = $this->parser->parse( $contents );

		$this->assertCount( 1, $result['arguments'] );
		$this->assertSame( 'slug', $result['arguments'][0]['name'] );
		$this->assertTrue( $result['arguments'][0]['required'] );
	}

	/**
	 * Malformed argument values are ignored rather than fatalling.
	 *
	 * @return void
	 */
	public function test_invalid_arguments_yield_empty_list(): void {
		$contents = "---\nname: skill\narguments: not-json\n---\nBody.";

		$result = $this->parser->parse( $contents );

		$this->assertSame( [], $result['arguments'] );
	}

	/**
	 * Argument entries without a name are dropped.
	 *
	 * @return void
	 */
	public function test_arguments_without_name_are_dropped(): void {
		$contents = "---\nname: skill\narguments: [{\"description\":\"no name\"},{\"name\":\"ok\"}]\n---\nBody.";

		$result = $this->parser->parse( $contents );

		$this->assertCount( 1, $result['arguments'] );
		$this->assertSame( 'ok', $result['arguments'][0]['name'] );
	}

	/**
	 * Comment and blank lines inside the frontmatter are ignored.
	 *
	 * @return void
	 */
	public function test_ignores_comments_and_blank_lines(): void {
		$contents = "---\n# a comment\n\nname: skill\n---\nBody.";

		$result = $this->parser->parse( $contents );

		$this->assertSame( 'skill', $result['name'] );
	}
}
