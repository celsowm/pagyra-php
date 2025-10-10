<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Celsowm\PagyraPhp\Core\PdfBuilder;
use Celsowm\PagyraPhp\Converter\HtmlToPdfConverter;

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Nested Divs Test</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      margin: 0;
      padding: 0;
      background-color: #f5f5f5;
    }

    .parent-div {
      border: 3px solid black;
      padding: 25px;
      background-color: #fff;
      margin: 20px 0;
      border-radius: 5px;
    }

    .child-div {
      background-color: #f9f9f9;
      padding: 15px;
      margin-top: 10px;
    }

    .lorem-paragraph {
      margin-bottom: 12px;
      line-height: 1.5;
      color: #444;
    }
  </style>
</head>
<body>
  <h1>Nested Divs with Lorem Ipsum Content</h1>

  <div class="parent-div">
    <h2>Parent Div with Border and Padding</h2>
    <p>This parent div has a border and padding but no explicit height. Its height will be determined by its content.</p>

    <div class="child-div">
      <h3>Child Div with Lorem Ipsum Paragraphs</h3>

      <p class="lorem-paragraph">
        Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.
      </p>

      <p class="lorem-paragraph">
        Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.
      </p>

      <p class="lorem-paragraph">
        Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque laudantium, totam rem aperiam, eaque ipsa quae ab illo inventore veritatis et quasi architecto beatae vitae dicta sunt explicabo.
      </p>
    </div>
  </div>

  <div class="parent-div">
    <h2>Another Parent Div Example</h2>
    <p>This is another parent div to show multiple instances of the same structure.</p>

    <div class="child-div">
      <h3>Second Child Div</h3>

      <p class="lorem-paragraph">
        Nemo enim ipsam voluptatem quia voluptas sit aspernatur aut odit aut fugit, sed quia consequuntur magni dolores eos qui ratione voluptatem sequi nesciunt. Neque porro quisquam est, qui dolorem ipsum quia dolor sit amet.
      </p>

      <p class="lorem-paragraph">
        At vero eos et accusamus et iusto odio dignissimos ducimus qui blanditiis praesentium voluptatum deleniti atque corrupti quos dolores et quas molestias excepturi sint occaecati cupiditate non provident.
      </p>

      <p class="lorem-paragraph">
        Et harum quidem rerum facilis est et expedita distinctio. Nam libero tempore, cum soluta nobis est eligendi optio cumque nihil impedit quo minus id quod maxime placeat facere possimus.
      </p>
    </div>
  </div>
</body>
</html>
HTML;

$pdf = new PdfBuilder();
$converter = new HtmlToPdfConverter();
$converter->convert($html, $pdf);
$pdf->save('19_html_nested_divs.pdf');
