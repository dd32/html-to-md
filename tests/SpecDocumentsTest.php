<?php

namespace WordPress\Experiments\HtmlToMarkdown;

use PHPUnit\Framework\Attributes\DataProvider;

class SpecDocumentsTest extends \PhpUnit\Framework\TestCase {
	/**
	 * Tests conversions based on static test cases.
	 *
	 *
	 * @param string $html     Input HTML to render.
	 * @param string $markdown Expected Markdown output.
	 * @param null   $options  Conversion settings, if provided.
	 */
	#[DataProvider('data_spec_documents')]
	public function test_matches_spec_documents( $html, $markdown, $options ) {
		$this->assertSame(
			self::visualize_invisibles( $markdown ),
			self::visualize_invisibles( isset( $options )
				? html_to_md( $html, $options )
				: html_to_md( $html ) )
		);
	}

	public static function visualize_invisibles( string $text ): string {
		return $text;

		$replacements = array();
		for ( $i = 0; $i <= 0x20; $i++ ) {
			$replacements[ chr( $i ) ] = mb_chr( $i + 0x2400 );
		}

		return strtr( $text, $replacements );
	}

	/**
	 * Reads the spec-documents folder, parses files, and yields
	 * HTML/Markdown/settings triplets for test consumption.
	 *
	 * @return Generator
	 */
	public static function data_spec_documents() {
		foreach ( self::walk_spec_documents() as $full_html ) {
			$dom = \DOM\HTMLDocument::createFromString( $full_html, LIBXML_NOERROR | LIBXML_HTML_NOIMPLIED );

			foreach ( $dom->querySelectorAll( 'SECTION' ) as $section ) {
				if ( null !== $section->querySelector( 'META' ) ) {
					$options = new MD_Options();
					if ( null !== ( $meta = $section->querySelector( 'META[name=soft-line-wrap]' ) ) ) {
						$soft_limit = $meta->getAttribute( 'content' );
						self::assertTrue(
							ctype_digit( $soft_limit ),
							"Configured soft line wrap value of '{$soft_limit}' must be all digits: check test fixture."
						);
						$options->soft_line_wrap = (int) $soft_limit;
					}
				} else {
					$options = null;
				}

				$test_name = $section->getAttribute( 'id' );
				$test_html = $section->querySelector( 'PRE' )->innerHTML;
				$test_md   = $section->querySelector( 'SCRIPT[type="text/x-markdown"]' )->textContent;

				// While PRE removes a leading newline, SCRIPT doesn’t.
				if ( "\n" === ( $test_md[0] ?? null ) ) {
					$test_md = substr( $test_md, 1 );
				}

				yield $test_name => array( $test_html, $test_md, $options );
			}
		}
	}

	/**
	 * Yields the spec documents by traversing their directory.
	 *
	 * @return Generator<string> Full unparsed HTML contents of each spec document.
	 */
	private static function walk_spec_documents() {
		$spec_dir = opendir( __DIR__ . '/spec-documents/' );
		assert( false !== $spec_dir );

		while ( false !== ( $path = readdir( $spec_dir ) ) ) {
			if ( '.' === $path || '..' === $path ) {
				continue;
			}

			$html = file_get_contents( __DIR__ . '/spec-documents/' . $path );
			assert( false !== $html );
			yield $html;
		}
	}
}