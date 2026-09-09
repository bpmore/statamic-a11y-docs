<?php

declare(strict_types=1);
use Bpmore\DocumentA11yCore\Ooxml\Excel\XlsxRule;
use Bpmore\DocumentA11yCore\Ooxml\PowerPoint\PptxRule;
use Bpmore\DocumentA11yCore\Ooxml\Word\DocxRule;
use Bpmore\DocumentA11yCore\Pdf\PdfRule;
use Bpmore\StatamicA11yDocs\RuleLabel;

it('keeps the rules reference in step with the rules', function () {
    // A rules reference written by hand is a rules reference that is wrong by
    // the third release. This one is generated, and this fails if the file on
    // disk is not what the generator would write today.
    $path = repoPath('docs/rules.md');
    $before = file_get_contents($path);

    // Guard against the whole test passing because the file could not be read:
    // false === false is not the assertion anybody wanted.
    expect($before)->toBeString()->not->toBeEmpty();

    exec('php '.escapeshellarg(repoPath('tools/build-rules-doc.php')).' 2>&1', $output, $status);

    $after = file_get_contents($path);
    file_put_contents($path, $before);

    expect($status)->toBe(0, implode("\n", $output))
        ->and($after)->toBe($before, 'docs/rules.md is out of date — run php tools/build-rules-doc.php');
});

it('documents every rule the code can emit', function () {
    $reference = file_get_contents(repoPath('docs/rules.md'));

    $rules = [
        ...PdfRule::cases(),
        ...DocxRule::cases(),
        ...PptxRule::cases(),
        ...XlsxRule::cases(),
    ];

    // str_contains rather than toContain: Pest's toContain takes several
    // needles, not a message, so a helpful message becomes a second thing it
    // looks for and never finds.
    foreach ($rules as $rule) {
        expect(str_contains($reference, "`{$rule->value}`"))
            ->toBeTrue("{$rule->value} is not in docs/rules.md");
    }
});

it('answers the veraPDF question before anybody installs Java to find out', function () {
    // Spec §12: without a clear "you probably don't need this" path, every
    // support ticket becomes a JRE question.
    $page = file_get_contents(repoPath('docs/verapdf.md'));
    $readme = file_get_contents(repoPath('README.md'));

    expect($readme)->toContain('Almost certainly not')
        ->and($page)->toContain('Almost certainly not')
        ->and($page)->toContain('That is the intended configuration for most sites');
});

it('keeps the repository name out of anything a customer reads', function () {
    // Spec §1: docugate and statamic-a11y-docs are internal shorthand and
    // should not appear in the listing, the control panel, the config
    // namespace or the commands.
    $listing = file_get_contents(repoPath('LISTING.md'));

    expect(strtolower($listing))->not->toContain('docugate ')
        ->and($listing)->toContain('A11y Docs')
        ->and($listing)->toContain('$99');
});

it('links every documentation page from the README', function () {
    // A page nothing links to is a page nobody reads.
    $readme = file_get_contents(repoPath('README.md'));

    foreach (glob(repoPath('docs/*.md')) as $page) {
        expect(str_contains($readme, 'docs/'.basename($page)))
            ->toBeTrue(basename($page).' is not linked from the README');
    }
});

it('quotes only strings the product actually produces in the listing copy', function () {
    // Marketing copy that quotes the product has to be checked against the
    // product. Two of these were wrong when written: the headline example said
    // "214 DOCX" where the product prints "214 DOCXs", and the rule label was
    // quoted as "Alt text is just a filename" against an actual
    // "Alt text is filename".
    $listing = (string) file_get_contents(repoPath('LISTING.md'));

    expect($listing)->not->toBe('');

    // A rule label, straight from the labeller the control panel uses.
    $label = RuleLabel::for('docx.alt_text_is_filename');
    expect(str_contains($listing, $label))
        ->toBeTrue("LISTING.md does not quote the real rule label: $label");

    // The badge wording, from the fieldtype that renders it.
    foreach (['No problems found', 'Format not checked'] as $badge) {
        expect(str_contains((string) file_get_contents(repoPath('src/Fieldtypes/DocumentStatus.php')), $badge))
            ->toBeTrue("the fieldtype no longer says '$badge'");
        expect(str_contains($listing, $badge))
            ->toBeTrue("LISTING.md quotes a badge label the fieldtype no longer uses: $badge");
    }

    // The gate's closing sentence, which the screenshot note quotes verbatim.
    $closing = 'Fix it, or exempt it with a reason, then publish.';
    expect(str_contains((string) file_get_contents(repoPath('src/Gate/PublishGate.php')), 'or exempt %s with a reason, then publish.'))
        ->toBeTrue('the gate no longer ends with the sentence the listing quotes');
    expect(str_contains($listing, $closing))
        ->toBeTrue("LISTING.md no longer quotes the gate's closing line");

    // The headline example has to match the shape headline() produces:
    // "<n> <FORMAT>s" biggest first, then plain-words findings.
    expect(preg_match('/^[\d,]+ [A-Z]+s · [\d,]+ [A-Z]+s · /m', $listing) === 1)
        ->toBeTrue('the headline example in LISTING.md is not the shape the product prints');
});
