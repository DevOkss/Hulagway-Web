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

const props = defineProps<{
    barangays: Array<{ id: number; name: string }>;
}>();

const breadcrumbs: BreadcrumbItemType[] = [
    { title: 'Surveys', href: '/surveys' },
    { title: 'Create', href: '/surveys/create' },
];

const form = useForm({
    title: '',
    description: '',
    type: 'generic' as 'household' | 'generic',
    barangay_id: '' as string | number,
    include_barangay: false as boolean,
    questions: [] as QuestionDraft[],
});

watch(
    () => form.type,
    (v) => {
        if (v === 'household') form.include_barangay = !!form.barangay_id;
    },
);

const submit = () => {
    if (form.type === 'generic' && !form.include_barangay) (form as any).barangay_id = null;
    else if (!form.barangay_id) (form as any).barangay_id = null;
    // For household, include_barangay reflects whether a specific barangay is chosen (false = All)
    if (form.type === 'household') (form as any).include_barangay = !!form.barangay_id;
    form.post('/surveys', {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Create Survey" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form class="mx-auto flex w-full max-w-4xl flex-col space-y-5 p-4 sm:p-6" @submit.prevent="submit">
            <div class="flex items-center justify-between">
                <h1 class="font-heading text-xl font-semibold sm:text-2xl">New Survey</h1>
                <div class="flex gap-2">
                    <Button variant="outline" as-child><Link href="/surveys">Cancel</Link></Button>
                    <Button type="submit" :disabled="form.processing">Save Draft</Button>
                </div>
            </div>

            <section class="hcard-p ">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <Label for="title" class="mb-1 block text-xs text-neutral-500">Title</Label>
                        <Input id="title" v-model="form.title" required placeholder="e.g. Community Needs Assessment 2026" />
                        <p v-if="form.errors.title" class="mt-1 text-xs text-red-500">{{ form.errors.title }}</p>
                    </div>
                    <div v-if="form.type === 'household'">
                        <Label for="barangay_id" class="mb-1 block text-xs text-neutral-500">Target Barangay</Label>
                        <BarangaySearchSelect
                            id="barangay_id"
                            v-model="form.barangay_id"
                            :barangays="props.barangays"
                            :allow-all="true"
                            all-label="All Barangays (City-wide)"
                            placeholder="Search barangay... (leave empty for All)"
                        />
                        <p class="mt-1 text-[11px] text-neutral-500">Leave as “All Barangays” for city-wide household survey, or select a specific barangay.</p>
                        <p v-if="form.errors.barangay_id" class="mt-1 text-xs text-red-500">{{ form.errors.barangay_id }}</p>
                    </div>
                    <div v-else class="space-y-2">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" v-model="form.include_barangay" class="size-4 accent-brand-500" />
                            <span class="text-xs font-medium text-neutral-700">Include Barangay selection</span>
                        </label>
                        <p class="text-[11px] text-neutral-500">If enabled, respondents will be asked to select a barangay. If not, barangay will not be collected.</p>
                        <div v-if="form.include_barangay">
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
                        <Label class="block text-xs font-medium text-neutral-600">Survey Type *</Label>
                        <div class="mt-1.5 flex gap-6">
                            <label class="flex items-center gap-2 text-sm">
                                <input type="radio" value="household" v-model="form.type" class="accent-brand-500" /> Community Household Survey
                            </label>
                            <label class="flex items-center gap-2 text-sm">
                                <input type="radio" value="generic" v-model="form.type" class="accent-brand-500" /> Generic Survey
                            </label>
                        </div>
                        <p class="mt-1 text-[11px] text-neutral-500">
                            <span v-if="form.type === 'household'">Standardized household + members structure (Maquilao-Purok 4) will be auto-created. No manual questions needed.</span>
                            <span v-else>Flexible dynamic questions for any purpose (training, satisfaction, etc.).</span>
                        </p>
                        <p v-if="form.errors.type" class="mt-1 text-xs text-red-500">{{ form.errors.type }}</p>
                    </div>
                    <div class="md:col-span-2">
                        <Label for="description" class="mb-1 block text-xs text-neutral-500">Description</Label>
                        <Textarea id="description" v-model="form.description" rows="2" placeholder="Purpose of this survey…" />
                    </div>
                </div>
            </section>

            <div v-if="form.type === 'generic'" class="w-full">
                <h2 class="font-heading mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-500">Questions</h2>
                <QuestionEditor v-model="form.questions" />
                <p v-if="form.errors.questions" class="mt-2 text-xs text-red-500">{{ form.errors.questions }}</p>
            </div>
            <div v-else class="flex min-h-[280px] w-full flex-col justify-center rounded-xl border border-dashed border-brand-300 bg-brand-50 p-6 text-center">
                <p class="text-sm font-medium text-brand-700">Community Household Survey</p>
                <p class="mx-auto mt-1 max-w-lg text-xs text-brand-600/80">Household Information + Household Conditions + Household Members (multiple) will be auto-created from the Maquilao-Purok 4 template. No need to add questions manually.</p>
            </div>
        </form>
    </AppLayout>
</template>
