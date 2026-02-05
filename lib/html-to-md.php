<?php

require __DIR__ . '/line-wrap.php';
require __DIR__ . '/class-md-options.php';
require __DIR__ . '/block.php';
require __DIR__ . '/block-code.php';
require __DIR__ . '/block-list.php';
require __DIR__ . '/block-paragraph.php';
require __DIR__ . '/inline-format.php';
require __DIR__ . '/inline-format-generic.php';
require __DIR__ . '/inline-format-link.php';
require __DIR__ . '/line-buffer.php';

/**
 * Render an HTML document into Markdown.
 *
 * @param string      $html    Input HTML to render.
 * @param ?MD_Options $options Optional. Pass to specify rendering options.
 *                             For defaults {@see MD_Options}.
 * @return string Input HTML rendered into Markdown
 */
function html_to_md( string $html, ?MD_Options $options = new MD_Options() ) {
	$p            = WP_HTML_Processor::create_fragment( $html );
	$o            = array();
	$b            = null;
	$lb           = new LineBuffer();

	/**
	 * Maintains track of the number of open elements in the stack
	 * of each given type.
	 *
	 * @var Array<string,int> $depths
	 */
	$depths = array(
		'PRE' => 0,
		'UL'  => 0,
	);

	/**
	 * Tracks open block containers.
	 *
	 * @var Array<Block>
	 */
	$stack = array();

	while ( $p->next_token() ) {
		$token_name = $p->get_token_name();
		$is_closer  = $p->is_tag_closer();
		
		switch ( $token_name ) {
			case '#text':
				$lb->append_text( $p->get_modifiable_text() );
				break;

			// Handle inline formatting.
			case 'B':
			case 'BR':
			case 'EM':
			case 'I':
			case 'Q':
			case 'S':
			case 'STRONG':
				if ( $is_closer ) {
					$lb->release_format();
				} else {
					$format = array(
						'B'      => 'bolding',
						'BR'     => 'newlining',
						'EM'     => 'emphasizing',
						'I'      => 'emphasizing',
						'Q'      => 'quoting',
						'S'      => 'striking-out',
						'STRONG' => 'bolding',
					)[ $token_name ];
					$lb->require_format( new InlineFormat_Generic( $format ) );
				}
				break;

			case 'P':
				if ( $is_closer ) {
					if ( $lb->has_non_whitespace_content() ) {
						end( $stack )->append_line( $lb );
					}

					$paragraph = array_pop( $stack );
					$parent    = end( $stack );
					if ( $parent instanceof Block ) {
						$parent->append( $paragraph );
					} else {
						$o[] = $paragraph->flush( $options );
					}

					$lb = new LineBuffer();
				} else {
					$stack[] = new Block_Paragraph();
				}
				break;

			case 'PRE':
				if ( isset( $b ) ) {
					$o[] = $b->flush( $options );
				}

				$lb = new LineBuffer();

				if ( $is_closer ) {
					$b  = null;
				} else {
					$b  = new Block_Code();
					$b->append_line_buffer( $lb );
				}
				break;

			case 'UL':
				if ( isset( $b ) ) {
					$o[] = $b->flush( $options );
				}

				$lb = new LineBuffer();

				$type = $p->get_attribute( 'type' );
				$type = array(
					'circle'   => '•',
					'disc'     => '◦',
					'square'   => '▪',
					'triangle' => '‣',
				)[ strtolower( trim( $type, " \t\f\r\n" ) ) ] ?? null;

				if ( null === $type ) {
					// @todo Track this.
					$list_depth = 0;
					$type       = array( '•', '◦', '▪', '‣', '⁃' )[ $list_depth % 5 ];
				}

				if ( isset( $b ) ) {
					$o[] = $b->flush( $options );
				}

				if ( $is_closer ) {
					$b = null;
				} else {
					$b = new Block_List( $type );
				}
				break;
		}
	}

	if ( $lb->has_non_whitespace_content() ) {
		$paragraph = new Block_Paragraph();
		$paragraph->append_line( $lb );
		$o[] = $paragraph->flush( $options );
	}

	while ( count( $stack ) > 0 ) {
		$o[] = ( array_pop( $stack ) )->flush( $options );
	}

	$markdown = '';
	$last     = '';
	foreach ( $o as $i => $b ) {
		// Ensure each block is separated by two spaces.
		$markdown .= ( $i === 0 ? '' : ( "\n" === $last ? "\n" : "\n\n" ) ) . ltrim( $b, "\n" );
		$last      = substr( $markdown, -1 );
	}

	return $markdown;
}