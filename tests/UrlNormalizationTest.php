<?php

use PHPUnit\Framework\Attributes\DataProvider;
class UrlNormalizationTest extends PHPUnit\Framework\TestCase {
	/**
	 * Ensures predictable URL normalization; smoke tests.
	 *
	 * @ticket {TICKET_NUMBER}
	 *
	 * @param string $url
	 * @param string|null $base
	 * @param string $normalized
	 * @return void
	 */
	#[DataProvider('data_normalization_forms')]
	public function test_normalization_forms( string $url, ?string $base, string $normalized ): void {
		$this->assertSame(
			$normalized,
			\WordPress\Experiments\HtmlToMarkdown\WP_Experimental_HTML_Renderer_Format_Link::normalize( $url, $base ),
			"Failed to properly normalize '{$url}'."
		);
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_normalization_forms(): array {
		return array(
			array( '/test', null, '/test' ),
			array( '/test', 'https://example.com/', 'https://example.com/test' ),
			array( '/test', 'https://example.com/radio', 'https://example.com/test' ),
			array( 'test.html', 'https://example.com/', 'https://example.com/test.html' ),
			array( 'test.html', 'https://example.com/bears/', 'https://example.com/bears/test.html' ),

			array( '?q=books', null, '?q=books' ),
			array( '?q=books', 'http://open-archive.localdomain/search', 'http://open-archive.localdomain/search?q=books' ),

			array( '#:~:text=WordPress', null, '#:~:text=WordPress' ),
			array( '#:~:text=WordPress', 'https://wordpress.org', 'https://wordpress.org#:~:text=WordPress' ),
		);
	}
}