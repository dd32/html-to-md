<?php

namespace WordPress\Experiments\HtmlToMarkdown;

/**
 * Represents generic inline text effects.
 */
class WP_Experimental_HTML_Renderer_Format_Generic extends WP_Experimental_HTML_Renderer_Format {
	/**
	 * What kind of format this represents.
	 *
	 * @todo cleanup to ensure comprehensiveness in this type.
	 *
	 * @var 'bold'|'italic'|'monospace'|'quote'|'strikeout'
	 */
	public string $type;

	/**
	 * Create a format for generic text formatting without state.
	 *
	 * @param 'bold'|'italic'|'monospace'|'quote'|'strikeout' $type
	 */
	public function __construct( string $type ) {
		$this->type = $type;
	}
}