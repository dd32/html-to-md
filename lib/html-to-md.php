<?php

require __DIR__ . '/block.php';
require __DIR__ . '/block-paragraph.php';
require __DIR__ . '/inline-format.php';
require __DIR__ . '/inline-format-generic.php';
require __DIR__ . '/inline-format-link.php';
require __DIR__ . '/line-buffer.php';

/**
 * Render an HTML document into Markdown.
 *
 * @param string $html    Input HTML to render.
 * @param null   $options No options supported yet.
 * @return string Input HTML rendered into Markdown
 */
function html_to_md( string $html, $options ) {
	$p  = WP_HTML_Processor::create_fragment( $html );
	$o  = '';
	$b  = null;
	$lb = null;

	while ( $p->next_token() ) {
		$token_name = $p->get_token_name();
		$token_type = $p->get_token_type();
		$is_closer  = $p->is_tag_closer();
		
		switch ( $token_name ) {
			case '#text':
				$chunk = $p->get_modifiable_text();
				$chunk = preg_replace( "~[ \t\f\r\n]+~", ' ', $chunk );

				if ( ! isset( $b ) && ( ' ' === $chunk || '' === $chunk ) ) {
					break;
				}

				if ( ! isset( $b ) ) {
					$b = new Block_Paragraph();
					$lb = $b->active_buffer();
				}

				$lb->append_text( $chunk );
				break;

			case 'B':
			case 'EM':
			case 'I':
			case 'S':
			case 'STRONG':
				if ( $is_closer ) {
					$lb->release_format();
				} else {
					$format = array(
						'B'      => 'bolding',
						'EM'     => 'emphasizing',
						'I'      => 'emphasizing',
						'S'      => 'striking-out',
						'STRONG' => 'bolding',
					)[ $token_name ];
					$lb->require_format( new InlineFormat_Generic( $format ) );
				}
				break;
		}
	}

	if ( isset( $b ) ) {
		$o .= $b->flush();
	}

	return $o;
}