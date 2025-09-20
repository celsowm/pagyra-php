<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Celsowm\PagyraPhp\Css\CssParser;
use Celsowm\PagyraPhp\Html\HtmlParser;
use Celsowm\PagyraPhp\Html\Style\CssCascade;

final class TestFailure extends RuntimeException {}

function assertSameValue(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $msg = $message === '' ? '' : " {$message}";
        throw new TestFailure(sprintf('Assertion failed.%s Expected %s, got %s', $msg, var_export($expected, true), var_export($actual, true)));
    }
}

function assertTrue(bool $condition, string $message = ''): void
{
    if (!$condition) {
        $msg = $message === '' ? '' : " {$message}";
        throw new TestFailure('Assertion failed.' . $msg);
    }
}

$parser = new CssParser();
$htmlParser = new HtmlParser();
$cascade = new CssCascade();

// Test 1: comments removed and multiple selectors parsed
$css = <<<CSS
/* comment */
p, .lead {
    color: #ff0000;
    font-weight: bold;
}
span.note { color: #00ff00; }
CSS;

$cssOm = $parser->parse($css);
$rules = $cssOm->getRules();
assertSameValue(3, count($rules), 'CSS parser should split combined selectors');
assertSameValue('p', $rules[0]['selector']);
assertSameValue('color', array_key_first($rules[0]['declarations']));

// Test 2: cascade specificity and inline precedence
$html = <<<HTML
<div>
    <p id="first" class="lead">Hello world</p>
    <p id="second" class="lead" style="color: blue; font-size: 18pt;">Inline</p>
</div>
HTML;

$document = $htmlParser->parse($html);
$nodesByDomId = [];
$document->eachElement(function (array $node) use (&$nodesByDomId): void {
    $domId = $node['attributes']['id'] ?? null;
    if ($domId !== null && $domId !== '') {
        $nodesByDomId[(string)$domId] = $node['nodeId'];
    }
});

assertTrue(isset($nodesByDomId['first']), 'HTML parser should expose id attribute');
assertTrue(isset($nodesByDomId['second']), 'HTML parser should expose id attribute');

$computed = $cascade->compute($document, $cssOm);
$first = $computed[$nodesByDomId['first']] ?? null;
$second = $computed[$nodesByDomId['second']] ?? null;

assertTrue($first !== null, 'First paragraph should yield style');
assertTrue($second !== null, 'Second paragraph should yield style');

assertSameValue('bold', $first->get('font-weight'), 'class selector should apply');
assertSameValue('#ff0000', $first->get('color'));

assertSameValue('blue', $second->get('color'), 'inline color should override cascade');
assertSameValue('18pt', $second->get('font-size'), 'inline font-size should be kept as string');

// Test 3: inline style beats higher specificity selectors
$css2 = <<<CSS
p#second { color: green; }
.lead { color: red; }
CSS;

$cssOm2 = $parser->parse($css2);
$computed2 = $cascade->compute($document, $cssOm2);
$second2 = $computed2[$nodesByDomId['second']] ?? null;
assertTrue($second2 !== null, 'Second paragraph should have computed style');
assertSameValue('blue', $second2->get('color'), 'inline style keeps highest priority');

fwrite(STDOUT, "All HTML/CSS pipeline tests passed.\n");
