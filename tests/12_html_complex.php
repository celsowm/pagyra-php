<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Celsowm\PagyraPhp\Core\PdfBuilder;
use Celsowm\PagyraPhp\Converter\HtmlToPdfConverter;

$html = <<<'HTML'
<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='utf-8'>
    <title>Advanced Layout Showcase</title>
    <style>
        :root {
            --primary: #3949ab;
            --accent: #ff7043;
            --background: #f4f6fb;
            --surface: #ffffff;
            --muted: #5f6b81;
        }
        * {
            box-sizing: border-box;
        }
        body {
            font-family: PagyraDefault, sans-serif;
            font-size: 13pt;
            line-height: 1.5;
            margin: 0;
            background: var(--background);
            color: #1f2430;
        }
        header {
            background: linear-gradient(135deg, var(--primary), #283593);
            color: #ffffff;
            padding: 36pt 28pt 28pt 28pt;
            position: relative;
            overflow: hidden;
        }
        header::after {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            bottom: 0;
            width: 140pt;
            background: radial-gradient(circle at center, rgba(255, 255, 255, 0.25), transparent);
            opacity: 0.4;
        }
        .brand {
            font-size: 20pt;
            font-weight: 600;
            letter-spacing: 2pt;
        }
        .tagline {
            margin-top: 4pt;
            opacity: 0.85;
        }
        nav {
            margin-top: 18pt;
        }
        nav ul {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            gap: 14pt;
            flex-wrap: wrap;
        }
        nav li {
            background: rgba(255, 255, 255, 0.12);
            border-radius: 40pt;
            padding: 6pt 14pt;
            font-size: 11pt;
            text-transform: uppercase;
            letter-spacing: 1pt;
        }
        nav li.active {
            background: #ffffff;
            color: var(--primary);
        }
        main {
            padding: 26pt;
        }
        .intro {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170pt, 1fr));
            gap: 20pt;
            margin-bottom: 22pt;
        }
        .hero {
            background: var(--surface);
            border-radius: 16pt;
            box-shadow: 0 8pt 18pt rgba(31, 36, 48, 0.12);
            padding: 20pt 24pt;
            position: relative;
            overflow: hidden;
        }
        .hero::before {
            content: '';
            position: absolute;
            top: -60pt;
            right: -40pt;
            width: 220pt;
            height: 220pt;
            background: linear-gradient(135deg, rgba(57, 73, 171, 0.08), rgba(255, 112, 67, 0.14));
            border-radius: 50%;
        }
        .hero h1 {
            margin: 0 0 10pt 0;
            font-size: 22pt;
            color: var(--primary);
        }
        .hero p {
            margin: 0 0 14pt 0;
            color: var(--muted);
        }
        .hero-action {
            display: inline-flex;
            align-items: center;
            gap: 8pt;
            background: var(--primary);
            color: #ffffff;
            padding: 8pt 16pt;
            border-radius: 999pt;
            font-weight: 600;
            text-decoration: none;
        }
        .hero-action span.icon {
            display: inline-flex;
            width: 16pt;
            height: 16pt;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.25);
            align-items: center;
            justify-content: center;
            font-size: 10pt;
        }
        .metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140pt, 1fr));
            gap: 16pt;
        }
        .metric-card {
            background: var(--surface);
            border-radius: 14pt;
            padding: 14pt;
            box-shadow: 0 6pt 16pt rgba(31, 36, 48, 0.08);
            border-top: 4pt solid var(--primary);
        }
        .metric-card:nth-child(even) {
            border-top-color: var(--accent);
        }
        .metric-card h2 {
            margin: 0;
            font-size: 12pt;
            text-transform: uppercase;
            letter-spacing: 1pt;
            color: var(--muted);
        }
        .metric-card strong {
            display: block;
            font-size: 24pt;
            margin: 8pt 0 12pt 0;
            color: #1f2430;
        }
        .metric-trend {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 11pt;
            color: var(--muted);
        }
        .metric-trend span.badge {
            padding: 4pt 8pt;
            border-radius: 999pt;
            background: rgba(57, 73, 171, 0.12);
            color: var(--primary);
            font-weight: 600;
        }
        .metric-trend span.badge.negative {
            background: rgba(255, 112, 67, 0.14);
            color: var(--accent);
        }
        .main-layout {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20pt;
            margin-top: 24pt;
        }
        .aspect-card {
            background: var(--surface);
            border-radius: 16pt;
            box-shadow: 0 8pt 18pt rgba(31, 36, 48, 0.08);
            padding: 18pt 22pt;
        }
        .aspect-card h3 {
            margin-top: 0;
            font-size: 16pt;
            color: var(--primary);
        }
        .aspect-card p {
            color: var(--muted);
            margin: 0 0 12pt 0;
        }
        .grid-split {
            display: grid;
            grid-template-columns: 1fr;
            gap: 18pt;
            margin-top: 12pt;
        }
        .grid-split article h4 {
            margin: 0 0 6pt 0;
            font-size: 13pt;
            color: #1f2430;
        }
        .grid-split article p {
            margin: 0;
            color: var(--muted);
            font-size: 11pt;
        }
        .timeline {
            position: relative;
            padding-left: 26pt;
        }
        .timeline::before {
            content: '';
            position: absolute;
            left: 9pt;
            top: 4pt;
            bottom: 4pt;
            width: 2pt;
            background: linear-gradient(180deg, var(--primary), rgba(57, 73, 171, 0));
        }
        .timeline-item {
            position: relative;
            padding: 10pt 12pt 12pt 0;
        }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -17pt;
            top: 12pt;
            width: 10pt;
            height: 10pt;
            border-radius: 50%;
            background: var(--surface);
            border: 3pt solid var(--primary);
        }
        .timeline-item h4 {
            margin: 0;
            font-size: 13pt;
        }
        .timeline-item p {
            margin: 4pt 0 0 0;
            color: var(--muted);
            font-size: 11pt;
        }
        .table-card {
            background: var(--surface);
            border-radius: 16pt;
            box-shadow: 0 8pt 18pt rgba(31, 36, 48, 0.08);
            padding: 18pt 22pt;
        }
        .table-card h3 {
            margin-top: 0;
            font-size: 16pt;
            color: var(--primary);
        }
        .table-card table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 10pt;
            font-size: 11pt;
        }
        .table-card thead th {
            text-align: left;
            background: #f0f4ff;
            padding: 8pt 10pt;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.8pt;
        }
        .table-card tbody td {
            padding: 8pt 10pt;
            border-bottom: 1pt solid rgba(31, 36, 48, 0.08);
        }
        .table-card tbody tr:last-child td {
            border-bottom: none;
        }
        .table-card tbody tr:hover {
            background: rgba(57, 73, 171, 0.06);
        }
        .snapshot-list {
            margin: 0;
            padding: 0;
            list-style: none;
            display: grid;
            gap: 12pt;
        }
        .snapshot-list li {
            background: #f7f9ff;
            border-radius: 12pt;
            padding: 12pt 14pt;
            border: 1pt solid rgba(57, 73, 171, 0.08);
        }
        .snapshot-list li strong {
            display: block;
            color: #1f2430;
        }
        .snapshot-list li span {
            color: var(--muted);
            font-size: 11pt;
        }
        .footnote {
            margin-top: 28pt;
            text-align: center;
            color: var(--muted);
            font-size: 10pt;
            letter-spacing: 0.4pt;
        }
        @media (min-width: 760pt) {
            .main-layout {
                grid-template-columns: 2fr 1fr;
            }
            .grid-split {
                grid-template-columns: repeat(2, 1fr);
            }
            .timeline {
                grid-row: span 2;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class='brand'>VENN STUDIO</div>
        <div class='tagline'>Quarterly product intelligence &mdash; Q4 overview</div>
        <nav>
            <ul>
                <li class='active'>Overview</li>
                <li>Engagement</li>
                <li>Pipeline</li>
                <li>Adoption</li>
                <li>Team</li>
            </ul>
        </nav>
    </header>
    <main>
        <div class='intro'>
            <section class='hero'>
                <h1>Experience dashboard</h1>
                <p>We analyzed feature usage, sentiment and support touches across the entire portfolio to identify where new success plays are needed for the upcoming quarter.</p>
                <a class='hero-action' href='#actions'><span class='icon'>&#8594;</span>Explore action items</a>
            </section>
            <section class='metrics'>
                <article class='metric-card'>
                    <h2>Active Accounts</h2>
                    <strong>1,247</strong>
                    <div class='metric-trend'>
                        <span>last 30 days</span>
                        <span class='badge'>+6.8%</span>
                    </div>
                </article>
                <article class='metric-card'>
                    <h2>Feature NPS</h2>
                    <strong>41</strong>
                    <div class='metric-trend'>
                        <span>promoters dominate</span>
                        <span class='badge'>+4.1</span>
                    </div>
                </article>
                <article class='metric-card'>
                    <h2>Time To Value</h2>
                    <strong>12 days</strong>
                    <div class='metric-trend'>
                        <span>median onboarding</span>
                        <span class='badge negative'>-2 days</span>
                    </div>
                </article>
                <article class='metric-card'>
                    <h2>Renewal Forecast</h2>
                    <strong>94%</strong>
                    <div class='metric-trend'>
                        <span>confidence window</span>
                        <span class='badge'>stable</span>
                    </div>
                </article>
            </section>
        </div>
        <div class='main-layout'>
            <section class='aspect-card' id='actions'>
                <h3>Actionable highlights</h3>
                <p>The product squads unlocked three directional initiatives that should be prioritized before the growth summit. Each item links revenue impact with a clear owner and expected deliverable.</p>
                <div class='grid-split'>
                    <article>
                        <h4>Unified handoffs</h4>
                        <p>Improve the lead-to-onboarding workflow by consolidating intake forms and instrumenting the welcome tour analytics.</p>
                    </article>
                    <article>
                        <h4>Insight surfacing</h4>
                        <p>Bring surfacing of AI co-pilot suggestions directly into the workspace canvas to increase weekly stickiness.</p>
                    </article>
                    <article>
                        <h4>Hybrid playbooks</h4>
                        <p>Launch the curated playbooks for hybrid teams including asynchronous templates and follow up nudges.</p>
                    </article>
                    <article>
                        <h4>Voice of customer</h4>
                        <p>Expand the research panel to include enterprise champions and attach closing-the-loop rituals.</p>
                    </article>
                </div>
            </section>
            <aside class='aspect-card timeline'>
                <h3>Delivery timeline</h3>
                <div class='timeline-item'>
                    <h4>January 08 &mdash; Discovery sprint</h4>
                    <p>Complete shadowing sessions with top 12 accounts and instrument new activation funnels.</p>
                </div>
                <div class='timeline-item'>
                    <h4>January 22 &mdash; Experience alpha</h4>
                    <p>Roll out revised navigation to 18% of the cohort and collect qualitative day-7 feedback.</p>
                </div>
                <div class='timeline-item'>
                    <h4>February 12 &mdash; Growth summit</h4>
                    <p>Present the narrative and secure resourcing for self-serve expansion alongside sales enablement.</p>
                </div>
            </aside>
            <section class='table-card'>
                <h3>Sentiment breakdown</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Segment</th>
                            <th>Advocates</th>
                            <th>Neutrals</th>
                            <th>Attention</th>
                            <th>Comment</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Enterprise</td>
                            <td>62%</td>
                            <td>28%</td>
                            <td>10%</td>
                            <td>Security compliance friction during onboarding.</td>
                        </tr>
                        <tr>
                            <td>Mid-market</td>
                            <td>54%</td>
                            <td>31%</td>
                            <td>15%</td>
                            <td>Need clearer in-app guidance for workflow automations.</td>
                        </tr>
                        <tr>
                            <td>Scale-ups</td>
                            <td>71%</td>
                            <td>19%</td>
                            <td>10%</td>
                            <td>Churn mostly tied to billing consolidation delays.</td>
                        </tr>
                        <tr>
                            <td>Startups</td>
                            <td>38%</td>
                            <td>36%</td>
                            <td>26%</td>
                            <td>Pricing experimentation is confusing for early teams.</td>
                        </tr>
                    </tbody>
                </table>
            </section>
            <section class='aspect-card'>
                <h3>Snapshot board</h3>
                <p>This board surfaces curated snapshots from the product research hub. Each snapshot combines a data slice with a qualitative insight that can be discussed during the next executive sync.</p>
                <ul class='snapshot-list'>
                    <li>
                        <strong>Canvas sessions up 32%</strong>
                        <span>Experiment: highlight content blocks with guided arrows on empty states.</span>
                    </li>
                    <li>
                        <strong>Automation drop-offs reduced</strong>
                        <span>Insight: inline coaching messages prevented 42% of abandonment mid-flow.</span>
                    </li>
                    <li>
                        <strong>Regional adoption parity</strong>
                        <span>Play: weekly lighthouse call calibrates context for LATAM and EMEA pods.</span>
                    </li>
                </ul>
            </section>
        </div>
        <p class='footnote'>Generated internally for the Venn Studio planning session &mdash; distribution beyond leadership requires approval.</p>
    </main>
</body>
</html>
HTML;

$pdf = new PdfBuilder();
$converter = new HtmlToPdfConverter();
$converter->convert($html, $pdf);
$pdf->save('12_html_complex.pdf');
