<?php

abstract class Block {
	abstract public function append_line( LineBuffer $line ): void;

	public function append( Block $block ): void {
		$type = strtr( get_class( $this ), array( 'Block_' => '' ) );
		throw new Error( "Cannot add blocks inside of block type '{$type}'" );
	}

	abstract public function flush( MD_Options $options ): string;
}