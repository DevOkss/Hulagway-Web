<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItemType } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { CheckCircle2, FileDown, FileSpreadsheet } from 'lucide-vue-next';
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';

interface Sdg { id: number; number: number; title: string; short_title: string; color: string; icon_url: string }
interface Activity {
    id: number;
    title: string;
    status: string;
    progress: number;
    start_date: string;
    end_date: string | null;
    beneficiaries: number;
    program_id?: number | null;
    created_by?: number | null;
    program: { id?: number; name: string } | null;
    barangay: { name: string } | null;
    sdgs?: Sdg[];
}

const props = defineProps<{
    activities: Activity[];
    programs: Array<{ id: number; name: string }>;
    years: number[];
    filters: { year?: string | null };
}>();

const exportUrl = (format: 'pdf' | 'xlsx') => {
    const params = new URLSearchParams();
    params.set('status', 'completed');
    if (props.filters?.year) params.set('year', props.filters.year);
    const qs = params.toString();
    return qs ? `/extensions/export/${format}?${qs}` : `/extensions/export/${format}`;
};
const onYearChange = (e: Event) => {
    const v = (e.target as HTMLSelectElement).value;
    router.get('/extensions/completed', v ? { year: v } : {}, { preserveState: true });
};

const breadcrumbs: BreadcrumbItemType[] = [
    { title: 'Extension Activities', href: '/extensions' },
    { title: 'Completed', href: '/extensions/completed' },
];

const page = usePage();
const isCoordinator = computed(() => (page.props.auth as any)?.user?.role === 'coordinator');
const isOfficer = computed(() => (page.props.auth as any)?.user?.role === 'officer');
const canExport = computed(() => isCoordinator.value || isOfficer.value);
const userProgramId = computed(() => (page.props.auth as any)?.user?.program_id ?? null);
const userId = computed(() => (page.props.auth as any)?.user?.id ?? null);
const canManage = (activity: Activity) => {
    if (!isCoordinator.value) return false;
    const isLeadProgram = userProgramId.value !== null && ((activity as any).program_id === userProgramId.value || (activity.program as any)?.id === userProgramId.value);
    const isCreator = userId.value !== null && (activity as any).created_by === userId.value;
    return isLeadProgram || isCreator;
};

const statusTint = (status: string) =>
    ({
        planned: 'bg-sky-500/10 text-sky-600',
        ongoing: 'bg-amber-500/10 text-amber-600',
        completed: 'bg-emerald-500/10 text-emerald-600',
        cancelled: 'bg-red-500/10 text-red-500',
    })[status] ?? '';

const fmt = (date: string | null) => (date ? new Date(date).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '—');
</script>

<template>
    <Head title="Completed Activities" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4 sm:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="font-heading flex items-center gap-2 text-xl font-semibold sm:text-2xl"><CheckCircle2 class="size-5 text-emerald-500" />Completed Activities</h1>
                    <p class="text-sm text-neutral-500">Activities that have reached their end date — progress reflects uploaded days.</p>
                </div>
            </div>

            <!-- Filter tabs -->
            <div class="inline-flex gap-1 rounded-xl bg-neutral-100 p-1 dark:bg-neutral-800">
                <Link
                    href="/extensions"
                    class="rounded-lg px-3 py-1.5 text-center text-xs font-medium transition sm:px-4 sm:text-sm"
                    :class="$page.url === '/extensions' ? 'bg-white shadow-sm text-neutral-900' : 'text-neutral-500 hover:text-neutral-700'"
                    >All</Link
                >
                <Link
                    href="/extensions/pending"
                    class="rounded-lg px-3 py-1.5 text-center text-xs font-medium transition sm:px-4 sm:text-sm"
                    :class="$page.url.startsWith('/extensions/pending') ? 'bg-white shadow-sm text-neutral-900' : 'text-neutral-500 hover:text-neutral-700'"
                    >Pending & Ongoing</Link
                >
                <Link
                    href="/extensions/completed"
                    class="rounded-lg px-3 py-1.5 text-center text-xs font-medium transition sm:px-4 sm:text-sm"
                    :class="$page.url.startsWith('/extensions/completed') ? 'bg-white shadow-sm text-neutral-900' : 'text-neutral-500 hover:text-neutral-700'"
                    >Completed</Link
                >
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-2">
                    <label class="text-xs font-medium text-neutral-600">Year:</label>
                    <select :value="props.filters?.year ?? ''" @change="onYearChange" class="rounded-lg border border-input bg-white px-3 py-1.5 text-sm">
                        <option value="">All years</option>
                        <option v-for="y in props.years" :key="y" :value="String(y)">{{ y }}</option>
                    </select>
                </div>
                <div v-if="canExport" class="ml-auto flex gap-2">
                    <Button variant="outline" size="sm" as-child class="gap-1.5"><a :href="exportUrl('pdf')"><FileDown class="size-4" /> PDF (completed)</a></Button>
                    <Button size="sm" as-child class="gap-1.5 bg-emerald-600 text-white"><a :href="exportUrl('xlsx')"><FileSpreadsheet class="size-4" /> Excel (completed)</a></Button>
                </div>
            </div>

            <div v-for="activity in activities" :key="activity.id" class="hcard-p space-y-3">
                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="font-heading truncate font-semibold">{{ activity.title }}</h2>
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize" :class="statusTint(activity.status)">
                                {{ activity.status }}
                            </span>
                        </div>
                        <p class="mt-0.5 text-sm text-neutral-500">{{ activity.program?.name ?? 'No program' }} · {{ activity.barangay?.name ?? 'City-wide' }}</p>
                        <div v-if="(activity.sdgs ?? []).length" class="mt-1.5 flex flex-wrap gap-1.5">
                            <span
                                v-for="s in activity.sdgs"
                                :key="s.id"
                                class="inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-[10px] font-semibold"
                                :style="{ borderColor: s.color, backgroundColor: s.color + '14', color: s.color }"
                                :title="s.title"
                            >
                                <img :src="s.icon_url" :alt="s.title" class="size-4 rounded-sm object-cover bg-white" />
                                SDG {{ s.number }}
                            </span>
                        </div>
                        <p class="mt-1 text-xs text-neutral-400">
                            {{ fmt(activity.start_date) }} – {{ fmt(activity.end_date) }} · {{ activity.beneficiaries.toLocaleString() }} beneficiaries
                        </p>
                    </div>
                    <Button variant="outline" size="sm" as-child class="shrink-0">
                        <Link :href="`/extensions/${activity.id}`">{{ canManage(activity) ? 'Manage' : 'View' }}</Link>
                    </Button>
                </div>
                <!-- Timeline grouped by card -->
                <div class="space-y-1.5">
                    <div class="flex items-center justify-between text-[11px]">
                        <span class="font-medium text-neutral-500">Timeline</span>
                        <span class="font-semibold text-emerald-600">{{ activity.progress }}% · <span class="capitalize">{{ activity.status }}</span></span>
                    </div>
                    <div class="relative h-2.5 w-full overflow-hidden rounded-full bg-neutral-100 dark:bg-neutral-800">
                        <div class="absolute left-0 top-0 h-full rounded-full bg-emerald-500 transition-all" :style="{ width: `${activity.progress}%` }" :title="`${activity.progress}% — ${activity.status}`" />
                    </div>
                    <div class="flex justify-between text-[10px] text-neutral-400">
                        <span>{{ fmt(activity.start_date) }}</span>
                        <span>{{ fmt(activity.end_date) }}</span>
                    </div>
                </div>
            </div>

            <div v-if="!activities.length" class="rounded-2xl border border-dashed border-emerald-300 bg-emerald-50 p-10 text-center text-sm text-emerald-600">
                No completed activities yet.
            </div>
        </div>
    </AppLayout>
</template>
