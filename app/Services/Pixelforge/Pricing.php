<?php

namespace App\Services\Pixelforge;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use Closure;
use InvalidArgumentException;

/**
 * Exact money math for Pixelforge generations.
 *
 * All amounts are integer nano-dollars (1e-9 USD), the same precision Pixelforge reports in
 * GET /v1/usage, so no floating point is ever involved in a charge.
 */
class Pricing
{
    public const int NANOS_PER_DOLLAR = 1_000_000_000;

    private const int TOKENS_PER_RATE_UNIT = 1_000_000;

    /**
     * Maps a usage token field to the rate field that prices it.
     *
     * @var array<string, string>
     */
    private const array TOKEN_RATE_FIELDS = [
        'input_text_tokens' => 'input_text_tokens',
        'input_image_tokens' => 'input_image_tokens',
        'output_tokens' => 'output_tokens',
        'reasoning_tokens' => 'reasoning_tokens',
    ];

    /**
     * @param  array{rates: array<string, string>, tokens: array<string, mixed>}|Closure(): array{rates: array<string, string>, tokens: array<string, mixed>}  $catalog
     *                                                                                                                                                                   Shape of GET /v1/pricing, or a resolver loaded only when an estimate is needed.
     */
    public function __construct(
        private array|Closure $catalog,
        private readonly int $markupPercent = 25,
    ) {}

    /**
     * @return array{rates: array<string, string>, tokens: array<string, mixed>}
     */
    public function catalog(): array
    {
        if ($this->catalog instanceof Closure) {
            $this->catalog = ($this->catalog)();
        }

        return $this->catalog;
    }

    public function markupPercent(): int
    {
        return $this->markupPercent;
    }

    /**
     * Exact provider cost of a finished generation, using the rates fixed on it at creation.
     *
     * @param  array<string, int>  $usage
     * @param  array<string, string>  $rates  Dollars per 1M tokens, as decimal strings.
     */
    public static function providerCostNanos(array $usage, array $rates): int
    {
        $numerator = 0;

        foreach (self::TOKEN_RATE_FIELDS as $tokenField => $rateField) {
            $tokens = (int) ($usage[$tokenField] ?? 0);

            if ($tokens === 0) {
                continue;
            }

            if (! isset($rates[$rateField])) {
                throw new InvalidArgumentException("Missing rate [{$rateField}].");
            }

            $numerator += $tokens * self::decimalToNanos($rates[$rateField]);
        }

        return self::divideRoundingUp($numerator, self::TOKENS_PER_RATE_UNIT);
    }

    /**
     * What the customer pays: provider cost plus markup, rounded up to the next nano-dollar.
     */
    public function chargeFor(int $providerCostNanos): int
    {
        return self::divideRoundingUp($providerCostNanos * (100 + $this->markupPercent), 100);
    }

    /**
     * Range of what a generation may cost the customer, before it runs.
     *
     * Text tokens are an estimate (±variance) and reasoning tokens vary by quality; output and
     * reference-image tokens are exact.
     *
     * @return array{min: int, max: int}
     */
    public function estimateCharge(string $prompt, GenerationSize $size, GenerationQuality $quality, int $referenceImageCount): array
    {
        ['tokens' => $tokens, 'rates' => $rates] = $this->catalog();

        $baseTextTokens = (int) ceil(mb_strlen($prompt) / (int) $tokens['characters_per_text_token']);
        $varianceBasisPoints = (int) round((float) $tokens['text_token_variance'] * 10_000);

        $exactTokens = [
            'input_image_tokens' => $referenceImageCount * (int) $tokens['per_reference_image'],
            'output_tokens' => (int) $tokens['output'][$quality->value][$size->value],
        ];

        $minUsage = $exactTokens + [
            'input_text_tokens' => intdiv($baseTextTokens * (10_000 - $varianceBasisPoints), 10_000),
            'reasoning_tokens' => (int) $tokens['reasoning'][$quality->value]['min'],
        ];

        $maxUsage = $exactTokens + [
            'input_text_tokens' => self::divideRoundingUp($baseTextTokens * (10_000 + $varianceBasisPoints), 10_000),
            'reasoning_tokens' => (int) $tokens['reasoning'][$quality->value]['max'],
        ];

        return [
            'min' => $this->chargeFor(self::providerCostNanos($minUsage, $rates)),
            'max' => $this->chargeFor(self::providerCostNanos($maxUsage, $rates)),
        ];
    }

    /**
     * Converts a non-negative decimal dollar string ("12.50") to integer nano-dollars without floats.
     */
    public static function decimalToNanos(string $amount): int
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,9}))?$/', trim($amount), $matches) !== 1) {
            throw new InvalidArgumentException("Invalid decimal amount [{$amount}].");
        }

        $fraction = str_pad($matches[2] ?? '', 9, '0');

        return (int) $matches[1] * self::NANOS_PER_DOLLAR + (int) $fraction;
    }

    /**
     * Formats nano-dollars as dollars with the given number of decimals (half-up rounding).
     */
    public static function formatNanos(int $nanos, int $decimals = 4): string
    {
        $scale = 10 ** (9 - $decimals);
        $scaled = intdiv($nanos + intdiv($scale, 2), $scale);
        $whole = intdiv($scaled, 10 ** $decimals);
        $fraction = str_pad((string) ($scaled % 10 ** $decimals), $decimals, '0', STR_PAD_LEFT);

        return '$'.$whole.($decimals > 0 ? '.'.$fraction : '');
    }

    private static function divideRoundingUp(int $numerator, int $denominator): int
    {
        return intdiv($numerator + $denominator - 1, $denominator);
    }
}
