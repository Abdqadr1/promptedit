<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { Button } from '@/components/ui/button';

withDefaults(
    defineProps<{
        prevUrl: string | null;
        nextUrl: string | null;
        currentPage: number;
        lastPage: number;
        only?: string[];
        prevLabel?: string;
        nextLabel?: string;
    }>(),
    {
        only: () => [],
        prevLabel: 'Previous',
        nextLabel: 'Next',
    },
);
</script>

<template>
    <nav
        v-if="lastPage > 1"
        class="flex items-center justify-between gap-2"
        aria-label="Pagination"
    >
        <Button v-if="prevUrl" variant="outline" size="sm" as-child>
            <Link :href="prevUrl" :only="only" preserve-scroll preserve-state>
                <ChevronLeft />
                {{ prevLabel }}
            </Link>
        </Button>
        <span v-else />

        <span class="text-sm text-muted-foreground">
            Page {{ currentPage }} of {{ lastPage }}
        </span>

        <Button v-if="nextUrl" variant="outline" size="sm" as-child>
            <Link :href="nextUrl" :only="only" preserve-scroll preserve-state>
                {{ nextLabel }}
                <ChevronRight />
            </Link>
        </Button>
        <span v-else />
    </nav>
</template>
