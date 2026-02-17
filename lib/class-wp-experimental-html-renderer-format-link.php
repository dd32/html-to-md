<?php

namespace WordPress\Experiments\HtmlToMarkdown;

/**
 * Represents a link with a label.
 */
class WP_Experimental_HTML_Renderer_Format_Link extends WP_Experimental_HTML_Renderer_Format {
	/**
	 * Where this link points.
	 *
	 * @var string
	 */
	public string $url;

	/**
	 * Create a format for a linked span of content.
	 *
	 * @param string $url
	 */
	public function __construct( string $url ) {
		$this->url = $url;
	}
}