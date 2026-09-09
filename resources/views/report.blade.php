<!DOCTYPE html>
{{-- The report about document accessibility has to be accessible itself. Spec
     §12: if this ends up as an untagged PDF, that is the screenshot that goes
     round. Every choice below is about the tagged PDF Chrome makes of it. --}}
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        /* Print styles only. No colour carries meaning on its own anywhere in
           this document: every severity is spelled out in words beside it. */
        body { font: 11pt/1.5 -apple-system, "Segoe UI", system-ui, sans-serif; color: #111; margin: 2.5cm 2cm; }
        h1 { font-size: 20pt; margin: 0 0 .2em; }
        h2 { font-size: 14pt; margin: 1.6em 0 .4em; }
        h3 { font-size: 12pt; margin: 1.2em 0 .3em; }
        p, li { max-width: 34em; }
        table { border-collapse: collapse; width: 100%; margin: .6em 0 1.4em; }
        caption { text-align: left; font-weight: 600; padding-bottom: .3em; }
        th, td { border: 1px solid #999; padding: .35em .6em; text-align: left; vertical-align: top; }
        thead th { background: #f0f0f0; }
        .meta { color: #444; font-size: 10pt; }
        /* Emphasis is done with a class, not <span class="figure"> or <em>. Chrome maps
           those to "Strong" and "Em" structure types, which are not standard
           PDF types, and writes no RoleMap to explain them — so the printed
           report fails PDF/UA clause 7.1-5. Measured, not guessed. */
        .figure { font-weight: 600; }
        code { font-family: ui-monospace, monospace; font-size: 10pt; }
        /* The report says both: the label so a reader knows what the problem is,
           the id so an auditor can cite it and trace it back to a rule. The id
           is secondary, but it is not decoration — do not hide it from a screen
           reader, and keep it dark enough to read. */
        .rule-id { display: block; color: #444; font-size: 9pt; }
    </style>
</head>
<body>
<main>
    <h1>{{ $title }}</h1>
    <p class="meta">{{ $site }} · {{ $generatedAt }}</p>

    <p>{{ $headline }}</p>

    @if ($engines->isNotEmpty())
        {{-- Spec §5: a report that does not say whether it used real PDF/UA
             validation or a set of heuristics is a report that overstates
             itself. --}}
        <h2>How these documents were checked</h2>
        <ul>
            @foreach ($engines as $engine)
                <li>
                    <span class="figure">{{ $engine['documents'] }}</span>
                    {{ $engine['documents'] === 1 ? 'document' : 'documents' }}
                    checked by <span class="figure">{{ $engine['engine'] }}</span>.
                    @if (str_contains($engine['engine'], 'verapdf'))
                        This includes authoritative PDF/UA validation.
                    @else
                        These are heuristic checks, not formal PDF/UA validation.
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    <h2>What was checked</h2>
    <table>
        <caption>Documents by format and outcome</caption>
        <thead>
            <tr>
                <th scope="col">Format</th>
                <th scope="col">Total</th>
                <th scope="col">Passing</th>
                <th scope="col">Failing</th>
                <th scope="col">Not checkable</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($formats as $format => $counts)
                <tr>
                    <th scope="row">{{ strtoupper($format) }}</th>
                    <td>{{ number_format($counts['total']) }}</td>
                    <td>{{ number_format($counts['pass'] ?? 0) }}</td>
                    <td>{{ number_format($counts['fail'] ?? 0) }}</td>
                    <td>{{ number_format(($counts['unsupported'] ?? 0) + ($counts['skipped'] ?? 0) + ($counts['error'] ?? 0)) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>What was found</h2>
    <table>
        <caption>Findings by severity</caption>
        <thead>
            <tr><th scope="col">Severity</th><th scope="col">Findings</th><th scope="col">What it means</th></tr>
        </thead>
        <tbody>
            @foreach ($severities as $severity => $count)
                <tr>
                    <th scope="row">{{ ucfirst($severity) }}</th>
                    <td>{{ number_format($count) }}</td>
                    <td>{{ $severityMeanings[$severity] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($rules->isNotEmpty())
        <h2>What is wrong most often</h2>
        <p>Counted by document rather than by finding, because that is the number to act on.</p>
        <table>
            <caption>Rules affecting the most documents</caption>
            <thead>
                <tr><th scope="col">Problem</th><th scope="col">Documents</th></tr>
            </thead>
            <tbody>
                @foreach ($rules as $rule)
                    <tr>
                        <th scope="row">
                            {{ $rule['label'] }}
                            <code class="rule-id">{{ $rule['rule'] }}</code>
                        </th>
                        <td>{{ number_format($rule['documents']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($offenders->isNotEmpty())
        <h2>Documents needing the most work</h2>
        <table>
            <caption>Documents with the most findings, worst first</caption>
            <thead>
                <tr>
                    <th scope="col">Document</th>
                    <th scope="col">Container</th>
                    <th scope="col">Critical findings</th>
                    <th scope="col">All findings</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($offenders as $offender)
                    <tr>
                        <th scope="row">{{ $offender['path'] }}</th>
                        <td>{{ $offender['container'] }}</td>
                        <td>{{ number_format($offender['critical']) }}</td>
                        <td>{{ number_format($offender['findings']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>What this report does not cover</h2>
    <p>
        Document conformance is PDF/UA and Section 508 Chapter 5 territory, not WCAG success criteria.
        These findings describe the documents in the asset library and say nothing about the accessibility of the website itself.
    </p>
    @if ($grandfathered > 0)
        <p>
            {{ number_format($grandfathered) }}
            {{ $grandfathered === 1 ? 'document was' : 'documents were' }}
            already in the library when checking began. They are listed here but do not block
            publishing.
        </p>
    @endif
</main>
</body>
</html>
