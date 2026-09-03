<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItemType } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Bar } from 'vue-chartjs';
import { ref, computed } from 'vue';
import {
    ArcElement,
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    Legend,
    LinearScale,
    Title,
    Tooltip,
} from 'chart.js';

ChartJS.register(Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale, ArcElement);

interface Survey {
    id: number;
    title: string;
    description: string | null;
    status: string;
    public_token: string | null;
    barangay: { name: string } | null;
    creator: { name: string } | null;
    questions: Array<{ id: number; question_text: string; type: string; is_required: boolean; options: Array<{ label: string }> }>;
}

const props = defineProps<{
    survey: Survey;
    summary: Array<{
        id: number;
        question_text: string;
        type: string;
        is_required: boolean;
        total_answered: number;
        option_counts: Record<string, number> | null;
        text_answers?: string[] | null;
        code?: string | null;
    }>;
    totalResponses: number;
    householdStats?: Record<string, any> | null;
    barangays?: Array<{ id: number; name: string }>;
}>();

// --- Aggregation dialog ---
const showAggDialog = ref(false);
const aggMetric = ref<string | null>(null);
const aggTitle = ref('');
const aggLoading = ref(false);
const aggMembers = ref<any[]>([]);
const aggHouseholds = ref<any[]>([]);
const aggTotal = ref(0);
const aggSearch = ref('');
const aggBarangayIds = ref<number[]>([]);
const aggBarangaySearch = ref('');

const barangayList = computed(() => (props as any).barangays ?? []);

const filteredBarangays = computed(() => {
    const q = aggBarangaySearch.value.trim().toLowerCase();
    if (!q) return barangayList.value;
    return barangayList.value.filter((b: any) => b.name.toLowerCase().includes(q));
});

const metricLabelMap: Record<string, string> = {
    total_households: 'Households',
    pwd_cat2: 'PWD',
    seniors_90: 'Senior 90+',
    seniors_60: 'Senior 60+',
    osy: 'Out-of-School Youth',
    bedridden_cat2: 'Bedridden',
    pregnant: 'Pregnant',
    mentally_cat2: 'Mentally Challenged',
    live_in: 'Live-in Households',
};

const isHouseholdMetric = (m: string) => m === 'live_in' || m === 'total_households';

const openAggregation = async (metric: string) => {
    aggMetric.value = metric;
    aggTitle.value = metricLabelMap[metric] ?? metric;
    showAggDialog.value = true;
    await fetchAggregation();
};

const closeAggregation = () => {
    showAggDialog.value = false;
    aggMetric.value = null;
    aggMembers.value = [];
    aggHouseholds.value = [];
};

const fetchAggregation = async () => {
    if (!aggMetric.value) return;
    aggLoading.value = true;
    try {
        const params = new URLSearchParams();
        if (aggBarangayIds.value.length) {
            aggBarangayIds.value.forEach((id) => params.append('barangay_ids[]', String(id)));
        }
        if (aggSearch.value.trim()) params.set('search', aggSearch.value.trim());
        const url = `/surveys/${props.survey.id}/aggregation/${aggMetric.value}?${params.toString()}`;
        const res = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await res.json();
        aggMembers.value = data.members ?? [];
        aggHouseholds.value = data.households ?? [];
        aggTotal.value = data.total ?? 0;
    } catch (e) {
        console.error(e);
    } finally {
        aggLoading.value = false;
    }
};

const toggleBarangay = (id: number) => {
    const idx = aggBarangayIds.value.indexOf(id);
    if (idx >= 0) aggBarangayIds.value.splice(idx, 1);
    else aggBarangayIds.value.push(id);
};

const clearBarangayFilter = () => {
    aggBarangayIds.value = [];
    aggBarangaySearch.value = '';
};

const applyBarangayFilter = async () => {
    await fetchAggregation();
};

const breadcrumbs: BreadcrumbItemType[] = [
    { title: 'Surveys', href: '/surveys' },
    { title: props.survey.title, href: '' },
];

const publish = () => router.post(`/surveys/${props.survey.id}/publish`);
const deactivate = () => router.post(`/surveys/${props.survey.id}/deactivate`);
const archive = () => {
    if (!confirm('Archive this survey? It will be hidden from active lists.')) return;
    router.post(`/surveys/${props.survey.id}/archive`);
};
const restore = () => router.post(`/surveys/${props.survey.id}/restore`);

const chartFor = (item: (typeof props.summary)[number]) => ({
    labels: Object.keys(item.option_counts ?? {}),
    datasets: [
        {
            data: Object.values(item.option_counts ?? {}),
            backgroundColor: ['#F97316', '#FBBF24', '#EA580C', '#FB923C', '#C2410C', '#FDBA74'],
            borderRadius: 6,
            maxBarThickness: 36,
        },
    ],
});

const chartOptions = {
    indexAxis: 'y' as const,
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: { x: { beginAtZero: true, ticks: { precision: 0, color: '#707eae' }, grid: { display: false } }, y: { ticks: { color: '#707eae' } } },
};
</script>

<template>
    <Head :title="survey.title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-5xl space-y-5 p-4 sm:p-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="font-heading text-xl font-semibold sm:text-2xl">{{ survey.title }}</h1>
                        <span class="rounded-full bg-brand-500/10 px-2 py-0.5 text-[11px] font-semibold capitalize text-brand-600">{{ survey.status }}</span>
                    </div>
                    <p class="text-sm text-neutral-500">{{ survey.description || 'No description' }}</p>
                    <p class="mt-1 text-xs text-neutral-400">
                        {{ totalResponses.toLocaleString() }} responses
                        <template v-if="survey.barangay"> · {{ survey.barangay.name }}</template>
                        · by {{ survey.creator?.name }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button v-if="survey.status !== 'published' && survey.status !== 'archived'" @click="publish">Publish</Button>
                    <Button v-if="survey.status === 'published'" variant="outline" @click="deactivate">Deactivate</Button>
                    <Button v-if="survey.status !== 'archived' && survey.status !== 'published'" variant="outline" @click="archive">Archive</Button>
                    <Button v-if="survey.status === 'archived'" @click="restore">Restore</Button>
                    <Button v-if="survey.public_token && survey.status === 'published'" variant="outline" as-child>
                        <a :href="`/s/${survey.public_token}`" target="_blank" rel="noopener">Preview public form ↗</a>
                    </Button>
                    <Button v-if="survey.status !== 'archived'" variant="outline" as-child><a :href="`/reports/surveys/${survey.id}/pdf`">Export PDF</a></Button>
                    <Button v-if="survey.status !== 'archived'" variant="outline" as-child><a :href="`/reports/surveys/${survey.id}/xlsx`">Export Excel</a></Button>
                </div>
            </div>

            <!-- Household aggregations (only for community household survey) -->
            <section v-if="householdStats" class="hcard-p">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="font-heading text-sm font-semibold uppercase tracking-wide text-neutral-500">Household Survey Aggregations</h2>
                    <span class="text-xs text-neutral-400">Click a card to view list</span>
                </div>
                <div class="grid gap-3 grid-cols-2 sm:grid-cols-3 lg:grid-cols-4">
                    <button type="button" class="rounded-xl bg-brand-50 p-4 text-center transition hover:bg-brand-100 hover:shadow-sm focus:outline-none focus:ring-2 focus:ring-brand-200" @click="openAggregation('total_households')">
                        <p class="text-2xl font-bold text-brand-600">{{ householdStats.total_households }}</p>
                        <p class="text-xs text-neutral-500">Households</p>
                    </button>
                    <button type="button" class="rounded-xl bg-amber-50 p-4 text-center transition hover:bg-amber-100 hover:shadow-sm focus:outline-none focus:ring-2 focus:ring-amber-200" @click="openAggregation('pwd_cat2')">
                        <p class="text-2xl font-bold text-amber-600">{{ householdStats.pwd_cat2 ?? 0 }}</p>
                        <p class="text-xs text-neutral-500">PWD</p>
                    </button>
                    <button type="button" class="rounded-xl bg-emerald-50 p-4 text-center transition hover:bg-emerald-100 hover:shadow-sm focus:outline-none focus:ring-2 focus:ring-emerald-200" @click="openAggregation('seniors_90')">
                        <p class="text-2xl font-bold text-emerald-600">{{ householdStats.seniors_90 ?? 0 }}</p>
                        <p class="text-xs text-neutral-500">Senior 90+</p>
                    </button>
                    <button type="button" class="rounded-xl bg-violet-50 p-4 text-center transition hover:bg-violet-100 hover:shadow-sm focus:outline-none focus:ring-2 focus:ring-violet-200" @click="openAggregation('seniors_60')">
                        <p class="text-2xl font-bold text-violet-600">{{ householdStats.seniors_60 ?? 0 }}</p>
                        <p class="text-xs text-neutral-500">Senior 60+ (auto)</p>
                    </button>
                    <button type="button" class="rounded-xl bg-blue-50 p-4 text-center transition hover:bg-blue-100 hover:shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-200" @click="openAggregation('osy')">
                        <p class="text-2xl font-bold text-blue-600">{{ householdStats.osy ?? 0 }}</p>
                        <p class="text-xs text-neutral-500">Out-of-School Youth</p>
                    </button>
                    <button type="button" class="rounded-xl bg-rose-50 p-4 text-center transition hover:bg-rose-100 hover:shadow-sm focus:outline-none focus:ring-2 focus:ring-rose-200" @click="openAggregation('bedridden_cat2')">
                        <p class="text-2xl font-bold text-rose-600">{{ householdStats.bedridden_cat2 ?? 0 }}</p>
                        <p class="text-xs text-neutral-500">Bedridden</p>
                    </button>
                    <button type="button" class="rounded-xl bg-pink-50 p-4 text-center transition hover:bg-pink-100 hover:shadow-sm focus:outline-none focus:ring-2 focus:ring-pink-200" @click="openAggregation('pregnant')">
                        <p class="text-2xl font-bold text-pink-600">{{ householdStats.pregnant ?? 0 }}</p>
                        <p class="text-xs text-neutral-500">Pregnant</p>
                    </button>
                    <button type="button" class="rounded-xl bg-teal-50 p-4 text-center transition hover:bg-teal-100 hover:shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-200" @click="openAggregation('mentally_cat2')">
                        <p class="text-2xl font-bold text-teal-600">{{ householdStats.mentally_cat2 ?? 0 }}</p>
                        <p class="text-xs text-neutral-500">Mentally Challenged</p>
                    </button>
                    <button type="button" class="rounded-xl bg-cyan-50 p-4 text-center transition hover:bg-cyan-100 hover:shadow-sm focus:outline-none focus:ring-2 focus:ring-cyan-200" @click="openAggregation('live_in')">
                        <p class="text-2xl font-bold text-cyan-600">{{ householdStats.live_in ?? 0 }}</p>
                        <p class="text-xs text-neutral-500">Live-in Households</p>
                    </button>
                    <div v-if="householdStats.gov_satisfaction_avg != null" class="rounded-xl bg-indigo-50 p-4 text-center">
                        <p class="text-2xl font-bold text-indigo-600">{{ householdStats.gov_satisfaction_avg }} / 5</p>
                        <p class="text-xs text-neutral-500">Gov Satisfaction Avg ({{ householdStats.gov_satisfaction_count }} votes)</p>
                    </div>
                </div>
                <div v-if="householdStats.gov_satisfaction_avg == null" class="mt-3 text-xs text-neutral-400">No government satisfaction responses yet.</div>

                <!-- Aggregation Detail Dialog -->
                <div v-if="showAggDialog" class="fixed inset-0 z-50 flex items-start justify-center overflow-auto bg-black/40 p-4 sm:p-6" @click.self="closeAggregation">
                    <div class="my-8 w-full max-w-3xl rounded-2xl bg-white p-5 shadow-xl">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="font-heading text-lg font-semibold">{{ aggTitle }} — List</h3>
                                <p class="text-xs text-neutral-500">{{ aggTotal }} {{ isHouseholdMetric(aggMetric ?? '') ? 'households' : 'people' }} found</p>
                            </div>
                            <button type="button" class="rounded-lg border px-3 py-1.5 text-xs hover:bg-neutral-50" @click="closeAggregation">Close</button>
                        </div>

                        <!-- Filters -->
                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-neutral-600">Search (name, purok, head)</label>
                                <input v-model="aggSearch" placeholder="Search..." class="w-full rounded-lg border border-neutral-200 bg-white px-3 py-2 text-sm" @input="fetchAggregation" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-neutral-600">Barangay filter (multi-select or All)</label>
                                <div class="flex gap-2">
                                    <input v-model="aggBarangaySearch" placeholder="Search barangay..." class="w-full rounded-lg border border-neutral-200 bg-white px-3 py-2 text-sm" />
                                    <button type="button" class="shrink-0 rounded-lg border px-3 py-2 text-xs hover:bg-neutral-50" @click="clearBarangayFilter">All</button>
                                </div>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    <span v-if="aggBarangayIds.length === 0" class="rounded bg-brand-50 px-2 py-1 text-xs font-medium text-brand-700">All barangays</span>
                                    <span v-for="id in aggBarangayIds" :key="id" class="inline-flex items-center gap-1 rounded bg-brand-100 px-2 py-1 text-xs font-medium text-brand-700">
                                        {{ barangayList.find((b:any)=>b.id===id)?.name ?? id }}
                                        <button type="button" class="ml-1 text-brand-400 hover:text-brand-700" @click="toggleBarangay(id)">✕</button>
                                    </span>
                                </div>
                                <div class="mt-1 max-h-32 overflow-auto rounded-lg border border-neutral-100 bg-neutral-50 p-1">
                                    <label v-for="b in filteredBarangays.slice(0, 20)" :key="b.id" class="flex cursor-pointer items-center gap-2 rounded px-2 py-1 text-xs hover:bg-white">
                                        <input type="checkbox" :checked="aggBarangayIds.includes(b.id)" @change="toggleBarangay(b.id)" class="size-3.5 accent-brand-500" />
                                        {{ b.name }}
                                    </label>
                                    <p v-if="filteredBarangays.length === 0" class="px-2 py-1 text-xs text-neutral-400">No barangay found</p>
                                </div>
                                <button type="button" class="mt-1 w-full rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-600" @click="applyBarangayFilter">Apply Filter</button>
                            </div>
                        </div>

                        <div v-if="aggLoading" class="mt-4 text-center text-sm text-neutral-400">Loading...</div>

                        <div v-else-if="isHouseholdMetric(aggMetric ?? '')" class="mt-4 max-h-96 overflow-auto">
                            <table class="w-full text-left text-xs">
                                <thead class="sticky top-0 bg-white text-neutral-500">
                                    <tr>
                                        <th class="border-b px-2 py-1 font-medium">Head</th>
                                        <th class="border-b px-2 py-1 font-medium">Barangay</th>
                                        <th class="border-b px-2 py-1 font-medium">Purok</th>
                                        <th class="border-b px-2 py-1 font-medium">Contact</th>
                                        <th class="border-b px-2 py-1 font-medium">Members</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="h in aggHouseholds" :key="h.id" class="border-b hover:bg-neutral-50">
                                        <td class="px-2 py-1.5 font-medium">{{ h.head_name }}</td>
                                        <td class="px-2 py-1.5">{{ h.barangay }}</td>
                                        <td class="px-2 py-1.5">{{ h.purok }}</td>
                                        <td class="px-2 py-1.5">{{ h.contact_no ?? '-' }}</td>
                                        <td class="px-2 py-1.5">{{ h.members_count }}</td>
                                    </tr>
                                    <tr v-if="aggHouseholds.length === 0"><td colspan="5" class="px-2 py-8 text-center text-neutral-400">No households found for this filter.</td></tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-else class="mt-4 max-h-96 overflow-auto">
                            <table class="w-full text-left text-xs">
                                <thead class="sticky top-0 bg-white text-neutral-500">
                                    <tr>
                                        <th class="border-b px-2 py-1 font-medium">Name</th>
                                        <th class="border-b px-2 py-1 font-medium">Age</th>
                                        <th class="border-b px-2 py-1 font-medium">Sex</th>
                                        <th class="border-b px-2 py-1 font-medium">Barangay</th>
                                        <th class="border-b px-2 py-1 font-medium">Purok</th>
                                        <th class="border-b px-2 py-1 font-medium">Household Head</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="m in aggMembers" :key="m.id" class="border-b hover:bg-neutral-50">
                                        <td class="px-2 py-1.5 font-medium">{{ m.name }} <span v-if="m.is_head" class="ml-1 rounded bg-brand-100 px-1 py-0.5 text-[9px] text-brand-700">Head</span></td>
                                        <td class="px-2 py-1.5">{{ m.age ?? '-' }}</td>
                                        <td class="px-2 py-1.5">{{ m.sex ?? '-' }}</td>
                                        <td class="px-2 py-1.5">{{ m.barangay }}</td>
                                        <td class="px-2 py-1.5">{{ m.purok ?? '-' }}</td>
                                        <td class="px-2 py-1.5">{{ m.household_head ?? '-' }}</td>
                                    </tr>
                                    <tr v-if="aggMembers.length === 0"><td colspan="6" class="px-2 py-8 text-center text-neutral-400">No people found for this filter.</td></tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-4 flex justify-end">
                            <button type="button" class="rounded-lg border px-4 py-2 text-sm hover:bg-neutral-50" @click="closeAggregation">Close</button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Questions & aggregated responses -->
            <section class="hcard-p ">
                <h2 class="font-heading mb-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">Questions & Responses</h2>

                <div class="space-y-6">
                    <article v-for="(item, i) in summary" :key="item.id">
                        <header class="flex flex-wrap items-baseline gap-2">
                            <span class="font-heading font-bold text-brand-500">Q{{ i + 1 }}</span>
                            <h3 class="font-medium">{{ item.question_text }}</h3>
                            <span v-if="item.is_required" class="text-[10px] font-bold uppercase text-red-400">required</span>
                            <span class="ml-auto text-xs text-neutral-400">{{ item.total_answered }} answers</span>
                        </header>

                        <div v-if="item.option_counts && Object.keys(item.option_counts).length" class="mt-3 h-auto min-h-40 max-w-xl">
                            <Bar :data="chartFor(item)" :options="chartOptions" />
                        </div>

                        <ul v-else-if="item.text_answers && item.text_answers.length" class="mt-2 space-y-1.5">
                            <li v-for="(txt, idx) in item.text_answers.slice(0, 10)" :key="idx" class="rounded-lg border border-neutral-100 bg-neutral-50 px-3 py-2 text-sm text-neutral-700">
                                {{ txt || '—' }}
                            </li>
                            <li v-if="item.text_answers.length > 10" class="text-xs text-neutral-400">+ {{ item.text_answers.length - 10 }} more responses (see Excel export)</li>
                        </ul>

                        <p v-else-if="item.type === 'likert' && item.option_counts" class="mt-2 text-sm text-neutral-500">Likert — see chart above</p>

                        <p v-else class="mt-2 text-sm text-neutral-400">
                            <span v-if="item.total_answered === 0">No responses yet.</span>
                            <span v-else class="capitalize">{{ item.type.replace('_', ' ') }} — {{ item.total_answered }} responses (see Excel for details)</span>
                        </p>
                    </article>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
