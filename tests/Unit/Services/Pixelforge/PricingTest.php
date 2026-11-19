<?php

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Services\Pixelforge\Pricing;

function pricingCatalog(): array
{
    return [
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ];
}

test('provider cost matches the documented worked maximum exactly', function () {
    $cost = Pricing::providerCostNanos([
        'input_text_tokens' => 115,
        'input_image_tokens' => 1530,
        'output_tokens' => 6450,
        'reasoning_tokens' => 8000,
    ], pricingCatalog()['rates']);

    expect($cost)->toBe(373_875_000);
});

test('the charge adds the 25 percent markup', function () {
    expect((new Pricing(pricingCatalog()))->chargeFor(373_875_000))->toBe(467_343_750);
});

test('the charge rounds a fractional nano-dollar up', function () {
    expect((new Pricing(pricingCatalog()))->chargeFor(1))->toBe(2)
        ->and((new Pricing(pricingCatalog()))->chargeFor(5_000))->toBe(6_250);
});

test('a failed generation with no usage costs nothing', function () {
    expect(Pricing::providerCostNanos([], pricingCatalog()['rates']))->toBe(0);
});

test('the estimate range brackets the documented worst case', function () {
    $estimate = (new Pricing(pricingCatalog()))->estimateCharge(
        str_repeat('a', 400),
        GenerationSize::Landscape,
        GenerationQuality::High,
        2,
    );

    // Min: 85 text, 1530 image, 6450 output, 1000 reasoning tokens.
    expect($estimate)->toBe(['min' => 357_781_250, 'max' => 467_343_750]);
});

test('the estimate uses the output tokens for the chosen size and quality', function (GenerationSize $size, GenerationQuality $quality, int $outputTokens) {
    $pricing = new Pricing(pricingCatalog());
    $estimate = $pricing->estimateCharge('', $size, $quality, 0);
    $reasoning = pricingCatalog()['tokens']['reasoning'][$quality->value];

    expect($estimate['min'])->toBe($pricing->chargeFor($outputTokens * 40_000 + $reasoning['min'] * 12_500));
})->with([
    'low square' => [GenerationSize::Square, GenerationQuality::Low, 280],
    'medium portrait' => [GenerationSize::Portrait, GenerationQuality::Medium, 1650],
    'high landscape' => [GenerationSize::Landscape, GenerationQuality::High, 6450],
]);

test('the pricing catalog resolver is not called until an estimate is needed', function () {
    $pricing = new Pricing(fn () => throw new RuntimeException('should not load'));

    expect($pricing->chargeFor(100))->toBe(125);
});

test('decimal amounts convert to nano-dollars without float drift', function (string $amount, int $nanos) {
    expect(Pricing::decimalToNanos($amount))->toBe($nanos);
})->with([
    ['12.50', 12_500_000_000],
    ['0.373875000', 373_875_000],
    ['0.1', 100_000_000],
    ['3', 3_000_000_000],
]);

test('it rejects amounts that are not plain decimals', function () {
    Pricing::decimalToNanos('1e-3');
})->throws(InvalidArgumentException::class);

test('nano-dollars format with half-up rounding', function () {
    expect(Pricing::formatNanos(467_343_750))->toBe('$0.4673')
        ->and(Pricing::formatNanos(467_350_000))->toBe('$0.4674')
        ->and(Pricing::formatNanos(373_875_000, 9))->toBe('$0.373875000')
        ->and(Pricing::formatNanos(0))->toBe('$0.0000');
});
