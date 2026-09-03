<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItemType } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import { LoaderCircle } from 'lucide-vue-next';
import { Bar, Line, Pie } from 'vue-chartjs';

import {
    ArcElement,
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    Legend,
    LinearScale,
    LineElement,
    PointElement,
    Title,
    Tooltip,
} from 'chart.js';

ChartJS.register(
    Title,
    Tooltip,
    Legend,
    BarElement,
    CategoryScale,
    LinearScale,
    LineElement,
    PointElement,
    ArcElement,
);

interface SurveyQuestion {
    id: number;
    survey_id: number;
    question_text: string;
    type: string;
    code: string | null;
    data_scope: string;
    map_enabled: boolean;
    options?: Array<{ id: number; label: string }>;
}
interface Props {
    stats: Record<string, number>;
    hulagway: Record<string, number>;
    surveys: Array<{ id: number; title: string; questions?: SurveyQuestion[] }>;
    years: number[];
    charts: {
        byProgram: Record<string, number>;
        overTime: Record<string, number>;
        participants: { faculty: number; students: number; beneficiaries: number };
    };
    filters: { year: string | null };
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItemType[] = [
    { title: 'Dashboard', href: '/dashboard' },
];

const statCards = [
    { key: 'total_barangays', label: 'Barangays Mapped', tint: 'bg-brand-500/10 text-brand-600' },
    { key: 'total_activities', label: 'Extension Activities', tint: 'bg-sky-500/10 text-sky-600' },
    { key: 'active_activities', label: 'Ongoing Activities', tint: 'bg-emerald-500/10 text-emerald-600' },
    { key: 'completed_activities', label: 'Completed Activities', tint: 'bg-violet-500/10 text-violet-600' },
];

/* ── Hulagway survey-driven aggregation (mirrors Map/Community.vue) ── */
const latestSurveyId = computed(() => {
    if (!props.surveys.length) return '';
    return String([...props.surveys].sort((a: any, b: any) => Number(b.id) - Number(a.id))[0].id);
});
const selectedSurvey = ref<string>(latestSurveyId.value);
const selectedFields = ref<string[]>([]);
const availableFields = ref<Array<{ value: string; label: string; group: string }>>([]);
const aggLoading = ref(false);
const aggDetail = ref<any>(null);
const showAllDialog = ref(false);
const openAllIds = ref<number[]>([]);
const fieldTotals = ref<Record<string, number>>({});

const selectedSurveyTitle = computed(() => props.surveys.find((s) => String(s.id) === selectedSurvey.value)?.title ?? '');

const fetchFields = async () => {
    if (!selectedSurvey.value) {
        availableFields.value = [];
        selectedFields.value = [];
        fieldTotals.value = {};
        aggDetail.value = null;
        return;
    }
    const found = (props.surveys as any[]).find((s: any) => String(s.id) === String(selectedSurvey.value));
    const qs: SurveyQuestion[] = (found as any)?.questions ?? [];
    const fields: Array<{ value: string; label: string; group: string }> = [];
    const seen = new Set<string>();
    const extra: Array<{ value: string; label: string; group: string }> = [
        { value: 'field_poor_cat2', label: 'Poor Family', group: 'Household Conditions' },
        { value: 'field_pwd', label: 'PWD (Persons)', group: 'Per-Member' },
        { value: 'field_mentally', label: 'Mentally Challenged', group: 'Per-Member' },
        { value: 'field_bedridden', label: 'Bedridden', group: 'Per-Member' },
        { value: 'field_seniors_60', label: 'Senior 60+', group: 'Per-Member' },
        { value: 'field_seniors_90', label: 'Senior 90+', group: 'Per-Member' },
        { value: 'field_osy', label: 'OSY', group: 'Per-Member' },
        { value: 'field_pregnant', label: 'Pregnant', group: 'Per-Member' },
        { value: 'field_live_in', label: 'Live-in Households', group: 'Household Conditions' },
        { value: 'field_total_households', label: 'Total Households', group: 'Aggregate' },
    ];
    const reserved = new Set(extra.map((f) => f.value.replace('field_', '')));
    ['pwd_cat2', 'mentally_cat2', 'bedridden_cat2', 'seniors_90', 'total_households', 'live_in'].forEach((c) => reserved.add(c));
    qs.forEach((q) => {
        if ((q as any).map_enabled === false) return;
        if (seen.has(`q_${q.id}`)) return;
        if (q.code && reserved.has(q.code)) return;
        seen.add(`q_${q.id}`);
        const group = q.data_scope === 'individual' ? 'Per-Member' : q.data_scope === 'household' ? 'Household Conditions' : 'Other';
        const label = q.question_text + (q.code ? ` (${q.code})` : ` (${q.type})`);
        fields.push({ value: `q_${q.id}`, label, group });
    });
    extra.forEach((f) => {
        if (!seen.has(f.value)) {
            seen.add(f.value);
            fields.push(f);
        }
    });
    availableFields.value = fields;
    // default: check all conditions (as requested)
    selectedFields.value = fields.map((f) => f.value);
    void fetchFieldTotals();
    // fetchAggregation will be triggered by watcher on selectedFields
};

const fetchFieldTotals = async () => {
    if (!selectedSurvey.value) { fieldTotals.value = {}; return; }
    const params = new URLSearchParams();
    params.set('survey_id', selectedSurvey.value);
    availableFields.value.forEach((f) => {
        if (f.value.startsWith('q_')) params.append('question_ids[]', f.value.replace('q_', ''));
        else if (f.value.startsWith('field_')) params.append('field_codes[]', f.value.replace('field_', ''));
        else params.append('field_codes[]', f.value);
    });
    try {
        const res = await fetch(`/api/map/aggregation?${params.toString()}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!res.ok) return;
        const data = await res.json();
        const map: Record<string, number> = {};
        (data.barangays ?? []).forEach((b: any) => {
            (b.fields ?? []).forEach((f: any) => { map[f.key] = (map[f.key] ?? 0) + Number(f.total ?? 0); });
        });
        fieldTotals.value = map;
    } catch {}
};

const fetchAggregation = async () => {
    if (!selectedSurvey.value || !selectedFields.value.length) { aggDetail.value = null; return; }
    aggLoading.value = true;
    try {
        const params = new URLSearchParams();
        params.set('survey_id', selectedSurvey.value);
        selectedFields.value.forEach((v) => {
            if (v.startsWith('q_')) params.append('question_ids[]', v.replace('q_', ''));
            else if (v.startsWith('field_')) params.append('field_codes[]', v.replace('field_', ''));
            else params.append('field_codes[]', v);
        });
        const res = await fetch(`/api/map/aggregation?${params.toString()}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!res.ok) return;
        const data = await res.json();
        aggDetail.value = data;
    } catch {} finally { aggLoading.value = false; }
};

watch(selectedSurvey, () => { void fetchFields(); });
watch(selectedFields, () => { void fetchAggregation(); });

onMounted(() => {
    if (selectedSurvey.value) void fetchFields();
    // if latestSurveyId was empty at setup but surveys arrived later
    else if (latestSurveyId.value) {
        selectedSurvey.value = latestSurveyId.value;
    }
});
watch(latestSurveyId, (val) => {
    if (val && !selectedSurvey.value) selectedSurvey.value = val;
});

const aggTotals = computed(() => {
    const bars = aggDetail.value?.barangays ?? [];
    let households = 0; let poor = 0;
    bars.forEach((b: any) => {
        households += Number(b.households ?? 0);
        const poorField = (b.fields ?? []).find((f: any) => f.key === 'field_poor_cat2' || f.label === 'Poor Family');
        poor += Number(poorField?.total ?? 0);
    });
    // also sum per selected field totals for generic header
    const perField: Record<string, number> = {};
    bars.forEach((b: any) => {
        (b.fields ?? []).forEach((f: any) => { perField[f.key] = (perField[f.key] ?? 0) + Number(f.total ?? 0); });
    });
    return { households, poor, count: bars.length, perField };
});

const toggleOpenAll = (id: number) => {
    const idx = openAllIds.value.indexOf(id);
    if (idx >= 0) openAllIds.value.splice(idx, 1); else openAllIds.value.push(id);
};

const palette = ['#F97316', '#FBBF24', '#EA580C', '#FB923C', '#C2410C', '#FDBA74', '#FFEDD5'];

const programChartData = computed(() => ({
    labels: Object.keys(props.charts.byProgram),
    datasets: [
        {
            data: Object.values(props.charts.byProgram),
            backgroundColor: palette[0],
            borderRadius: 6,
            maxBarThickness: 42,
        },
    ],
}));

const timelineData = computed(() => ({
    labels: Object.keys(props.charts.overTime),
    datasets: [
        {
            label: 'Activities',
            data: Object.values(props.charts.overTime),
            borderColor: palette[0],
            backgroundColor: 'rgba(249, 115, 22, 0.15)',
            tension: 0.35,
            fill: true,
            pointBackgroundColor: palette[1],
        },
    ],
}));

const participantData = computed(() => ({
    labels: ['Faculty', 'Students', 'Beneficiaries'],
    datasets: [
        {
            data: [
                props.charts.participants.faculty,
                props.charts.participants.students,
                props.charts.participants.beneficiaries,
            ],
            backgroundColor: [palette[0], palette[1], palette[2]],
            borderWidth: 2,
            borderColor: '#ffffff',
        },
    ],
}));

const baseOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
        x: { grid: { display: false }, ticks: { color: '#707eae' } },
        y: { beginAtZero: true, ticks: { precision: 0, color: '#707eae' } },
    },
};
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4 sm:p-6">
            <!-- Heading + year filter -->
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="font-heading text-xl font-semibold text-neutral-900 sm:text-2xl">
                        Community Overview
                    </h1>
                    <p class="text-sm text-neutral-500">
                        What is happening in the communities and what services have been delivered.
                    </p>
                </div>
                <form method="get" class="flex flex-wrap items-center gap-2">
                    <select
                        name="year"
                        :value="filters.year ?? ''"
                        @change="(e) => { const v = (e.target as HTMLSelectElement).value; router.get('/dashboard', v ? { year: v } : {}, { preserveState: false }); }"
                        class="rounded-xl border border-hgray-200 bg-white px-3 py-2 text-sm text-navy-700 outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200"
                    >
                        <option value="">All years</option>
                        <option v-for="y in years" :key="y" :value="String(y)">{{ y }}</option>
                    </select>
                    <a v-if="filters.year" href="/dashboard" class="text-sm font-medium text-brand-600 hover:underline">Clear</a>
                </form>
            </div>

            <!-- Stat cards (households / responses / beneficiaries removed per request) -->
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <div
                    v-for="card in statCards"
                    :key="card.key"
                    class="hcard-p transition hover:shadow-md "
                >
                    <span class="inline-flex rounded-lg px-2 py-1 text-[11px] font-semibold" :class="card.tint">
                        {{ card.label }}
                    </span>
                    <p class="font-heading mt-3 text-2xl font-bold text-neutral-900 sm:text-3xl">
                        {{ Number(stats[card.key] ?? 0).toLocaleString() }}
                    </p>
                </div>
            </div>

            <!-- Hulagway — Face of Community (survey-driven, mirrors Map aggregation) -->
            <section class="hcard-p">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="font-heading text-sm font-semibold uppercase tracking-wide text-neutral-500">Hulagway — Face of the Community</h2>
                        <p class="text-xs text-neutral-400">Aggregation is survey-based. Pick a household survey, then select household conditions — card and dialog match the Map.</p>
                    </div>
                    <button
                        v-if="aggDetail"
                        type="button"
                        class="shrink-0 rounded-lg border border-brand-200 bg-brand-50 px-3 py-1.5 text-xs font-semibold text-brand-700 hover:bg-brand-100"
                        @click="showAllDialog = true"
                    >
                        View per-barangay breakdown →
                    </button>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-neutral-500">Household Survey *</label>
                        <select v-model="selectedSurvey" class="w-full rounded-xl border border-neutral-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200">
                            <option value="">— Select household survey —</option>
                            <option v-for="s in surveys" :key="s.id" :value="String(s.id)">{{ s.title }}</option>
                        </select>
                        <p v-if="!selectedSurvey" class="mt-1 text-[11px] text-amber-600">Please select a household survey to load household conditions.</p>
                    </div>
                    <div v-if="selectedSurvey" class="rounded-xl border border-neutral-200 bg-neutral-50 p-2">
                        <p class="px-1 text-[11px] font-semibold uppercase tracking-wide text-neutral-500">{{ selectedFields.length }} of {{ availableFields.length }} conditions selected</p>
                        <p class="px-1 text-[11px] text-neutral-400">Counts are per selected conditions, summed per barangay — identical to the Map's choropleth + top-right aggregation card.</p>
                    </div>
                </div>

                <div v-if="selectedSurvey" class="mt-3">
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-neutral-500">Household conditions to show (multi-select)</label>
                    <div class="max-h-64 overflow-auto rounded-xl border border-neutral-200 bg-white p-2">
                        <template v-for="group in ['Household Conditions','Per-Member','Aggregate','Other']" :key="group">
                            <p v-if="availableFields.filter(f=>f.group===group).length" class="mt-2 px-1 text-[10px] font-semibold uppercase tracking-wide text-neutral-400">{{ group }}</p>
                            <label v-for="f in availableFields.filter(ff=>ff.group===group)" :key="f.value" class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-xs hover:bg-neutral-50">
                                <input type="checkbox" :value="f.value" v-model="selectedFields" class="size-3.5 accent-brand-500" />
                                <span class="flex-1 truncate">{{ f.label }}</span>
                                <span class="shrink-0 rounded bg-neutral-100 px-1.5 py-0.5 text-[10px] font-semibold text-neutral-500">
                                    {{ fieldTotals[f.value] !== undefined ? Number(fieldTotals[f.value]).toLocaleString() : '–' }}
                                </span>
                            </label>
                        </template>
                        <p v-if="!availableFields.length" class="px-2 py-2 text-xs text-neutral-400">No household conditions found for this survey.</p>
                    </div>
                    <p class="mt-1 text-[10px] text-neutral-400">{{ selectedFields.length }} conditions selected — aggregation card below sums per barangay (ANY logic).</p>
                </div>

                <!-- Aggregation card (mirrors Map top-right card) -->
                <div v-if="selectedSurvey && selectedFields.length" class="mt-4">
                    <div v-if="aggLoading" class="flex flex-col items-center justify-center gap-3 rounded-xl border border-neutral-200 bg-white p-8 text-xs text-neutral-500">
                        <LoaderCircle class="size-6 animate-spin text-brand-500" />
                        <span>Loading aggregation…</span>
                    </div>
                    <template v-else-if="aggDetail">
                        <div class="grid grid-cols-3 gap-3">
                            <div class="rounded-xl bg-neutral-50 p-3 text-center">
                                <p class="text-lg font-bold text-neutral-800">{{ aggTotals.count }}</p>
                                <p class="text-[11px] text-neutral-500">Barangays</p>
                            </div>
                            <div class="rounded-xl bg-neutral-50 p-3 text-center">
                                <p class="text-lg font-bold text-neutral-800">{{ aggTotals.households.toLocaleString() }}</p>
                                <p class="text-[11px] text-neutral-500">Households (from responses)</p>
                            </div>
                            <div class="rounded-xl bg-orange-50 p-3 text-center">
                                <p class="text-lg font-bold text-orange-600">{{ aggTotals.poor.toLocaleString() }}</p>
                                <p class="text-[11px] text-neutral-500">Poor Family</p>
                            </div>
                        </div>
                        <!-- Per-field totals (dynamic hulagway cards) -->
                        <div class="mt-3 grid grid-cols-2 gap-3 md:grid-cols-4">
                            <template v-for="b in (aggDetail.barangays[0]?.fields ?? [])" :key="b.key">
                                <div class="rounded-xl border border-neutral-200/60 bg-white p-3 text-center">
                                    <span class="inline-flex rounded-lg bg-brand-500/10 px-2 py-1 text-[11px] font-semibold text-brand-600">{{ b.label }}</span>
                                    <p class="font-heading mt-2 text-xl font-bold text-neutral-900">{{ Number(aggTotals.perField[b.key] ?? 0).toLocaleString() }}</p>
                                    <p class="text-[10px] text-neutral-400">{{ b.type === 'choice' ? 'across all barangays' : 'total' }}</p>
                                </div>
                            </template>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-xl bg-brand-50 px-3 py-2 text-xs">
                            <span class="font-medium text-brand-700">Showing aggregation for “{{ selectedSurveyTitle }}” — {{ selectedFields.length }} condition(s), {{ aggTotals.count }} barangays.</span>
                            <button type="button" class="rounded-lg bg-white px-3 py-1 text-xs font-semibold text-brand-700 shadow-sm hover:bg-neutral-50" @click="showAllDialog = true">Open per-barangay detail dialog</button>
                        </div>
                    </template>
                    <p v-else class="rounded-xl border border-dashed border-neutral-200 p-6 text-center text-xs text-neutral-400">No data for selected conditions.</p>
                </div>
                <p v-else-if="selectedSurvey && !selectedFields.length" class="mt-3 rounded-xl border border-dashed border-amber-200 bg-amber-50 p-4 text-center text-xs text-amber-700">Select at least one household condition above to generate the aggregation card.</p>

                <!-- Fallback notice: old static hulagway hidden — now survey-driven -->
                <p class="mt-3 text-[10px] italic text-neutral-400">Previously shown static “Face of Community” totals (8 metrics) are replaced by this survey-specific aggregation. Use the survey selector to recreate them: e.g. pick the Hulagway household survey + select Poor Family, PWD Cat2, etc.</p>
            </section>

            <!-- Per-barangay detail dialog (identical to Map's "View all" dialog) -->
            <div v-if="showAllDialog" class="fixed inset-0 z-[1200] flex items-start justify-center overflow-auto bg-black/40 p-4 sm:p-6" @click.self="showAllDialog = false">
                <div class="my-8 w-full max-w-3xl rounded-2xl bg-white p-5 shadow-xl">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="font-heading text-lg font-semibold text-brand-700">All Barangays — Aggregations</h2>
                            <p class="text-xs text-neutral-500">Survey: {{ selectedSurveyTitle }} · {{ selectedFields.length }} conditions · {{ aggTotals.count }} barangays</p>
                        </div>
                        <button type="button" class="rounded-lg border px-3 py-1.5 text-xs hover:bg-neutral-50" @click="showAllDialog = false">Close</button>
                    </div>
                    <div class="mt-4 grid grid-cols-3 gap-3 text-sm">
                        <div class="rounded-lg bg-neutral-50 p-3 text-center"><p class="text-lg font-bold text-neutral-800">{{ aggTotals.households.toLocaleString() }}</p><p class="text-[11px] text-neutral-500">Households</p></div>
                        <div class="rounded-lg bg-orange-50 p-3 text-center"><p class="text-lg font-bold text-orange-600">{{ aggTotals.poor.toLocaleString() }}</p><p class="text-[11px] text-neutral-500">Poor Family</p></div>
                        <div class="rounded-lg bg-neutral-50 p-3 text-center"><p class="text-lg font-bold text-neutral-800">{{ aggTotals.count }}</p><p class="text-[11px] text-neutral-500">Barangays</p></div>
                    </div>
                    <div class="mt-4 max-h-[60vh] overflow-auto rounded-xl border border-neutral-200">
                        <template v-if="aggDetail?.barangays?.length">
                            <div v-for="b in aggDetail.barangays" :key="b.id" class="border-b border-neutral-100 last:border-b-0">
                                <button type="button" class="flex w-full items-center justify-between gap-3 px-3 py-2.5 text-left transition hover:bg-neutral-50" @click="toggleOpenAll(b.id)">
                                    <span class="font-medium text-neutral-700">{{ b.name }}</span>
                                    <span class="flex items-center gap-2">
                                        <span class="text-xs text-neutral-500">{{ Number(b.households ?? 0).toLocaleString() }} households</span>
                                        <span class="text-[10px] text-neutral-400">{{ openAllIds.includes(b.id) ? '−' : '+' }}</span>
                                    </span>
                                </button>
                                <div v-if="openAllIds.includes(b.id)" class="space-y-2 bg-neutral-50/70 px-3 py-3">
                                    <div v-for="f in b.fields" :key="f.key" class="rounded-xl border border-neutral-100 bg-white p-3">
                                        <div class="flex items-start justify-between gap-2">
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-neutral-500">{{ f.label }}</p>
                                            <span v-if="f.type === 'count'" class="text-xs font-semibold text-neutral-700">{{ Number(f.total).toLocaleString() }}</span>
                                        </div>
                                        <ul v-if="f.type === 'choice'" class="mt-2 space-y-1">
                                            <li v-for="it in f.items" :key="it.name" class="flex items-center gap-2 text-xs">
                                                <span class="shrink-0 text-neutral-600">{{ it.name }}</span>
                                                <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-neutral-100"><span class="block h-full rounded-full bg-brand-400" :style="{ width: (f.total ? (it.count / f.total) * 100 : 0) + '%' }" /></span>
                                                <span class="shrink-0 font-semibold text-neutral-800">{{ it.count }}</span>
                                            </li>
                                            <li v-if="!f.items.length" class="text-xs text-neutral-400">No responses.</li>
                                        </ul>
                                        <div v-else-if="f.type === 'text'" class="mt-2">
                                            <ul v-if="f.items.length" class="space-y-1.5">
                                                <li v-for="it in f.items" :key="it.name" class="flex items-start gap-2 text-xs text-neutral-600"><span class="mt-1 size-1.5 shrink-0 rounded-full bg-brand-400" /><span class="flex-1">{{ it.name }}<span v-if="it.count > 1" class="text-neutral-400"> (× {{ it.count }})</span></span></li>
                                            </ul>
                                            <p v-else class="text-xs text-neutral-400">No written responses.</p>
                                        </div>
                                        <p v-else-if="f.type === 'count'" class="mt-1.5 text-xs text-neutral-600">Total: {{ Number(f.total).toLocaleString() }}</p>
                                    </div>
                                </div>
                            </div>
                        </template>
                        <p v-else class="p-6 text-center text-xs text-neutral-400">No barangay data.</p>
                    </div>
                </div>
            </div>

            <!-- Charts -->
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <section class="hcard-p ">
                    <h2 class="font-heading mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">
                        Activities by Program
                    </h2>
                    <div class="h-64">
                        <Bar v-if="programChartData.labels.length" :data="programChartData" :options="baseOptions" />
                        <p v-else class="pt-20 text-center text-sm text-neutral-400">No activities in range.</p>
                    </div>
                </section>

                <section class="hcard-p ">
                    <h2 class="font-heading mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">
                        Activities Over Time
                    </h2>
                    <div class="h-64">
                        <Line v-if="timelineData.labels.length" :data="timelineData" :options="baseOptions" />
                        <p v-else class="pt-20 text-center text-sm text-neutral-400">No activities in range.</p>
                    </div>
                </section>

                <section class="hcard-p lg:col-span-1 ">
                    <h2 class="font-heading mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">
                        Participants Reached
                    </h2>
                    <div class="h-64">
                        <Pie
                            :data="participantData"
                            :options="{ responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }"
                        />
                    </div>
                </section>

                <!-- Barangay coverage teaser linking to map -->
                <section
                    class="bg-brand-gradient flex flex-col justify-between rounded-2xl p-5 text-white shadow-brand lg:col-span-1"
                >
                    <div>
                        <h2 class="font-heading text-sm font-semibold uppercase tracking-wide text-white/80">
                            Geographic Coverage
                        </h2>
                        <p class="font-heading mt-4 text-4xl font-bold">{{ stats.total_barangays }}</p>
                        <p class="mt-1 max-w-xs text-sm text-white/85">
                            barangays of Tangub City mapped and monitored for community needs and extension delivery.
                        </p>
                    </div>
                    <a
                        href="/map"
                        class="mt-6 inline-flex w-fit items-center gap-2 rounded-xl bg-white/15 px-4 py-2 text-sm font-semibold backdrop-blur transition hover:bg-white/25"
                    >
                        Open Community Map →
                    </a>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
