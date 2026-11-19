<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CircleX, CircleCheck, Clock, RefreshCw } from '@lucide/vue';
import { reactive, ref } from 'vue';
import PaginationControls from '@/components/PaginationControls.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Report', href: '/admin/report' }],
    },
});

type Totals = {
    succeeded: number;
    failed: number;
    charged: string;
    provider_cost: string;
    profit: string;
};

type DayRow = Totals & {
    date: string;
    provider_reported_cost: string | null;
    provider_synced_at: string | null;
    provider_stale: boolean;
    matches_provider: boolean | null;
};

type UserRow = Totals & {
    user_id: number;
    name: string | null;
    email: string | null;
};

type Paginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

type ReportTotals = Totals & {
    provider_reported_cost: string;
    matches_provider: boolean | null;
    unsynced_days: number;
    stale_days: number;
};

const props = defineProps<{
    filters: { start_date: string; end_date: string };
    days: Paginator<DayRow>;
    users: Paginator<UserRow>;
    totals: ReportTotals;
}>();

const today = new Date().toISOString().slice(0, 10);
const syncing = ref(false);

const filters = reactive({ ...props.filters });

function applyFilters(): void {
    router.get('/admin/report', filters, {
        preserveScroll: true,
        preserveState: true,
    });
}

function formatTime(value: string | null): string {
    return value
        ? new Date(value).toLocaleString(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : '';
}

function syncFromPixelforge(): void {
    router.post('/admin/report/sync', props.filters, {
        preserveScroll: true,
        onStart: () => (syncing.value = true),
        onFinish: () => (syncing.value = false),
    });
}
</script>

<template>
    <Head title="Report" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <form
            class="flex flex-wrap items-end gap-4"
            @submit.prevent="applyFilters"
        >
            <div class="grid gap-2">
                <Label for="start_date">From (UTC)</Label>
                <Input
                    id="start_date"
                    v-model="filters.start_date"
                    type="date"
                    :max="today"
                />
            </div>
            <div class="grid gap-2">
                <Label for="end_date">To (UTC)</Label>
                <Input
                    id="end_date"
                    v-model="filters.end_date"
                    type="date"
                    :max="today"
                />
            </div>
            <Button type="submit">Apply</Button>
            <p class="text-xs text-muted-foreground">
                Any range. Generations count on the UTC day they completed.
            </p>
        </form>

        <div
            v-if="totals.unsynced_days > 0 || totals.stale_days > 0"
            class="flex flex-col gap-3 rounded-lg border border-amber-500/40 bg-amber-500/5 p-4 text-sm sm:flex-row sm:items-center sm:justify-between"
        >
            <p>
                <template v-if="totals.unsynced_days > 0">
                    {{ totals.unsynced_days }}
                    {{ totals.unsynced_days === 1 ? 'day' : 'days' }} in this
                    range haven't been synced from Pixelforge yet.
                </template>
                <template v-if="totals.stale_days > 0">
                    {{ totals.stale_days }}
                    {{ totals.stale_days === 1 ? 'day has' : 'days have' }}
                    generations that finished after the last sync.
                </template>
                Those days are not checked against Pixelforge until the next
                sync (every 10 minutes).
            </p>
            <Button
                variant="outline"
                size="sm"
                :disabled="syncing"
                @click="syncFromPixelforge"
            >
                <RefreshCw :class="{ 'animate-spin': syncing }" />
                Sync from Pixelforge
            </Button>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <Card>
                <CardHeader>
                    <CardDescription>Credits spent</CardDescription>
                    <CardTitle class="text-2xl tabular-nums">
                        {{ totals.charged }}
                    </CardTitle>
                </CardHeader>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>Provider cost</CardDescription>
                    <CardTitle class="text-2xl tabular-nums">
                        {{ totals.provider_cost }}
                    </CardTitle>
                </CardHeader>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>Pixelforge reports</CardDescription>
                    <CardTitle
                        class="inline-flex items-center gap-1.5 text-2xl tabular-nums"
                        :class="{
                            'text-destructive':
                                totals.matches_provider === false,
                        }"
                    >
                        {{ totals.provider_reported_cost }}
                        <CircleCheck
                            v-if="totals.matches_provider === true"
                            class="size-4 text-green-600"
                        />
                        <CircleX
                            v-else-if="totals.matches_provider === false"
                            class="size-4"
                        />
                    </CardTitle>
                </CardHeader>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>Profit</CardDescription>
                    <CardTitle class="text-2xl tabular-nums">
                        {{ totals.profit }}
                    </CardTitle>
                </CardHeader>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>Succeeded / failed</CardDescription>
                    <CardTitle class="text-2xl tabular-nums">
                        {{ totals.succeeded }} / {{ totals.failed }}
                    </CardTitle>
                </CardHeader>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Daily</CardTitle>
                <CardDescription>
                    {{ days.total }} days. Provider cost is checked against
                    Pixelforge's usage, synced every 10 minutes.
                </CardDescription>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-muted-foreground">
                        <tr class="border-b">
                            <th class="py-2 pr-4 font-medium">Date</th>
                            <th class="py-2 pr-4 text-right font-medium">
                                Succeeded
                            </th>
                            <th class="py-2 pr-4 text-right font-medium">
                                Failed
                            </th>
                            <th class="py-2 pr-4 text-right font-medium">
                                Charged
                            </th>
                            <th class="py-2 pr-4 text-right font-medium">
                                Provider cost
                            </th>
                            <th class="py-2 pr-4 text-right font-medium">
                                Pixelforge reports
                            </th>
                            <th class="py-2 text-right font-medium">Profit</th>
                        </tr>
                    </thead>
                    <tbody class="tabular-nums">
                        <tr
                            v-for="day in days.data"
                            :key="day.date"
                            class="border-b last:border-0"
                        >
                            <td class="py-2 pr-4">{{ day.date }}</td>
                            <td class="py-2 pr-4 text-right">
                                {{ day.succeeded }}
                            </td>
                            <td class="py-2 pr-4 text-right">
                                {{ day.failed }}
                            </td>
                            <td class="py-2 pr-4 text-right">
                                {{ day.charged }}
                            </td>
                            <td class="py-2 pr-4 text-right">
                                {{ day.provider_cost }}
                            </td>
                            <td class="py-2 pr-4 text-right">
                                <span
                                    v-if="day.provider_stale"
                                    class="inline-flex items-center gap-1 text-muted-foreground"
                                    :title="`Synced ${formatTime(day.provider_synced_at)}; generations finished since then`"
                                >
                                    {{ day.provider_reported_cost }}
                                    <Clock class="size-3.5" />
                                    <span class="text-xs">pending sync</span>
                                </span>
                                <span
                                    v-else-if="
                                        day.provider_reported_cost !== null
                                    "
                                    class="inline-flex items-center gap-1"
                                    :class="
                                        day.matches_provider
                                            ? ''
                                            : 'text-destructive'
                                    "
                                    :title="
                                        day.matches_provider
                                            ? 'Matches Pixelforge'
                                            : 'Differs from our records'
                                    "
                                >
                                    {{ day.provider_reported_cost }}
                                    <CircleCheck
                                        v-if="day.matches_provider"
                                        class="size-3.5 text-green-600"
                                    />
                                    <CircleX v-else class="size-3.5" />
                                </span>
                                <span v-else class="text-muted-foreground">
                                    Not synced
                                </span>
                            </td>
                            <td class="py-2 text-right">{{ day.profit }}</td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
            <CardFooter>
                <PaginationControls
                    class="w-full"
                    :prev-url="days.prev_page_url"
                    :next-url="days.next_page_url"
                    :current-page="days.current_page"
                    :last-page="days.last_page"
                    :only="['days']"
                />
            </CardFooter>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Per user</CardTitle>
                <CardDescription>
                    {{ users.total }} users with completed generations.
                </CardDescription>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <p
                    v-if="users.total === 0"
                    class="text-sm text-muted-foreground"
                >
                    No completed generations in this range.
                </p>
                <table v-else class="w-full text-sm">
                    <thead class="text-left text-muted-foreground">
                        <tr class="border-b">
                            <th class="py-2 pr-4 font-medium">User</th>
                            <th class="py-2 pr-4 text-right font-medium">
                                Succeeded
                            </th>
                            <th class="py-2 pr-4 text-right font-medium">
                                Failed
                            </th>
                            <th class="py-2 pr-4 text-right font-medium">
                                Charged
                            </th>
                            <th class="py-2 pr-4 text-right font-medium">
                                Provider cost
                            </th>
                            <th class="py-2 text-right font-medium">Profit</th>
                        </tr>
                    </thead>
                    <tbody class="tabular-nums">
                        <tr
                            v-for="user in users.data"
                            :key="user.user_id"
                            class="border-b last:border-0"
                        >
                            <td class="py-2 pr-4">
                                <div>{{ user.name ?? 'Deleted user' }}</div>
                                <div class="text-xs text-muted-foreground">
                                    {{ user.email }}
                                </div>
                            </td>
                            <td class="py-2 pr-4 text-right">
                                {{ user.succeeded }}
                            </td>
                            <td class="py-2 pr-4 text-right">
                                {{ user.failed }}
                            </td>
                            <td class="py-2 pr-4 text-right">
                                {{ user.charged }}
                            </td>
                            <td class="py-2 pr-4 text-right">
                                {{ user.provider_cost }}
                            </td>
                            <td class="py-2 text-right">{{ user.profit }}</td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
            <CardFooter>
                <PaginationControls
                    class="w-full"
                    :prev-url="users.prev_page_url"
                    :next-url="users.next_page_url"
                    :current-page="users.current_page"
                    :last-page="users.last_page"
                    :only="['users']"
                />
            </CardFooter>
        </Card>
    </div>
</template>
