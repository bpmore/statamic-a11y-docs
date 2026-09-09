<?php

declare(strict_types=1);

use Bpmore\DocumentA11yCore\Location;

it('locates by page, slide, sheet or element', function () {
    expect(Location::page(14)->describe())->toBe('page 14')
        ->and(Location::page(14, 'Figure')->describe())->toBe('page 14, Figure')
        ->and(Location::slide(3, 'Picture 2')->describe())->toBe('slide 3, Picture 2')
        ->and(Location::sheet('Estates')->describe())->toBe('sheet Estates')
        ->and(Location::element('Table 2')->describe())->toBe('Table 2');
});

it('serialises only the keys that carry a value', function () {
    expect(Location::page(4)->jsonSerialize())->toBe(['page' => 4])
        ->and(Location::sheet('Budget', 'Chart 1')->jsonSerialize())
        ->toBe(['sheet' => 'Budget', 'element' => 'Chart 1']);
});

it('round-trips through an array', function () {
    $location = Location::slide(2, 'Title placeholder');

    expect(Location::fromArray($location->jsonSerialize()))->toEqual($location)
        ->and(Location::fromArray([]))->toBeNull();
});

it('counts pages the way a reader does', function () {
    expect(fn () => Location::page(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Location::slide(-1))->toThrow(InvalidArgumentException::class);
});

it('refuses to be a location that locates nothing', function () {
    expect(fn () => Location::element(''))->toThrow(InvalidArgumentException::class);
});
