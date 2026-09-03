<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItemType } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Archive, ArchiveRestore, Copy, Eye, FileDown, Pencil, PlusCircle, PowerOff } from 'lucide-vue-next';
import { ref } from 'vue';

interface Survey {
    id: number;
    title: string;
    description: string | null;
    status: string;
    public_token: string | null;
    barangay: { name: string } | null;
    creator: { name: string } | null;
    responses_count: number;
    created_at: string | null;
    published_at: string | null;
}

const props = defineProps<{ surveys: Survey[]; archivedCount: number }>();

const breadcrumbs: BreadcrumbItemType[] = [
    { title: 'Surveys', href: '/surveys' },
];

const page = usePage();
const copied = ref<number | null>(null);
const flash = (page.props as any).flash?.success;

const statusTint = (status: string) =>
    ({
        draft: 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300',
        published: 'bg-emerald-500/10 text-emerald-600',
        deactivated: 'bg-amber-500/10 text-amber-600',
        archived: 'bg-neutral-800 text-white dark:bg-neutral-700 dark:text-neutral-200',
    })[status] ?? 'bg-neutral-100 text-neutral-600';

const publicUrl = (survey: Survey) => `${window.location.origin}/s/${survey.public_token}`;

const copyLink = async (survey: Survey) => {
    await navigator.clipboard.writeText(publicUrl(survey));
    copied.value = survey.id;
    setTimeout(() => (copied.value = null), 1500);
};

const formatDate = (iso: string | null) => {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    } catch {
        return iso;
    }
};

const publish = (id: number) => router.post(`/surveys/${id}/publish`);
const deactivate = (id: number) => router.post(`/surveys/${id}/deactivate`);
const archive = (id: number) => {
    if (!confirm('Archive this survey? It will be hidden from active lists and cannot receive responses.')) return;
    router.post(`/surveys/${id}/archive`);
};
</script>

<template>
    <Head title="Surveys" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4 sm:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="font-heading text-xl font-semibold sm:text-2xl">Surveys</h1>
                    <p class="text-sm text-neutral-500">Create and manage community surveys.</p>
                </div>
                <Button as-child>
                    <Link href="/surveys/create" class="gap-1.5">
                        <PlusCircle class="size-4" /> New Survey
                    </Link>
                </Button>
            </div>

            <div class="flex items-center justify-between">
                <div class="inline-flex gap-1 rounded-xl bg-neutral-100 p-1 dark:bg-neutral-800">
                    <Link href="/surveys" class="rounded-lg bg-white px-3 py-1.5 text-xs font-medium shadow-sm text-neutral-900">Active</Link>
                    <Link href="/surveys/archived" class="rounded-lg px-3 py-1.5 text-xs font-medium text-neutral-500 hover:text-neutral-700"
                        >Archived <span v-if="archivedCount" class="ml-1 rounded-full bg-neutral-200 px-1.5 py-0.5 text-[10px]">{{ archivedCount }}</span></Link
                    >
                </div>
                <span class="text-xs text-neutral-400">{{ surveys.length }} active</span>
            </div>

            <p v-if="flash" class="rounded-lg bg-emerald-500/10 px-4 py-2 text-sm text-emerald-600">{{ flash }}</p>

            <div
                v-for="survey in surveys"
                :key="survey.id"
                class="flex flex-col gap-3 hcard-p md:flex-row md:items-center "
            >
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="font-heading truncate font-semibold">{{ survey.title }}</h2>
                        <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize" :class="statusTint(survey.status)">
                            {{ survey.status }}
                        </span>
                    </div>
                    <p class="mt-0.5 line-clamp-1 text-sm text-neutral-500">{{ survey.description || 'No description' }}</p>
                    <p class="mt-1 text-xs text-neutral-400">
                        {{ survey.responses_count.toLocaleString() }} responses · by {{ survey.creator?.name ?? '—' }}
                        · {{ survey.barangay?.name ?? 'All Barangays' }}
                        · Posted {{ formatDate(survey.published_at ?? survey.created_at) }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Button v-if="survey.public_token && survey.status === 'published'" variant="outline" size="sm" @click="copyLink(survey)" class="gap-1.5">
                        <Copy class="size-3.5" /> {{ copied === survey.id ? 'Copied!' : 'Public link' }}
                    </Button>
                    <Button v-if="survey.status !== 'published'" variant="outline" size="sm" @click="publish(survey.id)">Publish</Button>
                    <Button v-if="survey.status === 'published'" variant="outline" size="sm" @click="deactivate(survey.id)" class="gap-1.5">
                        <PowerOff class="size-3.5" /> Deactivate
                    </Button>
                    <Button v-if="survey.status !== 'published' && survey.status !== 'archived'" variant="ghost" size="icon" title="Archive" @click="archive(survey.id)">
                        <Archive class="size-4" />
                    </Button>
                    <Button variant="ghost" size="icon" as-child title="View & responses">
                        <Link :href="`/surveys/${survey.id}`"><Eye class="size-4" /></Link>
                    </Button>
                    <Button v-if="survey.status !== 'published' && survey.status !== 'archived'" variant="ghost" size="icon" as-child title="Edit">
                        <Link :href="`/surveys/${survey.id}/edit`"><Pencil class="size-4" /></Link>
                    </Button>
                    <Button variant="ghost" size="icon" as-child title="Export responses">
                        <a :href="`/reports/surveys/${survey.id}/xlsx`"><FileDown class="size-4" /></a>
                    </Button>
                </div>
            </div>

            <div v-if="!surveys.length" class="rounded-2xl border border-dashed border-brand-300 bg-brand-50 p-10 text-center text-sm text-brand-500">
                No surveys yet — create your first one.
            </div>
        </div>
    </AppLayout>
</template>
