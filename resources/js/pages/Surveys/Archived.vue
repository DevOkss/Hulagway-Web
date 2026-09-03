<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItemType } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArchiveRestore, Eye, Trash2 } from 'lucide-vue-next';

interface Survey {
    id: number;
    title: string;
    description: string | null;
    status: string;
    barangay: { name: string } | null;
    creator: { name: string } | null;
    responses_count: number;
    created_at: string | null;
    published_at: string | null;
}

defineProps<{ surveys: Survey[] }>();

const breadcrumbs: BreadcrumbItemType[] = [
    { title: 'Surveys', href: '/surveys' },
    { title: 'Archived', href: '/surveys/archived' },
];

const page = usePage();
const flash = (page.props as any).flash?.success;

const formatDate = (iso: string | null) => {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    } catch {
        return iso;
    }
};

const restore = (id: number) => router.post(`/surveys/${id}/restore`);
const destroy = (id: number) => {
    if (!confirm('Permanently delete this archived survey? This cannot be undone.')) return;
    router.delete(`/surveys/${id}`);
};
</script>

<template>
    <Head title="Archived Surveys" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4 sm:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="font-heading text-xl font-semibold sm:text-2xl">Archived Surveys</h1>
                    <p class="text-sm text-neutral-500">Surveys archived by the CAES Administrator. Restore to make them active again.</p>
                </div>
                <Button variant="outline" as-child><Link href="/surveys">Back to surveys</Link></Button>
            </div>

            <div class="inline-flex gap-1 rounded-xl bg-neutral-100 p-1 dark:bg-neutral-800">
                <Link href="/surveys" class="rounded-lg px-3 py-1.5 text-xs font-medium text-neutral-500 hover:text-neutral-700">Active</Link>
                <Link href="/surveys/archived" class="rounded-lg bg-white px-3 py-1.5 text-xs font-medium shadow-sm text-neutral-900">Archived</Link>
            </div>

            <p v-if="flash" class="rounded-lg bg-emerald-500/10 px-4 py-2 text-sm text-emerald-600">{{ flash }}</p>

            <div v-for="survey in surveys" :key="survey.id" class="flex flex-col gap-3 hcard-p md:flex-row md:items-center opacity-80">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="font-heading truncate font-semibold">{{ survey.title }}</h2>
                        <span class="rounded-full bg-neutral-800 px-2 py-0.5 text-[11px] font-semibold capitalize text-white">archived</span>
                    </div>
                    <p class="mt-0.5 line-clamp-1 text-sm text-neutral-500">{{ survey.description || 'No description' }}</p>
                    <p class="mt-1 text-xs text-neutral-400">
                        {{ survey.responses_count.toLocaleString() }} responses · by {{ survey.creator?.name ?? '—' }}
                        · {{ survey.barangay?.name ?? 'All Barangays' }}
                        · Posted {{ formatDate(survey.published_at ?? survey.created_at) }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Button variant="outline" size="sm" class="gap-1.5" @click="restore(survey.id)"><ArchiveRestore class="size-3.5" /> Restore</Button>
                    <Button variant="ghost" size="icon" as-child title="View">
                        <Link :href="`/surveys/${survey.id}`"><Eye class="size-4" /></Link>
                    </Button>
                    <Button variant="ghost" size="icon" title="Delete permanently" class="text-red-500 hover:text-red-600" @click="destroy(survey.id)">
                        <Trash2 class="size-4" />
                    </Button>
                </div>
            </div>

            <div v-if="!surveys.length" class="rounded-2xl border border-dashed border-neutral-300 bg-neutral-50 p-10 text-center text-sm text-neutral-500">
                No archived surveys.
            </div>
        </div>
    </AppLayout>
</template>
