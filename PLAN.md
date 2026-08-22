# Pagyra PHP — Port Plan

## 1. Product definition

`pagyra-php` will be rebuilt from zero as a behavioral port of `pagyra-js`.

The TypeScript implementation is the reference implementation. The PHP project must not revive the old architecture merely because code already existed there.

The objective is parity of observable behavior, not line-by-line translation.

## 2. Non-goals

- Do not preserve the legacy PHP API for compatibility.
- Do not preserve the previous class hierarchy.
- Do not version `vendor/`.
- Do not introduce browser-specific abstractions that have no meaning in PHP.
- Do not depend on Chromium, wkhtmltopdf, headless browsers, Imagick, GD, or an external HTML-to-PDF service as the rendering engine.
- Do not call `pagyra-js` from PHP as a hidden implementation shortcut.

## 3. Source of truth

For every feature, inspect the corresponding implementation, fixtures, and tests in `pagyra-js`.

Priority order when behavior is ambiguous:

1. `pagyra-js` tests and fixtures;
2. observable `pagyra-js` output;
3. `pagyra-js` implementation;
4. README/documentation;
5. CSS/HTML standards where the JS implementation has no explicit behavior yet.

## 4. Target public API

The PHP API should stay small and map conceptually to the JS entry points.

Initial target:

```php
use Pagyra\Pagyra;

$pdf = Pagyra::renderHtmlToPdf([
    'html' => '<h1>Hello World</h1>',
]);

file_put_contents('output.pdf', $pdf);
```

Core operations:

- `renderHtmlToPdf(array|RenderHtmlOptions $options): string`
- `prepareHtmlRender(array|RenderHtmlOptions $options): PreparedRender`

`html` is the only mandatory input. Defaults should track `pagyra-js` wherever they are meaningful in PHP.

## 5. Proposed architecture

```text
src/
├── Pagyra.php
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

tests/
├── Unit/
├── Integration/
├── Fixtures/
├── Golden/
└── Parity/
```

The names may evolve, but dependencies must flow roughly from parsing/style/geometry toward layout and finally PDF output. PDF serialization must not own layout decisions.

## 6. Porting strategy

### Phase 0 — Reset and parity harness

Goal: establish the rules before implementing rendering.

Deliverables:

- clean Composer package;
- PHPUnit test infrastructure;
- fixture format shared conceptually with `pagyra-js`;
- CLI/dev script that renders one fixture;
- mechanism to store JS reference results;
- deterministic output mode where possible;
- comparison tooling for layout trees and PDF-level assertions.

A feature cannot graduate without parity tests.

### Phase 1 — Core types, units, geometry, colors

Port the low-level value model first:

- dimensions and edges;
- points, rectangles, boxes;
- CSS absolute units: `px`, `pt`, `mm`, `cm`, `in`;
- relative units required by later style computation: `%`, `em`, `rem`;
- colors and alpha;
- margins, paddings and borders;
- page size conversion;
- numeric normalization and clamping.

Acceptance: equivalent numerical results to the JS reference fixtures.

### Phase 2 — HTML and DOM model

Implement:

- HTML fragment/document parsing;
- element, text and document nodes;
- attributes/classes/IDs;
- text normalization rules used by Pagyra;
- inline `<style>` collection;
- inline `style` attributes;
- image and SVG node recognition;
- resource URL normalization hooks.

Use PHP DOM facilities only as an input parser. Convert parsed nodes immediately to Pagyra-owned immutable/lightweight domain nodes so layout is not coupled to `DOMNode`.

### Phase 3 — CSS parser and cascade

Implement the CSS pipeline as its own subsystem:

- stylesheet parsing;
- selectors used by `pagyra-js`;
- specificity and source order;
- inheritance;
- initial/default values;
- inline styles;
- custom properties;
- `var()` resolution;
- shorthands;
- UA/default styles required for matching the JS output;
- `@font-face` discovery.

First parity milestone: simple block document produces the same computed style tree as JS.

### Phase 4 — Font subsystem and text metrics

Implement fonts before serious layout work.

Required behavior:

- TTF/OTF loading as supported by the JS reference;
- family/style/weight matching;
- fallback chains;
- glyph lookup;
- advances/kerning needed by layout;
- line metrics;
- Unicode text measurement;
- font embedding/subsetting strategy;
- `@font-face` resource resolution.

Text measurement must be centralized behind a font metrics interface. No layout class may invent its own approximation.

### Phase 5 — Block layout and box model

Implement the first end-to-end vertical layout path:

- block formatting;
- normal flow;
- width/height resolution;
- margin/padding/border;
- `box-sizing`;
- min/max constraints;
- backgrounds;
- basic overflow behavior;
- nested blocks.

Milestone: common documents with `div`, headings and paragraphs match JS geometry.

### Phase 6 — Inline layout and text

Implement:

- inline formatting context;
- line boxes;
- whitespace processing;
- wrapping;
- `overflow-wrap` / `word-wrap`;
- `text-align`;
- justified text;
- line-height;
- text transforms;
- text decoration;
- mixed inline elements;
- links.

This phase should reuse the exact conceptual line-breaking rules from JS wherever practical.

### Phase 7 — Pagination

Add page-aware layout without corrupting the normal-flow engine.

Implement:

- automatic page breaks;
- fragmentation of block and inline content;
- page content box;
- margins;
- break constraints supported by JS;
- repeated structures where applicable;
- deterministic page numbering.

Pagination should consume layout/fragmentation data and must not be implemented as ad-hoc Y-coordinate checks scattered through renderers.

### Phase 8 — PDF writer and paint pipeline

Separate painting from PDF serialization.

Implement a render/display-list layer with operations such as:

- save/restore state;
- transform;
- clip;
- fill/stroke path;
- draw text/glyphs;
- draw image;
- links/annotations.

Then serialize that display list to PDF:

- document/catalog/pages;
- content streams;
- fonts;
- images;
- graphics state;
- compression;
- annotations;
- metadata.

This makes the layout testable without binary PDF comparison.

### Phase 9 — Images and SVG

Implement parity for:

- PNG/JPEG as required by fixtures;
- data URLs;
- local resource loading;
- intrinsic dimensions;
- replaced-element sizing;
- SVG geometry and painting supported by JS;
- SVG stroke features, including dash array/offset where present in the reference.

### Phase 10 — Flexbox

Port flex only after block/inline/layout primitives are stable.

Cover the subset supported by JS, including:

- direction;
- wrapping;
- grow/shrink/basis;
- justify-content;
- align-items / align-self / align-content;
- gap;
- min/max constraints and intrinsic sizing interactions.

### Phase 11 — Grid

Port the actual `pagyra-js` grid subset rather than attempting all of CSS Grid at once.

Add fixtures for tracks, gaps, placement, spans and auto-flow as the JS behavior requires.

### Phase 12 — Positioning, floats and stacking

Port, according to real JS support:

- relative positioning;
- absolute positioning;
- fixed/sticky only if and to the extent the reference supports them;
- floats and clear;
- z-index / stacking order;
- clipping and overflow interactions.

Do not claim standards support beyond passing parity fixtures.

### Phase 13 — Headers and footers

Port the JS header/footer model:

- default header/footer HTML;
- first/even/odd variants;
- automatic measurement;
- explicit max heights;
- page-number and total-page placeholders;
- under/over layer mode;
- clipping behavior.

### Phase 14 — Resource/environment layer

PHP gets its own environment adapter while retaining JS semantics where relevant:

- filesystem resource loading;
- base directory resolution;
- asset-root sandboxing;
- data URLs;
- optional HTTP loading only if deliberately designed and secured;
- compression abstraction;
- logging/debug hooks.

Avoid Node/browser concepts in the public PHP model when they do not apply.

### Phase 15 — Advanced parity backlog

Port remaining JS capabilities by measured value and test coverage, including as applicable:

- CSS variables edge cases;
- gradients;
- border radius;
- opacity;
- transforms;
- more SVG;
- tables;
- lists/markers;
- advanced font fallback;
- additional CSS shorthands/properties;
- compression and PDF-size optimizations.

## 7. Parity test model

The central development rule is differential testing.

For each fixture:

1. render/prepare with `pagyra-js`;
2. capture a normalized reference representation;
3. execute the PHP implementation;
4. compare normalized trees/metrics/display operations;
5. optionally compare rendered PDF pages visually or by extracted geometry;
6. accept only intentional, documented deviations.

Prefer semantic intermediate snapshots over raw PDF byte equality because object numbering, compression and metadata can differ while rendering is equivalent.

Recommended normalized snapshot layers:

- DOM tree;
- computed-style tree;
- layout tree;
- fragmented/page tree;
- paint/display list;
- final PDF smoke assertions.

## 8. Definition of parity

Use explicit levels:

- **P0 API parity** — equivalent input can be expressed in PHP.
- **P1 style parity** — computed values match.
- **P2 layout parity** — boxes/lines/pages match within numeric tolerance.
- **P3 paint parity** — same visual primitives in same order.
- **P4 visual parity** — rasterized pages are effectively equivalent.

Feature documentation must state the achieved level.

## 9. Engineering rules

- PHP 8.2+ with `declare(strict_types=1);`.
- PSR-4 autoloading.
- Small domain objects and explicit types.
- Prefer readonly/value objects where practical.
- No global mutable rendering state.
- Parsing, cascade, layout, painting and PDF serialization are separate responsibilities.
- No dependency from lower-level modules back into high-level orchestration.
- Avoid inheritance-heavy class trees; prefer composition and focused interfaces.
- Every bug fix gets a fixture/regression test.
- No committed `vendor/`.
- No generated PDFs in Git unless they are deliberate tiny golden fixtures.

## 10. Suggested milestone releases

- `0.0.1` — clean skeleton + parity harness
- `0.0.2` — HTML/CSS/computed-style pipeline
- `0.0.3` — fonts + block layout
- `0.0.4` — inline text + wrapping
- `0.0.5` — pagination + first usable PDFs
- `0.0.6` — images/SVG
- `0.0.7` — flexbox
- `0.0.8` — grid/positioning
- `0.0.9` — headers/footers + broad parity suite
- `0.1.0` — first release considered practically usable

Versions are milestones, not deadlines.

## 11. First implementation slice

The first coding slice after this reset should be deliberately small:

1. Composer + PHPUnit + static analysis/lint baseline;
2. `RenderHtmlOptions`;
3. `Pagyra::prepareHtmlRender()` skeleton;
4. HTML-to-owned-DOM conversion;
5. a minimal CSS declaration parser;
6. geometry/value objects;
7. fixture runner;
8. one shared fixture: `<p>Hello World</p>`;
9. normalized JS reference snapshot for that fixture;
10. PHP test proving the first structural parity.

Do not start with PDF bytes. Establish the intermediate pipeline first.

## 12. Success criterion

`pagyra-php` succeeds when a growing corpus of the real `pagyra-js` fixtures can be executed through both implementations and discrepancies can be identified mechanically, rather than judged by manually opening PDFs and guessing whether they look close enough.
