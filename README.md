# Pagyra PHP

Fresh PHP port of [pagyra-js](https://github.com/celsowm/pagyra-js).

The previous PHP implementation was intentionally removed. From this point forward, `pagyra-js` is the behavioral reference for this project.

## Status

**DOM phase complete; initial CSS cascade/computed-style phase implemented. PDF rendering is intentionally not implemented yet.**

Current pipeline:

```text
HTML -> Pagyra DOM -> merged CSS -> cascade -> computed style tree
```

Current foundation:

- Composer + PHPUnit structure;
- `Pagyra::prepareHtmlRender()` public API;
- validated `RenderHtmlOptions` defaults;
- Pagyra-owned DOM model backed by `DOMDocument` only at the parsing boundary;
- fragment/document normalization following the `pagyra-js` model;
- attributes, IDs, classes, inline styles, text content, image and SVG recognition;
- embedded `<style>` collection and stylesheet href discovery;
- explicit text-whitespace normalization helpers;
- CSS declaration and stylesheet parser bootstrap;
- simple selector matching for tag, class, ID and compound simple selectors;
- specificity, source-order resolution, inherited properties and inline-style precedence;
- styled DOM tree exposed by `prepareHtmlRender()`;
- geometry primitives (`Rect`, `Edges`, `Box`);
- 96-DPI CSS unit conversions matching `pagyra-js`;
- CSS length parsing and resolution for absolute, viewport, relative, percentage, `calc()` and container-query units;
- RGBA/hex/rgb/named-color parsing foundation;
- unit/integration/parity test suites kept separate;
- deterministic bootstrap golden snapshot for `<p>Hello World</p>`.

Not yet implemented in the cascade layer: combinators, pseudo-classes/elements, attribute selectors, `!important`, CSS variables, shorthands, UA styles, `@media`, `@page`, `@font-face`, external stylesheet loading and the full `pagyra-js` property parser set.

`Pagyra::renderHtmlToPdf()` currently throws deliberately. Layout, pagination, paint and PDF serialization will be added only after the lower-level parity layers are established.

See [PLAN.md](PLAN.md) for the porting roadmap and parity criteria.

## Goal

Provide a pure-PHP HTML-to-PDF engine whose observable rendering behavior tracks `pagyra-js` as closely as practical, while exposing an idiomatic PHP API.

## Requirements

- PHP >= 8.2
- ext-dom
- Composer

## Development rule

A feature is not considered ported because equivalent-looking PHP code exists. It is considered ported only when fixtures shared with `pagyra-js` produce equivalent layout/rendering results within explicitly defined tolerances.
