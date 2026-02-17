<?php

namespace WordPress\Experiments\HtmlToMarkdown;

/**
 * Represents generic inline text effects.
 */
class InlineFormat_Generic extends InlineFormat {
	/**
	 * What kind of format this represents.
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