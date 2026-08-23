# Pagyra PHP

Fresh PHP port of [pagyra-js](https://github.com/celsowm/pagyra-js).

The previous PHP implementation was intentionally removed. From this point forward, `pagyra-js` is the behavioral reference for this project.

## Status

**The owned pure-PHP pipeline now reaches real PDF output: DOM/CSS, block and inline layout, first-pass pagination, physical page fragmentation, display-list paint preparation, Unicode TrueType embedding/subsetting, JPEG/PNG image XObjects, solid and rounded borders, CSS alpha and PDF serialization are implemented for the current supported subset.**

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
- border width keywords aligned with the reference (`thin=1px`, `medium=3px`, `thick=5px`), including the default medium width when a border style is supplied without an explicit width;
- `border-style:none|hidden` produces zero border geometry in both block and atomic-inline layout instead of merely suppressing paint;
- tag/class/ID compound selectors;
- descendant and child combinators;
- attribute selectors (`[attr]`, `=`, `~=`, `|=`, `^=`, `$=`, `*=`);
- specificity and source-order resolution;
- `!important`, including interaction with inline styles;
- inherited properties;
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
- page style / print viewport stabilization so matching `@media` rules are re-evaluated when `@page` changes the printable area;
- proportional clamping when page margins exceed the resolved page dimensions;
- resolved default page size is exposed in `PreparedRender.pageSize` as points while page margins remain CSS pixels;
- first-pass pagination for `break-before`, `break-after`, legacy `page-break-*`, `left`/`right` page parity, `break-inside: avoid`, `widows` and `orphans`;
- widow/orphan positive-integer parsing aligned with the reference, so signed forms such as `+2` are rejected and use fallback behavior;
- physical page model with preserved skipped parity pages;
- top-level `PagePlacement`, per-page `PageFragment`, typed per-line `LineFragment` and recursive descendant `BlockFragment` geometry;
- descendant block and text-line fragmentation while preserving the continuous layout tree unchanged;
- `PreparedRender.displayList` with physical per-page box/text/image/border paint commands and page-margin-adjusted coordinates;
- `background-color` fills, including rounded backgrounds for normalized elliptical `border-radius` values;
- CSS `border-radius` shorthand (`1-4` values and `/` elliptical syntax), per-corner longhands, percentages and global CSS radius normalization;
- fragmented top-level rounded boxes keep only the applicable top or bottom corner radii on their first/last physical page fragments;
- per-side solid border fills with independent widths/colors and `currentColor` fallback;
- uniform solid rounded borders are painted as vector outer-minus-inner rounded rings using PDF even-odd fill;
- text paint commands preserve family, weight, style, font size and color;
- CSS color alpha for background, solid borders and text through deduplicated PDF `ExtGState` resources;
- JPEG XObjects embedded directly with `/DCTDecode` and resource deduplication;
- PNG grayscale/RGB XObjects using original `IDAT` + `/FlateDecode` + PNG predictor when possible;
- PNG RGBA and grayscale+alpha split into color plus `/SMask` without GD/Imagick;
- indexed/palette PNG (`PLTE`) support for bit depths 1/2/4/8;
- indexed PNG `tRNS` transparency converted to a grayscale soft mask, including packed palette indices;
- grayscale/RGB PNG `tRNS` represented efficiently as PDF color-key `/Mask` arrays;
- pure-PHP PDF 1.4 object/xref/trailer serialization;
- Base14 font selection for Times/Helvetica/Courier normal/bold/italic variants when no embeddable TrueType face is available;
- WinAnsi text serialization for ASCII, Latin-1 and common CP1252 punctuation in the Base14 fallback;
- unsupported characters outside the Base14 WinAnsi repertoire fail explicitly instead of being silently corrupted;
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

Default page descriptors are also reflected by `prepareHtmlRender()`:

```html
<style>
@page {
  size: A4 landscape;
  margin: 12mm 18mm;
}
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

Atomic inline content can participate in the same line and expose an internal layout:

```html
<p>
  antes
  <span style="display:inline-block;width:60px;padding:4px">hello hello hello</span>
  <img width="200" height="100" style="width:80px;vertical-align:middle">
  depois
</p>
```

The span carries nested `contentLines` laid out inside its content box. The image resolves to `80 x 40` content pixels because only its width is overridden.

The inline formatter still has deliberate limits: mixed inline/block formatting contexts inside atomic boxes, `aspect-ratio` property parsing, richer Unicode line-breaking rules, hyphenation, decorations and browser-specific vertical-align/justification edge cases remain for later slices.

Still pending in block layout: parent/child margin collapsing, full BFC rules, floats, positioning and broader intrinsic sizing.

Still pending in pagination/paint: page pseudo-class profiles (`:first`, `:left`, `:right`), variable per-page margins, full descendant forced-break propagation, stacking contexts/z-index, rounded asymmetric per-side borders, non-solid border paint (`dashed`, `dotted`, `double`, etc.), inline atomic-box border/background paint beyond the current image path, WebP/SVG paint, Adam7 PNG, element-level opacity, decorations and richer clipping/overflow behavior.

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
