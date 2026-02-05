<?php

function line_wrap( string $text, int $soft_limit ): array {
	$bi          = IntlBreakIterator::createWordInstance( locale_get_default() );
	$pi          = $bi->getPartsIterator();
	$lines       = array();
	$line_length = 0;
	$was_at      = 0;

	$bi->setText( $text );

	foreach ( $pi as $part ) {
		$offset      = $bi->current();
		$chunk_width = mb_strwidth( $part );
		$next_width  = $line_length + $chunk_width;

		// Add trailing non-word content to the previous line.
		if (
			0 === $line_length &&
			IntlBreakIterator::WORD_NONE === $bi->getRuleStatus() &&
			count( $lines ) > 0 &&
			1 === preg_match( '~\A[\p{C}\p{P}\p{Z}]*\Z~u', $part )
		) {
			$lines[ count( $lines ) - 1 ] .= preg_replace( '~\p{Z}+\Z~u', '', $part );
			$was_at = $offset;
			continue;
		}

		if ( $next_width < $soft_limit ) {
			// Is this faster from OOP/speculation than `= $next_width`?
			$line_length += $chunk_width;
			continue;
		}

		// It’s too long, but for now just append it and move on.
		$lines[]     = substr( $text, $was_at, $offset - $was_at );
		$line_length = 0;
		$was_at      = $offset;
	}

	if ( $was_at < strlen( $text ) ) {
		$lines[] = substr( $text, $was_at );
	}

	return $lines;
}