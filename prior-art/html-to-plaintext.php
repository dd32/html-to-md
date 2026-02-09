<?php

/*
 * This version leaned heavily into the pre- and post-markup required when entering
 * and exiting blocks. It introduced a budget and truncation bounds, demonstrating how
 * to generate excerpts and limited conversions, showing more of the long-term API that
 * appears out this more-generic process of rendering HTML to some plaintext form.
 */

/**
 * Returns up to the requested number of words of the plaintext content of an HTML string,
 * up to a max number of code points.
 *
 * @todo Use accessibility tree to aid in determining what to replace.
 * @todo Support tables in some way.
 * @todo Support PRE, which implies nesting sections. This may be out of scope until
 *       further needs are evident.
 * @todo Create a record class for the options to better self-document?
 *
 * @param string     $html            Input HTML from which to extract the plaintext.
 * @param array|null $options {
 *     Truncation options.
 *
 *     @type int|null $max_words      Optional. Stop extracting after having read this many words.
 *                                    Default (null) is to apply no limit.
 *     @type int|null $max_codepoints Optional. Stop extracting after this hard limit on total code
 *                                    points; this will interrupt words but preserve grapheme clusters.
 *                                    Default (null) is to apply no limit.
 * }
 * @return string Content similar to `.innerText` from the DOM.
 */
function html_to_plaintext( string $html, ?array $options = null ): string {
	$word_count       = 0;
	$code_point_count = 0;
	$plaintext        = '';
	$entering_block   = false;
	$exiting_block    = false;
	$last_word_end    = 0;
	$budget           = 10000;
	$li_depth         = 0;
	$indent           = '  ';
	$href             = '';
	$post_flush       = '';
	$pre_flush        = '';
	$bullets          = array(
		"\u{2022}", // • BULLET
		"\u{2043}", // ⁃ HYPHEN BULLET
		"\u{2023}", // ‣ TRIANGULAR BULLET
		"\u{25E6}", // ◦ WHITE BULLET
	);
	$bullet_count     = count( $bullets );
	$max_words        = $options['max_words'] ?? PHP_INT_MAX;
	$max_codepoints   = $options['max_code_points'] ?? PHP_INT_MAX;

	$custom_processor = new class( '', WP_HTML_Processor::CONSTRUCTOR_UNLOCK_CODE ) extends WP_HTML_Processor {
		public function is_inter_element_whitespace(): bool {
			if ( self::STATE_TEXT_NODE !== $this->parser_state ) {
				return false;
			}

			$this->set_bookmark( 'here' );
			$here = $this->bookmarks['_here'];

			return strspn( $this->html, " \t\f\n\r", $here->start, $here->length ) === $here->length;
		}

		public function skip_element(): bool {
			$depth = $this->get_current_depth();

			while ( null !== ( $did_seek = $this->next_token() ) && $this->get_current_depth() >= $depth ) {
				continue;
			}

			return $did_seek;
		}
	};

	$word_segmenter = \IntlBreakIterator::createWordInstance( 'en-US' );
	$processor      = $custom_processor::create_fragment( $html );

	while ( --$budget > 0 && $word_count < $max_words && $code_point_count < $max_codepoints && $processor->next_token() ) {
		$token_name = $processor->get_token_name();
		$token_type = $processor->get_token_type();
		$is_closer  = $processor->is_tag_closer();

		if ( '#tag' === $token_type ) {
			// Skip hidden elements.
			if (
				null !== $processor->get_attribute( 'hidden' ) ||
				'true' === strtolower( $processor->get_attribute( 'aria-hidden' ) ?? '' ) ||
				'button' === $processor->get_attribute( 'role' ) ||
				$processor->has_class( 'p2-hovercard' )
			) {
				$processor->skip_element();
				continue;
			}

			// Skip invisible elements.
			$style = $processor->get_attribute( 'style' );
			if ( is_string( $style ) && 1 === preg_match( "~(?:^|[; \t\n\r\f])(?:display:[ \t\f\r\n]*none|visibility:[ \t\f\r\n]*hidden)[ \t\f\r\n]*(?:;|$)~", $style ) ) {
				$processor->skip_element();
				continue;
			}

			// Handle preformatted elements.
			// Disabled because this needs to close out any pending sections before printing.
			if ( ':PRE' === $token_name ) {
				$current_depth = $processor->get_current_depth();
				$pre_text      = '';
				while ( $processor->next_token() && $processor->get_current_depth() >= $current_depth ) {
					if ( '#text' === $processor->get_token_name() ) {
						$pre_text .= $processor->get_modifiable_text();
					}
				}

				$pre_at   = 0;
				$pre_end  = strlen( $pre_text );
				$indented = '';
				$indent   = '    ';
				while ( $pre_at < $pre_end ) {
					$newline_at = strpos( $pre_text, "\n", $pre_at );
					if ( false === $newline_at ) {
						$line      = substr( $pre_text, $pre_at );
						$indented .= "{$indent}{$line}";
						break;
					}

					$line      = substr( $pre_text, $pre_at, $newline_at - $pre_at );
					$indented .= "{$indent}{$line}\n";
					$pre_at    = $newline_at + 1;
				}

				$plaintext .= "\n{$indent}```\n{$indented}\n{$indent}```\n";

				continue;
			}
		}

		switch ( $token_name ) {
			case '#text':
				$wanted_code_points = $max_codepoints - $code_point_count;
				$chunk              = $processor->get_modifiable_text();
				$chunk_length       = mb_strlen( $chunk );

				if (
					$processor->is_inter_element_whitespace() &&
					// @todo This should work better, and is probably confused with the entry/exit conditions.
					strlen( $plaintext ) > 0 &&
					1 === strspn( $plaintext, " \t\f\n\r", strlen( $plaintext ) - 1 )
				) {
					break;
				}

				$chunk = preg_replace( '~[ \t\f\r\n]+~', ' ', $chunk );

				if ( '' !== $pre_flush ) {
					$plaintext        .= $pre_flush;
					$code_point_count += mb_strlen( $pre_flush );
					$pre_flush    = '';
				}

				if ( $exiting_block ) {
					$plaintext .= "\n";
					++$code_point_count;
					$exiting_block = false;
				}

				if ( $entering_block ) {
					$plaintext .= "\n";
					++$code_point_count;
					$entering_block = false;
				}

				if ( '' !== $post_flush ) {
					$plaintext        .= $post_flush;
					$code_point_count += mb_strlen( $post_flush );
					$post_flush    = '';
				}

				if ( $chunk_length > $wanted_code_points ) {
					/** @todo Use {@see \grapheme_extract()} to avoid splitting grapheme. */
					/** @todo Toggle for cut-before vs. cut-after? */
					$orig = $chunk;
					$chunk = mb_substr( $chunk, 0, $wanted_code_points );
					$chunk .= grapheme_extract( $orig, 1, GRAPHEME_EXTR_COUNT, strlen( $chunk ) );
				}

				if ( 1 === strspn( $chunk, " \t\f\n", 0, 1 ) ) {
					$plaintext .= ltrim( $chunk, ' ' );
				} else {
					$plaintext .= $chunk;
				}

				$code_point_count += min( $wanted_code_points, $chunk_length );

				$word_segmenter->setText( $plaintext );
				$word_at = $last_word_end;
				while ( --$budget && $word_count < $max_words ) {
					$next_break_at = $word_segmenter->following( $word_at );
					if ( \IntlBreakIterator::DONE === $word_segmenter->getRuleStatus() || -1 === $next_break_at ) {
						break;
					}

					if ( \IntlBreakIterator::WORD_NONE === $word_segmenter->getRuleStatus() ) {
						$word_at = $next_break_at;
						continue;
					}

					/*
					 * Since this is building the plaintext as it proceeds, there could be a word boundary
					 * at the end of the string which is part of a word from the next chunk. For example,
					 * the HTML input `ba<em>zin</em>ga!` contains a word break at the end of each text
					 * node. Therefore, instead of immediately counting word breaks at the end as an actual
					 * word boundary, defer checking until the next chunk has been appended.
					 */
					if ( $next_break_at >= strlen( $plaintext ) ) {
						break;
					}

					// This is therefore the last break after a word that is not the end of the string.
					$last_word_end = $next_break_at;
					++$word_count;
					$word_at = $last_word_end;
				}
				break;

			// Currently deactivated because of issues making links readable with their URLs.
			case ':A':
				if ( $is_closer && '' !== $href ) {
					$post_flush .= "]({$href})";
				} else {
					$href = $processor->get_attribute( 'href' );
					if ( ! is_string( $href ) || '' === $href || '#' === $href[0] || '?' === $href[0] ) {
						$href = '';
					} else {
						$post_flush .= '[';
					}
				}
				break;

			case 'BR':
				$pre_flush = "\n";
				break;

			// Disabled because it probably fails when entering and existing a block, and with PRE wrappers.
			case ':CODE':
				$plaintext .= '`';
				break;

			case 'IMG':
				$alt = $processor->get_attribute( 'alt' );
				if ( is_string( $alt ) && ! empty( $alt ) ) {
					$pre_flush .= "[{$alt}]";
				}
				break;

			case 'ADDRESS':
			case 'ARTICLE':
			case 'ASIDE':
			case 'DIV':
			case 'FOOTER':
			case 'HEADER':
			case 'MAIN':
			case 'P':
			case 'SECTION':
				if ( $is_closer ) {
					$exiting_block = true;
				} else {
					$entering_block = true;
				}
				break;

			case 'UL':
			case 'OL':
				$li_depth += $processor->is_tag_closer() ? -1 : 1;
				if ( $is_closer ) {
					$exiting_block = true;
				} else {
					$entering_block = true;
				}
				break;

			case 'H1':
			case 'H2':
			case 'H3':
			case 'H4':
			case 'H5':
			case 'H6':
				if ( $is_closer ) {
					$exiting_block = true;
				} else {
					$entering_block = true;
					$post_flush    .= str_repeat( '#', intval( $token_name[1] ) ) . ' ';
				}
				break;

			case 'BLOCKQUOTE':
				if ( $is_closer ) {
					$exiting_block = true;
				} else {
					$entering_block = true;
					$post_flush    .= '> ';
				}
				break;

			case 'LI':
				if ( $is_closer ) {
					$exiting_block = true;
				} else {
					$entering_block = true;
					$post_flush     = str_repeat( $indent, $li_depth ) . " {$bullets[ ( $li_depth - 1 ) % $bullet_count ]} ";
				}
				break;

			case 'MATH':
				$current_depth  = $processor->get_current_depth();
				$done_with_math = false;
				while ( $processor->next_token() && $processor->get_current_depth() >= $current_depth ) {
					if ( $done_with_math ) {
						continue;
					}

					if ( 'ANNOTATION' === $processor->get_tag() ) {
						// Only use the default alternate-representation form.
						if (
							is_string( $processor->get_attribute( 'name' ) ) &&
							'alternate-representation' !== $processor->get_attribute( 'name' )
						) {
							continue;
						}

						// Stick with text-based forms.
						if (
							is_string( $processor->get_attribute( 'encoding' ) ) &&
							str_starts_with( $processor->get_attribute( 'encoding' ), 'image/' )
						) {
							continue;
						}

						$annotation       = '';
						$annotation_depth = $processor->get_current_depth();
						while ( $processor->next_token() && $processor->get_current_depth() > $annotation_depth ) {
							if ( '#text' === $processor->get_token_name() ) {
								$annotation .= $processor->get_modifiable_text();
							}
						}
						$done_with_math = true;
						$pre_flush    .= $annotation;
					}
				}
				if ( ! $done_with_math ) {
					$label     = $processor->get_attribute( 'aria-label' );
					$pre_flush .= is_string( $label ) ? "[{$label}]" : "\n[missing formula]";
				}
				break;

			case 'SVG':
				$entering_block = true;
				$label          = $processor->get_attribute( 'aria-label' );
				$pre_flush     .= is_string( $label ) ? "[{$label}]" : "\n[SVG graphic]";
				$processor->skip_element();
				break;

			case 'BUTTON':
			case 'DEL':
			case 'FORM':
			case 'NAV':
			case 'TEMPLATE':
				$entering_block = true;
				$exiting_block  = true;
				$processor->skip_element();
				break;
		}
	}

	if ( $processor->paused_at_incomplete_token() ) {
		error_log( "\e[31mpaused at incomplete token\e[m\n" );
	} elseif ( null !== $processor->get_last_error() ) {
		error_log( "\e[31merror: \e[3m{$processor->get_last_error()}\e[m\n" );
	}

	$trimmed      = trim( $plaintext, " \t\f\r\n" );
	$line_trimmed = strtr( $trimmed, array( "\n " => "\n", " \n" => "\n" ) );

	return $line_trimmed;
}
