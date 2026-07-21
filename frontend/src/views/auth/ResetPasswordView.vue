<script setup>
import { reactive, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import api from '@/services/api';

const route = useRoute();
const router = useRouter();
const form = reactive({
  email: typeof route.query.email === 'string' ? route.query.email : '',
  token: typeof route.query.token === 'string' ? route.query.token : '',
  password: '',
  password_confirmation: ''
});
const submitting = ref(false);
const errorMessage = ref('');

async function submit() {
  submitting.value = true;
  errorMessage.value = '';

  try {
    await api.post('/auth/reset-password', form);
    await router.replace({ name: 'login', query: { reset: 'success' } });
  } catch (error) {
    console.error('Password reset failed', error);
    errorMessage.value = error.response?.data?.message || 'This reset link is invalid or has expired.';
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <div class="mx-auto w-full max-w-xl rounded-3xl border border-white/70 bg-white/90 p-5 shadow-2xl shadow-slate-950/10 ring-1 ring-slate-900/5 backdrop-blur sm:p-8 dark:border-white/10 dark:bg-slate-950 dark:ring-white/10">
    <nav class="mb-8 flex items-center justify-between">
      <p class="text-xs font-black uppercase tracking-[0.2em] text-emerald-700 dark:text-emerald-300">MotorRelay</p>
      <RouterLink to="/login" class="text-sm font-bold text-slate-600 hover:text-emerald-700 dark:text-emerald-100 dark:hover:text-emerald-300">Back to login</RouterLink>
    </nav>

    <header class="space-y-2">
      <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-300">Account recovery</p>
      <h1 class="text-3xl font-black tracking-tight text-slate-950 dark:text-emerald-300">Create a new password</h1>
      <p class="text-sm leading-6 text-slate-600 dark:text-emerald-100">Choose a new password for your MotorRelay account.</p>
    </header>

    <form class="mt-8 space-y-5" @submit.prevent="submit">
      <div>
        <label for="reset-email" class="text-sm font-semibold text-slate-700 dark:text-emerald-100">Email</label>
        <input id="reset-email" v-model="form.email" type="email" autocomplete="email" required class="mt-2 w-full rounded-2xl px-4 py-3 text-base" />
      </div>
      <div>
        <label for="reset-password" class="text-sm font-semibold text-slate-700 dark:text-emerald-100">New password</label>
        <input id="reset-password" v-model="form.password" type="password" autocomplete="new-password" required class="mt-2 w-full rounded-2xl px-4 py-3 text-base" />
      </div>
      <div>
        <label for="reset-password-confirmation" class="text-sm font-semibold text-slate-700 dark:text-emerald-100">Confirm new password</label>
        <input id="reset-password-confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" required class="mt-2 w-full rounded-2xl px-4 py-3 text-base" />
      </div>

      <p v-if="errorMessage" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700 dark:border-rose-300/30 dark:bg-rose-400/10 dark:text-rose-100">{{ errorMessage }}</p>

      <button type="submit" class="btn-primary w-full py-3" :disabled="submitting || !form.token">
        {{ submitting ? 'Updating password...' : 'Update password' }}
      </button>
      <p v-if="!form.token" class="text-center text-xs font-semibold text-rose-600 dark:text-rose-200">This reset link is missing its token. Request a new one.</p>
    </form>
  </div>
</template>
