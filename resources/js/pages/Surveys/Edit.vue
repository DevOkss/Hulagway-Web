<script setup lang="ts">
import BarangaySearchSelect from '@/components/BarangaySearchSelect.vue';
import QuestionEditor, { type QuestionDraft } from '@/components/Survey/QuestionEditor.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItemType } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

interface Survey {
    id: number;
    title: string;
    description: string | null;
    type: string;
    barangay_id: number | null;
    status: string;
    questions: Array<{
        id: number;
        question_text: string;
        type: string;
        is_required: boolean;
        options: Array<{ id?: number; label: string }>;
    }>;
}

const props = defineProps<{
    survey: Survey;
    barangays: Array<{ id: number; name: string }>;
}>();

const breadcrumbs: BreadcrumbItemType[] = [
    { title: 'Surveys', href: '/surveys' },
    { title: props.survey.title, href: `/surveys/${props.survey.id}` },
    { title: 'Edit', href: '' },
];

const form = useForm({
    title: props.survey.title,
    description: props.survey.description ?? '',
    type: (props.survey as any).type ?? 'generic',
    barangay_id: props.survey.barangay_id ?? '',
    include_barangay: (props.survey as any).include_barangay ?? ((props.survey as any).type === 'household'),
    questions: props.survey.questions.map(
        (q): QuestionDraft => ({
            id: (q as any).id,
            question_text: q.question_text,
            type: q.type,
            is_required: !!q.is_required,
            code: (q as any).code ?? null,
            data_scope: (q as any).data_scope ?? 'response',
            map_enabled: (q as any).map_enabled ?? true,
            options: q.options.map((o) => o.label),
        }),
    ),
});

watch(
    () => (form as any).type,
    (v) => {
        if (v === 'household') (form as any).include_barangay = !!(form as any).barangay_id;
    },
);

const submit = () => {
    if ((form as any).type === 'generic' && !(form as any).include_barangay) (form as any).barangay_id = null;
    else if (!(form as any).barangay_id) (form as any).barangay_id = null;
    if ((form as any).type === 'household') (form as any).include_barangay = !!(form as any).barangay_id;
    form.put(`/surveys/${props.survey.id}`, { preserveScroll: true });
};
</script>

<template>
    <Head :title="`Edit — ${survey.title}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form class="mx-auto flex w-full max-w-4xl flex-col space-y-5 p-4 sm:p-6" @submit.prevent="submit">
            <div class="flex items-center justify-between">
                <h1 class="font-heading text-xl font-semibold sm:text-2xl">Edit Survey</h1>
                <div class="flex gap-2">
                    <Button variant="outline" as-child><Link :href="`/surveys/${survey.id}`">Cancel</Link></Button>
                    <Button type="submit" :disabled="form.processing">Save Changes</Button>
                </div>
            </div>

            <section class="hcard-p ">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <Label for="title" class="mb-1 block text-xs text-neutral-500">Title</Label>
                        <Input id="title" v-model="form.title" required />
                    </div>
                    <div v-if="(form as any).type === 'household'">
                        <Label for="barangay_id" class="mb-1 block text-xs text-neutral-500">Target Barangay</Label>
                        <BarangaySearchSelect
                            id="barangay_id"
                            v-model="form.barangay_id"
                            :barangays="props.barangays"
                            :allow-all="true"
                            all-label="All Barangays (City-wide)"
                            placeholder="Search barangay... (leave empty for All)"
                        />
                        <p class="mt-1 text-[11px] text-neutral-500">Leave as “All Barangays” for city-wide, or select a specific barangay.</p>
                        <p v-if="form.errors.barangay_id" class="mt-1 text-xs text-red-500">{{ form.errors.barangay_id }}</p>
                    </div>
                    <div v-else class="space-y-2">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" v-model="(form as any).include_barangay" class="size-4 accent-brand-500" />
                            <span class="text-xs font-medium text-neutral-700">Include Barangay selection</span>
                        </label>
                        <p class="text-[11px] text-neutral-500">If enabled, respondents will select a barangay.</p>
                        <div v-if="(form as any).include_barangay">
                            <Label for="barangay_id_generic" class="mb-1 block text-xs text-neutral-500">Target Barangay *</Label>
                            <BarangaySearchSelect
                                id="barangay_id_generic"
                                v-model="form.barangay_id"
                                :barangays="props.barangays"
                                :allow-all="false"
                                placeholder="Search barangay..."
                            />
                            <p v-if="form.errors.barangay_id" class="mt-1 text-xs text-red-500">{{ form.errors.barangay_id }}</p>
                        </div>
                    </div>
                    <div class="md:col-span-2">
                        <Label class="block text-xs font-medium text-neutral-600">Survey Type</Label>
                        <div class="mt-1.5 flex gap-6">
                            <label class="flex items-center gap-2 text-sm"><input type="radio" value="household" v-model="(form as any).type" class="accent-brand-500" /> Community Household Survey</label>
                            <label class="flex items-center gap-2 text-sm"><input type="radio" value="generic" v-model="(form as any).type" class="accent-brand-500" /> Generic Survey</label>
                        </div>
                    </div>
                    <div class="md:col-span-2">
                        <Label for="description" class="mb-1 block text-xs text-neutral-500">Description</Label>
                        <Textarea id="description" v-model="form.description" rows="2" />
                    </div>
                </div>
            </section>

            <div v-if="(form as any).type === 'generic'" class="w-full">
                <QuestionEditor v-model="form.questions" />
            </div>
            <div v-else class="flex min-h-[280px] w-full flex-col justify-center rounded-xl border border-dashed border-brand-300 bg-brand-50 p-6 text-center">
                <p class="text-sm font-medium text-brand-700">Community Household Survey</p>
                <p class="mx-auto mt-1 max-w-lg text-xs text-brand-600/80">Standardized household + members structure. Questions are auto-managed.</p>
            </div>
        </form>
    </AppLayout>
</template>
