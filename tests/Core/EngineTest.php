<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\Engine;

it('says what checked the document and at which version', function () {
    expect(Engine::heuristics('1.0.0')->describe())->toBe('heuristics 1.0.0')
        ->and(Engine::veraPdf('1.26.1')->describe())->toBe('verapdf 1.26.1');
});

it('knows which engine is the authoritative one', function () {
    // Every export has to say whether it used real PDF/UA validation or our
    // reading of the file. A report that does not is a report that overstates
    // itself.
    expect(Engine::veraPdf('1.26.1')->isAuthoritative())->toBeTrue()
        ->and(Engine::heuristics('1.0.0')->isAuthoritative())->toBeFalse();
});

it('serialises into the two columns the checks table stores', function () {
    expect(Engine::veraPdf('1.26.1')->jsonSerialize())
        ->toBe(['engine' => 'verapdf', 'engine_version' => '1.26.1']);
});

it('will not be nameless or versionless', function () {
    expect(fn () => new Engine('', '1.0.0'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Engine('heuristics', ' '))->toThrow(InvalidArgumentException::class);
});
