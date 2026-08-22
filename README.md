# Pagyra PHP

Fresh PHP port of [pagyra-js](https://github.com/celsowm/pagyra-js).

The previous PHP implementation was intentionally removed. From this point forward, `pagyra-js` is the behavioral reference for this project.

## Status

**DOM and substantial CSS cascade phases are implemented; block layout now supports parsed font metrics, styled inline runs, whitespace policy, word breaking and text alignment. PDF rendering is intentionally not implemented yet.**

Current pipeline:

```text
HTML -> Pagyra DOM -> merged CSS -> cascade -> computed style tree -> font resolution -> block layout -> styled inline fragments -> whitespace/token policy -> text metrics -> line breaking -> line boxes -> text runs
```

Current foundation:

- Composer + PHPUnit structure;
- `Pagyra::prepareHtmlRender()` public API;
- validated `RenderHtmlOptions` defaults;
- Pagyra-owned DOM model backed by `DOMDocument` only at the parsing boundary;
- fragment/document normalization following the `pagyra-js` model;
- attributes, IDs, classes, inline styles, text content, image and SVG recognition;
- embedded `<style>` collection and stylesheet href discovery;
- CSS declaration and stylesheet parsing foundation;
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
- deterministic `LineBox` output with x/y/width/height/baseline/text plus styled `TextRun` children;
- mixed-font-size baseline alignment inside a line;
- basic inherited `text-transform` application without requiring `ext-mbstring`;
- text-driven intrinsic block height for headings and paragraphs;
- big-endian sfnt binary reader;
- TTF/OTF metric parsing for `head`, `hhea`, `maxp`, `hmtx` and Unicode `cmap` formats 4/12;
- classic `kern` format-0 pair parsing;
- font registry with family/weight/style selection;
- glyph-advance and kerning-based text measurement with heuristic fallback;
- `fontConfig.fontFaceDefs` support for local paths / `file://` sources;
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
    'fontConfig' => [
        'fontFaceDefs' => [[
            'family' => 'MyFont',
            'weight' => 400,
            'style' => 'normal',
            'src' => '/path/to/font.ttf',
        ]],
    ],
]);
```

Styled inline content is preserved through layout. For example:

```html
<p>Todo <strong>poder</strong> emana do <em>povo</em>.</p>
```

produces line boxes containing separate `TextRun` objects for the normal, bold and italic portions, each measured with its own computed style/font selection.

The parsed-font path currently targets measurement, not rendering outlines. `glyf`/CFF outlines, GPOS kerning/class pairs, variable fonts, Base14 width tables, full fallback-chain policy and PDF embedding/subsetting remain pending.

The inline formatter still has deliberate limits: mixed inline/block formatting contexts, atomic inline boxes, richer Unicode line-breaking rules, hyphenation, full `vertical-align`, decorations and more browser-specific justification behavior remain for later slices.

Still pending in block layout: parent/child margin collapsing, full BFC rules, floats, positioning and broader intrinsic sizing.

Still pending in the cascade/style layer: pseudo-classes/elements, sibling combinators, full shorthands/property parsers, complete Chromium-derived UA styles, `@media`, `@page`, `@font-face`, external stylesheet loading and the remaining `pagyra-js` CSS surface.

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
