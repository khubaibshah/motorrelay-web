<script setup>
import { RouterLink } from 'vue-router';
import { formatStatusLabel } from '@/utils/statusLabels';

defineProps({
  jobs: {
    type: Array,
    default: () => []
  },
  loading: {
    type: Boolean,
    default: false
  },
  error: {
    type: String,
    default: ''
  },
  emptyMessage: {
    type: String,
    default: 'No active runs right now.'
  },
  actionState: {
    type: Object,
    default: () => ({})
  }
});

const emit = defineEmits(['open-job', 'cancel-job', 'mark-delivered']);

const priceFormatter = new Intl.NumberFormat('en-GB', {
  style: 'currency',
  currency: 'GBP',
  maximumFractionDigits: 0
});

function paymentLabel(job) {
  const status = String(job?.payment_status || '').replaceAll('_', ' ');
  return status ? status.charAt(0).toUpperCase() + status.slice(1) : 'Unpaid';
}

function paymentClass(job) {
  const status = String(job?.payment_status || '').toLowerCase();
  if (['paid', 'payout_released'].includes(status)) return 'bg-emerald-100 text-emerald-700';
  if (status === 'checkout_pending') return 'bg-amber-100 text-amber-700';
  return 'bg-slate-100 text-slate-700';
}

function statusClass(job) {
  const status = String(job?.status || '').toLowerCase();
  if (['completed', 'delivered', 'closed'].includes(status)) return 'bg-emerald-100 text-emerald-700';
  if (['open', 'pending'].includes(status)) return 'bg-amber-100 text-amber-700';
  if (['cancelled', 'declined'].includes(status)) return 'bg-rose-100 text-rose-700';
  return 'bg-slate-100 text-slate-700';
}

function formatTransportType(value) {
  const normalized = String(value || '').toLowerCase();
  if (normalized === 'trailer') return 'Trailer';
  if (normalized === 'drive_away') return 'Drive-away';
  return value || '--';
}

function driverPayoutForJob(job) {
  const storedPayout = Number(job?.driver_payout_amount || 0);
  if (storedPayout > 0) return storedPayout;

  const price = Number(job?.price || 0);
  const storedFee = Number(job?.platform_fee_amount || 0);
  const platformFee = storedFee > 0 ? storedFee : Math.round(price * 0.1 * 100) / 100;

  return Math.max(price - platformFee, 0);
}

function isActionPending(jobId, type) {
  return actionState?.id === jobId && actionState?.type === type;
}

function nextAction(job) {
  const status = String(job?.status || '').toLowerCase();
  const completionStatus = String(job?.completion_status || '').toLowerCase();
  const paymentStatus = String(job?.payment_status || '').toLowerCase();

  if (paymentStatus === 'unpaid' || paymentStatus === 'checkout_pending') return 'Confirm dealer payment';
  if (completionStatus === 'submitted') return 'Approve completion';
  if (completionStatus === 'not_submitted' && ['accepted', 'in_progress'].includes(status)) return 'Await inspection photos';
  if (completionStatus === 'inspection_approved' && ['accepted', 'in_progress'].includes(status)) return 'Driver should collect vehicle';
  if (['collected', 'in_transit'].includes(status)) return 'Track delivery';
  if (status === 'delivered') return 'Review completion';
  return formatStatusLabel(status, 'Review run');
}
</script>

<template>
  <section class="section-card order-1 space-y-4">
    <header class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
      <h2 class="text-lg font-black text-slate-950">Active runs</h2>
    </header>

    <p v-if="error" class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-700">
      {{ error }}
    </p>

    <div v-if="loading" class="rounded-2xl border bg-white p-4 text-sm text-slate-600">
      Loading your active runs...
    </div>
    <div v-else-if="!jobs.length" class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
      {{ emptyMessage }}
    </div>
    <div v-else class="space-y-4">
      <article
        v-for="job in jobs"
        :key="`active-${job.id}`"
        class="rounded-3xl border border-slate-200 bg-slate-50/80 p-4 transition hover:-translate-y-0.5 hover:bg-white hover:shadow-xl sm:p-5"
      >
        <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
          <div class="min-w-0 flex-1">
            <div class="mb-3 flex flex-wrap gap-2">
              <span class="badge" :class="statusClass(job)">{{ formatStatusLabel(job.status) }}</span>
              <span class="badge" :class="paymentClass(job)">{{ paymentLabel(job) }}</span>
              <span class="badge bg-slate-100 text-slate-700">{{ formatTransportType(job.transport_type) }}</span>
            </div>

            <p class="text-xl font-black text-slate-950">
              {{ job.title || `Run #${job.id}` }}
            </p>

            <div class="mt-3 grid gap-3 text-sm sm:grid-cols-3">
              <div class="rounded-2xl bg-slate-50 p-3">
                <p class="text-xs font-black uppercase tracking-wide text-slate-500">Route</p>
                <p class="mt-1 font-semibold text-slate-800">{{ job.pickup_postcode || '--' }} to {{ job.dropoff_postcode || '--' }}</p>
              </div>
              <div class="rounded-2xl bg-slate-50 p-3">
                <p class="text-xs font-black uppercase tracking-wide text-slate-500">Driver</p>
                <p class="mt-1 font-semibold text-slate-800">{{ job.assigned_to?.name || 'Not assigned yet' }}</p>
              </div>
              <div class="rounded-2xl bg-slate-50 p-3">
                <p class="text-xs font-black uppercase tracking-wide text-slate-500">Next action</p>
                <p class="mt-1 font-semibold text-emerald-700">{{ nextAction(job) }}</p>
              </div>
            </div>
          </div>

          <div class="rounded-3xl bg-slate-950 p-4 text-white lg:min-w-[180px] lg:text-right">
            <p class="text-xs font-black uppercase tracking-wide text-slate-400">Driver payout</p>
            <div class="mt-1 text-3xl font-black">
              {{ priceFormatter.format(driverPayoutForJob(job)) }}
            </div>
            <span class="badge mt-3 bg-emerald-100 text-emerald-700">{{ formatStatusLabel(job.status) }}</span>
          </div>
        </div>

        <div class="mt-4 grid gap-2 sm:flex sm:flex-wrap">
          <button
            type="button"
            class="btn-primary w-full px-4 py-2 text-sm sm:w-auto"
            @click="emit('open-job', job)"
          >
            View details
          </button>
          <RouterLink
            :to="`/jobs/${job.id}/edit`"
            class="btn-secondary w-full px-4 py-2 text-sm sm:w-auto"
          >
            Edit run
          </RouterLink>
          <button
            type="button"
            class="btn-secondary w-full px-4 py-2 text-sm disabled:opacity-60 sm:w-auto"
            :disabled="isActionPending(job.id, 'cancel')"
            @click="emit('cancel-job', job)"
          >
            <span v-if="isActionPending(job.id, 'cancel')">Cancelling...</span>
            <span v-else>Cancel run</span>
          </button>
          <button
            type="button"
            class="btn-primary w-full px-3 py-2 text-xs disabled:opacity-60 sm:w-auto"
            :disabled="isActionPending(job.id, 'deliver')"
            @click="emit('mark-delivered', job)"
          >
            <span v-if="isActionPending(job.id, 'deliver')">Updating...</span>
            <span v-else>Mark as delivered</span>
          </button>
        </div>
      </article>
    </div>
  </section>
</template>
