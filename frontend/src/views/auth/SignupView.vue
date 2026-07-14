<script setup>
import { reactive, ref } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import api from '@/services/api';
import { useAuthStore } from '@/stores/auth';

const router = useRouter();
const auth = useAuthStore();

const form = reactive({
  name: '',
  email: '',
  password: '',
  role: 'driver',
  plan: 'Starter'
});

const submitting = ref(false);
const errorMessage = ref('');

async function submit() {
  submitting.value = true;
  errorMessage.value = '';

  try {
    const { data } = await api.post('/auth/register', form);
    auth.setSession({
      token: data?.token || null,
      user: data?.user || null,
      plan: data?.plan || null
    });
    await auth.fetchMe().catch(() => null);
    await router.replace('/');
  } catch (error) {
    console.error('Signup failed', error);
    errorMessage.value = error.response?.data?.message || 'Sign up failed. Try again later.';
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <div class="mx-auto grid w-full max-w-5xl overflow-hidden rounded-3xl border border-white/70 bg-white/90 shadow-2xl shadow-slate-950/10 ring-1 ring-slate-900/5 backdrop-blur sm:rounded-[2rem] lg:grid-cols-[0.9fr_1.1fr] dark:border-white/10 dark:bg-slate-950 dark:shadow-black/30 dark:ring-white/10">
    <aside class="hidden bg-slate-950 p-10 text-white lg:flex lg:flex-col lg:justify-between">
      <div>
        <p class="text-xs font-bold uppercase tracking-[0.22em] text-emerald-300">MotorRelay</p>
        <h1 class="mt-4 text-4xl font-black tracking-tight">Get moving in just a few details.</h1>
        <p class="mt-4 text-sm leading-6 text-slate-300">
          Create your account now. We’ll guide you through identity and business verification separately, before you start moving vehicles.
        </p>
      </div>

      <div class="rounded-2xl border border-white/10 bg-white/[0.06] p-4">
        <p class="text-sm font-semibold text-white">Verification comes next</p>
        <p class="mt-1 text-xs leading-5 text-slate-400">
          You won’t need to find documents or upload identity photos on this screen.
        </p>
      </div>
    </aside>

    <section class="space-y-6 p-5 sm:space-y-8 sm:p-8 lg:p-10 dark:bg-slate-950">
      <nav class="grid grid-cols-2 gap-1 rounded-2xl bg-slate-100 p-1 dark:bg-white/[0.06]" aria-label="Account access">
        <RouterLink
          to="/login"
          class="rounded-xl px-4 py-2.5 text-center text-sm font-bold text-slate-600 transition hover:bg-white hover:text-slate-950 dark:text-emerald-100 dark:hover:bg-white/10 dark:hover:text-emerald-300"
        >
          Log in
        </RouterLink>
        <RouterLink
          to="/signup"
          class="rounded-xl bg-slate-950 px-4 py-2.5 text-center text-sm font-bold text-white shadow-sm dark:bg-emerald-400 dark:text-slate-950"
          aria-current="page"
        >
          Sign up
        </RouterLink>
      </nav>

      <header class="space-y-2">
        <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-emerald-700 sm:text-xs sm:tracking-[0.18em]">Join MotorRelay</p>
        <h1 class="text-2xl font-black tracking-tight text-slate-950 sm:text-3xl dark:text-emerald-300">Create your account</h1>
        <p class="text-sm leading-6 text-slate-600 dark:text-emerald-100">
          Join a trusted network built to make vehicle movements simpler, faster, and more connected.
        </p>
      </header>

      <form class="space-y-5" @submit.prevent="submit">
        <fieldset>
          <legend class="text-sm font-semibold text-slate-700 dark:text-emerald-100">I’m joining as a</legend>
          <div class="mt-2 grid grid-cols-2 gap-2 rounded-2xl bg-slate-100 p-1 dark:bg-white/[0.06]">
            <label
              class="cursor-pointer rounded-xl px-3 py-3 text-center text-sm font-bold transition"
              :class="form.role === 'driver' ? 'bg-white text-slate-950 shadow-sm dark:bg-emerald-400 dark:text-slate-950' : 'text-slate-500 dark:text-emerald-100'"
            >
              <input v-model="form.role" type="radio" value="driver" class="sr-only" />
              Driver
            </label>
            <label
              class="cursor-pointer rounded-xl px-3 py-3 text-center text-sm font-bold transition"
              :class="form.role === 'dealer' ? 'bg-white text-slate-950 shadow-sm dark:bg-emerald-400 dark:text-slate-950' : 'text-slate-500 dark:text-emerald-100'"
            >
              <input v-model="form.role" type="radio" value="dealer" class="sr-only" />
              Dealer
            </label>
          </div>
        </fieldset>

        <div>
          <label for="signup-name" class="text-sm font-semibold text-slate-700 dark:text-emerald-100">Full name</label>
          <input
            id="signup-name"
            v-model="form.name"
            type="text"
            autocomplete="name"
            required
            class="mt-2 w-full rounded-2xl px-4 py-3 text-base"
            placeholder="Your full name"
          />
        </div>

        <div>
          <label for="signup-email" class="text-sm font-semibold text-slate-700 dark:text-emerald-100">Email</label>
          <input
            id="signup-email"
            v-model="form.email"
            type="email"
            autocomplete="email"
            required
            class="mt-2 w-full rounded-2xl px-4 py-3 text-base"
            placeholder="you@example.com"
          />
        </div>

        <div>
          <label for="signup-password" class="text-sm font-semibold text-slate-700 dark:text-emerald-100">Password</label>
          <input
            id="signup-password"
            v-model="form.password"
            type="password"
            autocomplete="new-password"
            minlength="8"
            required
            class="mt-2 w-full rounded-2xl px-4 py-3 text-base"
            placeholder="At least 8 characters"
          />
        </div>

        <p v-if="errorMessage" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700">
          {{ errorMessage }}
        </p>

        <button type="submit" class="btn-primary w-full py-3" :disabled="submitting">
          <span v-if="submitting">Creating account...</span>
          <span v-else>Create account</span>
        </button>

        <p class="text-center text-xs leading-5 text-slate-500 dark:text-emerald-100">
          By creating an account, you agree to MotorRelay’s terms and privacy policy.
        </p>
      </form>
    </section>
  </div>
</template>
