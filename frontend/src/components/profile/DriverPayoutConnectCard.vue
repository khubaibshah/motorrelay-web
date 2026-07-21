<script setup>
import { computed, ref } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { disconnectDriverPayoutAccount, startDriverPayoutOnboarding } from '@/services/payments';
import ConfirmModal from '@/components/ConfirmModal.vue';

const auth = useAuthStore();
const payoutSetupLoading = ref(false);
const payoutDisconnectLoading = ref(false);
const payoutSetupError = ref('');
const showDisconnectModal = ref(false);

const hasStripePayoutAccount = computed(() => Boolean(auth.user?.stripe_account_id));
const payoutsEnabled = computed(() => Boolean(auth.user?.stripe_payouts_enabled || auth.user?.stripe_onboarding_complete));
const payoutCardTitle = computed(() => {
  if (payoutsEnabled.value) return 'Stripe payouts connected';
  if (hasStripePayoutAccount.value) return 'Stripe payout setup started';
  return 'Set up Stripe payouts';
});
const payoutCardText = computed(() => {
  if (payoutsEnabled.value) return 'Your Stripe payout account is connected. MotorRelay can release payment after inspection photos are approved.';
  if (hasStripePayoutAccount.value) return 'Your Stripe payout account exists. If Stripe still needs information, use the button to finish or update setup.';
  return 'Add your bank details securely with Stripe so MotorRelay can release payment after inspection photos are approved.';
});

async function handlePayoutSetup() {
  payoutSetupLoading.value = true;
  payoutSetupError.value = '';
  try {
    const payload = await startDriverPayoutOnboarding();
    if (!payload?.url) throw new Error('Stripe did not return an onboarding link.');
    window.location.href = payload.url;
  } catch (error) {
    console.error('Failed to start Stripe onboarding', error);
    payoutSetupError.value = error.response?.data?.message || error.message || 'Could not start payout setup.';
  } finally {
    payoutSetupLoading.value = false;
  }
}

function openDisconnectModal() {
  payoutSetupError.value = '';
  showDisconnectModal.value = true;
}

async function confirmDisconnect() {
  payoutDisconnectLoading.value = true;
  payoutSetupError.value = '';
  try {
    const payload = await disconnectDriverPayoutAccount();
    auth.user = payload?.user ?? {
      ...auth.user,
      stripe_account_id: null,
      stripe_onboarding_complete: false,
      stripe_charges_enabled: false,
      stripe_payouts_enabled: false
    };
    showDisconnectModal.value = false;
  } catch (error) {
    console.error('Failed to disconnect Stripe payout account', error);
    payoutSetupError.value = error.response?.data?.message || error.message || 'Could not disconnect payout account.';
  } finally {
    payoutDisconnectLoading.value = false;
  }
}
</script>

<template>
  <section class="rounded-2xl border p-5" :class="payoutsEnabled ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50'">
    <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
      <div class="min-w-0 flex-1">
        <p class="text-xs font-black uppercase tracking-wide" :class="payoutsEnabled ? 'text-emerald-700' : 'text-amber-700'">Driver payouts</p>
        <h2 class="mt-1 text-lg font-black" :class="payoutsEnabled ? 'text-emerald-950' : 'text-amber-950'">{{ payoutCardTitle }}</h2>
        <p class="mt-1 text-sm leading-6" :class="payoutsEnabled ? 'text-emerald-800' : 'text-amber-800'">{{ payoutCardText }}</p>
        <span class="mt-2 inline-flex rounded-full px-3 py-1 text-xs font-bold" :class="payoutsEnabled ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'">
          {{ payoutsEnabled ? 'Connected' : hasStripePayoutAccount ? 'Action may be needed' : 'Not connected' }}
        </span>
      </div>
      <div class="flex w-full flex-col gap-2 sm:flex-row md:w-auto md:flex-shrink-0">
        <button type="button" class="btn-primary w-full whitespace-nowrap px-4 py-2.5 md:w-auto" :disabled="payoutSetupLoading || payoutDisconnectLoading" @click="handlePayoutSetup">
          <span v-if="payoutSetupLoading">Opening Stripe...</span>
          <span v-else-if="payoutsEnabled">Manage payout setup</span>
          <span v-else-if="hasStripePayoutAccount">Finish payout setup</span>
          <span v-else>Set up payouts</span>
        </button>
        <button v-if="hasStripePayoutAccount" type="button" class="btn-secondary w-full whitespace-nowrap px-4 py-2.5 text-rose-700 md:w-auto" :disabled="payoutSetupLoading || payoutDisconnectLoading" @click="openDisconnectModal">Disconnect</button>
      </div>
    </div>
    <p v-if="payoutSetupError" class="mt-3 text-sm text-rose-700">{{ payoutSetupError }}</p>
    <p v-else class="mt-3 text-xs" :class="payoutsEnabled ? 'text-emerald-700' : 'text-amber-700'">MotorRelay does not store bank details. Stripe handles payout verification.</p>
  </section>

  <ConfirmModal :open="showDisconnectModal" title="Disconnect Stripe payouts?" description="You will not be able to receive driver payouts until Stripe setup is connected again." cancel-text="Cancel" confirm-text="Disconnect account" :loading="payoutDisconnectLoading" loading-text="Disconnecting..." confirm-tone="rose" icon-text="!" icon-class="bg-rose-100 text-rose-700" @cancel="showDisconnectModal = false" @confirm="confirmDisconnect" />
</template>
