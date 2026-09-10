<?php

use App\Support\PhoneNumber;

/**
 * Phone normalization for wa.me/ links.
 * Pure unit test — no DB, dataset-driven.
 */
it('normalizes phone numbers to E.164 (digits only, default-IT +39)', function (?string $raw, ?string $expected) {
    expect(PhoneNumber::toE164($raw))->toBe($expected);
})->with([
    'IT mobile 10 digits starting 3'    => ['3331234567', '393331234567'],
    'IT mobile with spaces'             => ['333 123 4567', '393331234567'],
    'IT mobile 9 digits starting 3'     => ['333123456', '39333123456'],
    'international with + already'       => ['+39 333 1234567', '393331234567'],
    'international with 00 prefix'       => ['0039 333 1234567', '393331234567'],
    'non-IT international (+1)'          => ['+1 415 555 0100', '14155550100'],
    'null'                              => [null, null],
    'empty string'                      => ['', null],
    'too short / ambiguous'             => ['123', null],
    'IT landline without prefix'        => ['02 1234567', null],
]);

it('reports whether a number is normalizable for wa.me links', function () {
    expect(PhoneNumber::isNormalizable('3331234567'))->toBeTrue();
    expect(PhoneNumber::isNormalizable('123'))->toBeFalse();
    expect(PhoneNumber::isNormalizable(null))->toBeFalse();
});
