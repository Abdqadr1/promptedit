<script setup lang="ts">
import { Head, Link, useForm, usePoll } from '@inertiajs/vue3';
import { ImageOff, Plus, X } from '@lucide/vue';
import { computed, reactive, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { estimateCharge, formatNanos } from '@/lib/pricing';
import type {
    Generation,
    GenerationStatus,
    Paginated,
    PricingCatalog,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Generate', href: '/generations' }],
    },
});

const props = defineProps<{
    generations: Paginated<Generation>;
    pricing: PricingCatalog;
    sizes: string[];
    qualities: string[];
}>();

const MAX_REFERENCES = 4;

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
});

const estimate = computed(() => {
    const references = form.reference_images.filter(
        (url) => url.trim() !== '',
    ).length;
    const { min, max } = estimateCharge(
        props.pricing,
        form.prompt,
        form.size,
        form.quality,
        references,
    );

    return { min: formatNanos(min), max: formatNanos(max) };
});

function addReference(): void {
    if (form.reference_images.length < MAX_REFERENCES) {
        form.reference_images.push('');
    }
}

function removeReference(index: number): void {
    form.reference_images.splice(index, 1);
}

function submit(): void {
    form.post('/generations', {
        preserveScroll: true,
        onSuccess: () => form.reset('prompt', 'reference_images'),
    });
}

function referenceError(index: number): string | undefined {
    return (form.errors as Record<string, string | undefined>)[
        `reference_images.${index}`
    ];
}

const hasActiveGenerations = computed(() =>
    props.generations.data.some((generation) => !generation.is_terminal),
);

const { start, stop } = usePoll(
    3000,
    { only: ['generations'] },
    { autoStart: false },
);

watch(
    hasActiveGenerations,
    (active) => (active ? start() : stop()),
    { immediate: true },
);

const statusLabels: Record<GenerationStatus, string> = {
    pending: 'Submitting',
    queued: 'Queued',
    processing: 'Processing',
    succeeded: 'Succeeded',
    failed: 'Failed',
};

function statusVariant(
    status: GenerationStatus,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (status === 'succeeded') {
        return 'default';
    }

    return status === 'failed' ? 'destructive' : 'secondary';
}

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '';
}

const brokenImages = reactive(new Set<string>());
</script>

<template>
    <Head title="Generate" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Card>
            <CardHeader>
                <CardTitle>New image</CardTitle>
                <CardDescription>
                    Priced per token by Pixelforge plus
                    {{ pricing.markup_percent }}%. You only pay for images
                    that succeed.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form class="grid gap-5" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="prompt">Prompt</Label>
                        <Textarea
                            id="prompt"
                            v-model="form.prompt"
                            maxlength="4000"
                            rows="3"
                            placeholder="A lighthouse on a cliff at dusk, oil painting"
                            :aria-invalid="!!form.errors.prompt"
                        />
                        <div
                            class="flex justify-between text-xs text-muted-foreground"
                        >
                            <span>
                                Test keywords: [fail], [slow], [duplicate],
                                [succeed], [blocked], [overloaded]
                            </span>
                            <span>{{ form.prompt.length }} / 4000</span>
                        </div>
                        <InputError :message="form.errors.prompt" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="size">Size</Label>
                            <Select v-model="form.size">
                                <SelectTrigger id="size" class="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="size in sizes"
                                        :key="size"
                                        :value="size"
                                    >
                                        {{ size }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="form.errors.size" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="quality">Quality</Label>
                            <Select v-model="form.quality">
                                <SelectTrigger id="quality" class="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="quality in qualities"
                                        :key="quality"
                                        :value="quality"
                                        class="capitalize"
                                    >
                                        {{ quality }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="form.errors.quality" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <div class="flex items-center justify-between">
                            <Label>Reference images (optional)</Label>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                :disabled="
                                    form.reference_images.length >=
                                    MAX_REFERENCES
                                "
                                @click="addReference"
                            >
                                <Plus /> Add URL
                            </Button>
                        </div>
                        <div
                            v-for="(url, index) in form.reference_images"
                            :key="`ref-${index}`"
                            class="grid gap-1"
                        >
                            <div class="flex gap-2">
                                <Input
                                    v-model="form.reference_images[index]"
                                    type="url"
                                    placeholder="https://example.com/reference.png"
                                    :aria-invalid="!!referenceError(index)"
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Remove reference ${index + 1}`"
                                    @click="removeReference(index)"
                                >
                                    <X />
                                </Button>
                            </div>
                            <InputError :message="referenceError(index)" />
                        </div>
                        <InputError :message="form.errors.reference_images" />
                    </div>

                    <div
                        class="flex flex-col gap-3 rounded-lg border bg-muted/40 p-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div>
                            <div class="text-sm text-muted-foreground">
                                Estimated cost
                            </div>
                            <div class="text-lg font-semibold tabular-nums">
                                {{ estimate.min }} – {{ estimate.max }}
                            </div>
                            <div class="text-xs text-muted-foreground">
                                Final price is set by the actual tokens used.
                                Failed generations are free.
                            </div>
                        </div>
                        <Button
                            type="submit"
                            :disabled="form.processing || !form.prompt.trim()"
                        >
                            <Spinner v-if="form.processing" />
                            Generate
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>

        <section class="grid gap-4">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold">History</h2>
                <span class="text-sm text-muted-foreground">
                    {{ generations.meta.total }} total
                </span>
            </div>

            <p
                v-if="generations.data.length === 0"
                class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                No generations yet. Your images will appear here.
            </p>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <Card
                    v-for="generation in generations.data"
                    :key="generation.id"
                    class="gap-0 overflow-hidden py-0"
                >
                    <div
                        class="flex aspect-square items-center justify-center bg-muted"
                    >
                        <img
                            v-if="
                                generation.image_src &&
                                !brokenImages.has(generation.id)
                            "
                            :src="generation.image_src"
                            :alt="generation.prompt"
                            class="h-full w-full object-cover"
                            loading="lazy"
                            @error="brokenImages.add(generation.id)"
                        />
                        <div
                            v-else-if="!generation.is_terminal"
                            class="flex flex-col items-center gap-2 text-sm text-muted-foreground"
                        >
                            <Spinner class="size-6" />
                            {{ statusLabels[generation.status] }}…
                        </div>
                        <div
                            v-else-if="generation.status === 'succeeded'"
                            class="flex flex-col items-center gap-2 p-4 text-center text-sm text-muted-foreground"
                        >
                            <ImageOff class="size-6" />
                            Image link expired and no stored copy is
                            available.
                        </div>
                        <div
                            v-else
                            class="flex flex-col items-center gap-2 p-4 text-center text-sm text-muted-foreground"
                        >
                            <ImageOff class="size-6" />
                            {{ generation.error_message ?? 'Generation failed' }}
                        </div>
                    </div>

                    <div class="grid gap-3 p-4">
                        <div class="flex items-center justify-between gap-2">
                            <Badge :variant="statusVariant(generation.status)">
                                <Spinner
                                    v-if="!generation.is_terminal"
                                    class="size-3"
                                />
                                {{ statusLabels[generation.status] }}
                            </Badge>
                            <span class="text-xs text-muted-foreground">
                                {{ generation.size }} ·
                                <span class="capitalize">{{
                                    generation.quality
                                }}</span>
                                <template
                                    v-if="generation.reference_images.length"
                                >
                                    ·
                                    {{ generation.reference_images.length }}
                                    ref
                                </template>
                            </span>
                        </div>

                        <p
                            class="line-clamp-2 text-sm"
                            :title="generation.prompt"
                        >
                            {{ generation.prompt }}
                        </p>

                        <dl class="grid grid-cols-2 gap-2 text-xs">
                            <div>
                                <dt class="text-muted-foreground">Estimated</dt>
                                <dd class="tabular-nums">
                                    {{ generation.estimated_min_charge }} –
                                    {{ generation.estimated_max_charge }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Charged</dt>
                                <dd
                                    class="font-medium tabular-nums"
                                    :title="generation.charge_exact ?? undefined"
                                >
                                    <template v-if="generation.charge !== null">
                                        {{ generation.charge }}
                                    </template>
                                    <span
                                        v-else
                                        class="text-muted-foreground"
                                    >
                                        Pending
                                    </span>
                                </dd>
                            </div>
                        </dl>

                        <p
                            v-if="
                                generation.status === 'failed' &&
                                generation.error_code
                            "
                            class="text-xs text-destructive"
                        >
                            {{ generation.error_code }} — not charged
                        </p>

                        <p class="text-xs text-muted-foreground">
                            {{ formatDate(generation.created_at) }}
                        </p>
                    </div>
                </Card>
            </div>

            <div
                v-if="generations.meta.last_page > 1"
                class="flex items-center justify-between"
            >
                <Button
                    v-if="generations.links.prev"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="generations.links.prev" preserve-scroll>
                        Newer
                    </Link>
                </Button>
                <span v-else />
                <span class="text-sm text-muted-foreground">
                    Page {{ generations.meta.current_page }} of
                    {{ generations.meta.last_page }}
                </span>
                <Button
                    v-if="generations.links.next"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="generations.links.next" preserve-scroll>
                        Older
                    </Link>
                </Button>
                <span v-else />
            </div>
        </section>
    </div>
</template>
