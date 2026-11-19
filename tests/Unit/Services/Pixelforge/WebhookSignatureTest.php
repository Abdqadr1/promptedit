<?php

use App\Services\Pixelforge\WebhookSignature;

const WEBHOOK_TEST_SECRET = 'whsec_test';
const WEBHOOK_TEST_NOW = 1_800_000_000;

function signatureHeader(string $body, int $timestamp = WEBHOOK_TEST_NOW, string $secret = WEBHOOK_TEST_SECRET): string
{
    return 't='.$timestamp.',v1='.WebhookSignature::sign($secret, $timestamp, $body);
}

test('a correctly signed body is accepted', function () {
    $verifier = new WebhookSignature(WEBHOOK_TEST_SECRET);

    expect($verifier->isValid('{"id":"evt_1"}', signatureHeader('{"id":"evt_1"}'), WEBHOOK_TEST_NOW))->toBeTrue();
});

test('a tampered body is rejected', function () {
    $verifier = new WebhookSignature(WEBHOOK_TEST_SECRET);

    expect($verifier->isValid('{"id":"evt_2"}', signatureHeader('{"id":"evt_1"}'), WEBHOOK_TEST_NOW))->toBeFalse();
});

test('a body signed with another secret is rejected', function () {
    $verifier = new WebhookSignature(WEBHOOK_TEST_SECRET);

    expect($verifier->isValid('{}', signatureHeader('{}', secret: 'whsec_other'), WEBHOOK_TEST_NOW))->toBeFalse();
});

test('a timestamp outside the tolerance window is rejected', function (int $offset, bool $valid) {
    $verifier = new WebhookSignature(WEBHOOK_TEST_SECRET, 300);

    expect($verifier->isValid('{}', signatureHeader('{}', WEBHOOK_TEST_NOW + $offset), WEBHOOK_TEST_NOW))->toBe($valid);
})->with([
    'just inside' => [-300, true],
    'too old' => [-301, false],
    'too far in the future' => [301, false],
]);

test('a missing or malformed header is rejected', function (?string $header) {
    expect((new WebhookSignature(WEBHOOK_TEST_SECRET))->isValid('{}', $header, WEBHOOK_TEST_NOW))->toBeFalse();
})->with([null, '', 'v1=abc', 't=abc,v1=def', 't='.WEBHOOK_TEST_NOW]);

test('verification fails closed when no secret is configured', function () {
    expect((new WebhookSignature(''))->isValid('{}', signatureHeader('{}', secret: ''), WEBHOOK_TEST_NOW))->toBeFalse();
});
