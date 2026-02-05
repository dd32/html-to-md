<?php

abstract class Block {
	abstract public function flush( MD_Options $options ): string;
}