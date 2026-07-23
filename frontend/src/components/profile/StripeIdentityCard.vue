<script setup>
import { computed, onMounted, ref } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { startIdentityVerification } from '@/services/identity';

const auth = useAuthStore();
const loading = ref(false);
const errorMessage = ref('');

const status = computed(() => auth.user?.stripe_identity_status || 'unverified');
const isVerified = computed(() => status.value === 'verified');
const statusLabel = computed(() => ({
  verified: 'Verified',
  processing: 'Review in progress',
  requires_input: 'More information needed',
  canceled: 'Verification cancelled',
  unverified: 'Not verified'
}[status.value] || 'Not verified'));

onMounted(() => {
  if (auth.token) auth.fetchMe().catch(() => null);
});

async function beginVerification() {
  loading.value = true;
  errorMessage.value = '';
  try {
    const session = await startIdentityVerification();
    if (!session?.url) throw new Error('Stripe did not return a verification link.');
    window.location.assign(session.url);
  } catch (error) {
    errorMessage.value = error.response?.data?.message || error.message || 'Unable to start verification.';
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <section class="tile space-y-3 p-4 md:p-5">
    <div class="flex items-start justify-between gap-3">
      <div>
        <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-300">ACCOUNT VERIFICATION</p>
        <h2 class="mt-1 text-lg font-black text-slate-950 dark:text-white">Verify your identity</h2>
      </div>
      <span
        class="rounded-full px-3 py-1 text-xs font-bold"
        :class="isVerified ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-800'"
      >
        {{ statusLabel }}
      </span>
    </div>
    <p class="text-sm text-slate-600 dark:text-emerald-100">
      Stripe securely checks your photo ID and selfie. MotorRelay only receives the verification result; your identity documents are handled by Stripe.
    </p>
    <p v-if="errorMessage" class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
      {{ errorMessage }}
    </p>
    <button v-if="!isVerified" type="button" class="btn-primary w-full" :disabled="loading" @click="beginVerification">
      {{ loading ? 'Opening secure verification…' : status === 'requires_input' ? 'Continue verification' : 'Verify identity' }}
    </button>
    <p v-else class="text-xs font-semibold text-emerald-700 dark:text-emerald-300">
      Your identity verification is complete.
    </p>
  </section>
</template>
