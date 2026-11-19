<?php

namespace App\Http\Controllers;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Services\Pixelforge\Pricing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    /**
     * Show the generate form and the user's generation history.
     */
    public function index(Request $request, Pricing $pricing): Response
    {
        $generations = $request->user()
            ->generations()
            ->latest()
            ->latest('id')
            ->paginate(12);

        return Inertia::render('generations/Index', [
            'generations' => GenerationResource::collection($generations),
            'pricing' => Inertia::once(fn (): array => [
                ...$pricing->catalog(),
                'markup_percent' => $pricing->markupPercent(),
            ]),
            'sizes' => array_column(GenerationSize::cases(), 'value'),
            'qualities' => array_column(GenerationQuality::cases(), 'value'),
        ]);
    }

    /**
     * Show one of the user's generations. Other users' generations are reported as not found.
     */
    public function show(Request $request, string $generation): Response
    {
        $generation = $request->user()->generations()->findOrFail($generation);

        return Inertia::render('generations/Show', [
            'generation' => GenerationResource::make($generation)->resolve($request),
        ]);
    }

    /**
     * Record a generation with its estimated price and queue it for submission to Pixelforge.
     */
    public function store(StoreGenerationRequest $request, Pricing $pricing): RedirectResponse
    {
        $size = GenerationSize::from($request->string('size')->value());
        $quality = GenerationQuality::from($request->string('quality')->value());
        /** @var list<string> $references */
        $references = $request->validated('reference_images', []);
        $prompt = $request->string('prompt')->value();

        $estimate = $pricing->estimateCharge($prompt, $size, $quality, count($references));

        $generation = $request->user()->generations()->create([
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality,
            'reference_images' => $references,
            'status' => GenerationStatus::Pending,
            'estimated_min_charge_nanos' => $estimate['min'],
            'estimated_max_charge_nanos' => $estimate['max'],
        ]);

        SubmitGeneration::dispatch($generation);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Generation queued.')]);

        return to_route('generations.index');
    }
}
