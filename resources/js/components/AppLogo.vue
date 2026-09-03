<script setup lang="ts">
interface Props {
    class?: string;
}

defineProps<Props>();
</script>

<template>
    <img src="/images/logo.png" alt="HULAGWAY" class="size-8 rounded-lg object-contain" />
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="font-heading mb-0.5 truncate font-semibold leading-none tracking-wide text-brand-600 dark:text-brand-400">HULAGWAY</span>
    </div>
</template>
