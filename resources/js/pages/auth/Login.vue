<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Eye, EyeOff, LoaderCircle, Lock, Mail } from 'lucide-vue-next';
import { ref } from 'vue';

defineProps<{
    status?: string;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const showPassword = ref(false);

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Hulagway – Sign In" />

    <div v-if="status" class="fixed top-4 left-1/2 z-50 -translate-x-1/2 rounded-lg bg-green-100 px-4 py-2 text-sm font-medium text-green-700">
        {{ status }}
    </div>

    <div class="flex min-h-svh flex-col md:flex-row">
        <!-- Left panel: brand / backdrop -->
        <div
            class="relative flex h-44 items-center justify-center overflow-hidden bg-orange-50 px-6 sm:h-56 md:h-auto md:w-1/2 md:min-h-svh md:px-8 md:py-16"
        >
            <img
                src="/images/login-backdrop.png"
                alt=""
                class="absolute inset-0 h-full w-full object-cover"
            />
            <div class="absolute inset-0 bg-gradient-to-t from-white/60 to-transparent md:hidden"></div>
            <div class="relative z-10 flex flex-col items-center text-center">
                <img
                    src="/images/logo-transparent.png"
                    alt="Hulagway logo"
                    class="mb-2 size-16 drop-shadow-sm sm:size-24 md:mb-6 md:size-48"
                />
                <h1 class="font-heading text-2xl leading-tight font-extrabold tracking-wider text-orange-600 sm:text-3xl md:text-5xl">
                    HULAGWAY
                </h1>
                <p class="mt-1 text-[11px] font-semibold tracking-[0.05em] text-orange-600/90 uppercase sm:text-xs md:mt-4 md:text-sm">
                    Mapping Community Realities
                </p>
                <div
                    class="mt-1 hidden items-center gap-3 text-[11px] font-semibold tracking-[0.15em] text-orange-500/80 uppercase sm:flex md:text-xs"
                >
                    <span class="h-px w-6 bg-orange-300"></span>
                    Toward Informed Extension Planning
                    <span class="h-px w-6 bg-orange-300"></span>
                </div>
            </div>
        </div>

        <!-- Right panel: sign-in form -->
        <div class="flex items-center justify-center bg-white px-6 py-12 sm:px-10 md:w-1/2">
            <div class="w-full max-w-md">
                <div class="mb-8 text-center md:text-left">
                    <h2 class="font-heading text-3xl font-bold text-gray-900">Welcome Back</h2>
                    <p class="mt-2 text-gray-500">Sign in to continue to your account</p>
                </div>

                <form @submit.prevent="submit" class="flex flex-col gap-5">
                    <!-- Email -->
                    <div>
                        <div class="relative">
                            <span class="pointer-events-none absolute top-0 bottom-0 left-0 flex items-center pl-4 text-orange-500">
                                <Mail class="size-5" />
                            </span>
                            <input
                                id="email"
                                type="email"
                                required
                                autofocus
                                tabindex="1"
                                autocomplete="email"
                                v-model="form.email"
                                placeholder="Email"
                                class="w-full rounded-xl border border-orange-200 bg-white py-3.5 pr-4 pl-12 text-gray-600 placeholder:text-gray-400 outline-none transition focus:border-orange-400 focus:ring-3 focus:ring-orange-400/35"
                            />
                        </div>
                        <InputError :message="form.errors.email" class="mt-2" />
                    </div>

                    <!-- Password -->
                    <div>
                        <div class="relative">
                            <span class="pointer-events-none absolute top-0 bottom-0 left-0 flex items-center pl-4 text-orange-500">
                                <Lock class="size-5" />
                            </span>
                            <input
                                id="password"
                                :type="showPassword ? 'text' : 'password'"
                                required
                                tabindex="2"
                                autocomplete="current-password"
                                v-model="form.password"
                                placeholder="Password"
                                class="w-full rounded-xl border border-gray-200 bg-white py-3.5 pr-12 pl-12 text-gray-600 placeholder:text-gray-400 outline-none transition focus:border-orange-400 focus:ring-3 focus:ring-orange-400/35"
                            />
                            <button
                                type="button"
                                tabindex="-1"
                                @click="showPassword = !showPassword"
                                class="absolute top-0 bottom-0 right-0 flex items-center pr-4 text-gray-400 transition hover:text-gray-600"
                                aria-label="Toggle password visibility"
                            >
                                <EyeOff v-if="showPassword" class="size-5" />
                                <Eye v-else class="size-5" />
                            </button>
                        </div>
                        <InputError :message="form.errors.password" class="mt-2" />
                    </div>

                    <!-- Remember me -->
                    <div class="flex items-center pt-1 text-sm">
                        <label class="flex cursor-pointer items-center gap-2 text-gray-600 select-none">
                            <input
                                id="remember"
                                type="checkbox"
                                tabindex="3"
                                v-model="form.remember"
                                class="size-4 accent-orange-500"
                            />
                            Remember me
                        </label>
                    </div>

                    <!-- Sign in button -->
                    <button
                        type="submit"
                        tabindex="4"
                        :disabled="form.processing"
                        class="w-full rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 py-3.5 font-semibold text-white shadow-[0_4px_10px_rgba(251,146,60,0.35)] transition hover:brightness-95 active:scale-[0.99] disabled:opacity-70"
                    >
                        <LoaderCircle v-if="form.processing" class="mx-auto size-4 animate-spin" />
                        <span v-else>Sign In</span>
                    </button>

                    <!-- Divider -->
                    <div class="flex items-center gap-4 pt-1">
                        <span class="h-px flex-1 bg-gray-200"></span>
                        <span class="text-sm text-gray-400">or</span>
                        <span class="h-px flex-1 bg-gray-200"></span>
                    </div>

                    <!-- Contact administrator -->
                    <p class="pt-1 text-center text-sm text-gray-500">
                        Don't have an account?
                        <a href="#" class="font-semibold text-orange-500 hover:text-orange-600 hover:underline">Contact your administrator.</a>
                    </p>
                </form>
            </div>
        </div>
    </div>
</template>
