<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { BarChart3, ClipboardList, FileBarChart, MapPinned } from 'lucide-vue-next';

defineProps<{
    canLogin?: boolean;
}>();
</script>

<template>
    <Head title="Welcome to HULAGWAY" />

    <div class="bg-brand-gradient min-h-screen">
        <!-- Navbar -->
        <header class="flex items-center justify-between px-6 py-5 sm:px-10">
            <div class="flex items-center gap-3">
                <img src="/images/logo.png" alt="" class="size-10 rounded-xl bg-white/90 p-1 shadow-brand" />
                <span class="font-heading text-lg font-bold tracking-wide text-white">HULAGWAY</span>
            </div>
            <nav v-if="canLogin" class="flex items-center gap-2">
                <Link
                    :href="route('login')"
                    class="rounded-xl bg-white px-4 py-2 text-sm font-bold text-brand-600 shadow-brand transition hover:bg-white/90"
                >
                    Log in
                </Link>
            </nav>
        </header>

        <!-- Hero -->
        <main class="mx-auto grid max-w-7xl grid-cols-1 items-center gap-10 px-6 pb-16 pt-8 sm:px-10 lg:grid-cols-2 lg:pt-14">
            <section class="text-center lg:text-left">
                <h1 class="font-heading text-4xl font-bold leading-tight text-white sm:text-5xl">
                    Mapping Community Realities
                    <span class="block text-white/85">Toward Informed Extension Planning</span>
                </h1>
                <p class="mx-auto mt-5 max-w-xl text-base leading-relaxed text-white/85 lg:mx-0">
                    HULAGWAY unites community surveys, GIS mapping, and extension activity monitoring — so the
                    College of Agriculture and Extension Services can see every barangay of Tangub City clearly
                    and respond where it matters most.
                </p>

                <div class="mt-8 flex flex-wrap justify-center gap-3 lg:justify-start">
                    <Link
                        :href="route('login')"
                        class="rounded-xl bg-white px-6 py-3 text-sm font-bold text-brand-600 shadow-brand transition hover:-translate-y-0.5 hover:bg-white/95"
                    >
                        Open Dashboard
                    </Link>
                </div>

                <dl class="mx-auto mt-12 grid max-w-md grid-cols-3 gap-4 lg:mx-0">
                    <div class="rounded-2xl bg-white/15 p-4 backdrop-blur">
                        <dt class="text-[11px] font-semibold uppercase tracking-wide text-white/70">Barangays</dt>
                        <dd class="font-heading text-2xl font-bold text-white">55</dd>
                    </div>
                    <div class="rounded-2xl bg-white/15 p-4 backdrop-blur">
                        <dt class="text-[11px] font-semibold uppercase tracking-wide text-white/70">Institutes</dt>
                        <dd class="font-heading text-2xl font-bold text-white">5</dd>
                    </div>
                    <div class="rounded-2xl bg-white/15 p-4 backdrop-blur">
                        <dt class="text-[11px] font-semibold uppercase tracking-wide text-white/70">Programs</dt>
                        <dd class="font-heading text-2xl font-bold text-white">12</dd>
                    </div>
                </dl>
            </section>

            <section id="features" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <article
                    v-for="feature in [
                        { title: 'Community Surveys', body: 'Build surveys, publish public links, and collect responses in the field — even offline.', icon: ClipboardList },
                        { title: 'GIS Community Map', body: 'Choropleth mapping of needs and services across all 55 barangays of Tangub City.', icon: MapPinned },
                        { title: 'Extension Monitoring', body: 'Track activities per institute program with status, progress, and Gantt timelines.', icon: BarChart3 },
                        { title: 'Reports & Analytics', body: 'Dashboards and exportable PDF / Excel reports for data-driven planning.', icon: FileBarChart },
                    ]"
                    :key="feature.title"
                    class="rounded-[20px] bg-white/95 p-5 shadow-horizon transition hover:-translate-y-0.5"
                >
                    <div class="flex size-10 items-center justify-center rounded-xl bg-brand-500/10">
                        <component :is="feature.icon" class="size-6 text-brand-600" />
                    </div>
                    <h2 class="font-heading mt-3 font-bold text-navy-700">{{ feature.title }}</h2>
                    <p class="mt-1 text-sm leading-relaxed text-hgray-700">{{ feature.body }}</p>
                </article>
            </section>
        </main>

        <footer class="border-t border-white/20 py-6 text-center text-xs text-white/70">
            © {{ new Date().getFullYear() }} HULAGWAY · CAES · City of Tangub, Misamis Occidental
        </footer>
    </div>
</template>
