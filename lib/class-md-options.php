<?php

namespace WordPress\Experiments\HtmlToMarkdown;

class MD_Options {
	public int $soft_line_wrap = 80;

	/**
	 * Contains the current indentation stack.
	 * Normally, this would not be set by calling code.
	 *
	 * You probably do not want to set this.
	 *
	 * At times, there might be rendering which occurs within some external
	 * context not conveyed from within the input HTML. For example, if wanting
	 * to render a document as a quote, then one could pass the leading indent
	 * into the converter and all output will be properly prefixed and line-wrapped
	 * in accordance with that indent.
	 *
	 * Example:
	 *
	 *     $options = new MD_Options();
	 *     $options->indent = array( '> ' );
	 *
	 * @var array
	 */
	public array $indent = array();

	/**
	 * The output rendering target is either for visual consumption or
	 * to produce a syntactically-valid Markdown document.
	 *
	 * @var 'syntax'|'presentation'
	 */
	public string $display_mode = 'syntax';
}