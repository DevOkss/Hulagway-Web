<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Plus, Trash2, GripVertical } from 'lucide-vue-next';

export interface QuestionDraft {
    id?: number;
    question_text: string;
    type: string;
    is_required: boolean;
    code?: string | null;
    data_scope: string;
    map_enabled: boolean;
    options: string[];
}

const model = defineModel<QuestionDraft[]>({ required: true });

const questionTypes = [
    { value: 'text', label: 'Short text' },
    { value: 'textarea', label: 'Paragraph' },
    { value: 'number', label: 'Number' },
    { value: 'single_choice', label: 'Single choice' },
    { value: 'multiple_choice', label: 'Checkboxes' },
    { value: 'dropdown', label: 'Dropdown' },
    { value: 'date', label: 'Date' },
    { value: 'likert', label: 'Likert Scale' },
];

const hulagwayCodes = [
    { value: '', label: '— No hulagway code' },
    { value: 'seniors_90', label: 'Senior 90+ (AG)' },
    { value: 'bedridden_cat2', label: 'Bedridden Cat2 (L+M)' },
    { value: 'mentally_cat2', label: 'Mentally Challenged Cat2 (O+P)' },
    { value: 'pwd_cat2', label: 'PWD Cat2 (R+S)' },
    { value: 'osy', label: 'Out-of-School Youth (U)' },
    { value: 'live_in', label: 'Live-in (Z)' },
    { value: 'poor_cat2', label: 'Poor Family Cat2 (AM+AN)' },
    { value: 'pregnant', label: 'Pregnant (AI)' },
];

const hasOptions = (type: string) => ['single_choice', 'multiple_choice', 'dropdown', 'likert'].includes(type);

const addQuestion = () => {
    model.value.push({
        question_text: '',
        type: 'text',
        is_required: false,
        code: null,
        data_scope: 'response',
        map_enabled: true,
        options: hasOptions('text') ? [] : [],
    });
};

const removeQuestion = (index: number) => model.value.splice(index, 1);

const onTypeChange = (question: QuestionDraft) => {
    if (!hasOptions(question.type)) question.options = [];
    else if (question.type === 'likert' && question.options.length === 0) {
        question.options = ['Strongly Disagree', 'Disagree', 'Neutral', 'Agree', 'Strongly Agree'];
    }
};
</script>

<template>
    <div class="space-y-4">
        <div
            v-for="(question, index) in model"
            :key="index"
            class="rounded-2xl border border-neutral-200/80 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900"
        >
            <div class="flex items-center gap-2">
                <GripVertical class="size-4 shrink-0 text-neutral-300" />
                <span class="font-heading text-xs font-bold text-brand-500">Q{{ index + 1 }}</span>
                <div class="ml-auto">
                    <Button variant="ghost" size="icon" @click="removeQuestion(index)" title="Remove question">
                        <Trash2 class="size-4 text-red-400" />
                    </Button>
                </div>
            </div>

            <div class="mt-3 grid gap-3 md:grid-cols-[1fr_200px_auto]">
                <div>
                    <Label :for="`q-text-${index}`" class="mb-1 block text-xs text-neutral-500">Question</Label>
                    <Input :id="`q-text-${index}`" v-model="question.question_text" placeholder="e.g. What is your primary source of income?" />
                </div>
                <div>
                    <Label :for="`q-type-${index}`" class="mb-1 block text-xs text-neutral-500">Type</Label>
                    <select
                        :id="`q-type-${index}`"
                        v-model="question.type"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                        @change="onTypeChange(question)"
                    >
                        <option v-for="t in questionTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                    </select>
                </div>
                <div class="flex items-end gap-2 pb-0.5">
                    <Checkbox
                        :id="`q-req-${index}`"
                        :model-value="question.is_required"
                        @update:model-value="(v: boolean | 'indeterminate') => (question.is_required = v === true)"
                    />
                    <Label :for="`q-req-${index}`" class="text-xs text-neutral-500">Required</Label>
                </div>
            </div>



            <div v-if="hasOptions(question.type)" class="mt-3 space-y-2">
                <Label class="text-xs text-neutral-500">Options</Label>
                <div v-for="(_, optionIndex) in question.options" :key="optionIndex" class="flex items-center gap-2">
                    <Input v-model="question.options[optionIndex]" placeholder="Option label" />
                    <Button variant="ghost" size="icon" @click="question.options.splice(optionIndex, 1)">
                        <Trash2 class="size-3.5 text-neutral-400" />
                    </Button>
                </div>
                <Button variant="outline" size="sm" type="button" class="gap-1.5" @click="question.options.push('')">
                    <Plus class="size-3.5" /> Add option
                </Button>
            </div>
        </div>

        <Button variant="outline" type="button" class="gap-1.5 border-dashed" @click="addQuestion">
            <Plus class="size-4" /> Add Question
        </Button>
    </div>
</template>
