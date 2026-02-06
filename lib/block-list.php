<?php

class Block_List extends Block {
	private string $style;

	/**
	 * @var Array<Block>
	 */
	public array $items = array();

	public function __construct( string $style ) {
		$this->style = $style;
	}

	public function append( Block $block ): void {
		$this->items[] = $block;
	}

	public function flush( MD_Options $options ): string {
		$md           = array();
		$bullet       = $this->style;
		$indent       = implode( '', $options->indent );
		$indent_width = mb_strwidth( $indent );
		$prefix1      = "{$indent} {$bullet} ";
		$prefixN      = "{$indent} " . str_repeat( ' ', mb_strwidth( $bullet ) ) . ' ';
		$prefix_width = mb_strwidth( $prefix1 );

		$soft_limit = $options->soft_line_wrap;
		$options->soft_line_wrap -= $prefix_width;

		foreach ( $this->items as $item ) {
			$buffer = '';

			foreach ( explode( "\n", $item->flush( $options ) ) as $i => $line ) {
				$buffer .= $i === 0 ? "{$prefix1}{$line}\n" : "{$prefixN}{$line}\n";
			}

			$md[] = rtrim( $buffer, "\n" );
		}

		$options->soft_line_wrap = $soft_limit;

		return implode( "\n", $md );
	}

	public function is_empty(): bool {
		foreach ( $this->items as $item ) {
			if ( ! $item->is_empty() ) {
				return false;
			}
		}

		return true;
	}
}