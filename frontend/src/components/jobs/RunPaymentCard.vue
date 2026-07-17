<script setup>
defineProps({
  eyebrow: {
    type: String,
    required: true
  },
  title: {
    type: String,
    required: true
  },
  dealerCharge: {
    type: String,
    required: true
  },
  driverPayout: {
    type: String,
    required: true
  },
  status: {
    type: String,
    required: true
  },
  statusClass: {
    type: String,
    default: ''
  },
  paidText: {
    type: String,
    default: ''
  },
  paymentError: {
    type: String,
    default: ''
  },
  paymentNotice: {
    type: String,
    default: ''
  },
  confirmationText: {
    type: String,
    default: ''
  },
  canStartCheckout: {
    type: Boolean,
    default: false
  },
  canReleasePayout: {
    type: Boolean,
    default: false
  },
  checkoutLoading: {
    type: Boolean,
    default: false
  },
  payoutReleaseLoading: {
    type: Boolean,
    default: false
  },
  actionHelp: {
    type: String,
    default: ''
  }
});

defineEmits(['checkout', 'sync-payment', 'release-payout']);
</script>

<template>
  <section class="tile space-y-2 border-sky-200 bg-sky-50/40 p-3 dark:border-emerald-400/20 dark:bg-white/[0.04]">
    <header class="flex flex-wrap items-center justify-between gap-2">
      <div class="min-w-0">
        <p class="text-xs font-black uppercase tracking-wide text-sky-700 dark:text-emerald-300">{{ eyebrow }}</p>
        <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-600 dark:text-emerald-100">
          <span class="font-black text-slate-950 dark:text-white">{{ title }}</span>
          <span>{{ dealerCharge }} dealer charge</span>
          <span>{{ driverPayout }} driver payout</span>
        </div>
      </div>
      <span class="badge uppercase" :class="statusClass">{{ status }}</span>
    </header>

    <p class="text-xs text-slate-500 dark:text-emerald-100">
      {{ paidText || 'Not paid yet. Payment should be completed before a driver starts.' }}
    </p>

    <p v-if="paymentError" class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700">
      {{ paymentError }}
    </p>
    <p v-if="paymentNotice" class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-700">
      {{ paymentNotice }}
    </p>
    <p v-else-if="confirmationText" class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-700">
      {{ confirmationText }}
    </p>

    <div class="grid gap-2 sm:flex sm:flex-wrap sm:items-center">
      <button
        v-if="canStartCheckout"
        type="button"
        class="btn-primary w-full px-4 py-2 text-sm sm:w-auto"
        :disabled="checkoutLoading"
        @click="$emit('checkout')"
      >
        <span v-if="checkoutLoading">Opening checkout...</span>
        <span v-else>Pay for this run</span>
      </button>
      <button
        v-if="status === 'checkout_pending'"
        type="button"
        class="btn-secondary w-full px-4 py-2 text-sm sm:w-auto"
        :disabled="checkoutLoading"
        @click="$emit('sync-payment')"
      >
        <span v-if="checkoutLoading">Checking payment...</span>
        <span v-else>Refresh payment status</span>
      </button>
      <button
        v-if="canReleasePayout"
        type="button"
        class="btn-primary w-full px-4 py-2 text-sm sm:w-auto"
        :disabled="payoutReleaseLoading"
        @click="$emit('release-payout')"
      >
        <span v-if="payoutReleaseLoading">Releasing payout...</span>
        <span v-else>Release driver payout</span>
      </button>
      <p class="w-full text-xs text-slate-500 dark:text-emerald-100">
        {{ actionHelp }}
      </p>
    </div>
  </section>
</template>
