<script setup>
import { RouterLink } from 'vue-router';
import { formatStatusLabel } from '@/utils/statusLabels';

defineProps({
  jobs: {
    type: Array,
    default: () => []
  },
  stats: {
    type: Array,
    default: () => []
  },
  loading: {
    type: Boolean,
    default: false
  },
  showAll: {
    type: Boolean,
    default: false
  },
  search: {
    type: String,
    default: ''
  },
  statusFilter: {
    type: String,
    default: 'all'
  },
  paymentFilter: {
    type: String,
    default: 'all'
  },
  totalJobs: {
    type: Number,
    default: 0
  }
});

const emit = defineEmits([
  'update:showAll',
  'update:search',
  'update:statusFilter',
  'update:paymentFilter',
  'open-job'
]);

function formatShortDate(value) {
  if (!value) return '--';
  try {
    return new Intl.DateTimeFormat('en-GB', {
      day: 'numeric',
      month: 'short'
    }).format(new Date(value));
  } catch {
    return value;
  }
}

function paymentLabel(job) {
  const status = String(job?.payment_status || '').replaceAll('_', ' ');
  return status ? status.charAt(0).toUpperCase() + status.slice(1) : 'Unpaid';
}

function resolveInvoiceLink(job) {
  if (!job) return null;
  return (
    job.invoice_download_url ||
    job.invoice_url ||
    job.invoice ||
    job.invoice_pdf ||
    job.invoice_link ||
    null
  );
}
</script>

<template>
  <section class="section-card space-y-4 dark:border-white/10 dark:bg-slate-950 dark:text-white">
    <header class="space-y-4">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-300">Dealer operations</p>
        <RouterLink
          to="/jobs/new"
          class="btn-primary w-full px-4 py-2 text-sm sm:w-auto"
        >
          Create run
        </RouterLink>
      </div>

      <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <h2 class="text-xl font-black text-slate-950 dark:text-emerald-300">Your runs</h2>
          <p class="mt-2 text-sm text-slate-600 dark:text-emerald-100">
            Keep an eye on your posted jobs, then expand the table when you need the full view.
          </p>
        </div>
        <div class="grid w-full grid-cols-3 gap-2 lg:w-auto">
          <div
            v-for="stat in stats"
            :key="stat.label"
            class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-center dark:border-white/10 dark:bg-white/[0.06]"
          >
            <p class="text-base font-black text-slate-950 dark:text-emerald-300">{{ stat.value }}</p>
            <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500 dark:text-emerald-100">{{ stat.label }}</p>
          </div>
        </div>
      </div>
    </header>

    <div v-if="showAll" class="grid w-full gap-3 sm:grid-cols-3">
      <div class="flex flex-col gap-2">
        <label for="dealer-jobs-search" class="text-xs font-black uppercase tracking-[0.16em] text-slate-500 dark:text-emerald-100">
          Search
        </label>
        <input
          id="dealer-jobs-search"
          :value="search"
          type="search"
          placeholder="Title, route, driver..."
          class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100"
          @input="emit('update:search', $event.target.value)"
        >
      </div>

      <div class="flex flex-col gap-2">
        <label for="dealer-jobs-status" class="text-xs font-black uppercase tracking-[0.16em] text-slate-500 dark:text-emerald-100">
          Status
        </label>
        <select
          id="dealer-jobs-status"
          :value="statusFilter"
          class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100"
          @change="emit('update:statusFilter', $event.target.value)"
        >
          <option value="all">All statuses</option>
          <option value="open">Open</option>
          <option value="pending">Pending</option>
          <option value="in_progress">In progress</option>
          <option value="accepted">Accepted</option>
          <option value="collected">Collected</option>
          <option value="in_transit">In transit</option>
          <option value="completion_pending">Completion pending</option>
          <option value="delivered">Delivered</option>
          <option value="completed">Completed</option>
          <option value="closed">Closed</option>
        </select>
      </div>

      <div class="flex flex-col gap-2">
        <label for="dealer-jobs-payment" class="text-xs font-black uppercase tracking-[0.16em] text-slate-500 dark:text-emerald-100">
          Payment
        </label>
        <select
          id="dealer-jobs-payment"
          :value="paymentFilter"
          class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100"
          @change="emit('update:paymentFilter', $event.target.value)"
        >
          <option value="all">All payments</option>
          <option value="unpaid">Unpaid</option>
          <option value="checkout_pending">Checkout pending</option>
          <option value="paid">Paid</option>
          <option value="payout_released">Payout released</option>
        </select>
      </div>
    </div>

    <div v-if="loading" class="rounded-2xl border bg-white p-4 text-sm text-slate-600 dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100">
      Loading your runs...
    </div>

    <div v-else-if="!jobs.length" class="space-y-3 rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-5 text-sm text-slate-600 dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100">
      <p>{{ showAll ? 'No runs match your search.' : 'No runs yet. Create a run to start receiving driver requests.' }}</p>
      <button
        v-if="showAll"
        type="button"
        class="text-xs font-bold text-emerald-700 hover:text-emerald-800"
        @click="emit('update:showAll', false)"
      >
        Show preview
      </button>
    </div>

    <div v-else class="space-y-4">
      <div class="space-y-3 md:hidden">
        <article
          v-for="job in jobs"
          :key="`mobile-job-${job.id}`"
          class="cursor-pointer rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-white/10 dark:bg-white/[0.06]"
          role="button"
          tabindex="0"
          @click="emit('open-job', job)"
          @keydown.enter="emit('open-job', job)"
          @keydown.space.prevent="emit('open-job', job)"
        >
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="text-base font-black text-slate-950 dark:text-white">{{ job.title || `Run #${job.id}` }}</p>
              <p class="mt-1 text-xs text-slate-500 dark:text-emerald-100">
                {{ job.pickup_label || job.pickup_postcode || '--' }} to {{ job.dropoff_label || job.dropoff_postcode || '--' }}
              </p>
            </div>
            <span class="badge bg-emerald-100 text-emerald-700">{{ formatStatusLabel(job.status) }}</span>
          </div>

          <div class="mt-3 flex items-center justify-between gap-3">
            <span class="text-xs font-semibold text-slate-500 dark:text-emerald-100">
              {{ formatShortDate(job.updated_at || job.created_at) }}
            </span>
            <a
              v-if="resolveInvoiceLink(job)"
              :href="resolveInvoiceLink(job)"
              target="_blank"
              rel="noreferrer"
              class="inline-flex rounded-full border border-emerald-200 px-3 py-1.5 text-xs font-bold text-emerald-700"
              @click.stop
            >
              Invoice
            </a>
          </div>
        </article>
      </div>

      <div class="hidden overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm md:block dark:border-white/10 dark:bg-white/[0.06]">
        <div :class="showAll ? 'max-h-[34rem] overflow-auto' : ''">
          <table class="min-w-full divide-y divide-slate-200 text-left dark:divide-white/10">
            <thead class="sticky top-0 z-10 bg-slate-50 dark:bg-slate-950">
              <tr class="text-[11px] font-black uppercase tracking-[0.16em] text-slate-500 dark:text-emerald-100">
                <th class="px-4 py-3">Run</th>
                <th class="px-4 py-3">Now</th>
                <th class="px-4 py-3">Payment</th>
                <th class="px-4 py-3">Updated</th>
                <th class="px-4 py-3 text-right">Open</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-white/10">
              <tr
                v-for="job in jobs"
                :key="job.id"
                class="cursor-pointer transition hover:bg-slate-50 dark:hover:bg-white/[0.08]"
                @click="emit('open-job', job)"
              >
                <td class="px-4 py-4 align-top">
                  <p class="font-black text-slate-950 dark:text-white">{{ job.title || `Run #${job.id}` }}</p>
                  <p class="mt-1 text-xs text-slate-500 dark:text-emerald-100">
                    {{ job.pickup_label || job.pickup_postcode || '--' }} to {{ job.dropoff_label || job.dropoff_postcode || '--' }}
                  </p>
                  <p class="mt-1 text-xs text-slate-500 dark:text-emerald-100">{{ job.assigned_to?.name || job.driver_name || 'Not assigned yet' }}</p>
                </td>
                <td class="px-4 py-4 align-top">
                  <span class="badge bg-emerald-100 text-emerald-700">{{ formatStatusLabel(job.status) }}</span>
                </td>
                <td class="px-4 py-4 align-top">
                  <span class="badge bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-emerald-100">{{ paymentLabel(job) }}</span>
                </td>
                <td class="px-4 py-4 align-top text-sm text-slate-600 dark:text-emerald-100">
                  {{ formatShortDate(job.updated_at || job.created_at) }}
                </td>
                <td class="px-4 py-4 align-top text-right">
                  <RouterLink
                    :to="`/jobs/${job.id}`"
                    class="inline-flex rounded-full border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-700 hover:border-emerald-200 hover:text-emerald-700 dark:border-white/10 dark:text-emerald-100 dark:hover:border-emerald-300 dark:hover:text-emerald-300"
                    @click.stop
                  >
                    Open
                  </RouterLink>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="flex flex-wrap items-center justify-between gap-3">
        <p v-if="!showAll && totalJobs > jobs.length" class="text-xs text-slate-500 dark:text-emerald-100">
          Showing {{ jobs.length }} of {{ totalJobs }} runs.
        </p>
        <button
          v-if="showAll || totalJobs > jobs.length"
          type="button"
          class="btn-secondary w-full px-4 py-2 text-sm sm:w-auto"
          @click="emit('update:showAll', !showAll)"
        >
          {{ showAll ? 'Show less' : 'View all runs' }}
        </button>
      </div>
    </div>
  </section>
</template>
