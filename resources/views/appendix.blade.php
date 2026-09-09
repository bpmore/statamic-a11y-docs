{{-- The document appendix, as A11y Report would render it.

     Kept to the same shape as that addon's own sections — an h2 with an id,
     tables with captions and scoped headers — so it reads as part of the
     report rather than as something bolted on.

     Note what this is *not*: nothing here appears in the conformance table.
     Spec §10: document conformance is PDF/UA and Section 508 Chapter 5, and
     folding a PDF's missing tags into SC 1.3.1 is a category error a
     knowledgeable auditor catches on sight. --}}
<section aria-labelledby="documents-appendix-heading">
    <h2 id="documents-appendix-heading">{{ $appendix['heading'] }}</h2>

    <p>{{ $appendix['scope'] }}</p>

    <table>
        <caption>Documents checked, by format</caption>
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
            @foreach ($appendix['documents']['by_format'] as $format => $counts)
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

    <table>
        <caption>Document findings, by severity</caption>
        <thead>
            <tr><th scope="col">Severity</th><th scope="col">Findings</th></tr>
        </thead>
        <tbody>
            @foreach ($appendix['findings']['by_severity'] as $severity => $count)
                <tr>
                    <th scope="row">{{ ucfirst($severity) }}</th>
                    <td>{{ number_format($count) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h3>How these documents were checked</h3>
    <ul>
        @foreach ($appendix['engines'] as $engine)
            <li>
                {{ number_format($engine['documents']) }}
                {{ $engine['documents'] === 1 ? 'document' : 'documents' }}
                checked by {{ $engine['engine'] }} —
                {{ $engine['authoritative']
                    ? 'authoritative PDF/UA validation.'
                    : 'heuristic checks, not formal PDF/UA validation.' }}
            </li>
        @endforeach
    </ul>

    <h3>Known issues</h3>
    @if (empty($appendix['known_issues']))
        <p>No document findings were recorded.</p>
    @else
        <table>
            <caption>Document findings, worst first</caption>
            <thead>
                <tr>
                    <th scope="col">Document</th>
                    <th scope="col">Severity</th>
                    <th scope="col">Problem</th>
                    <th scope="col">Where</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($appendix['known_issues'] as $issue)
                    <tr>
                        <th scope="row">{{ $issue['document'] }}</th>
                        <td>{{ ucfirst($issue['severity']) }}</td>
                        <td>{{ $issue['message'] }}</td>
                        <td>{{ $issue['where'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- A list that silently stops is a list that misleads about how much
             there is. --}}
        @if ($appendix['known_issues_omitted'] > 0)
            <p>
                {{ number_format($appendix['known_issues_omitted']) }} further
                {{ $appendix['known_issues_omitted'] === 1 ? 'finding is' : 'findings are' }}
                not listed here. The full list is in the control panel.
            </p>
        @endif
    @endif
</section>
