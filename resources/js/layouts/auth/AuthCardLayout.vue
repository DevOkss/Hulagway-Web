<script setup lang="ts">
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Link } from '@inertiajs/vue3';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="bg-brand-gradient flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
        <div class="flex w-full max-w-md flex-col gap-6">
            <Link :href="route('home')" class="self-center">
                <img src="/images/bg.png" alt="HULAGWAY" class="w-44 drop-shadow-lg" />
            </Link>

            <Card class="rounded-3xl border-none shadow-horizon">
                <CardHeader class="px-8 pb-0 pt-8 text-center sm:px-10">
                    <CardTitle class="font-heading text-xl font-bold text-navy-700">{{ title }}</CardTitle>
                    <CardDescription class="text-hgray-700">
                        {{ description }}
                    </CardDescription>
                </CardHeader>
                <CardContent class="px-8 py-8 sm:px-10">
                    <slot />
                </CardContent>
            </Card>

            <p class="text-center text-[11px] font-medium tracking-wide text-white/80">
                HULAGWAY · Mapping Community Realities Toward Informed Extension Planning
            </p>
        </div>
    </div>
</template>
