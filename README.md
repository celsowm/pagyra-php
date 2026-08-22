# Pagyra PHP

Fresh PHP port of [pagyra-js](https://github.com/celsowm/pagyra-js).

The previous PHP implementation was intentionally removed. From this point forward, `pagyra-js` is the behavioral reference for this project.

## Status

**DOM and substantial CSS cascade phases are implemented; block layout now supports parsed font metrics, styled inline runs, whitespace policy, word breaking, text alignment, vertical alignment, atomic inline boxes, intrinsic raster/SVG sizing, linked local stylesheets and CSS `@font-face` loading. PDF rendering is intentionally not implemented yet.**

Current pipeline:

```text
HTML -> Pagyra DOM -> merged CSS/resources -> cascade -> computed style tree -> font resolution -> block layout -> styled inline fragments -> whitespace/token policy -> text metrics -> line breaking -> vertical placement -> line boxes -> text runs / atomic inline boxes -> nested inline-block line boxes
```

Current foundation:

- Composer + PHPUnit structure;
- `Pagyra::prepareHtmlRender()` public API;
- validated `RenderHtmlOptions` defaults, including explicit `resourceBaseDir` for deterministic local resource resolution;
- Pagyra-owned DOM model backed by `DOMDocument` only at the parsing boundary;
- fragment/document normalization following the `pagyra-js` model;
- attributes, IDs, classes, inline styles, text content, image and SVG recognition;
- embedded `<style>` collection plus local `<link rel="stylesheet">` loading;
- linked stylesheet `url(...)` rewriting relative to the stylesheet file itself;
- CSS declaration and stylesheet parsing foundation;
- declaration splitting that preserves semicolons inside quoted/function values such as data URLs;
- tag/class/ID compound selectors;
- descendant and child combinators;
- attribute selectors (`[attr]`, `=`, `~=`, `|=`, `^=`, `$=`, `*=`);
- specificity and source-order resolution;
- `!important`, including interaction with inline styles;
- inherited properties;
- CSS custom properties and `var()` fallback resolution;
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
- oversized intrinsic images shrink to available content width after accounting for margin, padding and border;
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
- glyph-advance and kerning-based text measurement with heuristic fallback;
- `fontConfig.fontFaceDefs` support for local, `file://`, relative-to-`resourceBaseDir` and base64 data-URL sources;
- CSS `@font-face` extraction from embedded and linked stylesheets;
- CSS font source selection prefers sfnt-compatible TrueType/OpenType sources while WOFF/WOFF2 decoding is still pending;
- base64 embedded `@font-face` sources can be parsed directly from CSS and participate in text measurement;
- geometry primitives (`Rect`, `Edges`, `Box`);
- 96-DPI CSS unit conversions matching `pagyra-js`;
- CSS length parsing and resolution for absolute, viewport, relative, percentage, `calc()` and container-query units;
- RGBA/hex/rgb/named-color parsing foundation;
- unit/integration/parity test suites kept separate;
- GitHub Actions CI for PHP 8.2, 8.3 and 8.4.

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

Styled inline content is preserved through layout. For example:

```html
<p>Todo <strong>poder</strong> emana do <em>povo</em>.</p>
```

produces line boxes containing separate `TextRun` objects for the normal, bold and italic portions, each measured with its own computed style/font selection.

Atomic inline content can now participate in the same line and expose an internal layout:

```html
<p>
  antes
  <span style="display:inline-block;width:60px;padding:4px">hello hello hello</span>
  <img width="200" height="100" style="width:80px;vertical-align:middle">
  depois
</p>
```

The span carries nested `contentLines` laid out inside its content box. The image resolves to `80 x 40` content pixels because only its width is overridden.

The parsed-font path currently targets measurement, not rendering outlines. `glyf`/CFF outlines, GPOS kerning/class pairs, variable fonts, WOFF/WOFF2 decoding, Base14 width tables, full fallback-chain policy and PDF embedding/subsetting remain pending.

The inline formatter still has deliberate limits: mixed inline/block formatting contexts inside atomic boxes, `aspect-ratio` property parsing, replaced-element object-fit/object-position paint, richer Unicode line-breaking rules, hyphenation, decorations and browser-specific vertical-align/justification edge cases remain for later slices.

Still pending in block layout: parent/child margin collapsing, full BFC rules, floats, positioning and broader intrinsic sizing.

Still pending in the cascade/style layer: pseudo-classes/elements, sibling combinators, full shorthands/property parsers, complete Chromium-derived UA styles, `@media`, `@page`, richer `@font-face` descriptors and the remaining `pagyra-js` CSS surface.

Remote HTTP resource loading is intentionally not enabled in the current PHP resource layer; local deterministic resources are resolved through explicit paths / `resourceBaseDir`.

`Pagyra::renderHtmlToPdf()` currently throws deliberately. Pagination, paint and PDF serialization will be added only after the lower-level layout layers are established.

See [PLAN.md](PLAN.md) for the porting roadmap and parity criteria.

## Goal

Provide a pure-PHP HTML-to-PDF engine whose observable rendering behavior tracks `pagyra-js` as closely as practical, while exposing an idiomatic PHP API.

## Requirements

- PHP >= 8.2
- ext-dom
- Composer

## Development rule

A feature is not considered ported because equivalent-looking PHP code exists. It is considered ported only when fixtures shared with `pagyra-js` produce equivalent layout/rendering results within explicitly defined tolerances.
