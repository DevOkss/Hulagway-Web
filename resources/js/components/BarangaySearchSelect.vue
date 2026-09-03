<script setup lang="ts">
import { computed, ref, onMounted, onBeforeUnmount } from 'vue';
import { Input } from '@/components/ui/input';

const props = withDefaults(
    defineProps<{
        modelValue: string | number | null | undefined;
        barangays: Array<{ id: number; name: string }>;
        placeholder?: string;
        allowAll?: boolean;
        allLabel?: string;
        id?: string;
    }>(),
    {
        placeholder: 'Search barangay...',
        allowAll: false,
        allLabel: 'All barangays',
    },
);

const emit = defineEmits<{
    (e: 'update:modelValue', v: string | number | ''): void;
}>();

const open = ref(false);
const search = ref('');
const root = ref<HTMLElement | null>(null);

const selectedName = computed(() => {
    if (props.modelValue === '' || props.modelValue == null) return '';
    const b = props.barangays.find((x) => String(x.id) === String(props.modelValue));
    return b?.name ?? '';
});

const isAllSelected = computed(() => props.allowAll && (props.modelValue === '' || props.modelValue == null));

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.barangays;
    return props.barangays.filter((b) => b.name.toLowerCase().includes(q));
});

const select = (id: string | number | '') => {
    emit('update:modelValue', id);
    search.value = '';
    open.value = false;
};

const onFocus = () => {
    open.value = true;
};

const clearSearch = () => {
    search.value = '';
    open.value = true;
};

const clearSelection = () => {
    emit('update:modelValue', '');
    search.value = '';
    open.value = false;
};

onMounted(() => {
    const onDoc = (e: MouseEvent) => {
        if (!root.value) return;
        if (!root.value.contains(e.target as Node)) open.value = false;
    };
    document.addEventListener('click', onDoc);
    onBeforeUnmount(() => document.removeEventListener('click', onDoc));
});
</script>

<template>
    <div ref="root" class="relative">
        <div class="relative">
            <Input
                :id="id"
                v-model="search"
                :placeholder="placeholder"
                class="pr-8"
                autocomplete="off"
                @focus="onFocus"
                @input="open = true"
            />
            <button
                v-if="search"
                type="button"
                class="absolute right-2 top-1/2 -translate-y-1/2 text-neutral-400 hover:text-neutral-600"
                @click="clearSearch"
                tabindex="-1"
            >
                ✕
            </button>
            <span v-else class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-neutral-400">⌕</span>
        </div>
        <!-- Selected value display (not in search input) -->
        <div v-if="selectedName" class="mt-1.5 flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 rounded bg-brand-50 px-2 py-1 text-xs font-medium text-brand-700">
                {{ selectedName }}
                <button type="button" class="ml-1 text-brand-400 hover:text-brand-700" @click="clearSelection" title="Clear selection">✕</button>
            </span>
            <span v-if="isAllSelected" class="text-xs text-neutral-500">{{ allLabel }}</span>
        </div>
        <div v-else-if="isAllSelected" class="mt-1.5">
            <span class="inline-flex rounded bg-neutral-100 px-2 py-1 text-xs font-medium text-neutral-600">{{ allLabel }}</span>
        </div>
        <div
            v-if="open"
            class="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-md border border-neutral-200 bg-white py-1 shadow-md"
        >
            <button
                v-if="allowAll"
                type="button"
                class="flex w-full px-3 py-2 text-left text-sm hover:bg-neutral-50"
                :class="{ 'bg-brand-50 font-medium text-brand-700': isAllSelected }"
                @click="select('')"
            >
                {{ allLabel }}
            </button>
            <button
                v-for="b in filtered"
                :key="b.id"
                type="button"
                class="flex w-full px-3 py-2 text-left text-sm hover:bg-neutral-50"
                :class="{ 'bg-brand-50 font-medium text-brand-700': String(modelValue) === String(b.id) }"
                @click="select(b.id)"
            >
                {{ b.name }}
            </button>
            <div v-if="filtered.length === 0" class="px-3 py-2 text-sm text-neutral-400">No barangay found</div>
            <div v-if="!search && filtered.length > 0" class="px-3 py-1.5 text-[11px] text-neutral-400">{{ filtered.length }} barangays — type to filter</div>
        </div>
    </div>
</template>
