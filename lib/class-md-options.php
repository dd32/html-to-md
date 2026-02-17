<?php

namespace WordPress\Experiments\HtmlToMarkdown;

class MD_Options {
	public int $soft_line_wrap = 80;

	public array $indent = array();

	/**
	 * The output rendering target is either for visual consumption or
	 * to produce a syntactically-valid Markdown document.
	 *
	 * @var 'syntax'|'presentation'
	 */
	public string $display_mode = 'syntax';
}