<?php

require __DIR__ . '/line-wrap.php';
require __DIR__ . '/class-md-options.php';
require __DIR__ . '/block.php';
require __DIR__ . '/block-atx.php';
require __DIR__ . '/block-blockquote.php';
require __DIR__ . '/block-code.php';
require __DIR__ . '/block-list.php';
require __DIR__ . '/block-paragraph.php';
require __DIR__ . '/inline-format.php';
require __DIR__ . '/inline-format-generic.php';
require __DIR__ . '/inline-format-image.php';
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
	$line_buffer = new LineBuffer();
	$soft_limit  = $options->soft_line_wrap;
	$markdown    = '';

	/**
	 * Maintains track of the number of open elements in the stack
	 * of each given type.
	 *
	 * @var Array<string,int> $depths
	 */
	$depths = array(
		'PRE' => 0,
		'OL'  => 0,
		'UL'  => 0,
	);

	/**
	 * Tracks open block containers.
	 *
	 * @var Array<Block>
	 */
	$stack = array();

	$flush_block = function () use ( &$blocks, &$stack, &$markdown, $options ) {
		$block = array_pop( $stack );
		if ( null === $block || $block->is_empty() ) {
			return;
		}

		$parent = end( $stack );
		if ( $parent instanceof Block ) {
			$parent->append( $block );
		} else {
			if ( '' !== $markdown ) {
				$markdown .= "\n" === $markdown[ strlen( $markdown ) - 1 ] ? "\n" : "\n\n";
			}
			$markdown .= ltrim( $block->flush( $options ), "\n" );
		}
	};

	$close_a_paragraph = function () use ( &$line_buffer, &$stack, $options, &$flush_block ) {
		if (
			end( $stack ) instanceof Block_Paragraph &&
			$line_buffer->has_non_whitespace_content()
		) {
			$flush_block();
		} elseif ( $line_buffer->has_non_whitespace_content() ) {
			$paragraph = new Block_Paragraph();
			$paragraph->append_line( $line_buffer );
			$stack[] = $paragraph;
			$flush_block();
		}
		$line_buffer = new LineBuffer();
	};

	$skip_hidden_content = function () use ( $p ) {
		$token_name = $p->get_token_name();
		switch ( $token_name ) {
			case 'BUTTON':
			case 'DATALIST':
			case 'IFRAME':
			case 'INPUT':
			case 'OPTION':
			case 'PARAM':
			case 'SELECT':
			case 'SVG':
			case 'TEMPLATE':
			case 'TEXTAREA':
			case 'TITLE':
				goto skip;
		}

		$hidden = $p->get_attribute( 'hidden' );
		if (
			isset( $hidden ) &&
			! ( is_string( $hidden ) && 0 === strcasecmp( $hidden, 'until-found' ) )
		) {
			goto skip;
		}

		$hidden = $p->get_attribute( 'aria-hidden' );
		if ( is_string( $hidden ) && 0 === strcasecmp( $hidden, 'true' ) ) {
			goto skip;
		}

		return false;

		skip:
		if ( ! $p->expects_closer() ) {
			return true;
		}

		$depth = $p->get_current_depth();
		while ( $p->next_token() && $depth <= $p->get_current_depth() ) {
			continue;
		}
		return true;
	};

	while ( $p->next_token() ) {
		$token_name = $p->get_token_name();
		$is_closer  = $p->is_tag_closer();

		if ( $skip_hidden_content() ) {
			continue;
		}
		
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

			case 'A':
				// @todo Join with base URL of document, if available, to form URL.
				if ( $is_closer ) {
					$line_buffer->release_format();
				} else {
					$href = $p->get_attribute( 'href' );
					if ( is_string( $href ) ) {
						$line_buffer->require_format( new InlineFormat_Link( $href ) );
					}
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
			case 'SUB':
			case 'SUP':
				if ( $is_closer ) {
					$line_buffer->release_format();
				} else {
					if ( 'CODE' === $token_name && end( $stack ) instanceof Block_Code ) {
						end( $stack )->infer_language( $p );
					}
					$format = array(
						'B'      => 'bolding',
						'BR'     => 'newlining',
						'CODE'   => 'monospacing',
						'EM'     => 'emphasizing',
						'I'      => 'emphasizing',
						'Q'      => 'quoting',
						'S'      => 'striking-out',
						'STRONG' => 'bolding',
						'SUB'    => 'subscripting',
						'SUP'    => 'superscripting',
					)[ $token_name ];
					$line_buffer->require_format( new InlineFormat_Generic( $format ) );
				}
				break;

			case 'BLOCKQUOTE':
				$close_a_paragraph();

				if ( $is_closer ) {
					$flush_block();
				} else {
					$stack[] = new Block_Blockquote();
				}
				break;

			case 'H1':
			case 'H2':
			case 'H3':
			case 'H4':
			case 'H5':
			case 'H6':
				$close_a_paragraph();

				if ( $is_closer ) {
					$flush_block();
				} else {
					$heading = new Block_ATX( (int) $token_name[1] );
					$heading->append_line( $line_buffer );
					$stack[] = $heading;
				}

				break;

			/*
			 * For simplicity’s sake, adding a thematic break is considered
			 * equivalent here to adding a new paragraph whose contents is
			 * the break syntax. Note that this syntax can be quite colorful
			 * and there are other options, but three dashes are very popular
			 * forms of the break, so will likely be among the most familiar.
			 */
			case 'HR':
				$close_a_paragraph();
				$break = new Block_Paragraph();
				$line_buffer = new LineBuffer();
				$line_buffer->append_text( '---' );
				$break->append_line( $line_buffer );
				$stack[] = $break;
				$close_a_paragraph();
				break;

			case 'IMG':
				$src = $p->get_attribute( 'src' );
				if ( ! is_string( $src ) || empty( trim( $src ) ) ) {
					// Only consider images which contain some content.
					break;
				}

				$alt = $p->get_attribute( 'alt' );
				$alt = is_string( $alt ) ? $alt : '';

				$title = $p->get_attribute( 'title' );
				$title = is_string( $title ) ? $title : '';

				$line_buffer->require_format( new InlineFormat_Image( $src, $alt, $title ) );
				$line_buffer->release_format();
				break;

			case 'LI':
				$close_a_paragraph();
				if ( ! ( $is_closer || end( $stack ) instanceof Block_List ) ) {
					$stack[] = new Block_List( '' );
				}
				break;

			// @todo This group all deserves special attention, to be replaced later.
			case 'CENTER':
			case 'DETAILS':
			case 'DIALOG':
			case 'FIGURE':
			case 'FIGCAPTION':
			case 'FORM':
			case 'LEGEND':
			case 'NAV':
			case 'PLAINTEXT':
			case 'SEARCH':
			case 'SUMMARY':
			case 'XMP':
				$close_a_paragraph();
				break;

			case 'ADDRESS':
			case 'ARTICLE':
			case 'ASIDE':
			case 'DIV':
			case 'FOOTER':
			case 'HEADER':
			case 'HGROUP':
			case 'MAIN':
			case 'P':
			case 'SECTION':
				$close_a_paragraph();
				break;

			case 'PRE':
				$close_a_paragraph();
				if ( $is_closer ) {
					$flush_block();
				} else {
					$stack[] = new Block_Code();
					end( $stack )->infer_language( $p );
				}
				$depths['PRE'] += $is_closer ? -1 : 1;
				break;

			case 'OL':
			case 'UL':
				$close_a_paragraph();

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
					$flush_block();
				} else {
					if ( ! $line_buffer->has_non_whitespace_content() ) {
						$line_buffer = new LineBuffer();
					}

					$type  = $p->get_attribute( 'type' );

					if ( 'UL' === $token_name ) {
						$type = is_string( $type ) ? strtolower( trim( $type, " \t\f\r\n" ) ) : '';
						$bullets_syntax = array(
							'circle'   => '*',
							'disc'     => '-',
						);
						$bullets_presentational = array(
							'circle'   => '•',
							'disc'     => '◦',
							'square'   => '▪',
							'triangle' => '‣',
							'dash'     => '⁃',
						);
						$bullets = 'syntax' === $options->display_mode
							? $bullets_syntax
							: $bullets_presentational;
						$style = $bullets_presentational[ $type ] ?? null;
						$style = $style ?? array_values( $bullets )[ $depths['UL'] % 5 ];
					} elseif ( 'OL' === $token_name ) {
						$style = in_array( $type, [ '1', 'a', 'A', 'i', 'I' ], true )
							? $type
							: [ '1', 'a', 'A', 'i', 'I' ][ $depths['OL'] % 5 ];

						$start = $p->get_attribute( 'start' );
						if (
							is_string( $start ) &&
							strspn( $start, '0123456789' ) === strlen( $start )
						) {
							$start = (int) $start;
						} else {
							$start = 1;
						}

						// @todo this would be better communicated structurally.
						$style = "{$style}.{$start}";
					}

					$stack[] = new Block_List( $style );
				}
				$depths[ $token_name ] += $is_closer ? -1 : 1;
				break;

			/*
			 * Tables deserve their own block type which maintains
			 * consistent widths for the cells, handles colspan
			 * widths, rowspan, etc. This gets very complicated so
			 * the current implementation does little more than to
			 * draw borders around the cells so they are visually
			 * separated.
			 */
			case 'TABLE':
				$close_a_paragraph();
				$options->soft_line_wrap = $is_closer ? $soft_limit : PHP_INT_MAX;
				break;

			case 'TD':
			case 'TH':
				if ( $is_closer ) {
					$line_buffer->append_text( ' | ' );
				}
				break;

			case 'TR':
				if ( $is_closer ) {
					$line_buffer->append_text( "\n" );
				} else {
					$line_buffer->append_text( '| ' );
				}
				break;
		}
	}

	// Try again if parsing fails and it’s possible to recover it via the DOM.
	if (
		( $p->paused_at_incomplete_token() || null !== $p->get_last_error() ) &&
		class_exists( '\DOM\HTMLDocument' ) && extension_loaded( 'libxml' )
	) {
		$dom = \DOM\HTMLDocument::createFromString( $html, LIBXML_NOERROR | LIBXML_HTML_NOIMPLIED );
		return html_to_md( $dom->saveHTML(), $options );
	}

	while ( count( $stack ) > 0 ) {
		$flush_block();
	}

	return $markdown;
}