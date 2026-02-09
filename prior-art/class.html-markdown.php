<?php

/*
 * This version explored a mirrored ruleset following the way the HTML spec describes parsing
 * tags. This seemed to offer dramatic simplification of rules within known and recognized
 * node types, but I think it failed to deliver because of the mismatch between the many ways
 * that various HTML elements or tags collapse into a much smaller set of Markdown primitives.
 *
 * It explores the most tree-like AST of the methods explored.
 */

class Frame {
	public function __construct(
		public string $type,
		public string $buffer = '',
		public string $level = 'block',
		public array  $data = []
	) {}
}

class HtmlMarkdown {
	private WP_HTML_Processor $processor;

	private ?string $base_url = null;

	private array $stack = [];

	private array $depths = [];

	private array $links = [];

	private int $width;

	private IntlBreakIterator $worder;

	public static function convert( string $html, int $width = 80 ): string {
		$self            = new self();
		$self->processor = WP_HTML_Processor::create_full_parser( $html );
		$self->stack[]   = new Frame( 'root' );
		$self->width     = $width;
		$self->worder    = IntlRuleBasedBreakIterator::createWordInstance( locale_get_default() );

		while ( $self->step() ) {
			continue;
		}

		while ( $self->close() ) {
			continue;
		}

		if ( 0 !== count( $self->links ) ) {
			$self->stack[0]->buffer .= "\n\n";
		}

		foreach ( $self->links as $i => $link ) {
			$index                  = $i + 1;
			$self->stack[0]->buffer .= "[{$index}]: {$link}\n";
		}

		return $self->stack[0]->buffer;
	}

	private function step( $action = 'next-node' ) {
		if ( 'next-node' === $action ) {
			if ( !$this->processor->next_token() ) {
				return false;
			}

			if ( !in_array( $this->processor->get_token_type(), [ '#text', '#tag' ], true ) ) {
				return $this->step();
			}

			if ( !$this->is_unsupported() ) {
				return $this->skip_element();
			}

			if ( null !== $this->processor->get_attribute( 'hidden' ) ) {
				return $this->skip_element();
			}

			$style = $this->processor->get_attribute( 'style' );
			if ( is_string( $style ) ) {
				if ( 1 === preg_match( "~(?:^|;)\s*opacity:\s*0(?:\.0+)?(?:;|$)~", $style ) ) {
					return $this->skip_element();
				}

				if ( 1 === preg_match( "~(?:^|;)\s*display:\s*none(?:;|$)~", $style ) ) {
					return $this->skip_element();
				}
			}
		}

//		$location = '';
//		foreach ( $this->stack as $frame ) {
//			$location .= "{$frame->type} > ";
//		}
//		$location .= $this->processor->get_token_name();
//		echo "\e[90m{$location}\e[m\n";

		switch ( $this->processor->get_token_name() ) {
			case 'PRE':
				return $this->step_in_pre();
		}

		$formatting_type = $this->formatting_type();
		if ( isset( $formatting_type ) ) {
			if ( $this->processor->is_tag_closer() ) {
				$this->depths[ $formatting_type ]--;
				if ( $this->depths[ $formatting_type ] <= 1 ) {
					$this->close();
				}
			} else {
				if ( !isset( $this->depths[ $formatting_type ] ) ) {
					$this->depths[ $formatting_type ] = 0;
				}

				if ( $this->depths[ $formatting_type ] === 0 ) {
					$this->enter( $formatting_type, 'inline' );
				}

				$this->depths[ $formatting_type ]++;
			}
		}

		if ( 'A' === $this->processor->get_token_name() ) {
			if ( $this->processor->is_tag_closer() ) {
				$this->close();
			} else {
				$href = $this->processor->get_attribute( 'href' );
				if ( false === array_search( $href, $this->links, true ) ) {
					$this->links[] = $href;
				}
				$this->stack[ count( $this->stack ) - 1 ]->data['href'] = $href;
			}
		}

		$state = null;
		foreach ( array_reverse( $this->stack ) as $frame ) {
			if ( 'inline' === $frame->level ) {
				continue;
			}

			$state = $frame->type;
			break;
		}

		switch ( $state ) {
			case 'root':
				return $this->step_in_root();

			case 'heading':
				return $this->step_in_heading();

			case 'paragraph':
				return $this->step_in_paragraph();

			case 'blockquote':
				return $this->step_in_blockquote();
		}
	}

	private function step_in_root(): bool {
		switch ( $this->block_type() ) {
			case 'paragraph':
				$this->enter( 'paragraph' );
				return $this->step( 'reprocess-node' );

			case 'heading':
				$this->enter( 'heading' );
				$this->stack[ count( $this->stack ) - 1 ]->data['level'] = intval( $this->processor->get_tag()[1] );
				return $this->step( 'reprocess-node' );
		}

		return $this->step();
	}

	private function step_in_potential_interelement_whitespace(): bool {
		$token_type = $this->processor->get_token_type();

		if ( '#text' === $token_type ) {
			$this->stack[ count( $this->stack ) - 1 ]->type   = 'paragraph';
			$this->stack[ count( $this->stack ) - 1 ]->buffer .= $this->processor->get_modifiable_text();
			return true;
		}

		if ( '#tag' !== $token_type ) {
			// Anything other than a tag is invisible,
			// as if it didn’t even exist.
			return $this->step();
		}

		if ( $this->is_inside_formatting() ) {
			// @todo Convert into non-inter-element whitespace?
		}

		// But whether an opening or closing tag, this is
		// now inter-element whitespace and we can ignore it.
		$this->stack->pop();
		return $this->step( 'reprocess-node' );
	}

	private function step_in_paragraph(): bool {
		switch ( $this->processor->get_token_name() ) {
			case '#text':
				$this->add_text();
				break;

			case 'H1':
			case 'H2':
			case 'H3':
			case 'H4':
			case 'H5':
			case 'H6':
				$this->close();
				$this->enter( 'heading' );
				$this->stack[ count( $this->stack ) - 1 ]->data['level'] = intval( $this->processor->get_tag()[1] );
				return true;

			case 'IMG':
				$this->enter( 'image', 'inline' );
				$this->stack[ count( $this->stack ) - 1 ]->data['alt']   = $this->processor->get_attribute( 'alt' );
				$this->stack[ count( $this->stack ) - 1 ]->data['src']   = $this->processor->get_attribute( 'src' );
				$this->stack[ count( $this->stack ) - 1 ]->data['title'] = $this->processor->get_attribute( 'title' );
				$this->close();
				return true;

			case 'BLOCKQUOTE':
				$this->enter( 'blockquote' );
				return $this->step( 'reprocess-node' );

			case 'P':
				$this->close();
				break;
		}

		return true;
	}

	private function step_in_heading(): bool {
		switch ( $this->processor->get_token_name() ) {
			case '#text':
				$this->add_text();
				break;

			case 'H1':
			case 'H2':
			case 'H3':
			case 'H4':
			case 'H5':
			case 'H6':
				$this->close();
				break;
		}

		return true;
	}

	private function step_in_blockquote(): bool {
		switch ( $this->processor->get_token_name() ) {
			case '#text':
				$this->add_text();
				break;

			case 'BLOCKQUOTE':
				$this->close();
				return true;
		}
	}

	private function step_in_pre(): bool {
		$code = '';

		$depth = $this->processor->get_current_depth();
		while ( $this->processor->get_current_depth() >= $depth ) {
			switch ( $this->processor->get_token_name() ) {
				case '#text':
					$code .= $this->processor->get_modifiable_text();
					break;

				case 'BR':
					$code .= "\n";
					break;

				case 'code':
					$this->depths['code'] += $this->processor->is_tag_closer() ? -1 : 1;
					break;
			}

			$this->processor->next_token();
		}

		$code = preg_replace( "~\r\n~", "\n", $code );
		$code = preg_replace( "~\r~", "\n", $code );

		$fence_length = max( 3, self::longest_sequence_of( $code, '`' ) );
		$fence        = str_repeat( '`', $fence_length );
		$prefix       = '   ';

		$lines = explode( "\n", $code );

		$code = "\n{$prefix}{$fence}\n";
		$code .= implode( "\n", array_map( fn ( $line ) => "{$prefix}{$line}\n", $lines ) );
		$code .= "{$prefix}{$fence}\n";

		$this->stack[ count( $this->stack ) - 1 ]->buffer .= $code;
		return true;
	}

	private function enter( string $type, string $level = 'block' ): void {
		if ( !isset( $this->depths[ $type ] ) ) {
			$this->depths[ $type ] = 0;
		}

		$this->stack[] = new Frame( $type, '', $level );
		$this->depths[ $type ]++;
	}

	private function close(): bool {
		$frame = array_pop( $this->stack );
		if ( 'root' === $frame->type ) {
			$this->stack[] = $frame;
			return false;
		}

		$parent = $this->stack[ count( $this->stack ) - 1 ];
		$this->depths[ $frame->type ]--;

		$prefix_0 = '';
		$prefix_n = '';
		switch ( $frame->type ) {
			case 'blockquote':
				$prefix_0 = '> ';
				$prefix_n = $prefix_0;
				break;

			case 'heading':
				$prefix_0 = str_repeat( '#', $frame->data['level'] ) . ' ';
				$prefix_n = '';
				break;
		}

		$lines        = '';
		$width        = 0;
		$prefix_width = mb_strwidth( $prefix_n );
		$this->worder->setText( $frame->buffer );
		$words = $this->worder->getPartsIterator();
		foreach ( $words as $i => $word ) {
			$word_width  = mb_strwidth( $word, 'UTF-8' );
			$word_length = strlen( $word );

			if ( $word_length > 0 && 1 === strspn( substr( $word, -1 ), '.,;:?!%$#@^&*(){}[]`_' ) ) {
				$lines .= $word;
				$width += $word_width;
				continue;
			}

			if ( ( $prefix_width + $word_width ) > $this->width ) {
				$lines .= "\n{$prefix_0}{$word}\n";
				$width = 0;
				continue;
			}

			if ( $width + $word_width > $this->width ) {
				$lines .= "{$word}\n";
				$width = 0;
				continue;
			}

			if ( 0 === $width && $i > 0 ) {
				$lines .= $prefix_n;
			}

			$lines .= $word;
			$width += $word_width;
		}

		if ( !empty( trim( $lines ) ) ) {
			$lines = "{$prefix_0}{$lines}";
		}

		switch ( $frame->type ) {
			case 'bold':
				$lines = "*{$lines}*";
				break;

			case 'code':
				$lines = "`{$lines}`";
				break;

			case 'italic':
				$lines = "_{$lines}_";
				break;

			case 'link':
				$label      = trim( $lines );
				$href       = $frame->data['href'];
				$link_index = array_search( $href, $this->links, true ) + 1;
				$lines      = "[{$label}][{$link_index}]";
				break;

			case 'strikeout':
				$lines = "~{$lines}~";
				break;

			case 'quote':
				$lines = "“{$lines}”";
				break;

			case 'line-break':
				$parent->buffer .= "\n";
				return true;

			case 'image':
				$alt = str_replace( ']', '\\]', $frame->data['alt'] ?? '' );
				$src = str_replace( ')', '\\)', $frame->data['src'] ?? '' );
				if ( empty( $alt ) && !empty( $frame->data['title'] ) ) {
					$alt = str_replace( ']', '\\]', $frame->data['title'] );
				}
				$lines = "![{$alt}]({$src})";
				break;
		}

		$ending         = 'block' === $frame->level ? "\n" : '';
		$parent->buffer .= "{$lines}{$ending}";
		return true;
	}

	private function add_text( ?string $text = null ): void {
		$text = $text ?? $this->processor->get_modifiable_text();

		$needs_escaping = [];
		foreach ( $this->stack as $frame ) {
			switch ( $frame->type ) {
				case 'bold':
					$needs_escaping['*'] = '\\*';
					break;

				case 'code':
					$needs_escaping['`'] = '\\`';
					break;

				case 'italic':
					$needs_escaping['_'] = '\\_';
					break;

				case 'link':
					$needs_escaping[']'] = '\\]';
					break;

				case 'strikeout':
					$needs_escaping['~'] = '\\~';
					break;

				case 'table-cell':
				case 'table-row':
					$needs_escaping['|'] = '\\|';
					break;
			}
		}

		$text = str_replace( array_keys( $needs_escaping ), array_values( $needs_escaping ), $text );
		$text = preg_replace( "~\r\n~", "\n", $text );
		$text = preg_replace( "~\r~", "\n", $text );
		$text = preg_replace( "~[ \t\f\r\n]+~", " ", $text );

		$this->stack[ count( $this->stack ) - 1 ]->buffer .= $text;
	}

	private function skip_element(): bool {
		$depth = $this->processor->get_current_depth();

		while ( $this->processor->get_current_depth() >= $depth ) {
			if ( !$this->processor->next_token() ) {
				return false;
			}
		}

		return true;
	}

	private function is_inside_formatting(): bool {
		return false;
	}

	private function is_unsupported(): bool {
		if ( 'html' !== $this->processor->get_namespace() ) {
			return false;
		}

		switch ( $this->processor->get_token_name() ) {
			case 'MATH':
			case 'NOSCRIPT':
			case 'SCRIPT':
			case 'STYLE':
			case 'SVG':
			case 'TEMPLATE':
				return false;
		}

		return true;
	}

	private function block_type(): ?string {
		switch ( $this->processor->get_token_name() ) {
			case '#text':
				return ( 0 === ( $this->depths['paragraph'] ?? 0 ) ) ? 'paragraph' : null;

			case 'P':
				return 'paragraph';

			case 'HR':
				return 'separator';

			case 'UL':
				return 'unordered-list';

			case 'OL':
				return 'ordered-list';

			case 'DL':
				return 'definition-list';

			case 'LI':
				return 'list-item';

			case 'BLOCKQUOTE':
				return 'blockquote';

			case 'H1':
			case 'H2':
			case 'H3':
			case 'H4':
			case 'H5':
			case 'H6':
				return 'heading';

			case 'TABLE':
				return 'table';
		}

		return null;
	}

	private function formatting_type(): ?string {
		switch ( $this->processor->get_token_name() ) {
			case 'A':
				return 'link';

			case 'BR':
				return 'line-break';

			case 'B':
			case 'STRONG':
				return 'bold';

			case 'I':
			case 'EM':
				return 'italic';

			case 'S':
				return 'strikeout';

			case 'CODE':
				return 'code';

			case 'Q':
				return 'quote';

			case 'TD':
			case 'TH':
				return 'table-cell';

			case 'TR':
				return 'table-row';
		}

		return null;
	}

	private static function longest_sequence_of( string $haystack, string $needle ): int {
		$at     = 0;
		$end    = strlen( $haystack );
		$search = $needle;

		while ( $at < $end ) {
			$next_at = strpos( $haystack, $search, $at );
			if ( false === $next_at ) {
				break;
			}

			$search .= $needle;
			$at     = $next_at;
		}

		return strlen( $search ) / strlen( $needle );
	}
}
