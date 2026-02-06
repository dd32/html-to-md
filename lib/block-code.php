<?php

class Block_Code extends Block {
	public ?LineBuffer $code = null;

	public function append_line( LineBuffer $line ): void {
		$this->code = $line;
	}

	public function flush( MD_Options $options ): string {
		if ( ! isset( $this->code ) || $this->code->is_empty() ) {
			return '';
		}

		$indent                  = implode( '', $options->indent );
		$indent_length           = mb_strwidth( $indent );
		$soft_limit              = $options->soft_line_wrap;
		$options->soft_line_wrap = max( 1, $soft_limit - $indent_length );

		$buffer = "{$indent}```\n";
		foreach ( explode( "\n", $this->code->raw_buffer() ) as $line ) {
			$buffer .= "{$indent}{$line}\n";
		}
		$buffer .= "{$indent}```\n";

		$options->soft_line_wrap = $soft_limit;
		return $buffer;
	}

	public function is_empty(): bool {
		return $this->code->is_empty();
	}
}