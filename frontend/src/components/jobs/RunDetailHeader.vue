<script setup>
import { formatStatusLabel } from '@/utils/statusLabels';

defineProps({
  job: {
    type: Object,
    required: true
  },
  displayAmount: {
    type: String,
    required: true
  },
  isDriverView: {
    type: Boolean,
    default: false
  },
  canRequestJob: {
    type: Boolean,
    default: false
  },
  requestLoading: {
    type: Boolean,
    default: false
  },
  showDriverRequestPanel: {
    type: Boolean,
    default: false
  },
  myApplication: {
    type: Object,
    default: null
  },
  canUseDriverMode: {
    type: Boolean,
    default: false
  },
  canStartDriverMode: {
    type: Boolean,
    default: false
  },
  canMarkCollected: {
    type: Boolean,
    default: false
  },
  canMarkDelivered: {
    type: Boolean,
    default: false
  },
  showCollectionAction: {
    type: Boolean,
    default: false
  },
  canShareTracking: {
    type: Boolean,
    default: false
  },
  trackingLoading: {
    type: Boolean,
    default: false
  },
  collectedLoading: {
    type: Boolean,
    default: false
  },
  canCancelJob: {
    type: Boolean,
    default: false
  },
  cancelLoading: {
    type: Boolean,
    default: false
  },
  canWithdrawApplication: {
    type: Boolean,
    default: false
  },
  withdrawLoading: {
    type: Boolean,
    default: false
  }
});

defineEmits(['request-job', 'start-driver-mode', 'mark-collected', 'mark-delivered', 'share-location', 'cancel-job', 'withdraw-application']);
</script>

<template>
  <header class="tile p-3" :class="isDriverView ? 'space-y-2' : 'space-y-3'">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0 flex-1">
        <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-300">Run details</p>
        <h1 class="mt-0.5 break-words font-black leading-tight text-slate-950 dark:text-white" :class="isDriverView ? 'text-xl' : 'text-2xl'">
          {{ job.title || `Run #${job.id}` }}
        </h1>
        <p class="mt-0.5 text-xs font-semibold text-slate-600 dark:text-emerald-100">
          {{ job.company || 'Customer' }} · {{ job.vehicle_make || 'Vehicle' }}
        </p>
      </div>
      <div class="flex shrink-0 flex-col items-end gap-1.5">
        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-black text-slate-800 dark:bg-white/10 dark:text-emerald-100">
          {{ formatStatusLabel(job.status) }}
        </span>
        <p class="text-xl font-black leading-none text-emerald-600 dark:text-emerald-300">
          {{ displayAmount }}
        </p>
      </div>
    </div>

    <div
      v-if="canRequestJob || (showDriverRequestPanel && myApplication) || canUseDriverMode || showCollectionAction || canMarkCollected || canMarkDelivered || canCancelJob"
      class="flex flex-col gap-2 border-t border-slate-100 pt-2 dark:border-white/10 sm:flex-row sm:items-center sm:justify-end"
    >
      <div class="grid w-full gap-2 sm:flex sm:w-auto sm:flex-wrap sm:justify-end">
        <button
          v-if="canRequestJob"
          type="button"
          class="btn-primary w-full px-4 py-2 text-sm sm:w-auto"
          :disabled="requestLoading"
          @click="$emit('request-job')"
        >
          <span v-if="requestLoading">Sending request...</span>
          <span v-else>Request this run</span>
        </button>
        <div
          v-else-if="showDriverRequestPanel && myApplication"
          class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row"
        >
          <span class="inline-flex min-h-10 items-center justify-center rounded-xl bg-emerald-50 px-4 py-2 text-sm font-black text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-400 dark:text-slate-950 dark:ring-emerald-400">
            {{ formatStatusLabel(myApplication.status, 'Request sent') }}
          </span>
          <button
            v-if="canWithdrawApplication"
            type="button"
            class="btn-secondary min-h-10 px-4 py-2 text-sm"
            :disabled="withdrawLoading"
            @click="$emit('withdraw-application')"
          >
            <span v-if="withdrawLoading">Withdrawing...</span>
            <span v-else>Withdraw application</span>
          </button>
        </div>
        <button
          v-if="canStartDriverMode"
          type="button"
          class="btn-primary w-full px-4 py-2 text-sm sm:w-auto"
          @click="$emit('start-driver-mode')"
        >
          Start driver mode
        </button>
        <button
          v-if="showCollectionAction || canMarkCollected || canMarkDelivered"
          type="button"
          class="btn-primary w-full px-4 py-2 text-sm sm:w-auto"
          :disabled="collectedLoading || trackingLoading || (!canMarkCollected && !canMarkDelivered && !canShareTracking)"
          @click="canMarkDelivered ? $emit('mark-delivered') : (canMarkCollected ? $emit('mark-collected') : $emit('share-location'))"
        >
          <span v-if="collectedLoading">Updating...</span>
          <span v-else-if="trackingLoading">Sharing location...</span>
          <span v-else-if="canMarkDelivered">Mark vehicle delivered</span>
          <span v-else-if="!canMarkCollected">Share live location</span>
          <span v-else>Mark vehicle collected</span>
        </button>
        <button
          v-if="canCancelJob"
          type="button"
          class="btn-secondary w-full px-4 py-2 text-sm sm:w-auto"
          :disabled="cancelLoading"
          @click="$emit('cancel-job')"
        >
          <span v-if="cancelLoading">Cancelling...</span>
          <span v-else>Cancel run</span>
        </button>
      </div>
    </div>
  </header>
</template>
