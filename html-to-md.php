<?php

/*
 * Plugin Name: HTML to Markdown
 * Author: Dennis Snell <dennis.snell@automattic.com>
 * Description: Convert HTML documents to Markdown using WordPress’ HTML API.
 * Version: 2026-02-01
 */

// Don’t load directly.
if ( ! defined( 'ABSPATH' ) ) {
	die( '-1' );
}

require_once __DIR__ . '/lib/html-to-md.php';

add_filter( 'wp_template_enhancement_output_buffer', function ( $output, $original ) {
	$has_accept_header = isset( $_SERVER['HTTP_ACCEPT'] );
	$has_markdown_type = $has_accept_header && 1 === preg_match( '~^text/markdown(?:;|$)~', $_SERVER['HTTP_ACCEPT'] );
	$has_markdown_query_arg = in_array( $_GET['output_format'] ?? '', array( 'md', 'markdown' ), true );

	if ( ! ( $has_markdown_type || $has_markdown_query_arg ) ) {
		return  $output;
	}

	header( 'Content-type: text/markdown; charset=utf-8' );

	return html_to_md( $output );
}, 10, 2 );