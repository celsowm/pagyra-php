# Pagyra PHP

Fresh PHP port of [pagyra-js](https://github.com/celsowm/pagyra-js).

The previous PHP implementation was intentionally removed. From this point forward, `pagyra-js` is the behavioral reference for this project.

## Status

**The owned pure-PHP pipeline now reaches real PDF output: DOM/CSS, block and inline layout, basic float and table layout, recursive pagination, physical page fragmentation, display-list paint preparation, Unicode TrueType embedding/subsetting, JPEG/PNG image XObjects, solid/dashed/dotted/rounded borders, text-decoration underline/line-through, clickable link annotations, CSS alpha and PDF serialization are implemented for the current supported subset.**

Current pipeline:

```text
HTML -> Pagyra DOM -> merged CSS/resources -> cascade -> computed style tree -> font resolution -> page style resolution -> block/inline layout -> line boxes -> pagination -> physical page fragments -> display list -> PDF serialization
```

Current foundation:

- Composer + PHPUnit structure;
- `Pagyra::prepareHtmlRender()` public API;
- `Pagyra::renderHtmlToPdf()` returns PDF bytes produced by the owned PHP pipeline for the currently supported paint subset;
- validated `RenderHtmlOptions` defaults, including explicit `resourceBaseDir` for deterministic local resource resolution;
- Pagyra-owned DOM model backed by `DOMDocument` only at the parsing boundary;
- fragment/document normalization following the `pagyra-js` model;
- attributes, IDs, classes, inline styles, text content, image and SVG recognition;
- embedded `<style>` collection plus local `<link rel="stylesheet">` loading;
- linked stylesheet `url(...)` rewriting relative to the stylesheet file itself;
- CSS declaration and stylesheet parsing foundation;
- declaration splitting that preserves semicolons inside quoted/function values such as data URLs;
- `border`, `border-top/right/bottom/left`, `border-width`, `border-style` and `border-color` shorthand expansion with declaration-order and `!important` precedence;
- `background` shorthand expansion into `background-color` when the shorthand carries a recognizable color token (image/gradient/position/repeat/attachment/size/origin/clip components are not part of the paint pipeline yet, so they are not expanded);
- `margin`/`padding` shorthand expansion into their four `-top/-right/-bottom/-left` longhands at cascade time (1/2/3/4-value box syntax), so shorthand values correctly override the UA-stylesheet longhand defaults on `p`/`h1-h3`/`ul`/`ol` instead of being silently ignored;
- border width keywords aligned with the reference (`thin=1px`, `medium=3px`, `thick=5px`), including the default medium width when a border style is supplied without an explicit width;
- `border-style:none|hidden` produces zero border geometry in both block and atomic-inline layout instead of merely suppressing paint;
- tag/class/ID compound selectors;
- descendant and child combinators;
- attribute selectors (`[attr]`, `=`, `~=`, `|=`, `^=`, `$=`, `*=`);
- specificity and source-order resolution;
- `!important`, including interaction with inline styles;
- inherited properties, including `visibility`;
- CSS custom properties and `var()` fallback resolution;
- print `@media` evaluation for the currently supported media-query subset;
- initial UA/default styles for core block/inline elements, headings, paragraphs and lists;
- styled DOM tree exposed by `prepareHtmlRender()`;
- `LayoutNode`/`LayoutBox` tree exposed by `prepareHtmlRender()`;
- normal-flow block width/height resolution;
- content/padding/border/margin box geometry;
- CSS box sizing (`content-box` / `border-box`);
- `min-width`, `max-width`, `min-height`, `max-height`;
- horizontal `auto` margins for fixed-width blocks;
- adjacent sibling vertical-margin collapsing;
- block `display:none` filtering;
- `float: left`/`float: right` block siblings laid out side by side within a shared run (left floats growing inward from the run's left edge, right floats from the right edge), scoped to the motivating corpus's shape: inline-only content, no explicit width that overflows the run, and no following inline text reflowing around the float;
- basic `<table>` grid layout for uniform `<tr><td>` rows, reading `<thead>`/`<tbody>`/`<tfoot>` row-group wrappers transparently; column widths are distributed from each column's shrink-to-fit measurement, scaled down proportionally when they overflow the table's content width;
- `display:flex` and `display:grid` fall back to plain block layout so content and its own nested layout (including floats) are preserved, without honoring the flex/grid distribution itself;
- `visibility:hidden|collapse` suppresses display-list paint while preserving normal layout geometry; descendants can override inherited visibility with `visibility:visible`;
- centralized `TextMetrics` abstraction;
- heuristic text-metrics fallback ported from `pagyra-js` coefficients/calibration;
- styled inline fragment collection preserving `font-family`, `font-size`, `font-weight`, `font-style`, color and other computed properties;
- per-fragment token measurement, so style/font changes can affect wrapping;
- `white-space: normal`, `nowrap`, `pre`, `pre-wrap` and `pre-line` handling for the current inline formatter;
- explicit newline preservation for preformatted modes;
- oversized-word splitting for `overflow-wrap: anywhere`, `overflow-wrap: break-word` and `word-break: break-all`;
- `text-align: left`, `center`, `right`, `end` and first-pass `justify` spacing;
- `vertical-align: baseline`, `middle`, `top`, `bottom`, `text-top`, `text-bottom`, `super`, `sub` plus px/pt/em/rem/% shifts;
- two-pass inline vertical placement so raised/lowered runs can expand the effective line box;
- fallback baseline follows the `pagyra-js` ascent/half-leading model (`0.75 * font-size` ascent when font ascent metrics are unavailable);
- atomic inline-box participation for `inline-block`, `inline-flex`, `inline-grid`, `inline-table`, images and inline SVG;
- atomic inline wrapping uses full outer size: content + padding + border + margins;
- `AtomicInlineBox` exposes content size plus margin/padding/border edge metrics and nested `contentLines`;
- recursive atomic-inline paint for backgrounds, solid/rounded borders and nested `contentLines`, including nested inline-block text;
- `LineBox` exposes a unified ordered inline item view so surrounding `TextRun` and `AtomicInlineBox` paint in inline/layout order instead of all text first and atomics afterward;
- intrinsic PNG/JPEG/WebP metadata extraction from data URLs and readable local resources;
- SVG intrinsic sizing from `width`/`height`/`viewBox` for inline SVG and SVG image sources;
- relative image/SVG sources resolved against explicit `resourceBaseDir`;
- image `width`/`height` attributes remain usable as sizing fallback when source metadata is unavailable;
- image sizing preserves intrinsic aspect ratio when only CSS width or height is specified;
- image `min/max-width` and `min/max-height` constraints preserve aspect ratio when the opposite dimension remains automatic;
- oversized intrinsic images shrink to available content width before min/max constraints, matching the `pagyra-js` sizing order;
- replaced image sizing accounts for margin, padding, border and `box-sizing`;
- `object-fit: fill|contain|cover|none|scale-down` paint geometry;
- first-pass `object-position` parsing for keywords and percentages;
- image clipping against the replaced-element content box when object-fit overflows;
- object-fit clipping uses the content-box border radius derived exactly through border-radius shrink by border edges and then padding edges, matching the reference path;
- PDF image clipping uses a rounded Bézier path when the content-box radius is non-zero, otherwise the existing rectangular clip path;
- `inline-block` with `width:auto` uses internal max-content width as its initial intrinsic width;
- explicit inline-block width runs the child inline formatter so internal wrapping determines automatic height;
- nested inline-block line boxes are translated into their real content-box coordinates;
- deterministic `LineBox` output with x/y/width/height/baseline/text plus styled `TextRun` and `AtomicInlineBox` children;
- mixed-font-size baseline alignment inside a line;
- basic inherited `text-transform` application without requiring `ext-mbstring`;
- text-driven intrinsic block height for headings and paragraphs;
- big-endian sfnt binary reader;
- TTF/OTF metric parsing for `head`, `hhea`, `maxp`, `hmtx` and Unicode `cmap` formats 4/12;
- classic `kern` format-0 pair parsing;
- font registry with family/weight/style selection;
- font variant fallback aligned with `pagyra-js`: requested style is preferred first, then the nearest available numeric weight;
- glyph-advance and kerning-based text measurement with heuristic fallback;
- `fontConfig.fontFaceDefs` support for local, `file://`, relative-to-`resourceBaseDir` and base64 data-URL sources;
- CSS `@font-face` extraction from embedded and linked stylesheets;
- CSS font source selection prefers sfnt-compatible TrueType/OpenType sources while WOFF/WOFF2 decoding is still pending;
- base64 embedded `@font-face` sources can be parsed directly from CSS and participate in text measurement;
- TrueType PDF embedding through Type0 + Identity-H + CIDFontType2 + `FontFile2`;
- `ToUnicode` CMaps for Unicode extraction, including non-BMP UTF-16 surrogate pairs;
- sparse Identity TrueType subsetting with `.notdef` retention, composite-glyph dependency closure and rebuilt sfnt checksums;
- embedded text uses glyph widths and classic kern adjustments in `TJ` arrays;
- `letter-spacing` and `word-spacing` are reflected in PDF text output for the current supported length subset;
- default `@page { ... }` resolution for named/custom page sizes, portrait/landscape orientation, margin shorthand/longhands and `!important` precedence;
- `@page :first`, `@page :left` and `@page :right` margin profiles with page-specific cascade/specificity and `:first` participation in right-page rules;
- variable page-flow mapping whose continuous content starts are accumulated from each physical page's actual usable height;
- page style / print viewport stabilization uses the most constrained page variant and re-evaluates matching `@media` rules;
- proportional clamping when page margins exceed the resolved page dimensions;
- resolved default page size is exposed in `PreparedRender.pageSize`; `PreparedRender.margins` remains the default margin set and `PreparedRender.pageMargins` exposes `default/first/left/right` profiles;
- first-pass pagination for `break-before`, `break-after`, legacy `page-break-*`, `left`/`right` page parity, `break-inside: avoid`, `widows` and `orphans`;
- forced breaks, `break-inside`, widows and orphans propagate recursively through descendant block flow while the continuous layout tree remains unchanged;
- widow/orphan positive-integer parsing aligned with the reference, so signed forms such as `+2` are rejected and use fallback behavior;
- physical page model with preserved skipped parity pages, including parity skips under variable page heights;
- top-level `PagePlacement`, per-page `PageFragment`, typed per-line `LineFragment` and recursive descendant `BlockFragment` geometry;
- descendant block and text-line fragmentation while preserving the continuous layout tree unchanged;
- `PreparedRender.displayList` with physical per-page box/text/image/border paint commands and page-pseudo-specific margin offsets;
- `background-color` fills, including rounded backgrounds for normalized elliptical `border-radius` values;
- CSS `border-radius` shorthand (`1-4` values and `/` elliptical syntax), per-corner longhands, percentages and global CSS radius normalization;
- fragmented top-level rounded boxes keep only the applicable top or bottom corner radii on their first/last physical page fragments;
- per-side solid border fills with independent widths/colors and `currentColor` fallback;
- uniform solid rounded borders are painted as vector outer-minus-inner rounded rings using PDF even-odd fill;
- mixed/non-solid border sets follow the reference side-stroke geometry: centered side paths, `dashed` as `3w on / 3w off`, `dotted` as `w on / w off`, butt caps, and fragment-aware top/bottom suppression;
- text paint commands preserve family, weight, style, font size and color;
- CSS color alpha for background, solid/patterned borders and text through deduplicated PDF `ExtGState` resources;
- clickable PDF link annotations (`/Subtype /Link` with a URI action) for `<a href>` text runs, one annotation rect per text run; `<a>` has no default visual styling (no automatic underline or color) and the clickable rect follows only the text run's own box, not a reflowed multi-line link area;
- JPEG XObjects embedded directly with `/DCTDecode` and resource deduplication;
- PNG grayscale/RGB XObjects using original `IDAT` + `/FlateDecode` + PNG predictor when possible;
- PNG RGBA and grayscale+alpha split into color plus `/SMask` without GD/Imagick;
- indexed/palette PNG (`PLTE`) support for bit depths 1/2/4/8;
- indexed PNG `tRNS` transparency converted to a grayscale soft mask, including packed palette indices;
- grayscale/RGB PNG `tRNS` represented efficiently as PDF color-key `/Mask` arrays;
- pure-PHP PDF 1.4 object/xref/trailer serialization;
- Base14 font selection for Times/Helvetica/Courier normal/bold/italic variants when no embeddable TrueType face is available;
- WinAnsi text serialization for ASCII, Latin-1 and common CP1252 punctuation in the Base14 fallback;
- unsupported characters outside the Base14 WinAnsi repertoire fall back to `?` instead of throwing or being silently corrupted;
- geometry primitives (`Rect`, `Edges`, `Box`);
- 96-DPI CSS unit conversions matching `pagyra-js`;
- CSS length parsing and resolution for absolute, viewport, relative, percentage, `calc()` and container-query units;
- RGBA/hex/rgb/named-color parsing foundation;
- unit/integration/parity test suites kept separate;
- GitHub Actions CI configuration for PHP 8.2, 8.3 and 8.4.

Example local font configuration:

```php
$prepared = Pagyra::prepareHtmlRender([
    'html' => '<p style="font-family: MyFont">Hello</p>',
    'resourceBaseDir' => '/path/to/document',
    'fontConfig' => [
        'fontFaceDefs' => [[
            'family' => 'MyFont',
            'weight' => 400,
            'style' => 'normal',
            'src' => 'fonts/my-font.ttf',
        ]],
    ],
]);
```

CSS `@font-face` can also load local or embedded sfnt font data:

```html
<style>
@font-face {
  font-family: "MyFont";
  src: url("fonts/my-font.ttf") format("truetype");
}
p { font-family: "MyFont"; }
</style>
<p>Hello</p>
```

Page descriptors and page pseudo-class margins are reflected by `prepareHtmlRender()`:

```html
<style>
@page {
  size: A4 landscape;
  margin: 12mm 18mm;
}
@page :first { margin-top: 24mm; }
@page :left  { margin-left: 24mm; }
@page :right { margin-right: 24mm; }
</style>
<p>Hello</p>
```

A real PDF can be produced directly:

```php
$pdf = Pagyra::renderHtmlToPdf([
    'html' => '<h1>Hello</h1><p>PDF generated in pure PHP.</p>',
]);

file_put_contents('output.pdf', $pdf);
```

The PDF serializer is still a subset renderer, not yet a full `pagyra-js` replacement. TrueType sfnt fonts can be embedded and subsetted today; OpenType/CFF (`OTTO`) embedding through `FontFile3`, WOFF/WOFF2 decoding, GPOS shaping/kerning, variable-font support and richer fallback-chain behavior remain pending.

Styled inline content is preserved through layout. For example:

```html
<p>Todo <strong>poder</strong> emana do <em>povo</em>.</p>
```

produces line boxes containing separate `TextRun` objects for the normal, bold and italic portions, each measured with its own computed style/font selection.

Atomic inline content can participate in the same line, expose an internal layout and now paint that internal layout recursively:

```html
<p>
  antes
  <span style="display:inline-block;width:60px;padding:4px;background:#eee;border:1px solid #333">
    hello hello hello
  </span>
  <img width="200" height="100" style="width:80px;vertical-align:middle">
  depois
</p>
```

The span carries nested `contentLines` laid out inside its content box, and its background/border/text participate in the display list. The image resolves to `80 x 40` content pixels because only its width is overridden. Text and atomic boxes are consumed in one ordered inline sequence for paint.

The inline formatter still has deliberate limits: mixed inline/block formatting contexts inside atomic boxes, `aspect-ratio` property parsing, richer Unicode line-breaking rules, hyphenation and browser-specific vertical-align/justification edge cases remain for later slices.

`text-decoration: underline` and `line-through` (shorthand or the `text-decoration-line` longhand, inherited like `pagyra-js` treats it) are painted as solid filled rectangles, matching `TextDecorationRenderer::renderSolid`'s thickness/offset ratios from the JS reference. Still pending: `overline`, the `double`/`dashed`/`dotted`/`wavy` decoration styles, and the `text-decoration-color` longhand (the decoration currently always uses the text's own color).

Still pending in block layout: parent/child margin collapsing, full BFC rules, positioning and broader intrinsic sizing. Floats cover only the motivating corpus's shape (inline-only content, side by side siblings); inline text does not yet wrap around a float and floats with block children are unsupported. Table layout covers uniform `<tr><td>` grids only: `colspan`, `rowspan`, `border-collapse`, per-column `<col>` width hints and caption/footer semantics are not implemented. `display:flex`/`grid` fall back to plain block, so content survives but the flex/grid distribution itself is not honored.

Still pending in pagination/paint: stacking contexts/z-index, rounded asymmetric per-side borders, border styles beyond the current solid/dashed/dotted subset (`double`, `groove`, `ridge`, `inset`, `outset`), WebP/SVG paint, Adam7 PNG, element-level opacity and richer clipping/overflow behavior.

Still pending in the cascade/style layer: pseudo-classes/elements, sibling combinators, the remaining shorthands/property parsers, complete Chromium-derived UA styles, richer media queries, richer `@font-face` descriptors and the remaining `pagyra-js` CSS surface.

Remote HTTP resource loading is intentionally not enabled in the current PHP resource layer; local deterministic resources are resolved through explicit paths / `resourceBaseDir`.

See [PLAN.md](PLAN.md) for the porting roadmap and parity criteria.

## Goal

Provide a pure-PHP HTML-to-PDF engine whose observable rendering behavior tracks `pagyra-js` as closely as practical, while exposing an idiomatic PHP API.

## Requirements

- PHP >= 8.2
- ext-dom
- Composer

## Development rule

A feature is not considered ported because equivalent-looking PHP code exists. It is considered ported only when fixtures shared with `pagyra-js` produce equivalent layout/rendering results within explicitly defined tolerances.
