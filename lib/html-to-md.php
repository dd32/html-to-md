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
	$p           = WP_HTML_Processor::create_fragment( $html );
	$blocks      = array();
	$line_buffer = new LineBuffer();

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

	$close_a_paragraph = function () use ( &$line_buffer, &$stack, $options, &$blocks ) {
		if (
			end( $stack ) instanceof Block_Paragraph &&
			$line_buffer->has_non_whitespace_content()
		) {
			$paragraph = array_pop( $stack );
			if ( end( $stack ) instanceof Block ) {
				end( $stack )->append( $paragraph );
			} else {
				$blocks[] = $paragraph->flush( $options );
			}
		}
		$line_buffer = new LineBuffer();
	};

	while ( $p->next_token() ) {
		$token_name = $p->get_token_name();
		$is_closer  = $p->is_tag_closer();
		
		switch ( $token_name ) {
			case '#text':
				$preserve_whitespace = $depths['PRE'] > 0;
				$chunk               = $p->get_modifiable_text();
				$chunk = $preserve_whitespace
					? $chunk
					: preg_replace( '~[ \t\f\r\n]+~', ' ', $chunk );

				$line_buffer->append_text( $chunk );
				if (
					! ( end( $stack ) instanceof Block_Paragraph ) &&
					$line_buffer->has_non_whitespace_content()
				) {
					$paragraph = new Block_Paragraph();
					$paragraph->append_line( $line_buffer );
					$stack[] = $paragraph;
				}
				break;

			// Handle inline formatting.
			case 'B':
			case 'BR':
			case 'CODE':
			case 'EM':
			case 'I':
			case 'Q':
			case 'S':
			case 'STRONG':
				if ( $is_closer ) {
					$line_buffer->release_format();
				} else {
					$format = array(
						'B'      => 'bolding',
						'BR'     => 'newlining',
						'CODE'   => 'monospacing',
						'EM'     => 'emphasizing',
						'I'      => 'emphasizing',
						'Q'      => 'quoting',
						'S'      => 'striking-out',
						'STRONG' => 'bolding',
					)[ $token_name ];
					$line_buffer->require_format( new InlineFormat_Generic( $format ) );
				}
				break;

			case 'LI':
				$close_a_paragraph();
				if ( ! ( $is_closer || end( $stack ) instanceof Block_List ) ) {
					$stack[] = new Block_List( '' );
				}
				break;

			case 'P':
				$close_a_paragraph();
				break;

			case 'PRE':
				$close_a_paragraph();
				if ( $is_closer ) {
					$block = array_pop( $stack );
					if ( end( $stack ) instanceof Block ) {
						end( $stack )->append( $block );
					} else {
						$blocks[] = $block->flush( $options );
					}
				} else {
					$stack[] = new Block_Code();
				}
				$depths['PRE'] += $is_closer ? -1 : 1;
				break;

			case 'UL':
				$close_a_paragraph( $token_name, $is_closer );

				if ( $is_closer ) {
					if ( $line_buffer->has_non_whitespace_content() ) {
						if ( end( $stack ) instanceof Block_List ) {
							$item = new Block_Paragraph();
							$item->append_line( $line_buffer );
						} else {
							$item = array_pop( $stack );
						}

						end( $stack )->append( $item );
					}

					$line_buffer = new LineBuffer();
					$list        = array_pop( $stack );
					if ( $list->is_empty() ) {
						break;
					}

					$parent = end( $stack );
					if ( $parent instanceof Block ) {
						$parent->append( $list );
					} else {
						$blocks[] = $list->flush( $options );
					}
				} else {
					if ( ! $line_buffer->has_non_whitespace_content() ) {
						$line_buffer = new LineBuffer();
					}

					$type  = $p->get_attribute( 'type' );
					$type = is_string( $type ) ? strtolower( trim( $type, " \t\f\r\n" ) ) : '';
					$style = array(
						'circle'   => '•',
						'disc'     => '◦',
						'square'   => '▪',
						'triangle' => '‣',
					)[ $type ] ?? null;
					$style = $style ?? array( '•', '◦', '▪', '▴', '⁃' )[ $depths['UL'] % 5 ];

					$stack[] = new Block_List( $style );
				}
				$depths['UL'] += $is_closer ? -1 : 1;
				break;
		}
	}

	while ( count( $stack ) > 0 ) {
		$blocks[] = ( array_pop( $stack ) )->flush( $options );
	}

	$markdown = '';
	$last     = '';
	foreach ( $blocks as $i => $b ) {
		// Ensure each block is separated by two spaces.
		$markdown .= ( $i === 0 ? '' : ( "\n" === $last ? "\n" : "\n\n" ) ) . ltrim( $b, "\n" );
		$last      = substr( $markdown, -1 );
	}

	return $markdown;
}