<?php

class Block_Code extends Block {
	public ?LineBuffer $code = null;

	public function append_line( LineBuffer $line ): void {
		$this->code = $line;
	}

	public function append( Block $block ): void {
		if ( $block instanceof Block_Paragraph ) {
			$this->code = $block->lines[0] ?? null;
		} else {
			$type = strtr( get_class( $block ), array( 'Block_' => '' ) );
			throw new Error( "Cannot add block of type '{$type}' to code block." );
		}
	}

	public function flush( MD_Options $options ): string {
		if ( ! isset( $this->code ) || $this->code->is_empty() ) {
			return '';
		}

		$indent                  = implode( '', $options->indent );
		$indent_length           = mb_strwidth( $indent );
		$soft_limit              = $options->soft_line_wrap;
		$options->soft_line_wrap = max( 1, $soft_limit - $indent_length );

		$prefix = "{$indent}```\n";
		$buffer = '';
		foreach ( explode( "\n", $this->code->raw_buffer() ) as $line ) {
			$buffer .= "{$indent}{$line}\n";
		}
		$buffer = trim( $buffer, "\n" );
		$suffix = "\n{$indent}```\n";

		$options->soft_line_wrap = $soft_limit;
		return "{$prefix}{$buffer}{$suffix}";
	}

	public function is_empty(): bool {
		return $this->code->is_empty();
	}
}