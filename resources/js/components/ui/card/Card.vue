<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <div :class="cn('rounded-[20px] border-none bg-card text-card-foreground shadow-horizon', props.class)">
        <slot />
    </div>
</template>
