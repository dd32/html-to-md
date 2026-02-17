<?php

namespace WordPress\Experiments\HtmlToMarkdown;

/**
 * Represents a link with a label.
 */
class InlineFormat_Link extends InlineFormat {
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