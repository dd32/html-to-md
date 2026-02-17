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
				? \wp_html_to_markdown( $html, $options )
				: \wp_html_to_markdown( $html ) )
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
			$p = new class( $full_html ) extends \WP_HTML_Tag_Processor {
				public function get_span(): \WP_Html_Span {
					$this->set_bookmark( 'here' );
					return $this->bookmarks['here'];
				}
			};

			while ( $p->next_tag( 'SECTION' ) ) {
				$test_name = $p->get_attribute( 'id' );
				$options   = null;

				while ( $p->next_tag() ) {
					$token_name = $p->get_token_name();

					switch ( $token_name ) {
						case 'META':
							switch ( $p->get_attribute( 'name' ) ) {
								case 'display-mode':
									$display_mode = $p->get_attribute( 'content' );
									self::assertContains(
										$display_mode,
										array( 'syntax', 'presentation' ),
										"Configured display mode must be either 'syntax' or 'presentation': check test fixture."
									);
									if ( ! isset( $options ) ) {
										$options = new WP_Experimental_HTML_Renderer_Options();
									}
									$options->display_mode = $display_mode;
									break;

								case 'soft-line-wrap':
									$soft_limit = $p->get_attribute( 'content' );
									self::assertTrue(
										ctype_digit( $soft_limit ),
										"Configured soft line wrap value of '{$soft_limit}' must be all digits: check test fixture."
									);
									if ( ! isset( $options ) ) {
										$options = new WP_Experimental_HTML_Renderer_Options();
									}
									$options->soft_line_wrap = (int) $soft_limit;
									break;
							}
							break;

						case 'PRE':
							$start = $p->get_span();
							while ( $p->next_token() && 'SCRIPT' !== $p->get_tag() && 'text/x-markdown' !== $p->get_attribute( 'type' ) ) {
								$end = $p->get_span();
							}

							$start_at = $start->start + $start->length;
							$start_at += strspn( $full_html, "\n", $start_at, 1 );
							$test_html = substr( $full_html, $start_at, $end->start - $start_at );
							$test_md   = $p->get_modifiable_text();
							break 2;

						default:
							self::fail( 'Test fixture contains unexpected HTML structure.' );
					}
				}

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