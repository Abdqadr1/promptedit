<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck } from '@lucide/vue';
import { reactive } from 'vue';
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
    matches_provider: boolean | null;
};

type UserRow = Totals & {
    user_id: number;
    name: string | null;
    email: string | null;
};

const props = defineProps<{
    filters: { start_date: string; end_date: string };
    days: DayRow[];
    users: UserRow[];
    totals: Totals;
    provider_error: string | null;
}>();

const filters = reactive({ ...props.filters });

function applyFilters(): void {
    router.get('/admin/report', filters, {
        preserveScroll: true,
        preserveState: true,
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
                />
            </div>
            <div class="grid gap-2">
                <Label for="end_date">To (UTC)</Label>
                <Input id="end_date" v-model="filters.end_date" type="date" />
            </div>
            <Button type="submit">Apply</Button>
            <p class="text-xs text-muted-foreground">
                Up to 92 days. Generations count on the UTC day they
                completed.
            </p>
        </form>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
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
                    Provider cost is checked against Pixelforge's
                    <code>GET /v1/usage</code>.
                    <span v-if="provider_error" class="text-destructive">
                        {{ provider_error }}
                    </span>
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
                            v-for="day in days"
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
                                    v-if="day.provider_reported_cost !== null"
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
                                    <CircleAlert v-else class="size-3.5" />
                                </span>
                                <span v-else class="text-muted-foreground">
                                    —
                                </span>
                            </td>
                            <td class="py-2 text-right">{{ day.profit }}</td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Per user</CardTitle>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <p
                    v-if="users.length === 0"
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
                            v-for="user in users"
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
        </Card>
    </div>
</template>
