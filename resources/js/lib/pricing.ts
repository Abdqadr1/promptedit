import type { PricingCatalog } from '@/types';

const NANOS_PER_DOLLAR = 1_000_000_000;
const TOKENS_PER_RATE_UNIT = 1_000_000;

/**
 * Mirrors App\Services\Pixelforge\Pricing so the form shows the same estimate the server
 * stores. Integer nano-dollars throughout; values stay well inside Number.MAX_SAFE_INTEGER.
 */
export function decimalToNanos(amount: string): number {
    const [whole, fraction = ''] = amount.trim().split('.');

    return (
        Number(whole) * NANOS_PER_DOLLAR +
        Number(fraction.padEnd(9, '0').slice(0, 9))
    );
}

function divideRoundingUp(numerator: number, denominator: number): number {
    return Math.floor((numerator + denominator - 1) / denominator);
}

function providerCostNanos(
    usage: Record<string, number>,
    rates: Record<string, string>,
): number {
    const numerator = Object.entries(usage).reduce(
        (sum, [field, tokens]) =>
            sum + tokens * decimalToNanos(rates[field] ?? '0'),
        0,
    );

    return divideRoundingUp(numerator, TOKENS_PER_RATE_UNIT);
}

export function estimateCharge(
    catalog: PricingCatalog,
    prompt: string,
    size: string,
    quality: string,
    referenceImageCount: number,
): { min: number; max: number } {
    const { tokens, rates, markup_percent: markup } = catalog;
    const baseText = Math.ceil(
        Array.from(prompt).length / tokens.characters_per_text_token,
    );
    const variance = Math.round(tokens.text_token_variance * 10_000);
    const exact = {
        input_image_tokens: referenceImageCount * tokens.per_reference_image,
        output_tokens: tokens.output[quality]?.[size] ?? 0,
    };
    const charge = (cost: number) =>
        divideRoundingUp(cost * (100 + markup), 100);

    return {
        min: charge(
            providerCostNanos(
                {
                    ...exact,
                    input_text_tokens: Math.floor(
                        (baseText * (10_000 - variance)) / 10_000,
                    ),
                    reasoning_tokens: tokens.reasoning[quality]?.min ?? 0,
                },
                rates,
            ),
        ),
        max: charge(
            providerCostNanos(
                {
                    ...exact,
                    input_text_tokens: divideRoundingUp(
                        baseText * (10_000 + variance),
                        10_000,
                    ),
                    reasoning_tokens: tokens.reasoning[quality]?.max ?? 0,
                },
                rates,
            ),
        ),
    };
}

/**
 * Formats nano-dollars as dollars with the given number of decimals (half-up rounding).
 */
export function formatNanos(nanos: number, decimals = 4): string {
    const scale = 10 ** (9 - decimals);
    const scaled = Math.floor((nanos + Math.floor(scale / 2)) / scale);
    const whole = Math.floor(scaled / 10 ** decimals);
    const fraction = String(scaled % 10 ** decimals).padStart(decimals, '0');

    return '$' + whole + (decimals > 0 ? '.' + fraction : '');
}
