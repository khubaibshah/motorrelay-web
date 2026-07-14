<script setup>
import { reactive, ref } from 'vue';
import api from '@/services/api';
import { useAuthStore } from '@/stores/auth';
import { RouterLink, useRouter, useRoute } from 'vue-router';

const router = useRouter();
const route = useRoute();
const auth = useAuthStore();

const form = reactive({
  email: '',
  password: ''
});

const submitting = ref(false);
const errorMessage = ref('');

async function submit() {
  submitting.value = true;
  errorMessage.value = '';
  try {
    const { data } = await api.post('/auth/login', form);
    auth.setSession({
      token: data?.token || null,
      user: data?.user || null,
      plan: data?.plan || null,
      jobs: data?.jobs ?? undefined
    });
    await auth.fetchMe();
    const redirectTarget =
      typeof route.query.redirect === 'string' && route.query.redirect
        ? route.query.redirect
        : '/';
    router.replace(redirectTarget);
  } catch (error) {
    console.error('Login failed', error);
    errorMessage.value = error.response?.data?.message || 'Login failed. Check your credentials.';
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <div class="mx-auto grid w-full max-w-5xl overflow-hidden rounded-3xl border border-white/70 bg-white/90 shadow-2xl shadow-slate-950/10 ring-1 ring-slate-900/5 backdrop-blur sm:rounded-[2rem] lg:grid-cols-[0.9fr_1.1fr] dark:border-white/10 dark:bg-slate-950 dark:shadow-black/30 dark:ring-white/10">
    <aside class="hidden bg-slate-950 p-10 text-white lg:flex lg:flex-col lg:justify-between">
      <div>
        <p class="text-xs font-black uppercase tracking-[0.22em] text-emerald-300">MotorRelay</p>
        <h1 class="mt-4 text-4xl font-black tracking-tight">One workspace for every vehicle movement.</h1>
        <p class="mt-4 text-sm leading-6 text-slate-300">
          Manage runs, messages, live tracking, inspection photos, expenses, and invoices from a focused operations dashboard.
        </p>
      </div>

      <div class="grid gap-3">
        <div class="rounded-2xl border border-white/10 bg-white/[0.06] p-4">
          <p class="text-sm font-semibold text-white">Demo driver</p>
          <p class="mt-1 text-xs text-slate-400">driver@motorrelay.com / password</p>
        </div>
        <div class="rounded-2xl border border-white/10 bg-white/[0.06] p-4">
          <p class="text-sm font-semibold text-white">Demo dealer</p>
          <p class="mt-1 text-xs text-slate-400">dealer@motorrelay.com / password</p>
        </div>
      </div>
    </aside>

    <section class="space-y-6 p-5 sm:space-y-8 sm:p-8 lg:p-10 dark:bg-slate-950">
      <nav class="grid grid-cols-2 gap-1 rounded-2xl bg-slate-100 p-1 dark:bg-white/[0.06]" aria-label="Account access">
        <RouterLink
          to="/login"
          class="rounded-xl bg-slate-950 px-4 py-2.5 text-center text-sm font-bold text-white shadow-sm dark:bg-emerald-400 dark:text-slate-950"
          aria-current="page"
        >
          Log in
        </RouterLink>
        <RouterLink
          to="/signup"
          class="rounded-xl px-4 py-2.5 text-center text-sm font-bold text-slate-600 transition hover:bg-white hover:text-slate-950 dark:text-emerald-100 dark:hover:bg-white/10 dark:hover:text-emerald-300"
        >
          Sign up
        </RouterLink>
      </nav>

      <header class="space-y-2">
        <p class="text-[11px] font-black uppercase tracking-[0.16em] text-emerald-700 sm:text-xs sm:tracking-[0.18em]">Welcome back</p>
        <h1 class="text-2xl font-black tracking-tight text-slate-950 sm:text-3xl dark:text-emerald-300">Sign in to MotorRelay</h1>
        <p class="text-sm leading-6 text-slate-600 dark:text-emerald-100">
          Access your account to manage runs, drivers, messages, tracking, and paperwork.
        </p>
      </header>

      <div class="grid gap-2 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-xs text-emerald-900 lg:hidden dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100">
        <p class="font-bold">Demo accounts</p>
        <p><span class="font-semibold">Driver:</span> driver@motorrelay.com / password</p>
        <p><span class="font-semibold">Dealer:</span> dealer@motorrelay.com / password</p>
      </div>

    <form class="space-y-5" @submit.prevent="submit">
      <div>
        <label class="text-sm font-semibold text-slate-700 dark:text-emerald-100">Email</label>
        <input
          v-model="form.email"
          type="email"
          required
          class="mt-2 w-full rounded-2xl px-4 py-3 text-base"
          placeholder="driver@motorrelay.com"
        />
      </div>

      <div>
        <label class="text-sm font-semibold text-slate-700 dark:text-emerald-100">Password</label>
        <input
          v-model="form.password"
          type="password"
          required
          class="mt-2 w-full rounded-2xl px-4 py-3 text-base"
          placeholder="password"
        />
      </div>

      <p v-if="errorMessage" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700">
        {{ errorMessage }}
      </p>

      <button
        type="submit"
        class="btn-primary w-full py-3"
        :disabled="submitting"
      >
        <span v-if="submitting">Signing in...</span>
        <span v-else>Sign in</span>
      </button>
    </form>

    </section>
  </div>
</template>
