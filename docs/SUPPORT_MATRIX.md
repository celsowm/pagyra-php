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
| Tables | P2/P3 | Real grid, row groups, captions, colspan/rowspan, vertical-align and collapsed borders exist. Columns use recursive min/max-content sizing and consume CSS/legacy `<col>`/`<colgroup>` width hints including `span`. `table-header-group` / `table-footer-group` rows repeat across page fragments and reserve real page space; tables with multi-row `rowspan` fall back to the non-repeating fragment path until spanning-row packing is supported. |
| Recursive intrinsic sizing | P2+ | One reusable resolver exposes recursive min/max-content and border-box contributions, including declared descendant widths and box edges. Table, float, inline-block, flex and grid intrinsic paths consume the shared measurement instead of maintaining separate recursive probes. |
| Pagination | P2/P3 | Forced breaks, parity pages, break-inside, widows/orphans and recursive physical fragmentation are implemented. |
| Header/footer page model | Open | The independent measured first/even/odd header/footer subsystem from `pagyra-js` is not ported yet. |
| Background color/images/gradients | P3 | URL backgrounds, position, size, repeat, linear/radial gradients and color are painted. Attachment/origin/clip need broader semantics. |
| Borders / radius | Partial | Solid/dashed/dotted and rounded geometry are painted. `double`, `groove`, `ridge`, `inset`, `outset` and complete asymmetric rounded combinations remain. |
| Text decoration | P3 | Underline, line-through, overline, solid/double/dashed/dotted/wavy and decoration color are implemented. |
| Overflow clipping | P3 | `hidden`/`clip` paint clipping and text-overflow ellipsis exist; ellipsis preserves atomic inline boxes that fully fit before the truncation point. |
| Opacity | P3 for layout/atomic boxes | `opacity < 1` boxes/atomic inline boxes render through isolated PDF Transparency Group Form XObjects, so overlapping descendants composite first and group alpha is applied once. Nested groups are supported and primitive alpha is normalized out of the group factor. Normal non-atomic inline spans still use the per-run alpha fallback because they have no independent paint box yet. |
| Stacking contexts / z-index | P3 | The physical page is the root stacking context; positioned numeric z-index roots flatten through non-context ancestors and paint in stable negative / normal-auto / non-negative phases across top-level entries. `opacity < 1` and 2D transforms establish contexts; opacity contexts for layout/atomic boxes are materialized as isolated PDF transparency groups. |
| CSS transforms | P3 2D | Paint-only 2D transforms support `matrix()`, `translate*()`, `scale*()`, `rotate()`, `skew*()` and function lists, with CSS angle units, percentage translation and `transform-origin`. Transform scopes apply to blocks and atomic inline boxes, are re-opened across flattened stacking descendants, and establish stacking contexts. 3D/perspective and transformed link-annotation hit rectangles remain open. |
| JPEG / PNG PDF paint | P3 | JPEG direct embedding and multiple PNG color/transparency forms are supported. Adam7 remains open. |
| WebP | Partial | Metadata/intrinsic sizing is implemented; PDF paint/decoding is not. |
| SVG | Partial P3 | Inline/block inline-SVG now paints vector `path`, `rect`, `circle`, `ellipse`, `line`, `polyline` and `polygon` shapes directly into PDF, with `viewBox`, `preserveAspectRatio`, nested 2D `transform`, fill/stroke, stroke width, fill-rule and basic opacity. `defs` paint servers/gradients, `use`, SVG text/image, clipPath/mask/filter and richer SVG CSS remain open. |
| TrueType sfnt | P3 | Unicode cmap, metrics, classic kern, embedding, sparse subsetting and ToUnicode are implemented. |
| WOFF / WOFF2 | Open | The JS reference decodes these back to sfnt; PHP source selection currently skips them. |
| GPOS | Open | Classic `kern` works. The JS reference's PairPos format-1 subset is not yet ported. |
| CFF / variable fonts | Open / beyond current parity | Do not treat these as higher priority than JS-parity gaps; the JS reference is also incomplete here. |
| Font fallback chain | Partial | Family stack/Base14 fallback works; per-codepoint fallback-run segmentation remains open. |
| Remote HTTP resources | Intentional | Disabled by default. Any future support must be explicit and SSRF-safe rather than silently fetching arbitrary URLs. |

## Next implementation order

4. Give normal non-atomic inline opacity its own fragment/group identity instead of the per-run fallback.
5. Extend SVG vector paint to `defs`/gradients, `use`, text/image and clipPath/mask; add CSS 3D only if reference/corpus requires it.
6. Port WOFF/WOFF2 decoding and the JS GPOS PairPos format-1 subset.
7. Port the independent paged header/footer subsystem.
8. Finish WebP, Adam7 and the remaining border/effect styles.
9. Work through the long CSS tail only after the structural layout/paint gaps above.

## Documentation rule

Do not add a limitation to `README.md` or `AGENTS.md` without reflecting it here, and do not
mark a feature complete merely because syntax is parsed. The highest demonstrated parity level
must be backed by a fixture/regression test whenever practical.
