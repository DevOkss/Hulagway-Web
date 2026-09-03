<script setup lang="ts">
import BarangaySearchSelect from '@/components/BarangaySearchSelect.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Question {
    id: number;
    question_text: string;
    type: string;
    is_required: boolean;
    data_scope?: string;
    code?: string | null;
    map_enabled?: boolean;
    options: Array<{ label: string }>;
}

const props = defineProps<{
    survey: { id: number; title: string; description: string | null; type: string; questions: Question[]; barangay?: { name: string } | null };
    token: string;
    barangays: Array<{ id: number; name: string }>;
}>();

const isHouseholdSurvey = (props.survey as any).type === 'household';

type MemberForm = {
    name: string;
    age: string;
    sex: string;
    civil_status: string;
    relationship: string;
    is_head: boolean;
    is_pwd: string;
    is_mentally_challenged: string;
    is_osy: boolean | null;
    osy_last_grade: string;
    bedridden_status: string;
    is_pregnant: boolean;
    lcr_registered: string;
    lcr_reason: string;
    katungdanan_status: string;
    katungdanan_position: string;
};

const YOUTH_MIN = 15;
const YOUTH_MAX = 30;
const isYouth = (age: string) => {
    const a = Number(age);
    if (!age || Number.isNaN(a)) return false;
    return a >= YOUTH_MIN && a <= YOUTH_MAX;
};
const isSenior = (age: string) => {
    const a = Number(age);
    if (!age || Number.isNaN(a)) return false;
    return a >= 60;
};
const blockNegative = (e: KeyboardEvent) => {
    if (e.key === '-' || e.key.toLowerCase() === 'e') e.preventDefault();
};
const blockNegativeIfNumber = (e: KeyboardEvent, type: string) => {
    if (type === 'number' && (e.key === '-' || e.key.toLowerCase() === 'e')) e.preventDefault();
};
const clampAge = (m: any) => {
    if (m.age !== '' && Number(m.age) < 0) m.age = '0';
};
const clampLiveIn = () => {
    if (form.household.live_in_years !== '' && Number(form.household.live_in_years) < 0) form.household.live_in_years = '0';
};
const clampAnswer = (id: number) => {
    const v = form.answers[id];
    if (v !== '' && v != null && !Array.isArray(v) && Number(v as string) < 0) (form.answers as any)[id] = '0';
};
const clampMemberAnswer = (qid: number, name: string) => {
    const v = (form.member_answers[qid] as any)[name];
    if (v !== '' && v != null && Number(v) < 0) (form.member_answers[qid] as any)[name] = '0';
};

const form = useForm({
    uuid: crypto.randomUUID(),
    barangay_id: '' as string | number,
    purok: '',
    household: {
        head_name: '',
        purok: '',
        address: '',
        contact_no: '',
        live_in_status: 'No',
        live_in_years: '',
        live_in_reason: '',
    } as { head_name: string; purok: string; address: string; contact_no: string; live_in_status: string; live_in_years: string; live_in_reason: string },
    household_members: [
        {
            name: '',
            age: '',
            sex: '',
            civil_status: '',
            relationship: 'Head',
            is_head: true,
            is_pwd: 'No',
            is_mentally_challenged: 'No',
            is_osy: null,
            osy_last_grade: '',
            bedridden_status: 'No',
            is_pregnant: false,
            lcr_registered: 'No',
            lcr_reason: '',
            katungdanan_status: 'No',
            katungdanan_position: '',
        },
    ] as MemberForm[],
    answers: {} as Record<number, string | string[]>,
    member_answers: {} as Record<number, Record<string, string>>,
});

const visibleQuestions = computed(() => {
    if (!isHouseholdSurvey) return props.survey.questions;
    const liveInCodes = ['live_in', 'live_in_years', 'live_in_reason'];
    const hideTexts = ['Household Head (Surname, Firstname, MI)', 'Household Head', 'Purok'];
    return props.survey.questions.filter((q) => !liveInCodes.includes((q as any).code ?? '') && !hideTexts.includes((q.question_text || '').trim()));
});

const addMember = () =>
    form.household_members.push({
        name: '',
        age: '',
        sex: '',
        civil_status: '',
        relationship: 'Anak',
        is_head: false,
        is_pwd: 'No',
        is_mentally_challenged: 'No',
        is_osy: null,
        osy_last_grade: '',
        bedridden_status: 'No',
        is_pregnant: false,
        lcr_registered: 'No',
        lcr_reason: '',
        katungdanan_status: 'No',
        katungdanan_position: '',
    });
const removeMember = (idx: number) => {
    if (form.household_members.length > 1) form.household_members.splice(idx, 1);
};

const isIndividual = (q: Question) => (q as any).data_scope === 'individual';
const toggleMemberForQuestion = (questionId: number, memberName: string) => {
    if (!form.member_answers[questionId]) form.member_answers[questionId] = {};
    if (form.member_answers[questionId][memberName] !== undefined) {
        delete form.member_answers[questionId][memberName];
    } else {
        form.member_answers[questionId][memberName] = '';
    }
};

const submitted = ref(false);

const resetForm = () => {
    submitted.value = false;
    form.reset();
    form.uuid = crypto.randomUUID();
    form.clearErrors();
};

const toggleCheckbox = (questionId: number, label: string) => {
    const current = (form.answers[questionId] as string[]) ?? [];
    form.answers[questionId] = current.includes(label) ? current.filter((v) => v !== label) : [...current, label];
};

const submit = () => {
    const isHouse = isHouseholdSurvey;
    if (isHouse) {
        form.household.purok = form.purok;
        const head: any = (form.household_members as any[]).find((m: any) => m.is_head) || (form.household_members as any[])[0];
        if (head?.name?.trim()) form.household.head_name = head.name.trim();
    }
    if (!form.barangay_id) form.barangay_id = (props.survey as any).barangay_id ?? (props.survey as any).barangay?.id ?? null as any;
    if (form.barangay_id === '') (form as any).barangay_id = null;
    // Map live_in household fields to answers for analytics (if questions exist)
    for (const q of props.survey.questions as any[]) {
        if (q.code === 'live_in') (form.answers as any)[q.id] = form.household.live_in_status;
        if (q.code === 'live_in_years' && form.household.live_in_status === 'Yes') (form.answers as any)[q.id] = form.household.live_in_years;
        if (q.code === 'live_in_reason' && form.household.live_in_status === 'Yes') (form.answers as any)[q.id] = form.household.live_in_reason;
    }
    if (form.household.live_in_status !== 'Yes') {
        for (const q of props.survey.questions as any[]) {
            if (q.code === 'live_in_years' || q.code === 'live_in_reason') delete (form.answers as any)[q.id];
        }
    }
    // For generic, send null/empty for household to satisfy nullable validation and avoid 422
    form.transform((data: any) => {
        if (!isHouse) {
            return {
                ...data,
                barangay_id: data.barangay_id === '' ? null : data.barangay_id,
                purok: data.purok === '' ? null : data.purok,
                household: null,
                household_members: [],
            };
        }
        return {
            ...data,
            barangay_id: data.barangay_id === '' ? null : data.barangay_id,
            purok: data.purok === '' ? null : data.purok,
        };
    });
    form.post(`/s/${props.token}`, {
        preserveScroll: true,
        onSuccess: () => {
            submitted.value = true;
            // Keep form reset for next entry but hidden until user clicks button
            form.reset();
            form.uuid = crypto.randomUUID();
            form.clearErrors();
        },
    });
};
</script>

<template>
    <Head :title="survey.title" />

    <div class="flex min-h-screen flex-col bg-brand-50">
        <header class="bg-brand-gradient sticky top-0 z-20 flex items-center gap-3 px-4 py-3 text-white shadow-brand sm:px-6">
            <img src="/images/logo.png" alt="HULAGWAY" class="size-9 rounded-xl bg-white/90 object-contain p-0.5" />
            <div class="min-w-0">
                <p class="font-heading truncate text-base font-semibold leading-tight tracking-wide">HULAGWAY</p>
                <p class="truncate text-xs text-white/80">Field Data Collection</p>
            </div>
        </header>
        <div class="mx-auto w-full max-w-2xl flex-1 space-y-5 px-4 py-6">
            <header class="text-center">
                <h1 class="font-heading text-xl font-bold text-neutral-900 sm:text-2xl">{{ survey.title }}</h1>
                <p class="mt-1 max-w-lg text-sm text-neutral-500">{{ survey.description || 'Please answer the questions below.' }}</p>
            </header>

            <div v-if="submitted" class="rounded-2xl border border-neutral-200/80 bg-white p-8 text-center shadow-sm">
                <div class="mx-auto mb-3 flex size-12 items-center justify-center rounded-full bg-emerald-500/15">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="size-6 text-emerald-500">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                    </svg>
                </div>
                <h2 class="font-heading text-lg font-semibold">Thank you!</h2>
                <p class="mt-1 text-sm text-neutral-500">Your response has been recorded.</p>
                <button type="button" class="mt-6 cursor-pointer rounded-xl bg-brand-gradient px-6 py-2.5 text-sm font-semibold text-white shadow-brand hover:opacity-90" @click="resetForm">
                    Submit Another Response
                </button>
            </div>

            <form v-else class="space-y-4 rounded-2xl border border-neutral-200/80 bg-white p-5 shadow-sm sm:p-6" @submit.prevent="submit">
                <!-- Household — only for Community Household Survey -->
                <div v-if="isHouseholdSurvey" class="rounded-xl border border-brand-200 bg-brand-50/50 p-4">
                    <h2 class="font-heading text-sm font-semibold text-brand-800">Household — Barangay, Purok & Head</h2>
                    <p class="text-xs text-neutral-500">
                        Survey: <span class="font-medium text-neutral-700">{{ (survey as any).barangay?.name ?? 'All barangays' }}</span> · Select the household's barangay below · One household per submission
                    </p>
                    <div class="mt-3 grid gap-3">
                        <label class="block text-sm"
                            ><span class="text-xs font-medium text-neutral-600">Barangay *</span>
                            <BarangaySearchSelect v-model="form.barangay_id" :barangays="barangays" placeholder="Search barangay..." />
                            <p v-if="form.errors.barangay_id" class="mt-1 text-xs text-red-500">{{ form.errors.barangay_id }}</p></label
                        >
                        <label class="block text-sm"
                            ><span class="text-xs font-medium text-neutral-600">Purok *</span
                            ><input v-model="form.purok" placeholder="e.g. Purok 4" class="mt-1 w-full rounded-xl border border-neutral-200 bg-white px-4 py-2.5 text-sm outline-none focus:border-brand-500" required
                        /></label>
                        <label class="block text-sm"
                            ><span class="text-xs font-medium text-neutral-600">Contact No.</span
                            ><input v-model="form.household.contact_no" placeholder="09xxxxxxxxx" class="mt-1 w-full rounded-xl border border-neutral-200 bg-white px-4 py-2.5 text-sm outline-none focus:border-brand-500" /></label
                        >
                    </div>
                    <h3 class="font-heading mt-4 text-sm font-semibold text-neutral-800">Family Members — Roster *</h3>
                    <p class="text-xs text-neutral-400">Each member includes Civil Status, PWD, Mentally Challenged, OSY if youth {{ YOUTH_MIN }}–{{ YOUTH_MAX }}, Senior auto 60+, Bedridden, Pregnant if female.</p>
                    <div class="mt-2 space-y-4">
                        <div v-for="(m, idx) in form.household_members" :key="idx" class="rounded-xl border border-neutral-100 bg-white p-3">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold text-neutral-500"
                                    >Member {{ idx + 1 }} <span v-if="m.is_head" class="ml-1 rounded bg-brand-100 px-1.5 py-0.5 text-[10px] text-brand-700">Head</span>
                                    <span v-if="isSenior(m.age)" class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] text-amber-700">Senior 60+</span></span
                                >
                                <button v-if="form.household_members.length > 1" type="button" class="text-xs font-medium text-red-500" @click="removeMember(idx)">Remove</button>
                            </div>
                            <input v-model="m.name" placeholder="Name *" class="mt-2 w-full rounded-lg border border-neutral-200 bg-white px-3 py-2 text-sm" />
                            <div class="mt-2 grid grid-cols-3 gap-2">
                                <input v-model="m.age" type="number" min="0" placeholder="Age" class="rounded-lg border border-neutral-200 bg-white px-2 py-2 text-sm" @keydown="blockNegative" @input="clampAge(m)" />
                                <select v-model="m.sex" class="rounded-lg border border-neutral-200 bg-white px-2 py-2 text-sm"><option value="">Sex</option><option>Male</option><option>Female</option><option>Others</option></select>
                                <input v-model="m.relationship" placeholder="Relasyon" class="rounded-lg border border-neutral-200 bg-white px-2 py-2 text-sm" />
                            </div>
                            <div class="mt-2">
                                <select v-model="m.civil_status" class="w-full rounded-lg border border-neutral-200 bg-white px-2 py-2 text-sm">
                                    <option value="">Civil Status</option>
                                    <option>Single</option>
                                    <option>Married</option>
                                    <option>Widowed</option>
                                    <option>Separated</option>
                                    <option>Live-in</option>
                                    <option>Annulled</option>
                                </select>
                            </div>
                            <div class="mt-3 grid gap-3 rounded-lg border border-neutral-100 bg-neutral-50 p-3">
                                <label class="block text-sm"><span class="text-xs font-medium text-neutral-600">PWD</span><select v-model="m.is_pwd" class="mt-1 w-full rounded-lg border border-neutral-200 bg-white px-2 py-2 text-sm"><option value="No">No</option><option value="Yes - Makalakaw pa">Yes - Makalakaw pa</option><option value="Yes - Di na ka lakaw">Yes - Di na ka lakaw</option></select></label>
                                <label class="block text-sm"><span class="text-xs font-medium text-neutral-600">Mentally Challenged</span><select v-model="m.is_mentally_challenged" class="mt-1 w-full rounded-lg border border-neutral-200 bg-white px-2 py-2 text-sm"><option value="No">No</option><option value="Yes - dili problema sa katilingban">Yes - dili problema sa katilingban</option><option value="Yes - hasol sa katilingban">Yes - hasol sa katilingban</option></select></label>
                                <div v-if="isYouth(m.age)" class="rounded-lg border border-amber-200 bg-amber-50/50 p-2">
                                    <label class="block text-xs font-medium text-neutral-600">Out-of-School Youth (OSY)? — Youth {{ YOUTH_MIN }}–{{ YOUTH_MAX }}</label>
                                    <select v-model="m.is_osy" class="mt-1 w-full rounded-lg border border-neutral-200 bg-white px-2 py-2 text-sm">
                                        <option :value="null" disabled>Select</option>
                                        <option :value="true">Yes</option>
                                        <option :value="false">No</option>
                                    </select>
                                    <div v-if="m.is_osy === true" class="mt-2"><input v-model="m.osy_last_grade" placeholder="Last grade level attended" class="w-full rounded-lg border border-neutral-200 bg-white px-2 py-2 text-sm" /></div>
                                </div>
                                <label class="block text-sm"><span class="text-xs font-medium text-neutral-600">Bedridden Status</span><select v-model="m.bedridden_status" class="mt-1 w-full rounded-lg border border-neutral-200 bg-white px-2 py-2 text-sm"><option value="No">No</option><option value="Yes - Mabakod pa">Yes – Mabakod pa</option><option value="Yes - Di na kabakod">Yes – Di na kabakod</option></select></label>
                                <div v-if="m.sex === 'Female'" class="rounded-lg border border-pink-200 bg-pink-50/50 p-2"><label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="m.is_pregnant" class="size-4 accent-brand-500" /><span class="text-xs font-medium text-neutral-700">Pregnant</span><span class="ml-auto text-[11px] text-neutral-400">Default No</span></label></div>
                                <label class="block text-sm"><span class="text-xs font-medium text-neutral-600">LCR Registered</span><div class="mt-1 flex gap-4"><label class="flex items-center gap-1.5 text-sm"><input type="radio" value="Yes" v-model="m.lcr_registered" class="size-4 accent-brand-500" /> Yes</label><label class="flex items-center gap-1.5 text-sm"><input type="radio" value="No" v-model="m.lcr_registered" class="size-4 accent-brand-500" /> No</label></div></label>
                                <div v-if="m.lcr_registered === 'No'" class="rounded-lg border border-amber-200 bg-amber-50/50 p-2"><label class="block text-xs font-medium text-neutral-600">Reason why not LCR registered</label><input v-model="m.lcr_reason" placeholder="e.g. No birth certificate..." class="mt-1 w-full rounded-lg border border-neutral-200 bg-white px-2 py-2 text-sm" /></div>
                                <label class="block text-sm"><span class="text-xs font-medium text-neutral-600">Naay Katungdanan sa Simbahan/Barangay/Organization</span><select v-model="m.katungdanan_status" class="mt-1 w-full rounded-lg border border-neutral-200 bg-white px-2 py-2 text-sm"><option value="No">No</option><option value="Yes">Yes</option></select></label>
                                <div v-if="m.katungdanan_status === 'Yes'" class="rounded-lg border border-emerald-200 bg-emerald-50/50 p-2"><label class="block text-xs font-medium text-neutral-600">What position/responsibility?</label><input v-model="m.katungdanan_position" placeholder="e.g. Barangay Kagawad, Choir Member..." class="mt-1 w-full rounded-lg border border-neutral-200 bg-white px-2 py-2 text-sm" /></div>
                            </div>
                        </div>
                        <button type="button" class="w-full rounded-xl border border-dashed border-brand-300 bg-white py-2 text-sm font-semibold text-brand-700" @click="addMember">+ Add Member</button>
                    </div>
                    <!-- Live-in Status after roster per spec -->
                    <div class="mt-4 rounded-xl border border-neutral-100 bg-white p-3">
                        <label class="block text-sm"
                            ><span class="text-xs font-medium text-neutral-600">Live-in Status (Nag-live-in ba?)</span>
                            <select v-model="form.household.live_in_status" class="mt-1 w-full rounded-lg border border-neutral-200 bg-white px-3 py-2 text-sm">
                                <option value="No">No</option>
                                <option value="Yes">Yes</option>
                            </select></label
                        >
                        <div v-if="form.household.live_in_status === 'Yes'" class="mt-3 grid gap-3">
                            <label class="block text-sm"><span class="text-xs font-medium text-neutral-600">How many years living together?</span><input v-model="form.household.live_in_years" type="number" min="0" placeholder="e.g. 5" class="mt-1 w-full rounded-lg border border-neutral-200 bg-white px-3 py-2 text-sm" @keydown="blockNegative" @input="clampLiveIn()" /></label>
                            <label class="block text-sm"><span class="text-xs font-medium text-neutral-600">Reason why not married?</span><input v-model="form.household.live_in_reason" placeholder="e.g. Financial..." class="mt-1 w-full rounded-lg border border-neutral-200 bg-white px-3 py-2 text-sm" /></label>
                        </div>
                    </div>
                    <p v-if="form.errors.purok" class="mt-2 text-xs text-red-500">{{ form.errors.purok }}</p>
                    <p v-if="form.errors.household" class="mt-1 text-xs text-red-500">{{ form.errors.household }}</p>
                    <p v-if="form.errors.household_members" class="mt-1 text-xs text-red-500">{{ form.errors.household_members }}</p>
                </div>
                <div v-else-if="(survey as any).include_barangay" class="rounded-xl border border-neutral-200 bg-white p-4">
                    <h2 class="font-heading text-sm font-semibold text-neutral-800">Location</h2>
                    <p class="text-xs text-neutral-400">Select barangay for geographic analytics.</p>
                    <div class="mt-3 grid gap-3">
                        <label class="block text-sm"><span class="text-xs font-medium text-neutral-600">Barangay *</span>
                            <BarangaySearchSelect v-model="form.barangay_id" :barangays="barangays" placeholder="Search barangay..." />
                            <p v-if="form.errors.barangay_id" class="mt-1 text-xs text-red-500">{{ form.errors.barangay_id }}</p>
                        </label>
                        <label class="block text-sm"><span class="text-xs font-medium text-neutral-600">Purok</span><input v-model="form.purok" placeholder="e.g. Purok 4" class="mt-1 w-full rounded-xl border border-neutral-200 bg-white px-4 py-2.5 text-sm" /></label>
                    </div>
                </div>

                <div v-for="(question, index) in visibleQuestions" :key="question.id" class="border-b border-dashed border-neutral-200 pb-4 last:border-b-0 last:pb-0">
                    <template v-if="isHouseholdSurvey && isIndividual(question)">
                        <label class="font-heading mb-2 block text-sm font-semibold">
                            {{ index + 1 }}. {{ question.question_text }} <span v-if="question.is_required" class="text-red-400">*</span>
                            <span class="ml-2 rounded bg-brand-100 px-1.5 py-0.5 text-[10px] font-semibold text-brand-700">Per-member</span>
                        </label>
                        <p class="mb-2 text-xs text-neutral-500">Select household member(s) this applies to:</p>
                        <div class="space-y-2">
                            <label v-for="m in form.household_members" :key="m.name" class="flex items-center gap-2 rounded-lg border bg-white px-3 py-2 text-sm">
                                <input type="checkbox" :checked="!!form.member_answers[question.id]?.[m.name]" @change="toggleMemberForQuestion(question.id, m.name)" class="size-4 accent-brand-500" />
                                <span class="flex-1">{{ m.name || 'Unnamed' }} <span class="text-xs text-neutral-400">{{ m.age ? m.age+'y' : '' }} {{ m.sex }}</span></span>
                            </label>
                        </div>
                        <div v-if="Object.keys(form.member_answers[question.id] ?? {}).length" class="mt-3 space-y-3">
                            <div v-for="memberName in Object.keys(form.member_answers[question.id] ?? {})" :key="memberName" class="rounded-xl border border-brand-200 bg-brand-50/30 p-3">
                                <p class="text-xs font-semibold text-brand-700">{{ memberName }}</p>
                                <div class="mt-2">
                                    <select v-if="['single_choice','dropdown'].includes(question.type)" v-model="form.member_answers[question.id][memberName]" class="w-full rounded-lg border border-neutral-200 bg-white px-3 py-2 text-sm">
                                        <option value="">Select…</option>
                                        <option v-for="opt in question.options" :key="opt.label" :value="opt.label">{{ opt.label }}</option>
                                    </select>
                                    <input v-else v-model="form.member_answers[question.id][memberName]" :type="question.type==='number'?'number':'text'" :min="question.type==='number' ? 0 : undefined" placeholder="Value" class="w-full rounded-lg border border-neutral-200 bg-white px-3 py-2 text-sm" @keydown="blockNegativeIfNumber($event, question.type)" @input="clampMemberAnswer(question.id, memberName)" />
                                </div>
                            </div>
                        </div>
                    </template>
                    <template v-else>
                        <label class="font-heading mb-2 block text-sm font-semibold" :for="`q-${question.id}`">
                            {{ index + 1 }}. {{ question.question_text }} <span v-if="question.is_required" class="text-red-400">*</span>
                        </label>
                        <input
                            v-if="['text', 'number', 'date'].includes(question.type)"
                            :id="`q-${question.id}`"
                            :type="question.type === 'number' ? 'number' : question.type === 'date' ? 'date' : 'text'"
                            :min="question.type === 'number' ? 0 : undefined"
                            v-model="form.answers[question.id]"
                            :required="question.is_required"
                            class="w-full rounded-xl border border-neutral-200 px-4 py-2.5 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200"
                            @keydown="blockNegativeIfNumber($event, question.type)"
                            @input="clampAnswer(question.id)"
                        />
                        <textarea
                            v-else-if="question.type === 'textarea'"
                            :id="`q-${question.id}`"
                            rows="3"
                            v-model="form.answers[question.id]"
                            :required="question.is_required"
                            class="w-full rounded-xl border border-neutral-200 px-4 py-2.5 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200"
                        />
                        <div v-else-if="question.type === 'multiple_choice'" class="space-y-2">
                            <label v-for="option in question.options" :key="option.label" class="flex cursor-pointer items-center gap-2.5 text-sm">
                                <input type="checkbox" class="size-4 accent-brand-500" :value="option.label" :checked="((form.answers[question.id] as string[]) ?? []).includes(option.label)" @change="toggleCheckbox(question.id, option.label)" />
                                {{ option.label }}
                            </label>
                        </div>
                        <div v-else-if="question.type === 'single_choice'" class="space-y-2">
                            <label v-for="option in question.options" :key="option.label" class="flex cursor-pointer items-center gap-2.5 text-sm">
                                <input type="radio" :name="`q-${question.id}`" :value="option.label" v-model="form.answers[question.id]" class="size-4 accent-brand-500" />
                                {{ option.label }}
                            </label>
                        </div>
                        <div v-else-if="question.type === 'likert'" class="space-y-2">
                            <div class="grid gap-1.5" :style="{ gridTemplateColumns: `repeat(${question.options.length}, minmax(0, 1fr))` }">
                                <label
                                    v-for="option in question.options"
                                    :key="option.label"
                                    class="flex cursor-pointer flex-col items-center gap-1 rounded-lg border px-2 py-2.5 text-center hover:bg-neutral-50"
                                    :class="form.answers[question.id] === option.label ? 'border-brand-300 bg-brand-50 ring-1 ring-brand-200' : 'border-neutral-200 bg-white'"
                                >
                                    <input type="radio" :name="`q-${question.id}`" :value="option.label" v-model="form.answers[question.id]" class="size-4 accent-brand-500" />
                                    <span class="text-xs font-medium leading-tight">{{ option.label }}</span>
                                </label>
                            </div>
                            <div class="flex justify-between px-1 text-[10px] text-neutral-400">
                                <span>Strongly Disagree</span>
                                <span>Strongly Agree</span>
                            </div>
                        </div>
                        <select
                            v-else-if="question.type === 'dropdown'"
                            :id="`q-${question.id}`"
                            v-model="form.answers[question.id]"
                            :required="question.is_required"
                            class="w-full rounded-xl border border-neutral-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200"
                        >
                            <option value="" disabled>Select…</option>
                            <option v-for="option in question.options" :key="option.label" :value="option.label">{{ option.label }}</option>
                        </select>
                    </template>
                </div>

                <button type="submit" :disabled="form.processing" class="w-full rounded-xl bg-brand-gradient py-3 text-sm font-semibold text-white shadow-brand transition active:scale-[0.99] disabled:opacity-60">
                    {{ form.processing ? 'Submitting…' : 'Submit Response' }}
                </button>
                <p v-if="form.errors.answers" class="text-center text-xs text-red-500">{{ form.errors.answers }}</p>
            </form>

            <p class="text-center text-[11px] text-neutral-400">HULAGWAY · Mapping Community Realities Toward Informed Extension Planning</p>
        </div>
    </div>
</template>
