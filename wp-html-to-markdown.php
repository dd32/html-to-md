<?php

use function WordPress\Experiments\HtmlToMarkdown\{html_to_md};
use WordPress\Experiments\HtmlToMarkdown\MD_Options;

require __DIR__ . '/lib/html-to-markdown.php';

function wp_html_to_markdown( string $html, ?MD_Options $options = new MD_Options() ): string {
	return html_to_md( $html, $options );
}