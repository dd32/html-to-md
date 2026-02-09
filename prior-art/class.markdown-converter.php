<?php

/*
 * This approach leaned into the concept of entering and exiting blocks, and it explored
 * escaping and how it could be done more fully or visually. It determined that there are
 * many ways to analyze the output such that escaping can be avoided in many cases, but
 * failed to get the right paragraphing and wrapping characteristics.
 *
 * The inclusion of ANSI text formatting support highlights how this converter can not
 * only output to Markdown, but serve as a reasonable viewer for HTML pages in a shell.
 *
 * Built roughly around April 2025.
 */

class Inline {
	/**
	 * Type of inline span. E.g. "bold" or "code".
	 *
	 * @var string
	 */
	public string $name;

	/**
	 * Text of span before word-wrapping and prefixing.
	 *
	 * @var string
	 */
	public string $buffer = '';

	public function __construct( string $name ) {
		$this->name = $name;
	}

	public function append( string $s ) {
		$this->buffer .= $s;
	}
}

class Block {
	public string $name;

	/**
	 * Original depth in HTML where the block opened.
	 *
	 * This is used for knowing when to close a block.
	 */
	public int $depth_in_html;

	/**
	 * @var Inline[]
	 */
	public array $inlines = [];

	public function __construct( string $name, int $depth_in_html ) {
		$this->name = $name;
		$this->depth_in_html = $depth_in_html;
		$this->inlines[] = new Inline( 'root' );
	}

	public function append( string $s ) {
		if ( 'text' !== $this->inlines[ count( $this->inlines ) - 1 ]->name ) {
			$this->new_inline( 'text' );
		}
		$this->inlines[ count( $this->inlines ) - 1 ]->append( $s );
	}

	public function new_inline( string $name, string $content = '' ) {
		$this->inlines[] = new Inline( $name );
		if ( '' !== $content ) {
			$this->inlines[ count( $this->inlines ) - 1 ]->buffer = $content;
		}
	}
}


class MarkdownConverter {
	private WP_HTML_Processor $scanner;

	const PRE_START_MARKER = "\u{E000}";
	const PRE_END_MARKER = "\u{E001}";

	/**
	 * @var Block[]
	 */
	private array $open_blocks = array();

	/**
	 * Indicates recursive depth of given HTML elements, where
	 * 0 indicates that the element is not open.
	 *
	 * @var int[]
	 */
	private array $depths = array(
		'phrasing'   => 0,
		'strong'     => 0,
		'emphasis'   => 0,
		'pre'        => 0,
		'code'       => 0,
		'blockquote' => 0,
		'quote'      => 0,
		'table-cell' => 0,
		'table-row'  => 0,
	);

	/**
	 * How many grapheme clusters to print per line.
	 */
	private int $width;

	private bool $with_ansi_codes = false;

	private array $ansi_stack = array();

	/**
	 * Splits text on word boundaries.
	 */
	private IntlBreakIterator $worder;

	private string $output = '';

	/**
	 * Private constructor: Use convert() instead.
	 */
	private function __construct( string $html ) {
		$this->scanner = WP_HTML_Processor::create_full_parser( $html );
		$this->open_blocks[] = new Block( 'root', 0 );
	}

	/**
	 * Converts HTML content to Markdown format.
	 *
	 * @param string $html     The HTML content to be converted.
	 * @param string $base_url Optional base URL to resolve relative URLs. Default is an empty string.
	 * @param int $width       Optional maximum line width for the output Markdown in grapheme clusters.
	 *                         Default is 80.
	 * @return string The converted Markdown content.
	 */
	public static function convert( string $html, string $base_url = '', int $width = 80, bool $with_ansi_codes = false ): string {
		$scanner = new self( $html );
		$scanner->width = $width;
		$scanner->with_ansi_codes = $with_ansi_codes;

		// @todo Support locales
		$scanner->worder = IntlRuleBasedBreakIterator::createWordInstance( 'en-US' );

		while ( $scanner->md_step() ) {
			continue;
		}

		// Remove successive newlines, since I’ve failed to figure out how to prevent this.
		$at = 0;
		$output = $scanner->output;
		$collapsed = '';
		while ( $at < strlen( $output ) ) {
			$pre_at = strpos( $output, self::PRE_START_MARKER, $at );
			if ( false === $pre_at ) {
				$collapsed .= substr( $output, $at );
				break;
			}

			$chunk = substr( $output, $at, $pre_at - $at );
			$chunk = preg_replace( "~(?:\r\n|\n){3,}~", "\n\n", $chunk );
			$chunk = preg_replace( '~ +~', ' ', $chunk );
			$collapsed .= $chunk;

			$pre_end = strpos( $output, self::PRE_END_MARKER, $pre_at );
			if ( false === $pre_end ) {
				throw new Error( "Invalid PRE. Maybe it contained the end marker?" );
			}

			$collapsed .= substr( $output, $pre_at + 3, $pre_end - $pre_at - 3 );
			$at = $pre_end + 3;
		}

		return $collapsed;
	}

	private function md_step() {
		if ( ! $this->scanner->next_token() ) {
			return false;
		}

		/*
		 * Nothing actually occurs until we reach a text node, other than
		 * some extra void elements like HR and BR (which itself acts like
		 * a text node with fixed content of a newline). Waiting until a
		 * text node is what keeps empty and nested elements from creating
		 * too many consecutive blank lines.
		 */
		$token_type = $this->scanner->get_token_type();
		if ( '#text' === $token_type ) {
			$in_phrasing = (
				$this->depths['phrasing'] > 0 ||
				1 !== preg_match( "~^[ \t\f\r\n]*$~", $this->scanner->get_modifiable_text() )
			);

			$this->scanner->subdivide_text_appropriately();
			// @todo Inter-element whitespace does not contain encoded chars.
			$is_whitespace = 1 === preg_match( "~^[ \t\f\r\n]*$~", $this->scanner->get_modifiable_text() );

			// Skip whitespace before the document begins
			if ( $is_whitespace && 0 === count( $this->open_blocks ) ) {
				return $this->md_step();
			}

			// Skip inter-element whitespace
			// @todo This is broken and needs redoing.
			if ( ! $in_phrasing ) {
				return $this->md_step();
			}

			$text = $this->scanner->get_modifiable_text();
			if ( 0 === $this->depths['pre'] ) {
				$text = preg_replace( "~(?:\r\n|[ \t\f\r\n])+~", ' ', $text );
			}
			$this->open_blocks[ count( $this->open_blocks ) - 1 ]->append( $text );
			return true;
		}

		$token_name = $this->scanner->get_token_name();
		$token_type = $this->scanner->get_token_type();
		$is_closer  = $this->scanner->is_tag_closer();
		$depth      = $this->scanner->get_current_depth();
		$tag_delta  = $is_closer ? -1 : 1;
		if ( self::is_phrasing_content( $token_name ) ) {
			$this->depths['phrasing'] += $tag_delta;
		}

		// @todo What if this is a table cell inside a table? We can’t hide the cell.
		if ( '#tag' !== $token_type || null !== $this->scanner->get_attribute( 'hidden' ) ) {
			return $this->md_step();
		}

		$key = match ( $token_name ) {
			'B', 'STRONG' => 'strong',
			'I', 'EM'     => 'emphasis',
			'BLOCKQUOTE'  => 'blockquote',
			'CODE'        => 'code',
			'PRE'         => 'pre',
			'Q'           => 'quote',
			'TD', 'TH'    => 'table-cell',
			'TR'          => 'table-row',
			default       => null
		};

		if ( isset( $key ) ) {
			$this->depths[ $key ] += $tag_delta;
			if (
				( 1 === $this->depths[ $key ] && ! $is_closer ) ||
				( 0 === $this->depths[ $key ] && $is_closer )
			) {
				if ( 'PRE' === $token_name || 0 === $this->depths[ 'pre' ] ) {
					$this->open_blocks[ count( $this->open_blocks ) - 1 ]->new_inline( $key, $is_closer ? '-' : '+' );
				}
			}
		}

		switch ( $token_name ) {
			case 'BR':
				$this->open_blocks[ count( $this->open_blocks ) - 1 ]->new_inline( 'newline' );
				break;

			case 'HR':
				if ( 0 === $this->depths['pre'] ) {
					$this->open_blocks[ count( $this->open_blocks ) - 1 ]->new_inline( 'horizontal-separator' );
				}
				break;

			case 'MATH':
			case 'NOSCRIPT':
			case 'SVG':
			case 'TEMPLATE':
				while ( $this->scanner->next_token() && $this->scanner->get_current_depth() >= $depth ) {
					continue;
				}
				break;
		}

		$block_name = self::as_md_block( $token_name );
		if ( isset( $block_name ) ) {
			// Are we still re-opening an already-opened block?
			$open_block = $this->open_blocks[ count( $this->open_blocks ) - 1 ];
			if ( ! $is_closer ) {
				$this->open_blocks[] = new Block( $block_name, $depth );
				return true;
			}

			// When visiting tag closers, the depth is already decremented.
			if ( $depth <= $open_block->depth_in_html ) {
				$this->close_block();
				return true;
			}
		}

		return true;
	}

	private function close_block() {
		$prefix0 = '';
		$prefixN = '';

		foreach ( $this->open_blocks as $block ) {
			// @todo Check if the block is empty and skip if it is.

			switch ( $block->name ) {
				case 'blockquote':
					$prefix0 .= '> ';
					$prefixN .= '> ';
					break;

				// @todo Save list types and apply here.
				case 'list':
					$prefix0 .= ' * ';
					$prefixN .= '   ';
					break;

				// @todo Examine inside to see about how many fences are required.
				case 'code':
					$prefix0 .= '    ';
					$prefixN .= '    ';
					break;

				case 'table':
//					$prefix0 .= '|';
//					$prefixN .= '|';
					break;
			}
		}

		$inside_a_table = false;
		foreach ( $this->open_blocks as $block ) {
			if ( 'table' === $block->name ) {
				$inside_a_table = true;
				break;
			}
		}

		$block = array_pop( $this->open_blocks );
		if ( ! isset( $block ) ) {
			throw new Error( "Missing block" );
		}

		// Is the block empty?
		if ( 'pre' !== $block->name ) {
			// @todo This crashes the single-page from a memory leak, but where?
			$all_empty = true;
			foreach ( $block->inlines as $inline ) {
				if (
					'text' === $inline->name &&
					strlen( $inline->buffer ) !== strspn( $inline->buffer, " \t\f\r\n" )
				) {
					$all_empty = false;
					break;
				}
			}
			if ( $all_empty ) {
//				return;
			}
		}

		$prefix_length = strlen( $prefix0 );
		$line_length   = $this->width - $prefix_length;

		$get_body = function() use ( $block, $prefix0, $prefixN, $prefix_length, $line_length, $inside_a_table ) {
			$buffer = $this->join_inlines( $block->inlines );

			$length = 0;
			$output = $prefix0;
			$should_wrap = 0 === $this->depths['pre'] && ! $inside_a_table;
			if ( $should_wrap ) {
				$this->worder->setText( $buffer );
				$words               = $this->worder->getPartsIterator();
				$word                = '';
				$in_control_sequence = false;
				foreach ( $words as $chunk ) {
					$word .= $chunk;

					if ( "\e" === $chunk ) {
						$in_control_sequence = true;
						continue;
					}

					if ( $in_control_sequence ) {
						$in_control_sequence = !str_ends_with( $chunk, 'm' );
						continue;
					}

					if ( 1 === preg_match( "~^[\d!?.,`|;:%</\\\>_-]~", $chunk ) ) {
						continue;
					}

					$word_length = grapheme_strlen( $word );
					$ends_in_ws  = 1 === preg_match( "~^\p{Z}+$~", $word, $trailing_ws );
					if ( $ends_in_ws ) {
						$word_length -= grapheme_strlen( $trailing_ws[0] );
					}

					if (
						$prefix_length + $word_length >= $this->width ||
						$length + $word_length < $line_length
					) {
						$length += $word_length;
						$output .= $word;
						$word   = '';
						continue;
					}

					$output .= preg_replace( "~\p{Z}$~", '', "\n{$prefixN}{$word}" );
					$length = $word_length;
					$word   = '';
				}
			} else {
				$lines = explode( "\n", $buffer );
				foreach ( $lines as $i => $line ) {
					$output .= ( 0 === $i ? $prefix0 : "\n{$prefixN}" ) . $line;
				}
			}

			$output .= "\n";
			return $output;
		};

		switch ( $block->name ) {
			case 'blockquote':
				$this->output .= $this->ansi( "\e[36;3m" ) . $get_body() . $this->ansi() . "\n";
				break;

			case 'H1':
			case 'H2':
			case 'H3':
			case 'H4':
			case 'H5':
			case 'H6':
				$level = intval( $block->name[1] );
				$this->output .= $this->ansi( "\e[33;1m" );
				$this->output .= str_repeat( '#', $level ) . ' ' . $get_body() . "\n";
				$this->output .= $this->ansi();
				break;

			case 'pre':
				$longest = 0;
				preg_replace_callback(
					'~`+~',
					function ( $match ) use ( &$longest ) {
						$longest = max( $longest, strlen( $match[0] ) + 1 );
						return $match[0];
					},
					$get_body()
				);

				// @todo Verify max length of code fence syntax
				$fence = str_repeat( '`', max( 3, $longest ) );
				// @todo Verify that the start and end tokens (U+E000 and U+E001) are not in the content.
				$body = self::PRE_START_MARKER . $get_body() . self::PRE_END_MARKER;
				$this->output .= $this->ansi( "\e[91m" ) . "\n{$fence}\n{$body}\n{$fence}\n" . $this->ansi();
				break;

//			case 'table-row':
//				$this->output .= str_replace( "\n", ' ', $get_body() ) . "|\n";
//				break;

			case 'table':
				$body = $get_body();
				$widths = array();
				$lines = explode( "\n", $body );
				foreach ( $lines as $line ) {
					foreach ( preg_split( '~(?<!\\\\)\\|~', $line, -1, PREG_SPLIT_DELIM_CAPTURE ) as $c => $cell ) {
						$without_ansi = $this->with_ansi_codes
							? preg_replace( "~\e[[^m]+~", '', $cell )
							: $cell;

						$widths[ $c ] = max( $widths[ $c ] ?? 0, grapheme_strlen( $without_ansi ) );
					}
				}
				$aligned = '';
				foreach ( $lines as $line ) {
					foreach ( preg_split( '~(?<!\\\\)\\|~', $line, -1, PREG_SPLIT_DELIM_CAPTURE ) as $c => $cell ) {
						$ansi_length = 0;
						preg_replace_callback(
							"~\e[[^m]+~",
							function ( $m ) use ( &$ansi_length ) {
								$ansi_length .= strlen( $m[0] );
								return $m[0];
							},
							$cell
						);
						$aligned .= '| ' . str_pad( $cell, $ansi_length + ( $widths[ $c ] ?? 0 ), ' ', STR_PAD_LEFT );
					}
					$aligned .= "\n";
				}
				$this->output .= "{$aligned}\n";
				break;

			default:
				$this->output .= $get_body() . "\n";
		}
	}

	private function join_inlines( array $inlines, array $stack = [], string $buffer = '' ): string {
		if ( 0 === count( $inlines ) ) {
			// @todo This occurs on DD/DT pairs since they are unbalanced.
			return "{$buffer}\n" . implode( '', $inlines );
		}

		$inline = array_shift( $inlines );
		if ( 'newline' === $inline->name ) {
			$inline->name = 'text';
			$inline->buffer = "\n";
		} elseif ( 'horizontal-separator' === $inline->name ) {
			$inline->name = 'text';
			$inline->buffer = "\n---\n";
		}

		if ( 'text' === $inline->name ) {
			$text = self::escape_text( $inline->buffer );

			if ( count( $stack ) > 0 ) {
				$stack[ count( $stack ) - 1 ] .= $text;
				return $this->join_inlines( $inlines, $stack, $buffer );
			}

			return $this->join_inlines( $inlines, $stack, $buffer . $text );
		}

		if ( '+' === $inline->buffer ) {
			$stack[] = '';
			return $this->join_inlines( $inlines, $stack, $buffer );
		}

		$body = array_pop( $stack );
		// @todo Determine whether to insert invisible separators to ensure proper Markdown parsing of the syntax.
		$left_flank = substr( $buffer, -1 );
		$right_flank = isset( $inlines[0] ) ? $inlines[0] : null;
		switch ( $inline->name ) {
			case 'strong':
				$body = str_replace( '*', '\*', $body );
				$buffer .= $this->ansi( "\e[1m" ) . "**{$body}**" . $this->ansi();
				break;

			case 'emphasis':
				$body = str_replace( '_', '\_', $body );
				$buffer .= $this->ansi( "\e[3m" ) . "_{$body}_" . $this->ansi();
				break;

			case 'code':
				$body = str_replace( '`', '\`', $body );
				$buffer .= $this->ansi( "\e[94m" ) . "`{$body}`" . $this->ansi();
				break;

			case 'quote':
				$buffer .= "“{$body}”";
				break;

			case 'table-cell':
				$body = str_replace( '|', '\\|', $body );
				$buffer .= "| {$body} ";
				break;

			case 'table-row':
				$body = str_replace( "\n", '', $body );
				$buffer .= "{$body}|\n";
				break;
		}

		return $this->join_inlines( $inlines, $stack, $buffer );
	}

	private function ansi( ?string $new_ansi_sequence = null ): string {
		if ( ! $this->with_ansi_codes ) {
			return '';
		}

		if ( isset( $new_ansi_sequence ) ) {
			$this->ansi_stack[] = $new_ansi_sequence;
			return $new_ansi_sequence;
		} else {
			array_pop( $this->ansi_stack );
			$codes = implode( '', $this->ansi_stack );
			return "\e[m{$codes}";
		}
	}

	private function append( string $text ): void {
		$this->open_blocks[ count( $this->open_blocks ) - 1 ]->append( $text );
	}

	private function escape_text( string $text ): string {
		return $text;
	}

	private static function as_md_block( string $token_name ): ?string {
		switch ( $token_name ) {
			case 'BLOCKQUOTE':
				return 'blockquote';

			case 'H1':
			case 'H2':
			case 'H3':
			case 'H4':
			case 'H5':
			case 'H6':
				return $token_name;

			case 'DL':
			case 'OL':
			case 'UL':
				return 'list';

			case 'LI':
				return 'list-item';

			case 'PRE':
				return 'pre';

			case 'TABLE':
				return 'table';

//			case 'TR':
//				return 'table-row';
		}

		$is_blocky = self::is_palpable_content( $token_name ) && ! self::is_phrasing_content( $token_name );

		return $is_blocky ? 'paragraph' : null;
	}

	private static function is_phrasing_content( string $token_name ): bool {
		// Autonomous custom elements.
		$dash_at = strpos( $token_name, '-' );
		if ( $dash_at > 0 ) {
			return true;
		}

		return in_array(
			$token_name,
			array(
				'A',
				'ABBR',
				'AREA', // @todo † if it is a descendant of a MAP element.
				'AUDIO',
				'B',
				'BDI',
				'BDO',
				'BR',
				'BUTTON',
				'CANVAS',
				'CITE',
				'CODE',
				'DATA',
				'DATALIST',
				'DEL',
				'DFN',
				'EM',
				'EMBED',
				'I',
				'IFRAME',
				'IMG',
				'INPUT',
				'INS',
				'KBD',
				'LABEL',
				'LINK',
				'MAP',
				'MARK',
				'MATH',
				'META',
				'METER',
				'NOSCRIPT',
				'OBJECT',
				'OUTPUT',
				'PICTURE',
				'PROGRESS',
				'Q',
				'RUBY',
				'S',
				'SAMP',
				'SCRIPT',
				'SELECT',
				'SLOT',
				'SMALL',
				'SPAN',
				'STRONG',
				'SUB',
				'SUP',
				'SVG',
				'TEMPLATE',
				'TEXTAREA',
				'TIME',
				'U',
				'VAR',
				'VIDEO',
				'WBR'
			),
			true
		);
	}

	private static function is_palpable_content( string $token_name ): bool {
		$dash_at = strpos( $token_name, '-' );
		if ( $dash_at > 0 ) {
			return true;
		}

		return in_array(
			$token_name,
			array(
				'A',
				'ABBR',
				'ADDRESS',
				'ARTICLE',
				'ASIDE',
				'AUDIO', // @todo "If the `controls` attribute is present"
				'B',
				'BDI',
				'BDO',
				'BLOCKQUOTE',
				'BUTTON',
				'CANVAS',
				'CITE',
				'CODE',
				'DATA',
				'DEL',
				'DETAILS',
				'DFN',
				'DIV',
				'DL', // @todo "if the element's children include at least one name-value group"
				'EM',
				'EMBED',
				'FIELDSET',
				'FIGURE',
				'FOOTER',
				'FORM',
				'H1',
				'H2',
				'H3',
				'H4',
				'H5',
				'H6',
				'HEADER',
				'HGROUP',
				'I',
				'IFRAME',
				'IMG',
				'INPUT', // @todo "If the `type` attribute is not in the hidden state."
				'INS',
				'KBD',
				'LABEL',
				'MAIN',
				'MAP',
				'MARK',
				'MATH',
				'MENU', // @todo "If the element's children include at least one `li` element"
				'METER',
				'NAV',
				'OBJECT',
				'OL', // @todo "If the element's children include at least one `li` element"
				'OUTPUT',
				'P',
				'PICTURE',
				'PRE',
				'PROGRESS',
				'Q',
				'RUBY',
				'S',
				'SAMP',
				'SEARCH',
				'SECTION',
				'SELECT',
				'SMALL',
				'SPAN',
				'STRONG',
				'SUB',
				'SUP',
				'SVG',
				'TABLE',
				'TEXTAREA',
				'TIME',
				'U',
				'UL', // @todo "If the element's children include at least one `li` element"
				'VAR',
				'VIDEO',
			),
			true
		);
	}
}
