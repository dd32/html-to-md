<?php

class Block_ATX extends Block {
	public int         $level   = 1;
	public ?LineBuffer $heading = null;

	public function __construct( int $level ) {
		$this->level = $level;
	}

	public function append_line( LineBuffer $line ): void {
		$this->heading = $line;
	}

	public function append( Block $block ): void {
		if ( $block instanceof Block_Paragraph ) {
			$this->heading = $block->lines[0] ?? null;
		} else {
			$type = strtr( get_class( $block ), array( 'Block_' => '' ) );
			throw new Error( "Cannot add block of type '{$type}' to code block." );
		}
	}

	public function flush( MD_Options $options ): string {
		if ( ! isset( $this->heading ) || $this->heading->is_empty() ) {
			return '';
		}

		$prefix = str_repeat( '#', max( 1, min( 6, $this->level ) ) );
		// @todo This is a stylistic choice.
		$heading = strtr( $this->heading->flush(), array( "\n" => "⏎ " ) );

		return "{$prefix} {$heading}";
	}

	public function is_empty(): bool {
		return $this->heading->is_empty();
	}
}