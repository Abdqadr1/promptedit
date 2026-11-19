<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\Pricing;
use App\Services\Pixelforge\WebhookSignature;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(Pricing::class, fn (): Pricing => new Pricing(
            fn (): array => $this->pixelforgePricingCatalog(),
            (int) config('services.pixelforge.markup_percent'),
        ));

        $this->app->bind(WebhookSignature::class, fn (): WebhookSignature => new WebhookSignature(
            (string) config('services.pixelforge.webhook_secret'),
            (int) config('services.pixelforge.webhook_tolerance'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configurePixelforge();

        Gate::define('viewAdminReport', fn (User $user): bool => $user->is_admin);

        DevCommands::artisan('schedule:work', 'scheduler');
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Register the Http::pixelforge() client macro.
     */
    protected function configurePixelforge(): void
    {
        Http::macro('pixelforge', fn (): PendingRequest => Http::baseUrl((string) config('services.pixelforge.base_url'))
            ->withToken((string) config('services.pixelforge.api_key'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(15));
    }

    /**
     * Live price sheet, cached for a day, falling back to the bundled copy if Pixelforge is unreachable.
     *
     * @return array{rates: array<string, string>, tokens: array<string, mixed>}
     */
    protected function pixelforgePricingCatalog(): array
    {
        try {
            return Cache::remember('pixelforge.pricing', now()->addDay(), fn (): array => app(PixelforgeClient::class)->pricing());
        } catch (Throwable $exception) {
            Log::warning('Using fallback Pixelforge pricing.', ['error' => $exception->getMessage()]);

            /** @var array{rates: array<string, string>, tokens: array<string, mixed>} */
            return config('services.pixelforge.fallback_pricing');
        }
    }
}
