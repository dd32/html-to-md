<?php

/**
 * Stores formatting information while building a line for rendering.
 */
class LineBuffer {
	/**
	 * Contains the plaintext content of the buffer, without any syntax.
	 *
	 * @var string
	 */
	private string $buffer = '';

	/**
	 * Byte offsets into buffer where the format for the given format index
	 * starts to apply or ends: positive if starting, negative if ending.
	 *
	 * Example:
	 *
	 *     $buffer         = 'The dog did it!';
	 *     $format_offsets = array( 4, -7 );
	 *     $format_indices = array( 0, 0 );
	 *     $formats        = array( new InlineFormat_Generic( 'emphasizing' ) );
	 *
	 * @see self::$formats
	 *
	 * @var Array<int>
	 */
	private array $format_offsets = array();

	/**
	 * Relates an index in the {@see self::$format_offsets} array to a format in
	 * the {@see self::$formats} array; used to asosociate start and end of formats.
	 *
	 * @see self::$formats
	 *
	 * @var Array<int>
	 */
	private array $format_indices = array();

	private array $open_formats = array();

	/**
	 * References to inline formats and their state.
	 *
	 * @see self::$format_offset
	 * @see self::$format_indices
	 *
	 * @var array<InlineFormat>
	 */
	private array $formats = array();

	public function append_text( string $text ) {
		$this->buffer .= $text;
	}

	public function require_format( InlineFormat $format ) {
		$next_format_at         = count( $this->formats );
		$this->open_formats[]   = $next_format_at;
		$this->format_offsets[] = strlen( $this->buffer );
		$this->format_indices[] = $next_format_at;
		$this->formats[]        = $format;
	}

	public function release_format() {
		$this->format_offsets[] = -strlen( $this->buffer );
		$this->format_indices[] = array_pop( $this->open_formats );
	}

	public function flush(): string {
		$offsets      = $this->format_offsets;
		$indices      = $this->format_indices;
		$formats      = $this->formats;
		$was_at       = 0;
		$buffer       = '';
		$length       = strlen( $this->buffer );
		$bolding      = 0;
		$emphasizing  = 0;
		$striking_out = 0;

		for ( $i = 0; $i < count( $offsets ); $i++ ) {
			$at     = $offsets[ $i ];
			$state  = $at < 0 ? 'exiting' : 'entering';
			$at     = abs( $at );
			$index  = $indices[ $i ];
			$format = $formats[ $index ];

			if ( $at > $was_at ) {
				$chunk   = substr( $this->buffer, $was_at, $at - $was_at );
				$chunk   = $bolding > 0 ? strtr( $chunk, array( '*' => '\*' ) ) : $chunk;
				$chunk   = $emphasizing > 0 ? strtr( $chunk, array( '_' => '\_' ) ) : $chunk;
				$chunk   = $striking_out > 0 ? strtr( $chunk, array( '~' => '\~' ) ) : $chunk;
				$buffer .= $chunk;
				$was_at  = $at;
			}

			$type = $format instanceof InlineFormat_Generic ? $format->type : null;
			switch ( $type ) {
				case 'bolding':
					if ( ( 0 === $bolding && 'entering' === $state ) || ( $bolding > 0 && 'exiting' === $state ) ) {
						$buffer .= '**';
					}
					$bolding += 'entering' === $state ? 1 : -1;
					break;

				case 'emphasizing':
					if ( ( 0 === $emphasizing && 'entering' === $state ) || ( $emphasizing > 0 && 'exiting' === $state ) ) {
						$buffer .= '_';
					}
					$emphasizing += 'entering' === $state ? 1 : -1;
					break;

				case 'striking-out':
					if ( ( 0 === $striking_out && 'entering' === $state ) || ( $striking_out > 0 && 'exiting' === $state ) ) {
						$buffer .= '~';
					}
					$striking_out += 'entering' === $state ? 1 : -1;
					break;
			}

			$was_at = $at;
		}

		if ( $was_at < $length ) {
			$buffer .= substr( $this->buffer, $was_at );
		}

		return $buffer;
	}
}