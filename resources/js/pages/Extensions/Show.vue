<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItemType } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Calendar, ChevronDown, Download, FileDown, FileSpreadsheet, FileText, GanttChart, ImageIcon, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

// Helpers for Y-m-d date strings — avoid UTC shift (app timezone is Asia/Manila)
// Treat Y-m-d as local date without time, not as UTC midnight
const ymdToDate = (ymd: string) => new Date(ymd.substring(0, 10) + 'T00:00:00');
const formatYMD = (ymd: string, opts?: Intl.DateTimeFormatOptions) => {
    if (!ymd) return '';
    return ymdToDate(ymd).toLocaleDateString(undefined, { ...opts, timeZone: 'Asia/Manila' });
};
const todayIso = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila' }).format(new Date());

interface Document {
    id: number;
    file_path: string;
    original_name: string;
    mime_type: string | null;
    activity_date: string | null;
    progress: number | null;
    caption: string | null;
    created_at: string;
}

interface DailyTask {
    id: number;
    title: string;
    scheduled_date: string;
    description: string | null;
}

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
    status: string;
    progress: number;
    location: string | null;
    start_date: string;
    end_date: string | null;
    faculty_participants: number;
    student_participants: number;
    beneficiaries: number;
    program: { id: number; name: string } | null;
    barangay: { id?: number; name: string } | null;
    creator: { name: string } | null;
    documents: Document[];
    daily_tasks: DailyTask[];
    days?: ActivityDay[];
    collaborators?: Array<{ id: number; name: string }>;
    sdgs?: Sdg[];
}

const props = defineProps<{
    activity: Activity;
    barangays: Array<{ id: number; name: string }>;
    collaborationPrograms: Record<string, Array<{ id: number; name: string; institute_id: number }>>;
    canManage: boolean;
}>();

const breadcrumbs: BreadcrumbItemType[] = [
    { title: 'Extension Activities', href: '/extensions' },
    { title: props.activity.title, href: '' },
];

// Manage = lead coordinator only (server-verified via canManage prop)
const isLead = computed(() => props.canManage);
const canDelete = computed(() => isLead.value && props.activity.status !== 'completed');
const page = usePage();
const canExport = computed(() => {
    const r = (page.props.auth as any)?.user?.role ?? '';
    return r === 'coordinator' || r === 'officer';
});
const deleteDialogOpen = ref(false);
const confirmDelete = () => {
    router.delete(`/extensions/${props.activity.id}`, {
        onSuccess: () => (deleteDialogOpen.value = false),
    });
};

const statusTint = computed(
    () =>
        ({
            planned: 'bg-sky-500/10 text-sky-600',
            ongoing: 'bg-amber-500/10 text-amber-600',
            completed: 'bg-emerald-500/10 text-emerald-600',
            cancelled: 'bg-red-500/10 text-red-500',
        })[props.activity.status] ?? '',
);

const statusHint = computed(() => {
    if (props.activity.status === 'planned') return 'Not yet started — starts on ' + formatYMD(props.activity.start_date);
    if (props.activity.status === 'ongoing') return 'Ongoing — between start and end dates';
    if (props.activity.status === 'completed') return 'Completed — past end date';
    return 'Cancelled';
});

const removeDocument = (id: number) => {
    router.delete(`/extensions/${props.activity.id}/documents/${id}`, { preserveScroll: true });
};

// Toggle collaborating program (lead coordinator only) — sync full array
const toggleCollaborator = (programId: number, event: Event) => {
    const checked = (event.target as HTMLInputElement).checked;
    const currentIds = (props.activity.collaborators ?? []).map((c) => c.id);
    let newIds: number[];
    if (checked) {
        newIds = [...currentIds, programId];
    } else {
        newIds = currentIds.filter((id) => id !== programId);
    }
    // Never send lead program as collaborator
    newIds = newIds.filter((id) => id !== props.activity.program?.id);
    router.post(
        `/extensions/${props.activity.id}/collaborators`,
        { collaborator_program_ids: [...new Set(newIds)] },
        { preserveScroll: true },
    );
};

const groupedDocuments = computed(() => {
    const groups: Record<string, Document[]> = {};
    for (const doc of props.activity.documents) {
        const key = doc.activity_date ? formatYMD(doc.activity_date) : 'Initial Document';
        if (!groups[key]) groups[key] = [];
        groups[key].push(doc);
    }
    // Sort groups by date descending, initial documents last
    const sortedKeys = Object.keys(groups).sort((a, b) => {
        if (a === 'Initial Document') return 1;
        if (b === 'Initial Document') return -1;
        return new Date(b).getTime() - new Date(a).getTime();
    });
    return sortedKeys.map((k) => ({ date: k, docs: groups[k] }));
});

// Gantt — daily tasks per date
const dailyTasks = computed<DailyTask[]>(() => (props.activity as any).daily_tasks ?? (props.activity as any).dailyTasks ?? []);

const activityDays = computed<ActivityDay[]>(() => (props.activity as any).days ?? []);

// Gantt columns are the fragmented activity days themselves (Day 1..N), not a continuous range
const timelineDates = computed(() => {
    if (activityDays.value.length) {
        return [...activityDays.value]
            .map((d) => d.activity_date?.substring(0, 10))
            .filter(Boolean)
            .sort() as string[];
    }
    // Fallback: single day from start_date
    return props.activity.start_date ? [props.activity.start_date.substring(0, 10)] : [];
});

// Inclusive dates for document upload — every date from start to end inclusive (kept for reference, not used for select which now uses timelineDates only)
const inclusiveDates = computed(() => {
    const startStr = props.activity.start_date?.substring(0, 10);
    const endStr = (props.activity.end_date ?? props.activity.start_date)?.substring(0, 10);
    if (!startStr || !endStr) return timelineDates.value;
    const dates: string[] = [];
    // Increment Y-m-d without UTC shift (use local Date with Y,M,D)
    const [sy, sm, sd] = startStr.split('-').map(Number);
    const [ey, em, ed] = endStr.split('-').map(Number);
    const start = new Date(sy, sm - 1, sd);
    const end = new Date(ey, em - 1, ed);
    for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        dates.push(`${y}-${m}-${day}`);
    }
    return dates.length ? dates : timelineDates.value;
});

const uploadForm = useForm({
    documents: [] as File[],
    document_activity_date: timelineDates.value.includes(todayIso) ? todayIso : (timelineDates.value[0] ?? ''),
    document_caption: '',
});

const onDocuments = (event: Event) => {
    const files = (event.target as HTMLInputElement).files;
    uploadForm.documents = files ? Array.from(files) : [];
};

const upload = () => {
    // Tag with user-selected activity day — only the 3 selected days (e.g. Sept 7,9,11) are selectable via option
    uploadForm.transform((data) => ({ ...data, _method: 'put' as const })).post(`/extensions/${props.activity.id}`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            uploadForm.documents = [];
            uploadForm.document_caption = '';
        },
        onFinish: () => uploadForm.transform((data) => data),
    });
};

// Keep selected date in sync if activity days change (e.g. after edit)
watch(
    () => timelineDates.value,
    (dates) => {
        if (dates.length && !dates.includes(uploadForm.document_activity_date)) {
            uploadForm.document_activity_date = dates.includes(todayIso) ? todayIso : dates[0] ?? '';
        }
    },
);

const tasksByDate = computed(() => {
    const map: Record<string, DailyTask[]> = {};
    for (const t of dailyTasks.value) {
        const key = t.scheduled_date.substring(0, 10);
        if (!map[key]) map[key] = [];
        map[key].push(t);
    }
    return map;
});

const uploadedDaysCount = computed(() => {
    const daySet = new Set(timelineDates.value);
    if (!daySet.size) return 0;
    const uploaded = new Set(
        props.activity.documents
            .map((d) => d.activity_date?.substring(0, 10))
            .filter((d): d is string => Boolean(d) && daySet.has(d as string)),
    );
    return uploaded.size;
});

const hasUploadForToday = computed(() => {
    if (!timelineDates.value.includes(todayIso)) return false;
    return props.activity.documents.some((d) => d.activity_date?.substring(0, 10) === todayIso);
});

const isTodayActivityDay = computed(() => timelineDates.value.includes(todayIso));

const todayDayNumber = computed(() => {
    const idx = timelineDates.value.indexOf(todayIso);
    return idx === -1 ? null : idx + 1;
});

const dailyTaskForm = useForm({
    title: '',
    scheduled_date: timelineDates.value[0] ?? todayIso,
    description: '',
});
const editingTaskId = ref<number | null>(null);
const dailyTaskDialogOpen = ref(false);
const openDailyTaskDialog = (task?: DailyTask) => {
    if (task) {
        editingTaskId.value = task.id;
        dailyTaskForm.title = task.title;
        dailyTaskForm.scheduled_date = task.scheduled_date.substring(0, 10);
        dailyTaskForm.description = task.description ?? '';
    } else {
        editingTaskId.value = null;
        dailyTaskForm.title = '';
        dailyTaskForm.description = '';
        dailyTaskForm.scheduled_date = timelineDates.value[0] ?? todayIso;
    }
    dailyTaskDialogOpen.value = true;
};
const submitDailyTask = () => {
    if (editingTaskId.value) {
        dailyTaskForm.put(`/extensions/${props.activity.id}/daily-tasks/${editingTaskId.value}`, {
            preserveScroll: true,
            onSuccess: () => (dailyTaskDialogOpen.value = false),
        });
    } else {
        dailyTaskForm.post(`/extensions/${props.activity.id}/daily-tasks`, {
            preserveScroll: true,
            onSuccess: () => (dailyTaskDialogOpen.value = false),
        });
    }
};
const removeDailyTask = (id: number) => {
    router.delete(`/extensions/${props.activity.id}/daily-tasks/${id}`, { preserveScroll: true });
};
</script>

<template>
    <Head :title="activity.title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-5xl space-y-5 p-4 sm:p-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="font-heading text-xl font-semibold sm:text-2xl">{{ activity.title }}</h1>
                        <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize" :class="statusTint">{{ activity.status }}</span>
                    </div>
                    <p class="text-sm text-neutral-500">{{ activity.program?.name }} · {{ activity.barangay?.name ?? 'City-wide' }} · {{ activity.location }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <div v-if="canExport" class="flex gap-1.5">
                        <Button variant="outline" size="sm" as-child class="gap-1.5"><a :href="`/extensions/${activity.id}/export/pdf`"><FileDown class="size-4" /> PDF</a></Button>
                        <Button variant="outline" size="sm" as-child class="gap-1.5"><a :href="`/extensions/${activity.id}/export/xlsx`"><FileSpreadsheet class="size-4" /> Excel</a></Button>
                    </div>
                    <div v-if="isLead" class="flex gap-2">
                        <Button variant="outline" as-child><Link :href="`/extensions/${activity.id}/edit`">Edit details</Link></Button>
                        <Button v-if="canDelete" variant="destructive" class="gap-1.5" @click="deleteDialogOpen = true"
                            ><Trash2 class="size-4" />Delete</Button
                        >
                    </div>
                    <span v-if="!isLead && !canExport" class="rounded-full bg-neutral-100 px-3 py-1 text-xs font-medium text-neutral-600">View only</span>
                </div>
            </div>

            <!-- SDGs -->
            <section class="hcard-p">
                <h2 class="font-heading mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-500">Sustainable Development Goals</h2>
                <div v-if="(activity.sdgs ?? []).length" class="flex flex-wrap gap-2">
                    <span
                        v-for="s in activity.sdgs"
                        :key="s.id"
                        class="inline-flex items-center gap-1.5 rounded-xl border px-2 py-1 text-xs font-medium"
                        :style="{ borderColor: s.color, backgroundColor: s.color + '14', color: s.color }"
                        :title="s.title"
                    >
                        <img :src="s.icon_url" :alt="s.title" class="size-6 rounded-md object-cover border bg-white" :style="{ borderColor: s.color }" />
                        <span class="font-bold">SDG {{ s.number }}</span>
                        <span class="hidden sm:inline text-[11px] opacity-80">{{ s.short_title || s.title }}</span>
                    </span>
                </div>
                <p v-else class="text-sm text-neutral-400">No SDGs assigned.</p>
            </section>

            <div class="grid gap-4 lg:grid-cols-3">
                <!-- Details -->
                <section class="hcard-p lg:col-span-1 ">
                    <h2 class="font-heading mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-500">Details</h2>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between gap-2"><dt class="text-neutral-400">Start</dt><dd>{{ formatYMD(activity.start_date) }}</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-neutral-400">End</dt><dd>{{ activity.end_date ? formatYMD(activity.end_date) : '—' }}</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-neutral-400">Faculty / Students</dt><dd>{{ activity.faculty_participants }} / {{ activity.student_participants }}</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-neutral-400">Beneficiaries</dt><dd>{{ activity.beneficiaries.toLocaleString() }}</dd></div>
                    </dl>
                    <p class="mt-3 border-t pt-3 text-sm text-neutral-500">{{ activity.description || 'No description.' }}</p>
                </section>

                <!-- Status & progress — status date-based, progress upload-based -->
                <section class="hcard-p lg:col-span-2 ">
                    <h2 class="font-heading mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-500">Status & Progress</h2>
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <span class="rounded-full px-3 py-1 text-xs font-semibold capitalize" :class="statusTint">{{ activity.status }}</span>
                        <span class="text-xs text-neutral-400">{{ statusHint }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <label class="mb-1 block text-xs text-neutral-500" for="progress"
                            >Progress — {{ activity.progress }}% ({{ uploadedDaysCount }} of {{ activityDays.length }} day{{ activityDays.length === 1 ? '' : 's' }} uploaded)</label
                        >
                        <span class="text-[11px] text-neutral-400">Upload-based</span>
                    </div>
                    <div class="mt-3 h-2.5 w-full overflow-hidden rounded-full bg-neutral-100 dark:bg-neutral-800">
                        <div class="bg-brand-gradient h-full rounded-full transition-all" :style="{ width: `${activity.progress}%` }" />
                    </div>
                    <p class="mt-2 text-[11px] italic text-neutral-400">
                        Progress = (days with uploads ÷ total days) × 100. Advances only when you upload photos/documents for that day's event date.
                    </p>
                    <p
                        v-if="isTodayActivityDay"
                        class="mt-1 text-[11px] font-medium"
                        :class="hasUploadForToday ? 'text-emerald-600' : 'text-amber-600'"
                    >
                        <template v-if="hasUploadForToday">✓ Upload recorded for today ({{ formatYMD(todayIso) }}) — progress updated.</template>
                        <template v-else>● No upload yet for today ({{ formatYMD(todayIso) }}) — upload to advance progress.</template>
                    </p>
                    <p v-else class="mt-1 text-[11px] text-neutral-400">Today is not an activity day — progress advances only on activity days with uploads.</p>
                </section>
            </div>

            <!-- Gantt — daily schedule -->
            <section class="hcard-p">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h2 class="font-heading flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-neutral-500"><GanttChart class="size-4 text-brand-500" />Gantt & Daily Schedule</h2>
                        <p class="mt-1 text-xs text-neutral-400">Plan what happens each day. Dates are locked to the activity period.</p>
                    </div>
                    <Button v-if="isLead" size="sm" class="gap-1.5" @click="() => openDailyTaskDialog()"><Plus class="size-4" />Add daily task</Button>
                </div>

                <div v-if="timelineDates.length" class="overflow-x-auto">
                    <div class="min-w-[640px]">
                        <!-- Date header -->
                        <div class="grid gap-1" :style="{ gridTemplateColumns: `repeat(${timelineDates.length}, minmax(120px, 1fr))` }">
                            <div
                                v-for="date in timelineDates"
                                :key="date"
                                class="rounded-lg border p-2 text-center"
                                :class="date === todayIso ? 'border-brand-300 bg-brand-50' : 'border-neutral-200 bg-neutral-50'"
                            >
                                <div class="text-[11px] font-semibold uppercase tracking-wide" :class="date === todayIso ? 'text-brand-700' : 'text-neutral-500'">
                                    {{ formatYMD(date, { month: 'short', day: 'numeric' }) }}
                                </div>
                                <div class="text-[10px] text-neutral-400">{{ formatYMD(date, { weekday: 'short' }) }}</div>
                                <div class="mt-1 text-[10px] font-medium" :class="(tasksByDate[date]?.length ?? 0) > 0 ? 'text-brand-600' : 'text-neutral-400'">
                                    {{ tasksByDate[date]?.length ?? 0 }} task{{ (tasksByDate[date]?.length ?? 0) === 1 ? '' : 's' }}
                                </div>
                            </div>
                        </div>
                        <!-- Gantt bars -->
                        <div class="mt-3 grid gap-1" :style="{ gridTemplateColumns: `repeat(${timelineDates.length}, minmax(120px, 1fr))` }">
                            <div v-for="date in timelineDates" :key="'bar-'+date" class="min-h-[80px] rounded-lg border border-dashed border-neutral-200 bg-white p-1.5">
                                <div v-for="task in tasksByDate[date] ?? []" :key="task.id" class="group/task relative mb-1.5 rounded-md bg-brand-500 px-2 py-1.5 text-xs font-medium text-white shadow-sm">
                                    <div class="truncate pr-6">{{ task.title }}</div>
                                    <div v-if="task.description" class="truncate text-[11px] font-normal text-white/80">{{ task.description }}</div>
                                    <div v-if="isLead" class="absolute right-1 top-1 hidden gap-1 group-hover/task:flex">
                                        <button type="button" class="rounded bg-white/90 p-1 text-neutral-600 hover:text-brand-600" @click="openDailyTaskDialog(task)"><Pencil class="size-3" /></button>
                                        <button type="button" class="rounded bg-white/90 p-1 text-red-500 hover:bg-red-50" @click="removeDailyTask(task.id)"><Trash2 class="size-3" /></button>
                                    </div>
                                </div>
                                <p v-if="!(tasksByDate[date]?.length)" class="py-6 text-center text-[11px] italic text-neutral-300">—</p>
                            </div>
                        </div>
                    </div>
                </div>
                <p v-else class="text-sm text-neutral-400">Set start and end dates to enable the Gantt schedule.</p>

                <!-- List view fallback -->
                <div v-if="dailyTasks.length" class="mt-4 divide-y rounded-xl border border-neutral-200">
                    <div v-for="task in dailyTasks" :key="'list-'+task.id" class="flex items-center justify-between gap-3 p-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="rounded bg-brand-500/10 px-2 py-0.5 text-[11px] font-semibold text-brand-700">{{ formatYMD(task.scheduled_date) }}</span>
                                <span class="truncate text-sm font-medium">{{ task.title }}</span>
                            </div>
                            <p v-if="task.description" class="truncate text-xs text-neutral-500">{{ task.description }}</p>
                        </div>
                        <div v-if="isLead" class="flex shrink-0 gap-1">
                            <Button variant="ghost" size="icon" class="size-8" @click="openDailyTaskDialog(task)"><Pencil class="size-4" /></Button>
                            <Button variant="ghost" size="icon" class="size-8 text-red-500 hover:text-red-600" @click="removeDailyTask(task.id)"><Trash2 class="size-4" /></Button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Daily Task Dialog -->
            <Dialog v-model:open="dailyTaskDialogOpen">
                <DialogContent class="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{{ editingTaskId ? 'Edit daily task' : 'Add daily task' }}</DialogTitle>
                        <DialogDescription>Choose one of the {{ timelineDates.length }} activity day{{ timelineDates.length === 1 ? '' : 's' }} you set ({{ timelineDates.join(', ') }}). Tasks can only be scheduled on those dates.</DialogDescription>
                    </DialogHeader>
                    <form class="space-y-3" @submit.prevent="submitDailyTask">
                        <div>
                            <Label for="task-date" class="mb-1 block text-xs text-neutral-500">Activity Day</Label>
                            <select
                                id="task-date"
                                v-model="dailyTaskForm.scheduled_date"
                                required
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            >
                                <option v-for="(date, idx) in timelineDates" :key="date" :value="date">
                                    Day {{ idx + 1 }} — {{ formatYMD(date, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }) }}
                                </option>
                                <option
                                    v-if="dailyTaskForm.scheduled_date && !timelineDates.includes(dailyTaskForm.scheduled_date)"
                                    :value="dailyTaskForm.scheduled_date"
                                >
                                    {{ formatYMD(dailyTaskForm.scheduled_date) }} (previous)
                                </option>
                            </select>
                            <p v-if="dailyTaskForm.errors.scheduled_date" class="mt-1 text-xs text-red-500">{{ dailyTaskForm.errors.scheduled_date }}</p>
                        </div>
                        <div>
                            <Label for="task-title" class="mb-1 block text-xs text-neutral-500">Activity for this day</Label>
                            <Input id="task-title" v-model="dailyTaskForm.title" placeholder="e.g. Day 3 — Field demo" required />
                            <p v-if="dailyTaskForm.errors.title" class="mt-1 text-xs text-red-500">{{ dailyTaskForm.errors.title }}</p>
                        </div>
                        <div>
                            <Label for="task-desc" class="mb-1 block text-xs text-neutral-500">Description (optional)</Label>
                            <textarea id="task-desc" v-model="dailyTaskForm.description" rows="2" class="flex min-h-[60px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm" placeholder="What will happen this day?"></textarea>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" @click="dailyTaskDialogOpen = false">Cancel</Button>
                            <Button type="submit" :disabled="dailyTaskForm.processing">{{ editingTaskId ? 'Save changes' : 'Add task' }}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <!-- Documents — daily uploads -->
            <section class="hcard-p ">
                <h2 class="font-heading mb-1 text-sm font-semibold uppercase tracking-wide text-neutral-500">Daily Photos & Documents</h2>
                <p class="mb-4 text-xs text-neutral-400">Upload everyday during the activity period. Each upload is tagged with its activity date and progress for that day.</p>

                <div v-if="groupedDocuments.length" class="space-y-6">
                    <div v-for="group in groupedDocuments" :key="group.date">
                        <div class="mb-2 flex items-center gap-2">
                            <Calendar class="size-4 text-brand-500" />
                            <h3 class="text-sm font-semibold text-neutral-700">{{ group.date }}</h3>
                            <span class="rounded-full bg-neutral-100 px-2 py-0.5 text-[11px] text-neutral-500">{{ group.docs.length }} file{{ group.docs.length > 1 ? 's' : '' }}</span>
                        </div>
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <figure
                                v-for="doc in group.docs"
                                :key="doc.id"
                                class="group relative overflow-hidden rounded-xl border border-neutral-200 bg-neutral-50 p-3 dark:border-neutral-800"
                            >
                                <img v-if="doc.mime_type?.startsWith('image/')" :src="`/storage/${doc.file_path}`" class="h-24 w-full rounded-lg object-cover" />
                                <FileText v-else-if="doc.mime_type === 'application/pdf'" class="size-10 mx-auto my-4 text-red-400" />
                                <ImageIcon v-else class="size-10 mx-auto my-4 text-brand-400" />
                                <figcaption class="mt-2 truncate text-[11px] font-medium text-neutral-700" :title="doc.original_name">{{ doc.original_name }}</figcaption>
                                <p v-if="doc.caption" class="truncate text-[11px] italic text-neutral-500" :title="doc.caption">{{ doc.caption }}</p>
                                <div class="mt-1 flex items-center justify-between">
                                    <span v-if="doc.progress !== null" class="rounded bg-brand-500 px-1.5 py-0.5 text-[10px] font-bold text-white">{{ doc.progress }}%</span>
                                    <span class="ml-auto text-[10px] text-neutral-400">{{ doc.activity_date ? formatYMD(doc.activity_date) : '' }}</span>
                                </div>
                                <div class="absolute right-1.5 top-1.5 flex gap-1 opacity-0 transition group-hover:opacity-100">
                                    <a
                                        :href="`/extensions/${activity.id}/documents/${doc.id}/download`"
                                        class="rounded-md bg-white/90 p-1 shadow hover:bg-white hover:text-brand-600"
                                        title="Download"
                                    >
                                        <Download class="size-3.5" />
                                    </a>
                                    <button
                                        v-if="isLead"
                                        type="button"
                                        class="rounded-md bg-white/90 p-1 shadow hover:bg-white hover:text-red-500"
                                        title="Delete"
                                        @click="removeDocument(doc.id)"
                                    >
                                        <Trash2 class="size-3.5" />
                                    </button>
                                </div>
                            </figure>
                        </div>
                    </div>
                </div>
                <p v-else class="text-sm text-neutral-400">No documents uploaded yet. Upload daily during the activity.</p>

                <form v-if="isLead" class="mt-6 space-y-3 border-t pt-4" @submit.prevent="upload">
                    <div>
                        <Label for="doc-date" class="mb-1 block text-xs font-medium text-neutral-600"
                            >Activity Date * <span class="font-normal text-neutral-400">({{ timelineDates.length }} day{{ timelineDates.length === 1 ? '' : 's' }}: {{ timelineDates.join(', ') }})</span></Label
                        >
                        <select
                            id="doc-date"
                            v-model="uploadForm.document_activity_date"
                            required
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                        >
                            <option value="" disabled>Select activity day</option>
                            <option v-for="(date, idx) in timelineDates" :key="date" :value="date">
                                Day {{ idx + 1 }} — {{ formatYMD(date, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }) }}
                                <template v-if="date === todayIso"> (Today)</template>
                            </option>
                        </select>
                        <p class="mt-1 text-[11px] text-emerald-600">
                            ✓ Day {{ timelineDates.indexOf(uploadForm.document_activity_date) + 1 }} — upload will count toward progress ({{ uploadedDaysCount }}/{{ activityDays.length }} days uploaded).
                        </p>
                        <p v-if="uploadForm.errors.document_activity_date" class="mt-1 text-xs text-red-500">{{ uploadForm.errors.document_activity_date }}</p>
                    </div>
                    <div>
                        <Label for="doc-caption" class="mb-1 block text-xs text-neutral-500">Caption (optional)</Label>
                        <Input id="doc-caption" v-model="uploadForm.document_caption" placeholder="e.g. Day 2 — Training" />
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <input
                            type="file"
                            multiple
                            accept=".jpg,.jpeg,.png,.pdf,.xlsx,.xls,.doc,.docx"
                            class="flex-1 cursor-pointer text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-brand-500 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white"
                            @change="onDocuments"
                            required
                        />
                        <Button type="submit" size="sm" :disabled="!uploadForm.documents.length || uploadForm.processing || !uploadForm.document_activity_date"
                            >Upload for selected date</Button
                        >
                    </div>
                    <p class="text-[11px] text-neutral-400">Files are tagged with the selected inclusive date. Progress advances only when you upload for an activity day.</p>
                </form>
                <p v-else class="mt-5 border-t pt-4 text-xs italic text-neutral-400">Only the program coordinator can upload daily documents.</p>
            </section>

            <!-- Delete confirmation -->
            <Dialog v-model:open="deleteDialogOpen">
                <DialogContent class="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Delete extension activity?</DialogTitle>
                        <DialogDescription
                            >This will permanently delete “{{ activity.title }}” and all its documents and tasks. This action cannot be undone. Completed
                            activities cannot be deleted.</DialogDescription
                        >
                    </DialogHeader>
                    <DialogFooter class="gap-2 sm:gap-0">
                        <Button variant="outline" @click="deleteDialogOpen = false">Cancel</Button>
                        <Button variant="destructive" @click="confirmDelete">Delete activity</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <!-- Collaborating programs -->
            <section class="hcard-p" v-if="(activity.collaborators ?? []).length || isLead">
                <h2 class="font-heading mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-500">Collaborating Programs</h2>
                <div v-if="(activity.collaborators ?? []).length" class="flex flex-wrap gap-2">
                    <span
                        v-for="collab in activity.collaborators"
                        :key="collab.id"
                        class="inline-flex items-center gap-1 rounded-full border border-brand-200 bg-brand-50 px-3 py-1 text-xs font-medium text-brand-700"
                    >
                        {{ collab.name }}
                    </span>
                </div>
                <p v-else class="text-sm text-neutral-400">No collaborating programs assigned yet.</p>
                <form v-if="isLead" class="mt-4 space-y-2 border-t pt-3">
                    <Label class="mb-1 block text-xs text-neutral-500">Add or remove collaborating programs (lead coordinator only)</Label>
                    <div class="space-y-2">
                        <Collapsible
                            v-for="(progs, instituteName) in collaborationPrograms"
                            :key="instituteName"
                            v-slot="{ open }"
                            :default-open="progs.some((p: any) => (activity.collaborators ?? []).some((c: any) => c.id === p.id))"
                            class="overflow-hidden rounded-xl border border-neutral-200"
                        >
                            <CollapsibleTrigger
                                class="flex w-full items-center justify-between bg-neutral-50 px-3 py-2.5 text-left transition hover:bg-neutral-100"
                            >
                                <span class="text-xs font-semibold text-neutral-700">{{ instituteName }}</span>
                                <span class="flex items-center gap-2">
                                    <span class="rounded-full bg-white px-2 py-0.5 text-[11px] font-medium text-neutral-500"
                                        >{{ progs.filter((p: any) => (activity.collaborators ?? []).some((c: any) => c.id === p.id)).length }}/{{ progs.length }} selected</span
                                    >
                                    <ChevronDown :class="['size-4 shrink-0 text-neutral-400 transition-transform duration-200', open ? 'rotate-180' : '']" />
                                </span>
                            </CollapsibleTrigger>
                            <CollapsibleContent class="border-t bg-white">
                                <div class="space-y-1 p-2">
                                    <label
                                        v-for="prog in progs"
                                        :key="prog.id"
                                        class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-2 text-sm transition hover:bg-brand-50"
                                    >
                                        <input
                                            type="checkbox"
                                            :value="prog.id"
                                            :checked="(activity.collaborators ?? []).some((c: any) => c.id === prog.id)"
                                            class="size-4 accent-brand-500"
                                            @change="toggleCollaborator(prog.id, $event)"
                                        />
                                        <span class="text-neutral-700">{{ prog.name }}</span>
                                    </label>
                                </div>
                            </CollapsibleContent>
                        </Collapsible>
                    </div>
                </form>
            </section>

        </div>
    </AppLayout>
</template>
