# Pagyra PHP support matrix

This file is the detailed implementation-status source of truth for `pagyra-php`.
The behavioral reference remains `celsowm/pagyra-js`: tests and observable reference output take
priority over this document when they disagree.

Status levels:

- **P0** — API/input parity only.
- **P1** — style/computed-value parity.
- **P2** — layout/geometry parity.
- **P3** — paint/display-list parity.
- **P4** — visual parity demonstrated by reference fixtures.
- **Partial** — useful implementation exists, but a documented subset remains.
- **Open** — not implemented in the owned PHP pipeline.
- **Intentional** — deliberately not enabled by default.

## Current major capabilities

| Area | Status | Notes |
| --- | --- | --- |
| Owned DOM + HTML normalization | P2+ | `html`/`body`, fragments, unknown elements, generated content and presentational hints participate in the owned tree. |
| Cascade/selectors | P2+ | Type/class/id/attribute selectors, all four combinators, structural pseudo-classes, `:not()`/`:is()`/`:where()`/`:root`, CSS-wide keywords and custom properties are implemented. |
| `::before` / `::after` | P3 | Generated strings, `attr()`, counters and quotes reach layout/paint. |
| `::first-letter` / `::first-line` | Partial | Implemented. First-letter does not yet descend through the first inline descendant or model the full typographic-letter rules; first-line style changes that affect metrics do not trigger reflow. |
| `::marker` | Open | List markers exist, but the pseudo-element styling surface is not implemented. |
| Block flow / box model | P2+ | Width/height, box sizing, min/max constraints, auto margins and sibling + parent/child margin collapsing are implemented for the current model. |
| Inline formatting | P2+ | Styled runs, whitespace modes, wrapping, alignment, justification, vertical-align, atomic inline boxes, real inline padding/border geometry, inline-block internals and ellipsis around text plus fitting atomic boxes are implemented. |
| Mixed block/inline inside atomic boxes | P2/P3 | Inline-block atomic boxes delegate block-containing interiors to a real nested block formatting context; mixed inline/block order, nested paint and last-line baseline are preserved. |
| Floats / clear | P2+ | Left/right floats, inline/replaced/block-content floats, recursive shrink-to-fit and text exclusion wrapping exist. Float exclusions live in explicit per-BFC context frames, `clear:left|right|both` uses side-specific bottoms, and a float that cannot fit the current horizontal slot retries on the next float row. |
| Absolute / fixed / relative positioning | Partial | Offsets and containing-block placement exist. As in the current JS reference simplification, absolute/fixed boxes are laid out in flow before being repositioned. |
| Flexbox | P2+ | Direction, wrapping, grow/shrink/basis, order, gaps and main/cross-axis alignment are implemented. |
| Grid | P2+ | px/%/fr/auto/minmax/repeat tracks, auto-fill/fit, placement/spans, implicit tracks, alignment and `grid-template-areas` are implemented. Named grid lines remain open. |
| Tables | Partial | Real grid, row groups, captions, colspan/rowspan, vertical-align and collapsed borders exist. Columns use recursive min/max-content sizing and consume CSS/legacy `<col>`/`<colgroup>` width hints including `span`. Complete repeated header/footer fragmentation semantics remain open. |
| Recursive intrinsic sizing | Partial | A reusable resolver now exposes min/max-content bounds; tables and float shrink-to-fit consume it. Reusing it across inline-block/flex/grid intrinsic paths is the next slice. |
| Pagination | P2/P3 | Forced breaks, parity pages, break-inside, widows/orphans and recursive physical fragmentation are implemented. |
| Header/footer page model | Open | The independent measured first/even/odd header/footer subsystem from `pagyra-js` is not ported yet. |
| Background color/images/gradients | P3 | URL backgrounds, position, size, repeat, linear/radial gradients and color are painted. Attachment/origin/clip need broader semantics. |
| Borders / radius | Partial | Solid/dashed/dotted and rounded geometry are painted. `double`, `groove`, `ridge`, `inset`, `outset` and complete asymmetric rounded combinations remain. |
| Text decoration | P3 | Underline, line-through, overline, solid/double/dashed/dotted/wavy and decoration color are implemented. |
| Overflow clipping | P3 | `hidden`/`clip` paint clipping and text-overflow ellipsis exist; ellipsis preserves atomic inline boxes that fully fit before the truncation point. |
| Opacity | Partial | Element/ancestor opacity reaches paint through per-command alpha. True isolated-group compositing for overlapping child paint is still open. |
| Stacking contexts / z-index | Open | The JS reference has a dedicated stacking-context pipeline; the PHP display list still lacks equivalent ordering. |
| CSS transforms | Open | No general matrix/transform paint pipeline yet. |
| JPEG / PNG PDF paint | P3 | JPEG direct embedding and multiple PNG color/transparency forms are supported. Adam7 remains open. |
| WebP | Partial | Metadata/intrinsic sizing is implemented; PDF paint/decoding is not. |
| SVG | Partial | DOM/path parsing and intrinsic sizing exist; full vector PDF paint is not wired in yet. |
| TrueType sfnt | P3 | Unicode cmap, metrics, classic kern, embedding, sparse subsetting and ToUnicode are implemented. |
| WOFF / WOFF2 | Open | The JS reference decodes these back to sfnt; PHP source selection currently skips them. |
| GPOS | Open | Classic `kern` works. The JS reference's PairPos format-1 subset is not yet ported. |
| CFF / variable fonts | Open / beyond current parity | Do not treat these as higher priority than JS-parity gaps; the JS reference is also incomplete here. |
| Font fallback chain | Partial | Family stack/Base14 fallback works; per-codepoint fallback-run segmentation remains open. |
| Remote HTTP resources | Intentional | Disabled by default. Any future support must be explicit and SSRF-safe rather than silently fetching arbitrary URLs. |

## Next implementation order

1. Reuse recursive intrinsic sizing in flex and grid paths (inline-block, table and float paths already consume it).
2. Complete fragmented/repeated table header/footer semantics.
4. Port stacking contexts / z-index from `pagyra-js`.
5. Add 2D transforms and reuse them for SVG paint.
6. Port WOFF/WOFF2 decoding and the JS GPOS PairPos format-1 subset.
7. Port the independent paged header/footer subsystem.
8. Finish WebP, Adam7 and the remaining border/effect styles.
9. Work through the long CSS tail only after the structural layout/paint gaps above.

## Documentation rule

Do not add a limitation to `README.md` or `AGENTS.md` without reflecting it here, and do not
mark a feature complete merely because syntax is parsed. The highest demonstrated parity level
must be backed by a fixture/regression test whenever practical.
