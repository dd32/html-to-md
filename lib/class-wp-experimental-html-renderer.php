<?php

namespace WordPress\Experiments\HtmlToMarkdown;

class WP_Experimental_HTML_Renderer {
	/**
	 * Input HTML document which will be rendered.
	 *
	 * @since {WP_VERSION}
	 *
	 * @var string
	 */
	private $html;

	/**
	 * Buffer holding the partial output while rendering, or
	 * the complete output once done rendering.
	 *
	 * @since {WP_VERSION}
	 *
	 * @var string
	 */
	private $output = '';

	/**
	 * Configured options for this rendering.
	 *
	 * @since {WP_VERSION}
	 *
	 * @var MD_Options
	 */
	private $options;

	/**
	 * Builds a line buffer while compiling block.
	 *
	 * @since {WP_VERSION}
	 *
	 * @var LineBuffer
	 */
	private $line_buffer;

	/**
	 * Maintains track of the number of open elements in the stack
	 * of each given type.
	 *
	 * @since {WP_VERSION}
	 *
	 * @var Array<string,int> $depths
	 */
	private $depths = array(
		'PRE' => 0,
		'OL'  => 0,
		'UL'  => 0,
	);

	/**
	 * Tracks open block containers.
	 *
	 * @var Array<Block>
	 */
	private $stack = array();

	public function __construct( string $html, ?MD_Options $options = new MD_Options() ) {
		$this->html        = $html;
		$this->options     = $options;
		$this->line_buffer = new LineBuffer();
	}

	public function to_markdown() {
		$p            = \WP_HTML_Processor::create_fragment( $this->html );
		$soft_limit   = $this->options->soft_line_wrap;
		$this->output = '';

		$node_finder = \apply_filters( 'html_to_markdown_starting_node_finder', null );
		if ( \is_callable( $node_finder ) ) {
			// If it failed to find something, show everything.
			if ( ! \call_user_func( $node_finder, $p ) ) {
				$p = \WP_HTML_Processor::create_fragment( $this->html );
			};
		}
		$main_depth = $p->get_current_depth();

		while ( $p->get_current_depth() >= $main_depth && $p->next_token() ) {
			$token_name = $p->get_token_name();
			$is_closer  = $p->is_tag_closer();

			if ( $this->skip_hidden_content( $p ) ) {
				continue;
			}

			switch ( $token_name ) {
				case '#text':
					$preserve_whitespace = $this->depths['PRE'] > 0;
					$chunk               = $p->get_modifiable_text();
					$chunk = $preserve_whitespace
						? $chunk
						: \preg_replace( '~[ \t\f\r\n]+~', ' ', $chunk );

					$this->line_buffer->append_text( $chunk );
					if (
						! ( \end( $this->stack ) instanceof Block_Paragraph ) &&
						$this->line_buffer->has_non_whitespace_content()
					) {
						$paragraph = new Block_Paragraph();
						$paragraph->append_line( $this->line_buffer );
						$this->stack[] = $paragraph;
					}
					break;

				case 'A':
					// @todo Join with base URL of document, if available, to form URL.
					if ( $is_closer ) {
						$this->line_buffer->release_format();
					} else {
						$href = $p->get_attribute( 'href' );
						if ( is_string( $href ) ) {
							$this->line_buffer->require_format( new InlineFormat_Link( $href ) );
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
						$this->line_buffer->release_format();
					} else {
						if ( 'CODE' === $token_name && \end( $this->stack ) instanceof Block_Code ) {
							\end( $this->stack )->infer_language( $p );
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
						$this->line_buffer->require_format( new InlineFormat_Generic( $format ) );
					}
					break;

				case 'BLOCKQUOTE':
					$this->close_a_paragraph();

					if ( $is_closer ) {
						$this->flush_block();
					} else {
						$this->stack[] = new Block_Blockquote();
					}
					break;

				case 'H1':
				case 'H2':
				case 'H3':
				case 'H4':
				case 'H5':
				case 'H6':
					$this->close_a_paragraph();

					if ( $is_closer ) {
						$this->flush_block();
					} else {
						$heading = new Block_ATX( (int) $token_name[1] );
						$heading->append_line( $this->line_buffer );
						$this->stack[] = $heading;
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
					$this->close_a_paragraph();
					$break = new Block_Paragraph();
					$this->line_buffer = new LineBuffer();
					$this->line_buffer->append_text( '---' );
					$break->append_line( $this->line_buffer );
					$this->stack[] = $break;
					$this->close_a_paragraph();
					break;

				case 'IMG':
					$src = $p->get_attribute( 'src' );
					if ( ! \is_string( $src ) || empty( \trim( $src ) ) ) {
						// Only consider images which contain some content.
						break;
					}

					$alt = $p->get_attribute( 'alt' );
					$alt = \is_string( $alt ) ? $alt : '';

					$title = $p->get_attribute( 'title' );
					$title = \is_string( $title ) ? $title : '';

					$this->line_buffer->require_format( new InlineFormat_Image( $src, $alt, $title ) );
					$this->line_buffer->release_format();
					break;

				case 'LI':
					$this->close_a_paragraph();
					if ( ! ( $is_closer || \end( $this->stack ) instanceof Block_List ) ) {
						$this->stack[] = new Block_List( '' );
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
					$this->close_a_paragraph();
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
					$this->close_a_paragraph();
					break;

				case 'PRE':
					$this->close_a_paragraph();
					if ( $is_closer ) {
						$this->flush_block();
					} else {
						$this->stack[] = new Block_Code();
						\end( $this->stack )->infer_language( $p );
					}
					$this->depths['PRE'] += $is_closer ? -1 : 1;
					break;

				case 'OL':
				case 'UL':
					$this->close_a_paragraph();

					if ( $is_closer ) {
						if ( $this->line_buffer->has_non_whitespace_content() ) {
							if ( \end( $this->stack ) instanceof Block_List ) {
								$item = new Block_Paragraph();
								$item->append_line( $this->line_buffer );
							} else {
								$item = array_pop( $this->stack );
							}

							\end( $this->stack )->append( $item );
						}

						$this->line_buffer = new LineBuffer();
						$this->flush_block();
					} else {
						if ( ! $this->line_buffer->has_non_whitespace_content() ) {
							$this->line_buffer = new LineBuffer();
						}

						$type  = $p->get_attribute( 'type' );

						if ( 'UL' === $token_name ) {
							$type = \is_string( $type ) ? \strtolower( \trim( $type, " \t\f\r\n" ) ) : '';
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
							$bullets = 'syntax' === $this->options->display_mode
								? $bullets_syntax
								: $bullets_presentational;
							$style = $bullets_presentational[ $type ] ?? null;
							$style = $style ?? \array_values( $bullets )[ $this->depths['UL'] % 5 ];
						} elseif ( 'OL' === $token_name ) {
							$style = \in_array( $type, [ '1', 'a', 'A', 'i', 'I' ], true )
								? $type
								: [ '1', 'a', 'A', 'i', 'I' ][ $this->depths['OL'] % 5 ];

							$start = $p->get_attribute( 'start' );
							if (
								\is_string( $start ) &&
								\strspn( $start, '0123456789' ) === \strlen( $start )
							) {
								$start = (int) $start;
							} else {
								$start = 1;
							}

							// @todo this would be better communicated structurally.
							$style = "{$style}.{$start}";
						}

						$this->stack[] = new Block_List( $style );
					}
					$this->depths[ $token_name ] += $is_closer ? -1 : 1;
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
					$this->close_a_paragraph();
					$this->options->soft_line_wrap = $is_closer ? $soft_limit : PHP_INT_MAX;
					break;

				case 'TD':
				case 'TH':
					if ( $is_closer ) {
						$this->line_buffer->append_text( ' | ' );
					}
					break;

				case 'TR':
					if ( $is_closer ) {
						$this->line_buffer->append_text( "\n" );
					} else {
						$this->line_buffer->append_text( '| ' );
					}
					break;
			}
		}

		// Handle parsing failures.
		if ( $p->paused_at_incomplete_token() || null !== $p->get_last_error() ) {
			switch ( $this->options->recovery_mode ) {
				// @todo: Add default 'reduced-fidelity' mode continuing with Tag Processor.

				case 'abort':
				default:
					/*
					 * @todo Returning `null` would be a clearer signature, but also requiring
					 *       typing the function as `?string`, and that calling code check the
					 *       output for nullity. This could be resolved with different public
					 *       interfaces that wrap the conditional response, for example, with
					 *       `wp_html_to_markdown()` and `wp_try_html_to_markdown()`, but with
					 *       better names.
					 */
					return '';
			}
		}

		while ( \count( $this->stack ) > 0 ) {
			$this->flush_block();
		}

		return $this->output;
	}

	private function flush_block() {
		$block = \array_pop( $this->stack );
		if ( null === $block || $block->is_empty() ) {
			return;
		}

		$parent = \end( $this->stack );
		if ( $parent instanceof Block ) {
			$parent->append( $block );
		} else {
			if ( '' !== $this->output ) {
				$this->output .= "\n" === $this->output[ \strlen( $this->output ) - 1 ] ? "\n" : "\n\n";
			}
			$this->output .= \ltrim( $block->flush( $this->options ), "\n" );
		}
	}

	private function close_a_paragraph() {
		if (
			\end( $this->stack ) instanceof Block_Paragraph &&
			$this->line_buffer->has_non_whitespace_content()
		) {
			$this->flush_block();
		} elseif ( $this->line_buffer->has_non_whitespace_content() ) {
			$paragraph = new Block_Paragraph();
			$paragraph->append_line( $this->line_buffer );
			$this->stack[] = $paragraph;
			$this->flush_block();
		}
		$this->line_buffer = new LineBuffer();
	}

	private function skip_hidden_content( $p ) {
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
			!( \is_string( $hidden ) && 0 === \strcasecmp( $hidden, 'until-found' ) )
		) {
			goto skip;
		}

		$hidden = $p->get_attribute( 'aria-hidden' );
		if ( \is_string( $hidden ) && 0 === \strcasecmp( $hidden, 'true' ) ) {
			goto skip;
		}

		return false;

		skip:
		if ( !$p->expects_closer() ) {
			return true;
		}

		$depth = $p->get_current_depth();
		while ( $p->next_token() && $depth <= $p->get_current_depth() ) {
			continue;
		}
		return true;
	}
}