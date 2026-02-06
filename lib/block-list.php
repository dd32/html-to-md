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
		$prefix       = " {$bullet} ";
		$prefix_width = mb_strwidth( $prefix );

		$soft_limit = $options->soft_line_wrap;
		$options->soft_line_wrap -= $prefix_width;

		foreach ( $this->items as $item ) {
			$chunk = "{$prefix}{$item->flush( $options )}";

			$md[] = $chunk;
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