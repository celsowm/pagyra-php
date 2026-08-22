# Pagyra PHP

Fresh PHP port of [pagyra-js](https://github.com/celsowm/pagyra-js).

The previous PHP implementation was intentionally removed. From this point forward, `pagyra-js` is the behavioral reference for this project.

## Status

**Bootstrap slice implemented; PDF rendering is intentionally not implemented yet.**

Current foundation:

- Composer + PHPUnit structure;
- `Pagyra::prepareHtmlRender()` public API;
- validated `RenderHtmlOptions` defaults;
- Pagyra-owned DOM model backed by `DOMDocument` only at the parsing boundary;
- CSS declaration parser bootstrap;
- geometry primitive (`Rect`);
- 96-DPI CSS unit conversions matching `pagyra-js`;
- CSS length parsing for absolute, viewport, relative and percentage lengths;
- unit/integration/parity test suites kept separate;
- deterministic bootstrap golden snapshot for `<p>Hello World</p>`.

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
