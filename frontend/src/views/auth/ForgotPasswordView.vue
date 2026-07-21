<script setup>
import { reactive, ref } from 'vue';
import { RouterLink } from 'vue-router';
import api from '@/services/api';

const form = reactive({ email: '' });
const submitting = ref(false);
const errorMessage = ref('');
const successMessage = ref('');

async function submit() {
  submitting.value = true;
  errorMessage.value = '';
  successMessage.value = '';

  try {
    const { data } = await api.post('/auth/forgot-password', form);
    successMessage.value = data?.message || 'If an account exists for that email, we have sent reset instructions.';
  } catch (error) {
    console.error('Password reset request failed', error);
    errorMessage.value = error.response?.data?.message || 'We could not send reset instructions right now.';
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
      <h1 class="text-3xl font-black tracking-tight text-slate-950 dark:text-emerald-300">Forgot your password?</h1>
      <p class="text-sm leading-6 text-slate-600 dark:text-emerald-100">Enter your account email and we’ll send you a secure link to choose a new password.</p>
    </header>

    <form class="mt-8 space-y-5" @submit.prevent="submit">
      <div>
        <label for="forgot-email" class="text-sm font-semibold text-slate-700 dark:text-emerald-100">Email</label>
        <input id="forgot-email" v-model="form.email" type="email" autocomplete="email" required class="mt-2 w-full rounded-2xl px-4 py-3 text-base" placeholder="you@example.com" />
      </div>

      <p v-if="errorMessage" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700 dark:border-rose-300/30 dark:bg-rose-400/10 dark:text-rose-100">{{ errorMessage }}</p>
      <p v-if="successMessage" class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-300/30 dark:bg-emerald-400/10 dark:text-emerald-100">{{ successMessage }}</p>

      <button type="submit" class="btn-primary w-full py-3" :disabled="submitting">
        {{ submitting ? 'Sending instructions...' : 'Send reset link' }}
      </button>
    </form>
  </div>
</template>
