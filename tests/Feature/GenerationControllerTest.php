<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['services.pixelforge.base_url' => 'https://pixelforge.test/v1']);
    Http::preventStrayRequests();
    Http::fake([
        'pixelforge.test/v1/pricing' => Http::response(config('services.pixelforge.fallback_pricing')),
    ]);
});

describe('index', function () {
    it('redirects guests to the login page', function () {
        $this->get(route('generations.index'))->assertRedirect(route('login'));
    });

    it('shows only the signed-in user\'s generations', function () {
        $user = User::factory()->create();
        $own = Generation::factory()->for($user)->succeeded()->create();
        Generation::factory()->succeeded()->create();

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('generations/Index')
                ->has('generations.data', 1)
                ->where('generations.data.0.id', $own->id)
                ->where('generations.data.0.charge', '$0.0875')
                ->where('pricing.markup_percent', 25));
    });

    it('hides an expired image link when no stored copy exists', function () {
        $user = User::factory()->create();
        Generation::factory()->for($user)->succeeded()->create(['image_url_expires_at' => now()->subMinute()]);

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('generations.data.0.image_src', null)
                ->where('generations.data.0.image_expired', true));
    });
});

describe('show', function () {
    it('redirects guests to the login page', function () {
        $generation = Generation::factory()->create();

        $this->get(route('generations.show', $generation))->assertRedirect(route('login'));
    });

    it('shows the generation with its usage and charge to its owner', function () {
        $user = User::factory()->create();
        $generation = Generation::factory()->for($user)->succeeded(70_000_000)->create([
            'usage' => ['input_text_tokens' => 5, 'input_image_tokens' => 0, 'output_tokens' => 6450, 'reasoning_tokens' => 4095, 'total_tokens' => 10550],
        ]);

        $this->actingAs($user)
            ->get(route('generations.show', $generation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('generations/Show')
                ->where('generation.id', $generation->id)
                ->where('generation.provider_id', $generation->provider_id)
                ->where('generation.usage.total_tokens', 10550)
                ->where('generation.charge', '$0.0875')
                ->missing('generation.provider_cost'));
    });

    it('shows the provider cost only to admins', function () {
        $admin = User::factory()->admin()->create();
        $generation = Generation::factory()->for($admin)->succeeded(70_000_000)->create();

        $this->actingAs($admin)
            ->get(route('generations.show', $generation))
            ->assertInertia(fn (Assert $page) => $page
                ->where('generation.provider_cost', '$0.070000000'));
    });

    it('returns 404 for another user\'s generation', function () {
        $generation = Generation::factory()->succeeded()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('generations.show', $generation))
            ->assertNotFound();
    });
});

describe('store', function () {
    it('records the estimate and queues the generation for submission', function () {
        Queue::fake([SubmitGeneration::class]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('generations.store'), [
                'prompt' => str_repeat('a', 400),
                'size' => '1536x1024',
                'quality' => 'high',
                'reference_images' => ['https://example.test/a.png', '', 'https://example.test/b.png'],
            ])
            ->assertRedirect(route('generations.index'));

        $generation = $user->generations()->sole();
        expect($generation->status)->toBe(GenerationStatus::Pending)
            ->and($generation->reference_images)->toBe(['https://example.test/a.png', 'https://example.test/b.png'])
            ->and($generation->estimated_min_charge_nanos)->toBe(357_781_250)
            ->and($generation->estimated_max_charge_nanos)->toBe(467_343_750);
        Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generation->is($generation));
    });

    it('rejects invalid input', function (array $input, string $field, string $message) {
        Queue::fake([SubmitGeneration::class]);

        $this->actingAs(User::factory()->create())
            ->post(route('generations.store'), [
                'prompt' => 'A lighthouse',
                'size' => '1024x1024',
                'quality' => 'medium',
                ...$input,
            ])
            ->assertSessionHasErrors([$field => $message]);

        Queue::assertNothingPushed();
    })->with([
        'missing prompt' => [['prompt' => ''], 'prompt', 'The prompt field is required.'],
        'prompt too long' => [['prompt' => str_repeat('a', 4001)], 'prompt', 'The prompt field must not be greater than 4000 characters.'],
        'unknown size' => [['size' => '512x512'], 'size', 'The selected size is invalid.'],
        'unknown quality' => [['quality' => 'ultra'], 'quality', 'The selected quality is invalid.'],
        'too many references' => [['reference_images' => array_fill(0, 5, 'https://example.test/a.png')], 'reference_images', 'You can add up to 4 reference images.'],
        'non-http reference' => [['reference_images' => ['ftp://example.test/a.png']], 'reference_images.0', 'Each reference image must be an http(s) URL.'],
    ]);
});
