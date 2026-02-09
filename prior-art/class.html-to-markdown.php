<?php

/*
 * This version explored the utility functions like “flush a line buffer” and
 * “append a text node” while trying to be a little more concrete than tree-building
 * versions.
 *
 * It suffered from some basic formatting issues that were confusing, with the
 * line buffer and structure too muddled to make coherent sense; bug fixes were too
 * complicated to reason about.
 */

/**
 * Class providing a generic HTML-to-Markdown transformation.
 *
 * This class is powered by the HTML API and will properly
 * understand HTML parsing semantics, meaning that it’s safe
 * to pass in HTML with “unbalanced” tags and other oddities.
 *
 * Markdown syntax is based off of the CommonMark spec with
 * (planned) extensions for GitHub Flavored Markdown (GFM).
 *
 * This class only examines HTML content and ignores CSS.
 * Conversion could be improved by examining inline styles
 * and linked stylesheets, were there a reliable means to
 * parse and understand them.
 *
 * Converting HTML into any number of text forms is a bit like
 * implementing part of a rendering engine. There is not a
 * direct deterministic conversion for most formats, including
 * Markdown, and some aspects of this class will be subjective.
 * When those cases arise they should be documented, and one
 * is welcome to improve the conversion (for example, when
 * determining how to break and wrap lines, or around which
 * elements to add newlines).
 *
 * @see https://spec.commonmark.org
 * @see https://github.github.com/gfm/
 */
class HtmlToMarkdown extends WP_HTML_Processor {
	/**
	 * Used to ensure that formatting boundaries apply as syntax.
	 *
	 * This is the INVISIBLE SEPARATOR
	 */
	const SEP = "\u{2063}";

	/**
	 * Converts a given HTML document into a corresponding Markdown document.
	 *
	 * Conversion in this function follows two primary stages:
	 *  - Conversion from HTML structure into Markdown blocks.
	 *  - Flushing lines that exist within those blocks.
	 *
	 * As content is appended, it doesn’t pass directly to the output. Instead,
	 * it builds in a line buffer inside a surrounding block, and then flushes
	 * at appropriate events. These events are things like the text reaching a
	 * certain width, or the start of a new block.
	 *
	 * Example:
	 *
	 *     $md = HtmlToMarkdown::convert( '<p><strong>Wow</strong>, you <em>really</em> believe that?</p>' );
	 *     $md === '*Wow*, your _really_ believe that?';
	 *
	 * @param string $html     HTML to convert.
	 * @param string $base_url Base URL for the page, if provided, otherwise inferred from the HTML.
	 * @param int    $width    Approximate max line length in count of grapheme clusters.
	 * @return string Markdown representation of input HTML.
	 */
	public static function convert( string $html, string $base_url = '', int $width = 80 ): string {
		$html = self::preprocess_input_stream( $html );

		/**
		 * Scans through the input HTML document.
		 */
		$scanner = WP_HTML_Processor::create_full_parser( $html );
		if ( null === $scanner ) {
			return self::fallback( $html );
		}

		/**
		 * Output buffer containing fully-processed Markdown text.
		 */
		$md = '';

		/**
		 * Stores type of every open un/ordered list and its counter.
		 * Used when flushing the line buffer into the output markdown.
		 *
		 * Currently only supports a static set of counter styles but
		 * could be expanded by examining the input HTML and CSS.
		 *
		 * Example:
		 *
		 *     // For <ol><li><li><ul><li>HERE</ul></ul>
		 *     array( '-', 2, 'lower-alpha', 1 );
		 */
		$ol_counts = array();

		/**
		 * Buffers the current line before performing indentation,
		 * prefixing, and line wrapping.
		 */
		$line = '';

		/**
		 * Stores attributes from the last-parsed tag, if set.
		 */
		$last_attrs = null;

		/**
		 * Temporarily traps the line buffer while processing links.
		 *
		 * @todo Turn this into a stack, to allow for things like extending
		 *       the number of backticks in use for inline code.
		 */
		$link_swap = '';

		/**
		 * How many nested EM/I tags there are. Used to determine whether
		 * to add '_' syntax or elide it as there can only be one level.
		 */
		$em_depth = 0;

		/**
		 * How many nested B/STRONG tags there are. Used to determine whether
		 * to add '_' syntax or elide it as there can only be one level.
		 */
		$strong_depth = 0;

		/**
		 * Counts words. Call ::setText() to update it.
		 */
		$word_iterator = IntlBreakIterator::createWordInstance();

		$flush_line = function () use ( $scanner, &$md, &$ol_counts, &$line, $width, $word_iterator ) {
			$first_prefix = '';
			$line_prefix  = '';
			$in_pre       = false;
			$no_newlines  = false;
			$list_depth   = 0;

			$breadcrumbs = array_slice( $scanner->get_breadcrumbs(), 2 );

			// Block-level elements create line prefixes. These go in order.
			foreach ( $breadcrumbs as $tag ) {
				switch ( $tag ) {
					case 'BLOCKQUOTE':
						$first_prefix .= '> ';
						$line_prefix  .= '> ';
						break;

					case 'CODE':
						if ( $in_pre ) {
							$first_prefix .= '    ';
							$line_prefix  .= '    ';
						}
						break;

					/*
					 * @todo For some reason the last list item in every list is missing
					 *       its marker _and_ indentation.
					 */
					case 'LI':
						if ( 0 === $list_depth ) {
							break;
						}

						$ld = $list_depth - 1;

						$list_type = $ol_counts[ $ld * 2 ];
						$count     = $ol_counts[ $ld * 2 + 1 ];

						$marker = self::list_marker( $list_type, '-' === $ol_counts[ $ld * 2 ] ? $list_depth : $count );
						$indent = str_pad( '', grapheme_strlen( $marker ), ' ' );

						if ( $list_depth !== ( count( $ol_counts ) / 2 ) ) {
							$marker = $indent;
						}

						$first_prefix .= "{$marker} ";
						$line_prefix  .= "{$indent} ";
						break;

					case 'PRE':
						$in_pre = true;
						break;

					case 'H1':
					case 'H2':
					case 'H3':
					case 'H4':
					case 'H5':
					case 'H6':
						$no_newlines = true;
						break;

					case 'OL':
					case 'UL':
						$list_depth++;
						break;
				}
			}

			if ( ! $in_pre ) {
				$line = trim( $line, " \t" );
			}

			if ( $no_newlines ) {
				$md .= "{$first_prefix}{$line}\n";
				return;
			}

			$prefix        = $line_prefix;
			$line_length   = grapheme_strlen( $first_prefix );
			$prefix_length = grapheme_strlen( $line_prefix );
			$word_iterator->setText( $line );
			foreach ( $word_iterator->getPartsIterator() as $i => $word ) {
				/*
				 * @todo Something is wrong with this, leading to eager wrapping
				 *       of paragraphs where they souldn’t wrap.
				 */
				if ( 1 === preg_match( "~^[\n]+$~", $word ) ) {
					$md         .= "{$word[0]}{$prefix}";
					$line_length = $prefix_length;
					continue;
				}

				if ( 0 === $i ) {
					$md .= $first_prefix;
				}

				$word_length = grapheme_strlen( $word );
				// Keep trailing punctuation on the same line.
				if ( $word_length + $line_length > $width && 1 !== preg_match( '~^[,.?!]+$~', trim( $word ) ) ) {
					$word        = ltrim( $word, " \t" );
					$md         .= "\n{$prefix}{$word}";
					$line_length = $prefix_length + $word_length;
				} else {
					$md          .= $word;
					$line_length += $word_length;
				}
			}

			$md  .= "\n";
			$line = '';
		};

		$append = function ( $chunk ) use ( $scanner, &$line ) {
			if ( ! in_array( 'PRE', $scanner->get_breadcrumbs(), true ) ) {
				$chunk = preg_replace( "~[ \t]+\n+~", "\n", $chunk );
				$chunk = preg_replace( "~[ \t]+~", ' ', $chunk );
				$chunk = preg_replace( "~[\f\n]+~", "\n", $chunk );
			}
			$line .= $chunk;
		};

		$remember = function ( $attributes ) use ( $scanner, &$last_attrs ) {
			$last_attrs = array();

			foreach ( $attributes as $attr ) {
				$value = $scanner->get_attribute( $attr );
				if ( true === $value ) {
					$value = '';
				}

				$last_attrs[ $attr ] = $value;
			}
		};

		while ( $scanner->next_token() ) {
			$token_name     = $scanner->get_token_name();
			$is_closer      = $scanner->is_tag_closer();
			$breadcrumbs    = array_slice( $scanner->get_breadcrumbs(), 2 ); // Chop off HTML and BODY

			switch ( $token_name ) {
				case '#text':
					$text = $scanner->get_modifiable_text();
					if ( 1 === preg_match( "~^[ ]*[\n]+$~", $text ) ) {
						break;
					}

					if ( ! in_array( 'PRE', $breadcrumbs, true ) ) {
						$text = self::escape_ascii_punctuation( $text );
					}

					$append( $text );
					break;

				case 'A':
					if ( $is_closer ) {
						$url = self::to_url( $last_attrs['href'] ?? '', $base_url );
						$url = self::escape_ascii_punctuation( $url );
						$link_label = trim( $line, " \t\f\n" );
						$line = $link_swap;

						$title = isset( $last_attrs['title'] )
							? (' "' . self::escape_ascii_punctuation( $last_attrs['title'] ) . '"' )
							: '';

						if ( empty( $url ) ) {
							$append( $link_label );
						} else {
							$append( "[{$link_label}]({$url}{$title})" );
						}
					} else {
						$remember( array( 'href', 'title' ) );
						$link_swap = $line;
						$line = '';
					}
					break;

				case 'B':
				case 'STRONG':
					$strong_depth += $is_closer ? -1 : 1;
					if (
						( 1 === $strong_depth && ! $is_closer ) ||
						( 0 === $strong_depth && $is_closer )
					) {
						$left_flank  = $is_closer ? '' : self::SEP;
						$right_flank = $is_closer ? self::SEP : '';
						$append( "{$left_flank}**{$right_flank}" );
					}
					break;

				case 'BASE':
					if ( ! empty( $base_url ) ) {
						break;
					}

					$href = $scanner->get_attribute( 'href' );
					if ( ! is_string( $href ) ) {
						$href = '';
					}
					$href = trim( $href, " \t\f\r\n" );

					if ( ! empty( $href ) ) {
						$base_url = self::to_url( $href, $base_url );
					}

					break;

				case 'BR':
					if ( strlen( $line ) > 0 ) {
						$append( '  ' );
					}
					$flush_line();
					break;

				// @todo Ensure that there isn’t a sequence of the code fence within the code.
				case 'CODE':
					if ( in_array( 'PRE', $breadcrumbs, true ) ) {
						if ( $is_closer ) {
							$flush_line();
							$append( "```" );
							$flush_line();
						} else {
							$flush_line();
							$append( '```' );
							$lang = '';
							// Try to extract the language from the CSS class names.
							foreach ( $scanner->class_list() as $class_name ) {
								$class_name = strtolower( $class_name );

								if ( str_starts_with( $class_name, 'language-' ) ) {
									$lang = substr( $class_name, strlen( 'language-' ) );
									break;
								}

								if ( in_array( $class_name, self::KNOWN_LANGUAGES, true ) ) {
									$lang = $class_name;
									break;
								}
							}

							$lang = trim( $lang, " \t" );
							if ( empty( $lang ) || str_ends_with( $lang, '`' ) ) {
								$lang = '';
							}

							// Look in specific attributes if the language isn’t yet inferred.
							if ( empty( $lang ) ) {
								foreach ( array( 'data-lang', 'data-language', 'data-codetag', 'syntax', 'data-programming-language', 'type' ) as $attribute ) {
									$data_lang = $scanner->get_attribute( $attribute );
									if ( is_string( $data_lang ) ) {
										$data_lang = trim( $data_lang, " \t" );
										if ( in_array( $data_lang, self::KNOWN_LANGUAGES, true ) ) {
											$lang = $data_lang;
											break;
										}
									}
								}
							}

							$lang = trim( $lang, " \t" );
							if ( empty( $lang ) || str_ends_with( $lang, '`' ) ) {
								$lang = '';
							}

							if ( ! empty( $lang ) ) {
								$append( $lang );
							}
						}
						$flush_line();
					} else {
						$append( '`' );
					}
					break;

				case 'H1':
				case 'H2':
				case 'H3':
				case 'H4':
				case 'H5':
				case 'H6':
					if ( $is_closer ) {
						$line = trim( $line, " \t" );
						$flush_line();
					} else {
						$append( "\n" );
						$flush_line();
						$append( str_repeat( '#', (int) $token_name[1] ) . ' ' );
					}
					break;

				case 'HR':
					$flush_line();
					$append( '***' ); // Use '*' to avoid clashes with settext_headings, which use '-'.
					$flush_line();
					break;

				case 'I':
				case 'EM':
					$em_depth += $is_closer ? -1 : 1;
					if (
						( 1 === $em_depth && ! $is_closer ) ||
						( 0 === $em_depth && $is_closer )
					) {
						$left_flank  = $is_closer ? '' : self::SEP;
						$right_flank = $is_closer ? self::SEP : '';
						$append( "{$left_flank}_{$right_flank}" );
					}
					break;

				case 'IMG':
					$alt = $scanner->get_attribute( 'alt' );
					$alt = is_string( $alt ) ? $alt : '';

					$url = self::to_url( trim( $scanner->get_attribute( 'src' ), " \t" ), $base_url );
					$url = self::escape_ascii_punctuation( $url );

					$title = $scanner->get_attribute( 'title' );
					if ( ! is_string( $title ) ) {
						$title = '';
					}
					if ( ! empty( $title ) ) {
						$title = ' "' . self::escape_ascii_punctuation( $title ) . '"';
					}

					$append( "![{$alt}]({$url}{$title})" );
					break;

				case 'LI':
					if ( $is_closer ) {
						break;
					}
					$flush_line();
					if ( count( $ol_counts ) > 0 ) {
						$ol_counts[ count( $ol_counts ) - 1 ]++;
					}
					break;

				case 'OL':
					$flush_line();
					if ( $is_closer ) {
						array_pop( $ol_counts );
						array_pop( $ol_counts );
					} else {
						$ol_counts[] = 'decimal';
						$ol_counts[] = 0;
					}
					break;

				case 'UL':
					$flush_line();
					if ( $is_closer ) {
						array_pop( $ol_counts );
						array_pop( $ol_counts );
					} else {
						$ol_counts[] = '-';
						$ol_counts[] = 0;
					}
					break;

				// Block-elements
				case 'BLOCKQUOTE':
				case 'P':
					$flush_line();
					break;
			}
		}

		if ( null !== $scanner->get_last_error() && strlen( $md ) < 50 ) {
			return self::fallback( $html );
		}

		$flush_line();

		return trim( $md );
	}

	/**
	 * Failure mechanism in case the HTML Processor is unable
	 * to fully parser the given HTML in {@see self::convert}
	 */
	private static function fallback( string $html ): string {
		/*
		 * This is the most primitive conversion and is essentially
		 * here to avoid throwing an exception, as _some_ output is
		 * better than _no_ output.
		 *
		 * In the future this should be resolved more adequately, or
		 * removed once the HTML Processor supports all HTML.
		 */
		$processor = new WP_HTML_Tag_Processor( $html );
		$output    = '';

		while ( $processor->next_token() ) {
			if ( '#text' === $processor->get_token_name() ) {
				$output .= $processor->get_modifiable_text();
			}
		}

		return $output;
	}

	/**
	 * Follows the HTML preprocess-the-input-stream algorithm.
	 *
	 * @see https://html.spec.whatwg.org/#preprocessing-the-input-stream
	 * @see https://infra.spec.whatwg.org/#normalize-newlines
	 *
	 * @param string $html Input HTML to preprocess.
	 * @return string Preprocessed output HTML.
	 */
	private static function preprocess_input_stream( string $html ): string {
		return str_replace( array( "\r\n", "\r" ), "\n", $html );
	}

	/**
	 * Escapes ASCII punctuation characters in plaintext so they won’t
	 * be interpreted as Markdown syntax.
	 *
	 * Send only plaintext chunks here which have already otherwise been
	 * processed and converted to Markdown.
	 *
	 * Example:
	 *
	 *     '1\. is not a list' === self::escape_ascii_punctuation( '1. is not a list' );
	 *
	 * @param string $plaintext
	 * @return string
	 */
	private static function escape_ascii_punctuation( string $plaintext ): string {
		return preg_replace_callback(
			"~[!\"#$%&'()*+,-./:;<=>?@\[\\\]^_`{|}\~]~",
			fn ( $m ) => "\\{$m[0]}",
			$plaintext
		);
	}

	/**
	 * Returns a list counter given a list type and count.
	 *
	 * Example:
	 *
	 *     '-'  === self::get_list_counter( '-', 1 );
	 *     '3.' === self::get_list_counter( 'decimal', '3' );
	 *
	 * @param string $list_type One of '-', 'decimal'.
	 * @param int    $count     Position of this list item in a list.
	 * @return string List counter as a string.
	 */
	private static function list_marker( string $list_type, int $count ): string {
		switch ( $list_type ) {
			case '-':
				// @todo Sibling lists should alternate between *, +, and -
				return '*+-'[ $count % 3 ];

			case 'decimal':
				/*
				 * > The reason for the length limit is that with 10 digits we start
				 * > seeing integer overflows in some browsers.
				 *
				 * @see https://spec.commonmark.org/0.31.2/#list-items
				 */
				return sprintf( '%d.', max( 1, min( $count, 999999999 ) ) );
		}

		// This should not be reachable.
		return '';
	}

	/**
	 * Normalizes URLs and joins a base URL to relative paths, if provided.
	 *
	 * @todo Implement this function.
	 *
	 * @param string $href     Absolute or relative HREF value from an A element.
	 * @param string $base_url Base URL for the HTML, if available.
	 * @return string Absolute and resolved URL associated with link.
	 */
	private static function to_url( string $href, string $base_url = '' ): string {
		if ( 1 !== preg_match( '~^(?:https?|mailto)://~', $href ) ) {
			$base = empty( $base_url ) ? '/' : $base_url;

			return "{$base}{$href}";
		}

		return $href;
	}

	const KNOWN_LANGUAGES = array(
		'apl', 'asm', 'assembly', 'bash', 'c', 'c#', 'c++', 'clojure', 'cobol', 'cpp', 'csharp',
		'css', 'd', 'dart', 'elixir', 'elm', 'erlang', 'f#', 'fish', 'fortran', 'fsharp', 'go',
		'groovy', 'guile', 'haskell', 'html', 'java', 'javascript', 'js', 'julia', 'kotlin',
		'less', 'lisp', 'lua', 'matlab', 'objectivec', 'objective-c', 'ocaml', 'perl', 'php',
		'powershell', 'python', 'python2', 'python3', 'r', 'racket', 'raku', 'ruby', 'rust',
		'sass', 'scala', 'scheme', 'sgml', 'sh', 'shell', 'sql', 'swift', 'typescript', 'ts',
		'vba', 'xml', 'zsh'
	);
}
