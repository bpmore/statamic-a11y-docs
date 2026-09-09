<?php

declare(strict_types=1);

/**
 * Generates docs/rules.md from the rule catalogues themselves.
 *
 *     php tools/build-rules-doc.php
 *
 * A rules reference written by hand is a rules reference that is wrong by the
 * third release. This reads the enums, the Microsoft correspondence table and
 * the fixture manifest, so the documentation cannot drift from the code — and a
 * test fails if the file on disk is not what this would write.
 */

require __DIR__.'/../vendor/autoload.php';

use Bpmore\DocumentA11yCore\Ooxml\Excel\XlsxRule;
use Bpmore\DocumentA11yCore\Ooxml\MicrosoftChecker;
use Bpmore\DocumentA11yCore\Ooxml\PowerPoint\PptxRule;
use Bpmore\DocumentA11yCore\Ooxml\Word\DocxRule;
use Bpmore\DocumentA11yCore\Pdf\PdfRule;

$manifest = json_decode(
    (string) file_get_contents(__DIR__.'/../tests/fixtures/documents/manifest.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);

/**
 * Which fixture proves each rule, so a reader can go and look at one.
 *
 * The one that isolates it wins: a fixture expecting this rule and nothing else
 * shows what the rule means, where a fixture failing five rules at once shows
 * what a bad scan looks like.
 */
$fixtures = [];
foreach ($manifest['fixtures'] as $fixture) {
    foreach ($fixture['expect'] as $expectation) {
        $current = $fixtures[$expectation['rule']] ?? null;
        // A fixture filed under the rule's own format beats one that only
        // happens to trip it — pdf.not_tagged is better shown by an untagged
        // PDF than by a PDF somebody renamed .docx.
        $home = str_starts_with($fixture['path'], strstr($expectation['rule'], '.', true).'/');
        $better = $current === null
            || count($fixture['expect']) < $current['rules']
            || (count($fixture['expect']) === $current['rules'] && $home && ! $current['home']);

        if ($better) {
            $fixtures[$expectation['rule']] = [
                'path' => $fixture['path'],
                'rules' => count($fixture['expect']),
                'home' => $home,
            ];
        }
    }
}

$families = [
    'PDF' => ['rules' => PdfRule::cases(), 'note' => 'Checked by the built-in heuristics, and by veraPDF where it is installed.'],
    'Word' => ['rules' => DocxRule::cases(), 'note' => 'Grounded in Microsoft\'s own Accessibility Checker, so a finding matches something the author can see and fix in Word.'],
    'PowerPoint' => ['rules' => PptxRule::cases(), 'note' => 'As for Word: these correspond to what PowerPoint\'s own checker reports.'],
    'Excel' => ['rules' => XlsxRule::cases(), 'note' => 'As for Word: these correspond to what Excel\'s own checker reports.'],
];

$total = array_sum(array_map(static fn (array $family): int => count($family['rules']), $families));

$out = <<<MD
# What A11y Docs checks

{$total} rules across four formats. Generated from the rule definitions
themselves by `tools/build-rules-doc.php` — edit the code, not this file.

Severities are the same four A11y Report uses, so the two products describe a
site and its documents in one vocabulary:

| Severity | What it means |
|---|---|
| **critical** | Somebody cannot read the document at all. |
| **serious** | A real barrier, though it can be worked around. |
| **moderate** | Makes the document harder to use than it needs to be. |
| **minor** | Worth fixing; blocks nobody. |


MD;

foreach ($families as $name => $family) {
    $out .= "## $name\n\n{$family['note']}\n\n";
    $out .= "| Rule | Severity | Microsoft calls this | Example |\n|---|---|---|---|\n";

    foreach ($family['rules'] as $rule) {
        $microsoft = MicrosoftChecker::classificationOf($rule->value);
        $departure = MicrosoftChecker::departureReason($rule->value);

        $theirs = $microsoft === null
            ? '—'
            : ucfirst($microsoft->value).($departure === null ? '' : ' †');

        $example = isset($fixtures[$rule->value])
            ? '`'.$fixtures[$rule->value]['path'].'`'
            : '—';

        $out .= sprintf("| `%s` | %s | %s | %s |\n", $rule->value, $rule->severity()->value, $theirs, $example);
    }

    $out .= "\n";
}

// The departures, spelled out. A severity that differs from Microsoft's without
// a stated reason is the kind of thing a customer notices and does not ask about.
$departures = [];
foreach ($families as $family) {
    foreach ($family['rules'] as $rule) {
        if ($reason = MicrosoftChecker::departureReason($rule->value)) {
            $departures[$rule->value] = $reason;
        }
    }
}

if ($departures !== []) {
    $out .= "## † Where we grade differently from Microsoft\n\n";
    $out .= "Microsoft's checker has three levels and this has four, so the mapping cannot be\n"
        ."one to one: **Error → critical, Warning → moderate, Tip → minor**, leaving *serious*\n"
        ."for the rules Microsoft does not check at all. Every rule that departs from that\n"
        ."says why.\n\n";

    foreach ($departures as $ruleId => $reason) {
        $out .= "**`$ruleId`** — $reason\n\n";
    }
}

$unmapped = MicrosoftChecker::NO_EQUIVALENT;

if ($unmapped !== []) {
    $out .= "## Rules Microsoft does not check\n\n";
    $out .= "Checked here anyway, each for a stated reason.\n\n";

    foreach ($unmapped as $ruleId => $reason) {
        $out .= "**`$ruleId`** — $reason\n\n";
    }
}

$out .= "## What is not checked\n\n"
    ."- **Legacy `.doc`, `.ppt` and `.xls`** are detected and reported as `unsupported`, never\n"
    ."  parsed. A half-understood binary file that yields no findings would be recorded as a\n"
    ."  document with nothing wrong, which is worse than saying it cannot be checked.\n"
    ."- **Colour contrast inside documents.** Microsoft's checker does this and this does not;\n"
    ."  it needs rendering, not reading.\n"
    ."- **Anything about the website itself.** That is A11y Report's job.\n";

file_put_contents(__DIR__.'/../docs/rules.md', $out);

printf("docs/rules.md written: %d rules, %d with a Microsoft equivalent, %d departures.\n",
    $total,
    count(MicrosoftChecker::RULES),
    count($departures),
);
