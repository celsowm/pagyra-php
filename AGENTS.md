# AGENTS.md

This repository is a fresh PHP port of `celsowm/pagyra-js`.

The goal is **behavioral parity**, not source-code similarity.

## Source of truth

When implementing or changing behavior, use this priority order:

1. `pagyra-js` tests and fixtures
2. Observable `pagyra-js` output
3. `pagyra-js` implementation
4. Repository documentation (`PLAN.md`, `README.md`)
5. Web/CSS/PDF standards only when `pagyra-js` does not define the behavior clearly

Do not guess browser-like behavior when the JavaScript reference already answers the question.

## Reference repository

Reference implementation:

- `https://github.com/celsowm/pagyra-js`

Before implementing a non-trivial layout, CSS, font, image, pagination, rendering, or PDF feature, inspect the corresponding code and tests in `pagyra-js` first.

Prefer porting the observable rules and invariants over copying TypeScript structure literally.

## Project direction

This is a **pure PHP** implementation.

Do not introduce any of the following as rendering dependencies:

- Chromium / Chrome
- Playwright / Puppeteer
- wkhtmltopdf
- headless browsers
- external rendering services
- hidden calls to `pagyra-js`
- Imagick/GD as a substitute for the layout/rendering engine

The intended pipeline is:

```text
HTML
  -> owned DOM
  -> CSS parsing and cascade
  -> computed style
  -> fonts/resources
  -> layout
  -> pagination
  -> display list / paint representation
  -> PDF serialization
```

Do not bypass unfinished stages.

## PDF rule

`Pagyra::renderHtmlToPdf()` must not return fake, placeholder, or externally-rendered PDF data.

Keep it intentionally unavailable until the real layout, pagination, paint, and PDF serialization path exists.

A feature is not complete merely because a PDF byte string can be produced somehow.

## Public API direction

The main public API is expected to remain centered on:

```php
use Pagyra\Pagyra;

$pdf = Pagyra::renderHtmlToPdf([
    'html' => '<h1>Hello World</h1>',
]);
```

and:

```php
$prepared = Pagyra::prepareHtmlRender([...]);
```

Do not preserve APIs from the removed legacy PHP implementation unless they are explicitly reintroduced as part of the new design.

## Architecture rules

Keep responsibilities separated. Prefer the existing areas rather than creating cross-cutting utility blobs:

```text
src/
├── Core/
├── Environment/
├── Html/
├── Dom/
├── Css/
├── Style/
├── Units/
├── Geometry/
├── Fonts/
├── Image/
├── Svg/
├── Layout/
├── Pagination/
├── Render/
├── Pdf/
└── Debug/
```

Important principles:

- DOM ownership belongs to Pagyra; platform parsers are only parsing boundaries.
- CSS parsing/cascade must not be mixed into layout code.
- Layout must produce deterministic geometry that later paint/PDF code can consume.
- Text measurement belongs behind `TextMetrics`-style abstractions.
- Atomic inline boxes must remain real layout objects, not encoded as fake text.
- Image sizing, font resolution, pagination, and PDF serialization should each have dedicated responsibilities.
- Avoid giant classes that accumulate unrelated browser-engine behavior.

## Behavioral parity policy

When porting a feature:

1. Locate the equivalent behavior in `pagyra-js`.
2. Find or create the smallest relevant fixture/test.
3. Implement the PHP behavior.
4. Compare observable geometry/style/output against the JavaScript reference when possible.
5. Document deliberate gaps instead of silently inventing behavior.

Prefer small, traceable parity slices over broad speculative rewrites.

## Tests

Every behavioral change should add or update tests.

Use the existing separation:

- `tests/Unit` for local algorithms and primitives
- `tests/Integration` for PHP pipeline behavior
- `tests/Parity` only for real reference snapshots/comparisons against `pagyra-js`
- `tests/Golden` for stable PHP-side prepared-output fixtures where appropriate

Do not label a PHP-only test as parity if it does not compare against the JavaScript reference.

Do not claim a test suite or CI is green unless it was actually executed and the result was observed.

The GitHub Actions workflow is expected to validate supported PHP versions and run lint/tests.

## Parity levels

Use these levels when describing progress:

- **P0 — API parity**
- **P1 — style parity**
- **P2 — layout parity**
- **P3 — paint parity**
- **P4 — visual parity**

Do not call a feature fully ported when only an earlier parity level is implemented.

## Current implementation state

The project currently has substantial work in:

- owned HTML/DOM parsing
- CSS declarations, selectors, specificity and cascade
- computed styles and custom properties
- units, lengths, colors and geometry primitives
- initial block layout and box model
- margin collapsing between adjacent siblings
- styled inline text runs
- whitespace modes, wrapping, alignment and justification
- vertical alignment
- atomic inline boxes
- inline-block internal text layout
- intrinsic image sizing from known dimensions and aspect-ratio preservation
- TTF/OTF metric parsing for text measurement
- font registry and glyph-advance/kerning-based metrics

This list is only orientation. Inspect the current code before assuming exact support.

## Known incomplete areas

Do not accidentally present these as complete:

- full mixed inline/block formatting contexts
- full CSS shorthand/property grammar
- pseudo-classes and pseudo-elements
- sibling combinators
- complete UA stylesheet behavior
- `@media`, `@page`, `@font-face`
- external stylesheet/resource loading parity
- full CSS `calc()` grammar and all numeric functions
- complete named-color table parity
- advanced Unicode line breaking and hyphenation
- full intrinsic image decoding from PNG/JPEG/WebP/SVG resources
- full `object-fit` / replaced-element rendering
- full inline-block/block formatting internals
- parent/child margin collapsing and complete BFC behavior
- floats and positioning
- flexbox and grid
- pagination
- headers/footers
- paint/display-list generation
- PDF serialization
- font outlines, embedding and subsetting
- GPOS kerning/class pairs
- variable fonts
- complete font fallback/aliases/Base14 behavior

Check `PLAN.md` and current source for the most recent status.

## Image behavior

For images, follow `pagyra-js` replaced-element sizing rules rather than ad hoc defaults.

In particular, when intrinsic dimensions are known:

- `auto/auto` should use intrinsic dimensions;
- an explicit width with automatic height should preserve aspect ratio;
- an explicit height with automatic width should preserve aspect ratio;
- min/max constraints should preserve the ratio when the opposite dimension remains automatic;
- box sizing, padding, borders, and margins must be handled separately from image content size.

Do not infer arbitrary square sizes when the reference has better information.

## Inline layout behavior

Preserve style boundaries through inline formatting.

Do not flatten styled descendants into one text string before measurement.

Line layout must be able to distinguish at least:

- text runs with their own computed style/font metrics;
- spaces/newline tokens according to `white-space`;
- atomic inline boxes;
- inline-block internal content;
- vertical alignment and baseline placement.

Wrapping decisions must use the measured outer size of atomic inline boxes.

## Fonts

Font measurement and PDF font rendering are separate concerns.

Current metric parsing must not be described as full font rendering support.

When extending font behavior, inspect the corresponding `pagyra-js` font code first, especially for:

- cmap
- hmtx
- kern / GPOS
- aliases and generic-family resolution
- Base14 behavior
- fallback chains
- embedding/subsetting

## Coding style

- Target modern PHP (currently PHP >= 8.2).
- Use strict types.
- Prefer readonly value objects where appropriate.
- Keep APIs explicit and typed.
- Avoid legacy compatibility layers.
- Avoid speculative abstractions that are not yet needed by the reference behavior.
- Prefer deterministic data structures suitable for JSON snapshots and later rendering stages.

## Repository workflow

For this repository, work directly on `main` unless explicitly instructed otherwise.

Keep commits focused and descriptive.

Do not create a pull request unless explicitly requested.

When making several independent behavioral changes, prefer separate commits so regressions can be traced easily.

## Documentation

Update `README.md` when the externally relevant implementation status changes.

Update `PLAN.md` when roadmap or parity strategy changes.

Do not use documentation to overstate implementation status.

## Final rule

If uncertain about how something should behave, **inspect `pagyra-js` first**.

The objective is not to build a generic HTML renderer that merely seems reasonable. The objective is to make `pagyra-php` track the observable behavior of `pagyra-js` as closely as practical, one verified parity slice at a time.
