<?php

abstract class Block {
	public function append( Block $block ): void {
		$type = strtr( get_class( $this ), array( 'Block_' => '' ) );
		throw new Error( "Cannot add blocks inside of block type '{$type}'" );
	}

	public function append_line( LineBuffer $line ): void {
		$type = strtr( get_class( $this ), array( 'Block_' => '' ) );
		throw new Error( "Cannot add lines to block type '{$type}'" );
	}
	abstract public function flush( MD_Options $options ): string;

	abstract public function is_empty(): bool;
}