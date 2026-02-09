# Algorithm for converting HTML to Markdown

This document describes the process for converting HTML into Markdown or other
plaintext formats, whereby the goal is to reasonably represent the rendered
HTML in a text-only format.

This will be implemented using WordPress’ “HTML API” and its `WP_HTML_Processor`
class, which provides for a basic structure of creating a parser and then
iterating over every node in the document.

```php
$processor = WP_HTML_Processor::create_full_parser( $html );
while ( $processor->next_token() ) {
    $token_type = $processor->get_token_type(); // #text, #tag, #comment, #doctype, etc...
    $token_name = $processor->get_token_name(); // #text, DIV, P, SECTION, #comment, etc...

    $is_hidden = $processor->get_attribute( 'hidden' );
    if ( true === $is_hidden || 'hidden' === $is_hidden ) {
        $processor->skip_element();
        continue;
    }

    $decoded_text = $processor->get_modifiable_text();

    ...
}
```

It provides a number of helper methods to inspect structurally the currently-matched token.

## Knowns

### Block and inline content.

Markdown comprises two kinds of content: block and inline, just like HTML. However, it’s
important to understand this conversion as a rendering target, just like the browser renders
into pixels, so does this need to render into text. That distinguishes the concepts of block
and inline between Markdown and HTML. Whereas _elements_ in HTML may be considered “block
elements,” in Markdown the concept is different.

It’s more helpful to think about _entering_ and _existing_ Markdown blocks. For instance,
upon reading an HTML block element, such as DIV or P, the Markdown parser _enters_ a new
block, but only it hasn’t already entered it.

Consider the case of `<section><div><p>`: each of the three elements, SECTION, DIV, and P
are considered HTML block elements, and so they should open a Markdown block. However, these
three combine in a render to form one new Markdown block. On a page this would only involve
one paragraph, not two empty paragraphs and then the third one.

 * Markdown blocks are _entered_ after a contiguous series of HTML block elements.

The same is true for the closing tags, but in a different way. A Markdown block is closed
once the final HTML tag which opened it has closed. Consider again the earlier example,
but imagine it continues as `<section><div><p>One</p><p>Two</p></div></section>`: in this
case it’s clear that not only did the first P tag _enter_ a block, but its corresponding
P closing tag _exited_ that block. There are two blocks (or paragraphs) in that case.

Because Markdown contains no block nesting, there’s an asymmetry between sliding past
the opening SECTION and DIV tags before opening the Markdown block, and immediately
closing it once reaching the closing P tag.

 * Markdown blocks are _exited_ once the HTML element which opened it has closed.

There’s a caveat to this description though. No actual Markdown blocks appear until a
non-empty text node is found. The _enter_ and _exit_ conditions can be considered to set
a flag, like `$should_enter_new_block` and `$should_close_block`. When reaching a text
node, these flags will determine whether or not to perform any block-related actions.

It’s possible for these flags to both be set and for no block to appear. Consider
the segment `<header></header><body>Welcome to my site</body>`. In this case, there was
no content in the HEADER elements, and even though that would normally enter a block,
since no non-empty text node was found inside of it, the parser skipped entering any
block until reaching the inner content of the BODY element.

 * Markdown blocks only appear when renderable, non-empty text nodes are matched.

### Inter-element whitespace.

Whitespace, mostly newlines and indentation, are used to format HTML in ways which are
not intended to be rendered. This is called “inter-element whitespace.” It exists only
between HTML block elements, as all space between inline HTML elements is treated as
real whitespace.

When the only content between two HTML tags for block elements are unescaped ASCII
whitespace (x09, x0A, x0C, x0D, and x20), then that span is considered to be
inter-element whitespace and _may_ be ignored under most circumstances.

It should be ignored _unless_ it is immediately followed by a text node _or_ by an
HTML tag for an inline element, such as the formatting elements or SPAN.

### Block indentation.

Each block comprises a sequence of lines of text and inline formatting. These lines
may be indented by an appropriate amount corresponding to the number of nested blocks
which are open. Consider `<h2>Food</h2><ul><li>Apples</li><li>Oranges</li></ul>`: in
this case there are two top-level blocks receiving no indentation: the “Food” heading
and the entire unordered list that follows. Inside that list are two more blocks, one
for each list item, and these list items are indented by one level because they are
nested one level.

Rendering starts with zero indentation and naturally expands by block depth with the
configured indentation string, set by the containing open block.

This configured indent comprises two values: a first-line indent; and a continuing
indent. This may sound surprising, but there’s a reason for this, and it has to do
with how the different block types render their contained lines.

The `$indent1` (first-line indent) is the string to be used to indent the first line
of content within a block while `$indentN` is applied to any lines which follow the
first. These might be the same in certain blocks but different in others.

For paragraphs, all indentation will be the same, likely four space characters. For
list items, however, the first line receives a list bullet or number but any other
lines do not. Any other lines should be indented past the list bullet or number so
that the start of the text on each line is vertically aligned. The `$indent1` might
be " - " while the `$indentN` could be "   ", for instance.

### Word-wrapping.

The converter will have configured word-wrapping limits: a soft limit and a hard limit.
When a block is “flushed” to the output buffer, it must combine all of the inline
content contained inside itself and start producing lines from that.

A basic mechanism is sufficient, powered by functions that return glyph width rather
than byte, code unit, or code point lengths. For example, the flag of England glyph
comprises a seven-code-point sequence but renders as a single fullwidth glyph. Tools
like `mb_strwidth()` will be important for this, though not perfect. ICU tools like
the `IntlBreakIterator::createWordInstance()` will be critical as well for the length
of text between the soft and hard limits.

 * Soft-wrapping limits are character widths at which easy line wrapping should occur.
   In a perfectly-matched document, all line-break opportunities would appear after
   the soft-wrapping limit and before the hard-wrapping limit.

 * Hard-wrapping limits are character widths at which point line-wrapping must occur.
   Any line-break opportunity falling beyond the hard-wrapping limit must produce a
   line wrap.

Because of a block’s indentation, line-wrapping must be performed on adjusted forms
of the soft and hard limits. For instance, consider "<ul><li><ul><li><blockquote>":
the BLOCKQUOTE’s content will see two indentations from the unordered lists and a
further indent ("> ") from the BLOCKQUOTE. This means that the new line-wrapping
limits must be reduced by an appropriate amount, which is the sum of the indents.
In this case, assuming a list indent of four characters and the blockquote indent
of two characters, the augmented soft and hard limits would be reduced by six
character widths.

There will be times when a line-break opportunity does not fit within the augmented
hard-wrapping limit. This could occur when a single word is longer than the augmented
limit. In these cases, non-breakable spans of text will run past the hard-limit.

Further, certain preformatted text may not be wrapped. Whether inside a PRE element
or styled with "word-break: keep-all;" or "white-space: pre;" or some similar style,
the content inside these blocks should preserve the original newlines and wrapping.

Finally, care should be taken to avoid word-wrapping significant syntax, as not all
of the Markdown syntax forms may be split across lines. Bolded segments, for instance,
ought to remain on a single line or be split into two separately-bolded segments.

### Inline formats.

Not every HTML format has a corresponding representation in Markdown or plaintext.
It’s a lossy conversion. However, a number of formats do have analogues: namely,
_bolding_, _italicizing_, _monospacing_, _quoting_, _striking-out_, _linking_, and
potentially more depending on the flavor of Markdown.

Inline formats are surrounded by or comprise surrounding syntax elements which mark
them distinct form the other surrounding text. For example, _bolding_ marks are the
asterisk character. For basic formats like **bolding**, entry and exit of the inline
format is marked by _flanking_. Bold text is left-flanked and right-flanked by two 
asterisks. Flanking is a complicated concept and requires its own function to detect.
Flanking was devised to differentiate between Markdown syntax as syntax and as normal
text. For instance, the text "1,***,***.00" does not indicate any _bolding_ because
the asterisk characters do not _flank_ textual content. An oversimplified view of
flanking is that it separates whitespace or punctuation from word characters. In
reality the rules are significantly more complicated.

Markdown provides escaping methods for writing syntax characters without them being
interpreted as such. For example, it’s possible to write "that \*is\* interesting"
without it implying that the "is" is **bolded**, because the asterisks are preceded by
a reverse solidus character.

When converting from HTML to Markdown, a configurable option indicates whether to
escape syntax characters or to replace them with visually-similar characters. This
is a purpose-driven configuration change, because while it’s possible to escape
certain syntax to prevent its interpretation, and that is good for documents which
will later be _rendered_ from the Markdown, that escaping can make it visually
distracting for humans or LLMs to read. In applications where the visual output is
more important than the structural output, visual replacement can be more useful.
For instance, the _bolding_ asterisk might be replaced in non-bold contexts with the
Unicode character FULLWIDTH ASTERISK (U+FF0A). This looks the same but is a different
character.

Inline formats may nest, and to this end, certain characters may need escaping or
replacement which are not obvious when looking only at the containing inline format.
For instance, a bold and italicized span of text should prevent the use of the
asterisk and low line characters inside the inner format. For this reason, inline
formats maintain a buffer of their contained inner text and inner formats, which
themselves have their own inner text. When the time comes to render these, when their
containing blocks are performing line-wrapping, it must be determined for each inline
format which characters must be escaped or replaced, and then returned as a full
buffer to the next-outer containing inline format, up until the rendered buffer is
handed to the containing block for final line-wrapping and indentation.

Some inline formats are representable with different syntax forms, for example, a
horizontal rule may be `***`, `---`, `-*-*-*-*-`, `   ---   ---   ---   ` as well
as many other incantations. At times it may be useful to render HTML content using
more than one of the many possible syntax forms if another form removes the need to
escape characters within. A code block, for example, while not an inline format, may
be delimited by three or more tilde characters, the runlength of which should be
extended past the longest sequence of tilde characters inside the code block.

Some inline formats are more complicated than containing flanking syntax characters.
The link, or the HTML anchor element or A element, comprises a title surrounded by
square brackets and a URL surrounded by parenthesis. Alternatively, instead of a
URL it may contain a second set of square brackets which wrap an id whereby a URL
is provided later in the document associated with that id. This is convenient for
preserving the visual flow of text in a rendered document, as lengthy URLs in the
middle of a paragraph obscure content.

A configurable option `$link_style` indicates whether all links should have URLs
`'inline'`, or `'at-end'` where inline links will have this alternate form and the
document will end with a list of URLs associated with those ids, or `'hybrid'`
where the parser will make a dynamic judgment in each location whether to inline
the URL or place it at the end. Dynamic judgment is the responsibility of a
separate function and will base the determination on the surrounding content, role,
and context where the link is found.

When 'at-end' links are written, the ids start with the number 1 and increase as
each new link appears. When a URL is used more than once, the inline link will refer
to the original id associated with that URL instead of incrementing the id and
duplicating the URL at the end.

### Document truncation.

Not every document will be processed in full. Configurable `$max_words` and
`$max_codepoints` limits indicate that the parser should stop proceeding once
the output buffer would contain this many words or characters. An "out parameter"
for the function indicates if the entire document was processed or not.

## Notes

The conversion to or rendering of HTML into Markdown is essentially an accessibility
view over the document. It is helpful to frame this conversion in that way. Elements
of a page which are presentational in nature, for instance, may not need to appear
in the text output. Images should be replaced by their ALT text, if appropriate, and
in a way that indicates it’s an image replacement (e.g. being surrounded by square
brackets). Certain operations may need to be considered from the accessibility tree
of the document rather than from the DOM tree, or at least need to be considered in
the same manner as would be done if such trees were to be constructed.

Many of the transformations require establishing forms of buffers and structure.
Effectively there will be some abstract tree-form of the document before flushing
to the output. Appropriate data structures and documentation will make the purpose
of these nodes and the trees clear and will improve the performance of the operations.

This document is incomplete. It’s meant to convey the critical factors to consider
when designing an HTML-to-Markdown converter. Fill in the missing details as is
appropriate, consulting existing Markdown documents, specifications, and tradeoffs.
