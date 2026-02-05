<?php

class Block_Paragraph extends Block {
	private int $active_buffer = 0;

	/**
	 * @var Array<LineBuffer>
	 */
	public array $lines = array();

	public function __construct() {

	}

	public function append_line_buffer( LineBuffer $buffer ) {
		$this->lines[] = $buffer;
		$this->active_buffer = count( $this->lines ) - 1;
	}

	public function active_buffer(): LineBuffer {
		return $this->lines[ $this->active_buffer ];
	}

	public function flush(): string {
		$md = '';
		foreach ( $this->lines as $line ) {
			if ( ! $line->has_non_whitespace_content() ) {
				continue;
			}

			if ( '' !== $md ) {
				$md .= "\n\n";
			}

			$md .= $line->flush();
		}

		return $md;
	}
}