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

function formatCurrency(value) {
  try {
    return new Intl.NumberFormat('en-GB', {
      style: 'currency',
      currency: 'GBP',
      maximumFractionDigits: 0
    }).format(Number(value || 0));
  } catch {
    return `£${Number(value || 0).toFixed(0)}`;
  }
}

function paymentLabel(job) {
  const status = String(job?.payment_status || '').replaceAll('_', ' ');
  return status ? status.charAt(0).toUpperCase() + status.slice(1) : 'Unpaid';
}

function paymentBadgeClass(job) {
  const status = String(job?.payment_status || 'unpaid').toLowerCase();
  if (status === 'paid') return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-400 dark:text-slate-950';
  if (status === 'payout_released') return 'bg-slate-900 text-white dark:bg-white dark:text-slate-950';
  if (status === 'checkout_pending') return 'bg-amber-100 text-amber-700 dark:bg-amber-300 dark:text-slate-950';
  return 'bg-rose-100 text-rose-700 dark:bg-rose-300 dark:text-slate-950';
}

function statusBadgeClass(job) {
  const status = String(job?.status || '').toLowerCase();
  if (status === 'open') return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-400 dark:text-slate-950';
  if (['accepted', 'in_progress', 'collected', 'in_transit'].includes(status)) return 'bg-sky-100 text-sky-700 dark:bg-sky-300 dark:text-slate-950';
  if (['completion_pending', 'delivered'].includes(status)) return 'bg-amber-100 text-amber-700 dark:bg-amber-300 dark:text-slate-950';
  if (['completed', 'closed'].includes(status)) return 'bg-slate-900 text-white dark:bg-white dark:text-slate-950';
  return 'bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-emerald-100';
}

function routeLabel(job) {
  const pickup = job?.pickup_postcode || job?.pickup_label || '--';
  const dropoff = job?.dropoff_postcode || job?.dropoff_label || '--';
  return `${pickup} to ${dropoff}`;
}

function vehicleLabel(job) {
  return [job?.company || 'Customer', job?.vehicle_make || job?.vehicle_model || 'Vehicle']
    .filter(Boolean)
    .join(' · ');
}

function assignedDriverLabel(job) {
  return job?.assigned_to?.name || job?.driver_name || 'No driver assigned';
}

function applicationCount(job) {
  return Number(job?.applications_count ?? job?.job_applications_count ?? job?.applications?.length ?? 0);
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
  <section class="flex h-[calc(100dvh-13rem)] min-h-[32rem] flex-col gap-3 overflow-hidden dark:text-white">
    <div class="shrink-0">
      <div class="section-card space-y-4 bg-white opacity-100 dark:border-white/10 dark:bg-slate-950">
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
            class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100 dark:border-white/10 dark:bg-white/[0.06] dark:text-white"
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
            class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100 dark:border-white/10 dark:bg-white/[0.06] dark:text-white"
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
            class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100 dark:border-white/10 dark:bg-white/[0.06] dark:text-white"
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
      </div>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain pb-32 pr-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
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

      <div v-else class="space-y-3">
      <article
        v-for="job in jobs"
        :key="job.id"
        class="group cursor-pointer rounded-[1.35rem] border border-slate-200 bg-white p-3 shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-200 hover:shadow-md dark:border-white/10 dark:bg-white/[0.06]"
        role="button"
        tabindex="0"
        @click="emit('open-job', job)"
        @keydown.enter="emit('open-job', job)"
        @keydown.space.prevent="emit('open-job', job)"
      >
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="truncate text-lg font-black text-slate-950 dark:text-white">{{ job.title || `Run #${job.id}` }}</p>
            <p class="mt-1 truncate text-sm font-bold text-slate-600 dark:text-emerald-100">
              {{ routeLabel(job) }}
            </p>
            <p class="mt-1 truncate text-xs font-semibold text-slate-500 dark:text-emerald-100/80">
              {{ vehicleLabel(job) }}
            </p>
          </div>

          <div class="shrink-0 text-right">
            <p class="text-xl font-black leading-none text-emerald-600 dark:text-emerald-300">
              {{ formatCurrency(job.price || job.dealer_charge_amount || 0) }}
            </p>
            <p class="mt-1 text-[0.65rem] font-black uppercase tracking-wide text-slate-500 dark:text-emerald-100">
              {{ formatShortDate(job.updated_at || job.created_at) }}
            </p>
          </div>
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-2">
          <span class="badge" :class="statusBadgeClass(job)">
            {{ formatStatusLabel(job.status) }}
          </span>
          <span class="badge" :class="paymentBadgeClass(job)">
            {{ paymentLabel(job) }}
          </span>
          <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-black text-slate-700 dark:bg-white/10 dark:text-emerald-100">
            {{ applicationCount(job) }} applications
          </span>
          <span class="min-w-0 truncate rounded-full bg-slate-100 px-3 py-1 text-xs font-black text-slate-700 dark:bg-white/10 dark:text-emerald-100">
            {{ assignedDriverLabel(job) }}
          </span>
        </div>

        <div class="mt-3 grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
          <div class="grid grid-cols-2 gap-2 text-xs">
            <div class="rounded-2xl bg-slate-50 px-3 py-2 dark:bg-white/[0.05]">
              <p class="font-black uppercase tracking-wide text-slate-500 dark:text-emerald-100">Pickup</p>
              <p class="mt-0.5 truncate font-black text-slate-950 dark:text-white">{{ job.pickup_postcode || job.pickup_label || '--' }}</p>
            </div>
            <div class="rounded-2xl bg-slate-50 px-3 py-2 dark:bg-white/[0.05]">
              <p class="font-black uppercase tracking-wide text-slate-500 dark:text-emerald-100">Drop-off</p>
              <p class="mt-0.5 truncate font-black text-slate-950 dark:text-white">{{ job.dropoff_postcode || job.dropoff_label || '--' }}</p>
            </div>
          </div>

          <div class="flex gap-2">
            <a
              v-if="resolveInvoiceLink(job)"
              :href="resolveInvoiceLink(job)"
              target="_blank"
              rel="noreferrer"
              class="inline-flex flex-1 items-center justify-center rounded-2xl border border-emerald-200 px-4 py-2 text-sm font-black text-emerald-700 sm:flex-none dark:border-emerald-300/30 dark:text-emerald-300"
              @click.stop
            >
              Invoice
            </a>
            <RouterLink
              :to="`/jobs/${job.id}`"
              class="inline-flex flex-1 items-center justify-center rounded-2xl bg-slate-950 px-4 py-2 text-sm font-black text-white sm:flex-none dark:bg-emerald-400 dark:text-slate-950"
              @click.stop
            >
              Open
            </RouterLink>
          </div>
        </div>
      </article>

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
    </div>
  </section>
</template>
