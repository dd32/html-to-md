<?php

use WordPress\Experiments\HtmlToMarkdown\WP_Experimental_HTML_Renderer;
use WordPress\Experiments\HtmlToMarkdown\MD_Options;

function wp_html_to_markdown( string $html, ?MD_Options $options = new MD_Options() ): string {
	$renderer = new WP_Experimental_HTML_Renderer( $html, $options );

	return $renderer->to_markdown();
}