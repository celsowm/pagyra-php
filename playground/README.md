# Pagyra PHP Playground

A local browser playground for the pure-PHP renderer, modeled after the `pagyra-js` playground.

The browser is only the editor/preview UI. PDF generation is performed by `Pagyra::renderHtmlToPdf()` through the local PHP `/render` endpoint. The playground does not call `pagyra-js`, Chromium, a headless browser, or an external rendering service.

## Run

Install dependencies first:

```bash
composer install
```

Then start the playground:

```bash
composer playground
```

Open:

```text
http://127.0.0.1:5177
```

## Endpoints

- `POST /render` — accepts HTML/CSS/page dimensions and returns `application/pdf`.
- `POST /prepare` — returns a JSON snapshot from `Pagyra::prepareHtmlRender()` for playground diagnostics.

Static resources referenced by playground documents are resolved from `playground/public` through `resourceBaseDir`.
