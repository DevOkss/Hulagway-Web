<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItemType } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Calendar, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';

interface ActivityDay {
    id: number;
    day_number: number;
    activity_date: string;
}

interface Sdg { id: number; number: number; code: string; title: string; short_title: string; color: string; icon_url: string }
interface Activity {
    id: number;
    title: string;
    description: string | null;
    program_id: number | null;
    barangay_id: number | null;
    location: string | null;
    start_date: string;
    end_date: string | null;
    status: string;
    days: ActivityDay[];
    sdgs?: Sdg[];
}

const props = defineProps<{
    activity: Activity;
    programs: Array<{ id: number; name: string }>;
    barangays: Array<{ id: number; name: string }>;
    sdgs: Sdg[];
}>();

const breadcrumbs: BreadcrumbItemType[] = [
    { title: 'Extension Activities', href: '/extensions' },
    { title: props.activity.title, href: `/extensions/${props.activity.id}` },
    { title: 'Edit', href: '' },
];

const form = useForm({
    title: props.activity.title,
    description: props.activity.description ?? '',
    program_id: props.activity.program_id ?? '',
    barangay_id: props.activity.barangay_id ?? '',
    location: props.activity.location ?? '',
    days: (props.activity.days ?? []).map((d) => d.activity_date?.substring(0, 10) ?? ''),
    sdg_ids: (props.activity.sdgs ?? []).map((s) => s.id) as number[],
});
const toggleSdg = (id: number) => {
    const idx = form.sdg_ids.indexOf(id);
    if (idx >= 0) form.sdg_ids.splice(idx, 1);
    else form.sdg_ids.push(id);
};

// Number of activity days — each day gets its own selectable date (fragmented schedule)
const dayCount = computed({
    get: () => form.days.length,
    set: (n: number) => {
        const target = Math.max(1, Math.min(60, n || 1));
        while (form.days.length < target) form.days.push('');
        while (form.days.length > target) form.days.pop();
    },
});

const submit = () => {
    form.days = form.days.filter((d) => d !== '');
    form.put(`/extensions/${props.activity.id}`, { preserveScroll: true });
};
</script>

<template>
    <Head :title="`Edit — ${activity.title}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form class="mx-auto max-w-4xl space-y-5 p-4 sm:p-6" @submit.prevent="submit">
            <div class="flex items-center justify-between">
                <h1 class="font-heading text-xl font-semibold sm:text-2xl">Edit Activity</h1>
                <div class="flex gap-2">
                    <Button variant="outline" as-child><Link :href="`/extensions/${activity.id}`">Cancel</Link></Button>
                    <Button type="submit" :disabled="form.processing">Save Changes</Button>
                </div>
            </div>

            <section class="hcard-p ">
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <Label for="title" class="mb-1 block text-xs text-neutral-500">Title</Label>
                        <Input id="title" v-model="form.title" required />
                    </div>
                    <div>
                        <Label for="program_id" class="mb-1 block text-xs text-neutral-500">Program</Label>
                        <select id="program_id" v-model="form.program_id" required class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                            <option v-for="p in programs" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </select>
                    </div>
                    <div>
                        <Label for="barangay_id" class="mb-1 block text-xs text-neutral-500">Barangay</Label>
                        <select id="barangay_id" v-model="form.barangay_id" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                            <option value="">City-wide</option>
                            <option v-for="b in barangays" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <Label for="location" class="mb-1 block text-xs text-neutral-500">Specific Location</Label>
                        <Input id="location" v-model="form.location" />
                    </div>
                </div>

                <!-- Fragmented activity days -->
                <div class="mt-4 rounded-xl border border-brand-200 bg-brand-50/60 p-4">
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <Label for="day-count" class="mb-1 flex items-center gap-1.5 block text-xs font-semibold text-brand-700">
                                <Calendar class="size-3.5" /> Number of activity days
                            </Label>
                            <p class="text-[11px] text-neutral-500">Pick each date separately — days don't need to be consecutive.</p>
                        </div>
                        <Input id="day-count" v-model.number="dayCount" type="number" min="1" max="60" class="w-24" />
                    </div>

                    <div class="mt-3 space-y-2">
                        <div v-for="(_, index) in form.days" :key="index" class="flex items-center gap-2">
                            <span class="w-16 shrink-0 text-xs font-semibold text-neutral-600">Day {{ index + 1 }}</span>
                            <Input
                                :id="`day-${index}`"
                                v-model="form.days[index]"
                                type="date"
                                :class="form.errors[`days.${index}`] ? 'border-red-400' : ''"
                            />
                            <Button
                                v-if="form.days.length > 1"
                                type="button"
                                variant="ghost"
                                size="icon"
                                title="Remove day"
                                @click="form.days.splice(index, 1); dayCount = form.days.length"
                            >
                                <Trash2 class="size-4 text-neutral-400" />
                            </Button>
                        </div>
                    </div>
                    <p v-if="form.errors.days" class="mt-2 text-xs text-red-500">{{ form.errors.days }}</p>
                    <p class="mt-2 text-[11px] italic text-neutral-400">Status is automatic: planned before the first date, ongoing between first and last, completed after.</p>
                </div>

                <div class="mt-4">
                    <Label for="description" class="mb-1 block text-xs text-neutral-500">Description</Label>
                    <Textarea id="description" v-model="form.description" rows="3" />
                </div>

                <!-- SDGs -->
                <div class="mt-4 rounded-xl border border-neutral-200 bg-white p-4">
                    <Label class="mb-1 block text-xs font-semibold text-neutral-600">Sustainable Development Goals <span class="text-red-500">*</span></Label>
                    <p class="mb-3 text-[11px] text-neutral-500">Select at least one SDG this activity contributes to. Multiple allowed.</p>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                        <button
                            v-for="s in sdgs"
                            :key="s.id"
                            type="button"
                            @click="toggleSdg(s.id)"
                            class="group flex items-center gap-2 rounded-xl border p-2 text-left transition"
                            :class="form.sdg_ids.includes(s.id) ? 'bg-neutral-50 shadow-sm' : 'border-neutral-200 hover:border-neutral-300 bg-white'"
                            :style="form.sdg_ids.includes(s.id) ? { borderColor: s.color, backgroundColor: s.color + '12' } : {}"
                        >
                            <img :src="s.icon_url" :alt="s.title" class="size-10 shrink-0 rounded-md object-cover border" :style="{ borderColor: s.color }" />
                            <span class="min-w-0">
                                <span class="block text-[11px] font-bold leading-tight" :style="{ color: form.sdg_ids.includes(s.id) ? s.color : '#52525b' }">SDG {{ s.number }}</span>
                                <span class="block text-[11px] leading-tight text-neutral-600 line-clamp-2">{{ s.short_title || s.title }}</span>
                            </span>
                            <span v-if="form.sdg_ids.includes(s.id)" class="ml-auto size-4 shrink-0 rounded-full flex items-center justify-center text-[10px] font-bold text-white" :style="{ backgroundColor: s.color }">✓</span>
                        </button>
                    </div>
                    <p v-if="form.errors.sdg_ids" class="mt-2 text-xs text-red-500">{{ form.errors.sdg_ids }}</p>
                </div>
            </section>
        </form>
    </AppLayout>
</template>
