<script setup lang="ts">
import { Head, setLayoutProps, usePoll } from '@inertiajs/vue3';
import { Check, Copy, ImageOff } from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { Generation, GenerationStatus } from '@/types';

const props = defineProps<{
    generation: Generation;
}>();

const displayId = computed(
    () => props.generation.provider_id ?? props.generation.id,
);

setLayoutProps({
    breadcrumbs: [
        { title: 'Generations', href: '/generations' },
        {
            title: displayId.value,
            href: `/generations/${props.generation.id}`,
        },
    ],
});

const { copy, copied } = useClipboard({ legacy: true });

const { start, stop } = usePoll(
    5000,
    { only: ['generation'] },
    { autoStart: false },
);

watch(
    () => props.generation.is_terminal,
    (terminal) => (terminal ? stop() : start()),
    { immediate: true },
);

const imageFailed = ref(false);

const aspectRatio = computed(() => {
    const [width, height] = props.generation.size.split('x').map(Number);

    return `${width} / ${height}`;
});

const statusLabels: Record<GenerationStatus, string> = {
    pending: 'Submitting',
    queued: 'Queued',
    processing: 'Processing',
    succeeded: 'Succeeded',
    failed: 'Failed',
};

function statusVariant(
    status: GenerationStatus,
): 'default' | 'secondary' | 'destructive' {
    if (status === 'succeeded') {
        return 'default';
    }

    return status === 'failed' ? 'destructive' : 'secondary';
}

const tokenRows = [
    { key: 'input_text_tokens', label: 'Input text tokens' },
    { key: 'input_image_tokens', label: 'Input image tokens' },
    { key: 'output_tokens', label: 'Output tokens' },
    { key: 'reasoning_tokens', label: 'Reasoning tokens' },
];

const totalTokens = computed(() => {
    const usage = props.generation.usage;

    if (!usage) {
        return 0;
    }

    return (
        usage.total_tokens ??
        tokenRows.reduce((sum, row) => sum + (usage[row.key] ?? 0), 0)
    );
});

function formatDate(value: string | null): string {
    return value
        ? new Date(value).toLocaleString(undefined, {
              dateStyle: 'medium',
              timeStyle: 'medium',
          })
        : '—';
}

function formatNumber(value: number | undefined): string {
    return (value ?? 0).toLocaleString();
}
</script>

<template>
    <Head :title="displayId" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="font-mono text-lg font-semibold break-all md:text-xl">
                {{ displayId }}
            </h1>
            <Button
                variant="ghost"
                size="icon-sm"
                :aria-label="copied ? 'Copied' : 'Copy id'"
                @click="copy(displayId)"
            >
                <Check v-if="copied" />
                <Copy v-else />
            </Button>
            <Badge :variant="statusVariant(generation.status)">
                <Spinner v-if="!generation.is_terminal" class="size-3" />
                {{ statusLabels[generation.status] }}
            </Badge>
        </div>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <div
                class="flex items-center justify-center self-start overflow-hidden rounded-xl bg-muted"
                :style="{ aspectRatio }"
            >
                <a
                    v-if="generation.image_src && !imageFailed"
                    :href="generation.image_src"
                    target="_blank"
                    rel="noopener"
                    class="h-full w-full"
                >
                    <img
                        :src="generation.image_src"
                        :alt="generation.prompt"
                        class="h-full w-full object-contain"
                        @error="imageFailed = true"
                    />
                </a>
                <div
                    v-else-if="!generation.is_terminal"
                    class="flex flex-col items-center gap-2 text-sm text-muted-foreground"
                >
                    <Spinner class="size-6" />
                    {{ statusLabels[generation.status] }}…
                </div>
                <div
                    v-else
                    class="flex max-w-sm flex-col items-center gap-2 p-6 text-center text-sm text-muted-foreground"
                >
                    <ImageOff class="size-6" />
                    <template v-if="generation.status === 'succeeded'">
                        The image link expired and no stored copy is available.
                    </template>
                    <template v-else>
                        {{ generation.error_message ?? 'Generation failed' }}
                    </template>
                </div>
            </div>

            <dl class="grid content-start gap-5 text-sm">
                <div class="grid gap-1">
                    <dt class="text-muted-foreground">Prompt</dt>
                    <dd class="whitespace-pre-wrap">{{ generation.prompt }}</dd>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-1">
                        <dt class="text-muted-foreground">Size</dt>
                        <dd>{{ generation.size }}</dd>
                    </div>
                    <div class="grid gap-1">
                        <dt class="text-muted-foreground">Quality</dt>
                        <dd class="capitalize">{{ generation.quality }}</dd>
                    </div>
                    <div class="grid gap-1">
                        <dt class="text-muted-foreground">Created</dt>
                        <dd>{{ formatDate(generation.created_at) }}</dd>
                    </div>
                    <div class="grid gap-1">
                        <dt class="text-muted-foreground">Completed</dt>
                        <dd>{{ formatDate(generation.completed_at) }}</dd>
                    </div>
                </div>

                <div
                    v-if="generation.reference_images.length"
                    class="grid gap-1"
                >
                    <dt class="text-muted-foreground">Reference images</dt>
                    <dd
                        v-for="url in generation.reference_images"
                        :key="url"
                        class="truncate"
                    >
                        <a
                            :href="url"
                            target="_blank"
                            rel="noopener"
                            class="underline underline-offset-4"
                            >{{ url }}</a
                        >
                    </dd>
                </div>

                <div
                    v-if="generation.status === 'failed'"
                    class="grid gap-1 rounded-lg border border-destructive/40 p-3"
                >
                    <dt class="text-destructive">
                        {{ generation.error_code }}
                    </dt>
                    <dd>
                        {{ generation.error_message }} You were not charged.
                    </dd>
                </div>

                <div class="grid gap-2">
                    <dt class="text-muted-foreground">Cost</dt>
                    <dd class="grid gap-0 divide-y tabular-nums">
                        <div class="flex justify-between py-2">
                            <span>Estimated</span>
                            <span>
                                {{ generation.estimated_min_charge }} –
                                {{ generation.estimated_max_charge }}
                            </span>
                        </div>
                        <div
                            v-if="generation.provider_cost !== undefined"
                            class="flex justify-between py-2"
                        >
                            <span>Provider cost (admin)</span>
                            <span>{{ generation.provider_cost }}</span>
                        </div>
                        <div class="flex justify-between py-2 font-semibold">
                            <span>Charged</span>
                            <span
                                v-if="generation.charge !== null"
                                :title="generation.charge_exact ?? undefined"
                            >
                                {{ generation.charge }}
                            </span>
                            <span
                                v-else
                                class="font-normal text-muted-foreground"
                            >
                                Pending
                            </span>
                        </div>
                    </dd>
                </div>

                <div v-if="generation.usage" class="grid gap-2">
                    <dt class="text-muted-foreground">Token usage</dt>
                    <dd class="grid gap-0 divide-y tabular-nums">
                        <div
                            v-for="row in tokenRows"
                            :key="row.key"
                            class="flex justify-between py-2"
                        >
                            <span>{{ row.label }}</span>
                            <span>{{
                                formatNumber(generation.usage[row.key])
                            }}</span>
                        </div>
                        <div class="flex justify-between py-2 font-semibold">
                            <span>Total</span>
                            <span>{{ formatNumber(totalTokens) }}</span>
                        </div>
                    </dd>
                </div>
            </dl>
        </div>
    </div>
</template>
