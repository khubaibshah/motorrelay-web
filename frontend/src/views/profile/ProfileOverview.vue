<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { RouterLink } from 'vue-router';
import { disconnectDriverPayoutAccount, startDriverPayoutOnboarding } from '@/services/payments';
import ConfirmModal from '@/components/ConfirmModal.vue';
import AccountSettingsBlock from '@/components/AccountSettingsBlock.vue';

const auth = useAuthStore();
const router = useRouter();
const payoutSetupLoading = ref(false);
const payoutDisconnectLoading = ref(false);
const payoutSetupError = ref('');
const showDisconnectModal = ref(false);

const isDriver = computed(() => auth.role === 'driver');
const hasStripePayoutAccount = computed(() => Boolean(auth.user?.stripe_account_id));
const payoutsEnabled = computed(() => Boolean(auth.user?.stripe_payouts_enabled || auth.user?.stripe_onboarding_complete));
const payoutCardTitle = computed(() => {
  if (payoutsEnabled.value) return 'Stripe payouts connected';
  if (hasStripePayoutAccount.value) return 'Stripe payout setup started';
  return 'Set up Stripe payouts';
});
const payoutCardText = computed(() => {
  if (payoutsEnabled.value) {
    return 'Your Stripe payout account is connected. MotorRelay can release payment after inspection photos are approved.';
  }
  if (hasStripePayoutAccount.value) {
    return 'Your Stripe payout account exists. If Stripe still needs information, use the button to finish or update setup.';
  }
  return 'Add your bank details securely with Stripe so MotorRelay can release payment after inspection photos are approved.';
});

const initials = computed(() => {
  if (!auth.user?.name) return 'MR';
  return auth.user.name
    .split(' ')
    .map((part) => part[0])
    .join('')
    .toUpperCase()
    .slice(0, 2);
});

onMounted(() => {
  if (auth.token) {
    auth.fetchMe().catch(() => null);
  }
});

async function handlePayoutSetup() {
  payoutSetupLoading.value = true;
  payoutSetupError.value = '';

  try {
    const payload = await startDriverPayoutOnboarding();
    if (payload?.url) {
      window.location.href = payload.url;
      return;
    }
    throw new Error('Stripe did not return an onboarding link.');
  } catch (error) {
    console.error('Failed to start Stripe onboarding', error);
    payoutSetupError.value = error.response?.data?.message || error.message || 'Could not start payout setup.';
  } finally {
    payoutSetupLoading.value = false;
  }
}

function openPayoutDisconnectModal() {
  payoutSetupError.value = '';
  showDisconnectModal.value = true;
}

function closePayoutDisconnectModal() {
  if (payoutDisconnectLoading.value) return;
  showDisconnectModal.value = false;
}

async function confirmPayoutDisconnect() {
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

async function handleLogout() {
  await auth.logout();
  if (typeof window !== 'undefined') {
    window.location.assign('/login');
    return;
  }
  await router.replace({ name: 'login' });
}

</script>

<template>
  <div class="grid gap-4 lg:grid-cols-[2fr_1fr]">
    <div class="space-y-4">
      <section class="tile space-y-5 p-5 md:p-6">
        <header class="flex items-center gap-4">
          <div class="flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100 text-lg font-bold text-emerald-700">
            {{ initials }}
          </div>
          <div class="min-w-0">
            <h1 class="truncate text-2xl font-black text-slate-950">
              {{ auth.user?.name || 'New MotorRelay user' }}
            </h1>
            <p class="truncate text-sm text-slate-500">
              {{ auth.user?.email || 'email@motorrelay.com' }}
            </p>
          </div>
        </header>

        <div class="flex flex-wrap gap-3">
          <div class="rounded-full border border-slate-200 bg-slate-50 px-4 py-2">
            <span class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">Role</span>
            <p class="mt-0.5 text-sm font-semibold text-slate-900">
              {{ auth.role || 'Pending' }}
            </p>
          </div>
          <div class="rounded-full border border-slate-200 bg-slate-50 px-4 py-2">
            <span class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-500">Plan</span>
            <p class="mt-0.5 text-sm font-semibold text-slate-900">
              {{ auth.planDisplayLabel || 'Free' }}
            </p>
          </div>
        </div>
      </section>

      <section
        v-if="isDriver"
        class="rounded-2xl border p-6"
        :class="payoutsEnabled ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50'"
      >
        <div class="flex flex-col gap-5 md:flex-row md:items-center md:justify-between">
          <div class="min-w-0 flex-1">
            <p class="text-xs font-bold uppercase tracking-wide" :class="payoutsEnabled ? 'text-emerald-700' : 'text-amber-700'">Driver payouts</p>
            <h2 class="mt-1 text-xl font-black" :class="payoutsEnabled ? 'text-emerald-950' : 'text-amber-950'">{{ payoutCardTitle }}</h2>
            <p class="mt-2 text-sm leading-6" :class="payoutsEnabled ? 'text-emerald-800' : 'text-amber-800'">
              {{ payoutCardText }}
            </p>
            <span
              class="mt-3 inline-flex rounded-full px-3 py-1 text-xs font-bold"
              :class="payoutsEnabled ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'"
            >
              {{ payoutsEnabled ? 'Connected' : hasStripePayoutAccount ? 'Action may be needed' : 'Not connected' }}
            </span>
          </div>
          <div class="flex w-full flex-col gap-3 sm:flex-row md:w-auto md:flex-shrink-0 md:justify-end">
            <button
              type="button"
              class="btn-primary w-full whitespace-nowrap px-5 py-3 md:w-auto"
              :disabled="payoutSetupLoading || payoutDisconnectLoading"
              @click="handlePayoutSetup"
            >
              <span v-if="payoutSetupLoading">Opening Stripe...</span>
              <span v-else-if="payoutsEnabled">Manage payout setup</span>
              <span v-else-if="hasStripePayoutAccount">Finish payout setup</span>
              <span v-else>Set up payouts</span>
            </button>
            <button
              v-if="hasStripePayoutAccount"
              type="button"
              class="btn-secondary w-full whitespace-nowrap border-rose-200 bg-white px-5 py-3 text-rose-700 hover:bg-rose-50 md:w-auto"
              :disabled="payoutSetupLoading || payoutDisconnectLoading"
              @click="openPayoutDisconnectModal"
            >
              Disconnect
            </button>
          </div>
        </div>
        <p v-if="payoutSetupError" class="mt-3 text-sm text-rose-700">{{ payoutSetupError }}</p>
        <p v-else class="mt-3 text-xs" :class="payoutsEnabled ? 'text-emerald-700' : 'text-amber-700'">
          MotorRelay does not store bank details. Stripe handles payout verification.
        </p>
      </section>

      <AccountSettingsBlock />

    </div>

    <aside class="tile space-y-4 p-6">
      <RouterLink to="/legal" class="btn-secondary w-full">
        Legal
      </RouterLink>
      <button
        type="button"
        class="w-full rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100 hover:text-rose-800"
        @click="handleLogout"
      >
        Logout
      </button>
    </aside>

    <ConfirmModal
      :open="showDisconnectModal"
      title="Disconnect Stripe payouts?"
      description="You will not be able to receive driver payouts until Stripe setup is connected again."
      cancel-text="Cancel"
      confirm-text="Disconnect account"
      :loading="payoutDisconnectLoading"
      loading-text="Disconnecting..."
      confirm-tone="rose"
      icon-text="!"
      icon-class="bg-rose-100 text-rose-700"
      @cancel="closePayoutDisconnectModal"
      @confirm="confirmPayoutDisconnect"
    />
  </div>
</template>
