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

		return "```\n{$this->code->flush()}\n```\n";
	}
}