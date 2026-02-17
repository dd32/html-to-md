<?php

namespace WordPress\Experiments\HtmlToMarkdown;

class Block_Paragraph extends Block {
	/**
	 * @var Array<LineBuffer>
	 */
	public array $lines = array();

	public function append_line( LineBuffer $line ): void {
		$this->lines[] = $line;
	}

	public function flush( MD_Options $options ): string {
		$md = array();
		foreach ( $this->lines as $line ) {
			if ( ! $line->has_non_whitespace_content() ) {
				continue;
			}

			$md[] = \implode( "\n", line_wrap( $line->flush(), $options->soft_line_wrap ) );
		}

		return \implode( "\n\n", $md );
	}

	public function is_empty(): bool {
		foreach ( $this->lines as $line ) {
			if ( ! $line->is_empty() ) {
				return false;
			}
		}

		return true;
	}
}