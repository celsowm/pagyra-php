const $ = (selector) => document.querySelector(selector);

const templates = {
  basic: {
    label: 'Basic document',
    html: `<h1>Hello from Pagyra PHP</h1>\n<p>This PDF is rendered by the pure PHP engine.</p>\n<p><strong>Bold</strong>, <em>italic</em>, wrapping and pagination can be tested here.</p>`,
    css: `body { font-family: Helvetica, sans-serif; color: #202124; }\nh1 { color: #111827; }\np { line-height: 1.5; }`,
  },
  cards: {
    label: 'Boxes & borders',
    html: `<div class="card">\n  <h2>Pagyra</h2>\n  <p>Backgrounds, padding, borders and radius.</p>\n</div>`,
    css: `.card { width: 320px; padding: 24px; background: #f3f4f6; border: 3px solid #111827; border-radius: 24px; }\nh2 { margin-top: 0; }`,
  },
  image: {
    label: 'PNG image',
    html: `<h2>Image sizing</h2>\n<img width="200" height="100" style="width:160px; object-fit:contain" src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=">`,
    css: `img { border: 2px solid #111; padding: 8px; }`,
  },
  svg: {
    label: 'SVG workbench',
    html: `<h2>SVG parity workbench</h2>\n<svg width="300" height="180" viewBox="0 0 300 180">\n  <path d="M30 130 A70 70 0 0 1 170 130" fill="none" stroke="#111" stroke-width="8"/>\n  <rect x="190" y="45" width="70" height="70" fill="#ddd" stroke="#111" stroke-width="4"/>\n</svg>`,
    css: `h2 { font-family: Helvetica, sans-serif; }`,
  },
  pages: {
    label: '@page & pagination',
    html: `<h1>First page</h1>\n${Array.from({length: 18}, (_, i) => `<p>Paragraph ${i + 1}: Pagyra PHP pagination playground content.</p>`).join('\n')}`,
    css: `@page { size: A4; margin: 18mm; }\n@page :first { margin-top: 30mm; }\nbody { font-family: Helvetica, sans-serif; }\np { line-height: 1.55; }`,
  },
};

let pdfUrl = null;

function setStatus(message, tone = 'neutral') {
  const status = $('#status');
  status.textContent = message;
  status.dataset.tone = tone;
}

function payload() {
  return {
    html: $('#html').value,
    css: $('#css').value,
    viewportWidth: Number($('#viewport-width').value) || 794,
    viewportHeight: Number($('#viewport-height').value) || 1123,
    pageWidth: Number($('#viewport-width').value) || 794,
    pageHeight: Number($('#viewport-height').value) || 1123,
  };
}

function refreshHtmlPreview() {
  const doc = `<!doctype html><html><head><style>${$('#css').value}</style></head><body>${$('#html').value}</body></html>`;
  $('#html-preview').srcdoc = doc;
}

async function renderPdf() {
  const button = $('#render');
  button.disabled = true;
  setStatus('Rendering with PHP…', 'busy');
  refreshHtmlPreview();

  try {
    const response = await fetch('/render', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(payload()),
    });
    if (!response.ok) {
      let message = `HTTP ${response.status}`;
      try {
        const error = await response.json();
        if (error?.error) message = error.error;
      } catch {}
      throw new Error(message);
    }

    const blob = await response.blob();
    if (pdfUrl) URL.revokeObjectURL(pdfUrl);
    pdfUrl = URL.createObjectURL(blob);
    $('#pdf').data = pdfUrl;
    $('#empty').hidden = true;

    const diagnosticsResponse = await fetch('/prepare', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(payload()),
    });
    if (diagnosticsResponse.ok) {
      const diagnostics = await diagnosticsResponse.json();
      $('#diagnostics').textContent = JSON.stringify({
        pageSize: diagnostics.pageSize,
        margins: diagnostics.margins,
        pageMargins: diagnostics.pageMargins,
        pageCount: diagnostics.pagination?.pageCount ?? null,
        stylesheetHrefs: diagnostics.stylesheetHrefs,
      }, null, 2);
    }

    setStatus(`Rendered ${(blob.size / 1024).toFixed(1)} KB PDF.`, 'ok');
  } catch (error) {
    setStatus(error instanceof Error ? error.message : String(error), 'error');
  } finally {
    button.disabled = false;
  }
}

for (const [id, item] of Object.entries(templates)) {
  const option = document.createElement('option');
  option.value = id;
  option.textContent = item.label;
  $('#template').append(option);
}

$('#template').addEventListener('change', (event) => {
  const item = templates[event.target.value];
  if (!item) return;
  $('#html').value = item.html;
  $('#css').value = item.css;
  refreshHtmlPreview();
});

for (const button of document.querySelectorAll('[data-editor]')) {
  button.addEventListener('click', () => {
    document.querySelectorAll('[data-editor]').forEach((item) => item.classList.toggle('active', item === button));
    document.querySelectorAll('.editor').forEach((editor) => editor.classList.remove('active'));
    $(`#${button.dataset.editor}`).classList.add('active');
  });
}

for (const button of document.querySelectorAll('[data-preview]')) {
  button.addEventListener('click', () => {
    document.querySelectorAll('[data-preview]').forEach((item) => item.classList.toggle('active', item === button));
    document.querySelectorAll('.preview').forEach((pane) => pane.classList.remove('active'));
    $(`#${button.dataset.preview}-pane`).classList.add('active');
  });
}

$('#render').addEventListener('click', renderPdf);
$('#html').addEventListener('input', refreshHtmlPreview);
$('#css').addEventListener('input', refreshHtmlPreview);
$('#template').value = 'basic';
$('#template').dispatchEvent(new Event('change'));
