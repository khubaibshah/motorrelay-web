<script setup>
import { computed } from 'vue';
import { Capacitor } from '@capacitor/core';
import { formatStatusLabel } from '@/utils/statusLabels';

const props = defineProps({
  jobs: {
    type: Array,
    default: () => []
  },
  query: {
    type: String,
    default: ''
  },
  radius: {
    type: [Number, String],
    default: 25
  },
  focused: {
    type: Boolean,
    default: false
  },
  suggestions: {
    type: Array,
    default: () => []
  },
  suggestionsLoading: {
    type: Boolean,
    default: false
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
    default: 'No runs found.'
  },
  marketplaceLabel: {
    type: String,
    default: ''
  },
  appliedJobIds: {
    type: Object,
    default: () => new Set()
  }
});

const emit = defineEmits([
  'update:query',
  'update:radius',
  'update:focused',
  'search',
  'input',
  'clear',
  'choose-suggestion',
  'open-job',
  'apply'
]);

const priceFormatter = new Intl.NumberFormat('en-GB', {
  style: 'currency',
  currency: 'GBP',
  maximumFractionDigits: 0
});

const isIosApp = computed(() => Capacitor.isNativePlatform() && Capacitor.getPlatform() === 'ios');

function updateQuery(event) {
  emit('update:query', event.target.value);
  emit('input');
}

function updateRadius(event) {
  const value = event.target.value === 'all' ? 'all' : Number(event.target.value);
  emit('update:radius', value);
  emit('search');
}

function hasApplied(jobId) {
  return props.appliedJobIds?.has?.(jobId) ?? false;
}

function driverPayoutForJob(job) {
  const storedPayout = Number(job?.driver_payout_amount || 0);
  if (storedPayout > 0) return storedPayout;

  const price = Number(job?.price || 0);
  const storedFee = Number(job?.platform_fee_amount || 0);
  const platformFee = storedFee > 0 ? storedFee : Math.round(price * 0.1 * 100) / 100;

  return Math.max(price - platformFee, 0);
}

function formatTransportType(value) {
  const normalized = String(value || '').toLowerCase();
  if (normalized === 'trailer') return 'Trailer';
  if (normalized === 'drive_away') return 'Drive-away';
  return value || '--';
}

function formatDriverDistance(job) {
  const distance = Number(job?.driver_distance_mi);

  if (!Number.isFinite(distance)) {
    return null;
  }

  if (distance < 1) {
    return 'Pickup under 1 mi away';
  }

  return `Pickup ${distance.toFixed(distance >= 10 ? 0 : 1)} mi away`;
}

function vehicleInitial(job) {
  const source = job?.vehicle_make || job?.title || 'MR';
  return String(source).trim().charAt(0).toUpperCase() || 'M';
}

function formatRunDate(job) {
  const rawDate = job?.pickup_ready_at || job?.created_at;
  if (!rawDate) return null;

  const date = new Date(rawDate);
  if (Number.isNaN(date.getTime())) return null;

  return new Intl.DateTimeFormat('en-GB', {
    day: '2-digit',
    month: 'short'
  }).format(date);
}

function formatRunTime(job) {
  const rawDate = job?.pickup_ready_at;
  if (!rawDate) return null;

  const date = new Date(rawDate);
  if (Number.isNaN(date.getTime())) return null;

  return new Intl.DateTimeFormat('en-GB', {
    hour: '2-digit',
    minute: '2-digit'
  }).format(date);
}
</script>

<template>
  <section
    v-if="isIosApp"
    class="order-1 flex h-[calc(100dvh-15.5rem)] min-h-[31rem] flex-col overflow-hidden sm:hidden"
  >
    <header class="shrink-0 space-y-3 rounded-[2rem] border border-slate-200 bg-white/95 p-3 shadow-xl shadow-slate-950/10 dark:border-white/10 dark:bg-slate-950">
      <div class="flex items-center justify-between gap-3 px-1">
        <div>
          <p class="text-[10px] font-black uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-300">Run marketplace</p>
          <h2 class="mt-0.5 text-lg font-black tracking-tight text-slate-950 dark:text-white">Available runs</h2>
        </div>
        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-black text-slate-600 dark:bg-white/10 dark:text-emerald-100">
          {{ jobs.length }}
        </span>
      </div>

      <form class="flex items-center gap-2" @submit.prevent="emit('search')">
        <label class="flex min-w-0 flex-1 items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2.5 shadow-inner shadow-white/60 focus-within:border-emerald-500 focus-within:bg-white dark:border-white/10 dark:bg-white/[0.06] dark:shadow-none">
          <svg viewBox="0 0 24 24" class="h-4 w-4 shrink-0 text-slate-400 dark:text-emerald-100" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <circle cx="11" cy="11" r="7" />
            <path stroke-linecap="round" d="m20 20-3.5-3.5" />
          </svg>
          <input
            :value="query"
            type="text"
            inputmode="search"
            autocomplete="off"
            placeholder="Search by town or postcode"
            class="min-w-0 flex-1 border-0 bg-transparent text-sm font-bold text-slate-950 outline-none placeholder:font-semibold placeholder:text-slate-400 dark:text-white dark:placeholder:text-emerald-100/40"
            @focus="emit('update:focused', true)"
            @input="updateQuery"
          >
        </label>

        <select
          :value="radius"
          aria-label="Search radius"
          class="h-11 rounded-2xl border border-slate-200 bg-slate-50 px-2 text-xs font-black text-slate-900 outline-none dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100"
          @change="updateRadius"
        >
          <option :value="25">25</option>
          <option :value="50">50</option>
          <option :value="100">100</option>
          <option value="all">All</option>
        </select>
      </form>

      <div v-if="focused" class="rounded-3xl border border-slate-200 bg-white p-2 shadow-sm dark:border-white/10 dark:bg-slate-900">
        <p v-if="suggestionsLoading" class="px-3 py-2 text-xs font-bold text-slate-500 dark:text-emerald-100">
          Finding places...
        </p>
        <button
          v-for="suggestion in suggestions"
          :key="`${suggestion.label}-${suggestion.value}`"
          type="button"
          class="flex w-full items-center gap-3 rounded-2xl px-3 py-2 text-left transition hover:bg-emerald-50 dark:hover:bg-emerald-300/10"
          @click="emit('choose-suggestion', suggestion)"
        >
          <span class="grid size-8 place-items-center rounded-full bg-slate-100 text-sm text-slate-700 dark:bg-white/10 dark:text-emerald-100">
            {{ suggestion.icon === 'home' ? '⌂' : '⌖' }}
          </span>
          <span class="min-w-0">
            <span class="block truncate text-sm font-black text-slate-900 dark:text-white">{{ suggestion.label }}</span>
            <span v-if="suggestion.sublabel" class="block truncate text-xs font-semibold text-slate-500 dark:text-emerald-100">{{ suggestion.sublabel }}</span>
          </span>
        </button>
        <p v-if="!suggestionsLoading && query.trim().length >= 2 && !suggestions.length" class="px-3 py-2 text-xs font-bold text-slate-500 dark:text-emerald-100">
          No places found. Try a postcode like BB9 or a nearby town.
        </p>
      </div>

      <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-1 pb-2 dark:border-white/10">
        <p class="text-sm font-black text-emerald-800 dark:text-emerald-300">Available runs</p>
        <p class="truncate text-right text-[11px] font-bold text-slate-500 dark:text-emerald-100">
          {{ marketplaceLabel }}
        </p>
      </div>
    </header>

    <div class="mt-3 min-h-0 flex-1 overflow-y-auto overscroll-contain [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
      <p v-if="error" class="rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-700 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-200">
        {{ error }}
      </p>

      <div v-if="loading && !jobs.length" class="rounded-2xl border bg-white p-4 text-sm text-slate-600 dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100">
        Loading runs...
      </div>

      <div v-else-if="!jobs.length" class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-600 dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100">
        {{ emptyMessage }}
      </div>

      <div v-else class="space-y-2 pb-2">
        <article
          v-for="job in jobs"
          :key="job.id"
          class="rounded-3xl border border-slate-200 bg-white p-3 shadow-lg shadow-slate-950/5 dark:border-white/10 dark:bg-slate-950"
        >
          <button type="button" class="grid w-full grid-cols-[2.75rem_minmax(0,1fr)_auto] gap-3 text-left" @click="emit('open-job', job)">
            <span class="grid size-11 place-items-center rounded-2xl bg-emerald-900 text-base font-black text-white shadow-sm dark:bg-emerald-400 dark:text-slate-950">
              {{ vehicleInitial(job) }}
            </span>

            <span class="min-w-0">
              <span class="inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-[9px] font-black uppercase tracking-wide text-emerald-800 dark:bg-emerald-400/15 dark:text-emerald-200">
                {{ formatTransportType(job.transport_type) }}
              </span>
              <span class="mt-1 block truncate text-sm font-black text-slate-950 dark:text-white">
                {{ job.vehicle_make || job.title || 'Vehicle run' }}
              </span>
              <span class="mt-0.5 block truncate text-xs font-bold text-slate-600 dark:text-emerald-100">
                {{ job.pickup_postcode || job.pickup_label || '--' }} → {{ job.dropoff_postcode || job.dropoff_label || '--' }}
              </span>
              <span class="mt-1 flex flex-wrap items-center gap-2 text-[11px] font-bold text-slate-500 dark:text-slate-300">
                <span v-if="formatRunDate(job)">▦ {{ formatRunDate(job) }}<template v-if="formatRunTime(job)"> · {{ formatRunTime(job) }}</template></span>
                <span v-if="formatDriverDistance(job)">⌖ {{ formatDriverDistance(job).replace('Pickup ', '') }}</span>
              </span>
            </span>

            <span class="text-right">
              <span class="block text-base font-black text-emerald-700 dark:text-emerald-300">
                {{ priceFormatter.format(driverPayoutForJob(job)) }}
              </span>
              <span class="mt-1 inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-[9px] font-black uppercase tracking-wide text-slate-600 dark:bg-white/10 dark:text-emerald-100">
                {{ formatStatusLabel(job.status) }}
              </span>
            </span>
          </button>

          <div class="mt-3 grid grid-cols-2 gap-2">
            <button
              type="button"
              class="rounded-2xl bg-slate-950 px-3 py-2 text-xs font-black text-white transition disabled:opacity-60 dark:bg-emerald-400 dark:text-slate-950"
              :disabled="hasApplied(job.id)"
              @click.stop="emit('apply', job)"
            >
              <span v-if="hasApplied(job.id)">Sent</span>
              <span v-else>Request run</span>
            </button>
            <button
              type="button"
              class="rounded-2xl border border-slate-200 bg-white px-3 py-2 text-xs font-black text-slate-700 transition dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100"
              @click="emit('open-job', job)"
            >
              View
            </button>
          </div>
        </article>
      </div>
    </div>
  </section>

  <section v-else class="section-card order-1 space-y-3 p-4 dark:border-white/10 dark:bg-slate-950 sm:p-5">
    <header class="space-y-3">
      <div class="flex items-start justify-between gap-3">
        <div>
          <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-300">Run marketplace</p>
          <h2 class="mt-1 text-lg font-black tracking-tight text-slate-950 dark:text-emerald-300">Available runs</h2>
        </div>
        <span class="w-fit rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600 dark:bg-white/10 dark:text-emerald-100">
          {{ jobs.length }} shown
        </span>
      </div>

      <form
        class="grid gap-2 rounded-3xl border-2 border-emerald-700/70 bg-white p-3 shadow-sm transition focus-within:border-emerald-500 focus-within:ring-4 focus-within:ring-emerald-100 dark:border-emerald-300/40 dark:bg-white/[0.04] dark:focus-within:ring-emerald-300/10 sm:grid-cols-[minmax(0,1fr)_auto_auto]"
        @submit.prevent="emit('search')"
      >
        <label class="flex min-w-0 items-center gap-3">
          <span class="text-lg text-slate-700 dark:text-emerald-200">●</span>
          <input
            :value="query"
            type="text"
            inputmode="search"
            autocomplete="off"
            placeholder="City, town, or postcode"
            class="min-w-0 flex-1 border-0 bg-transparent py-2 text-sm font-black uppercase text-slate-900 outline-none placeholder:normal-case placeholder:font-semibold placeholder:text-slate-400 dark:text-emerald-100 dark:placeholder:text-emerald-100/40"
            @focus="emit('update:focused', true)"
            @input="updateQuery"
          >
          <button
            v-if="query"
            type="button"
            class="rounded-full px-2 py-1 text-xs font-black text-slate-500 hover:bg-slate-100 hover:text-emerald-700 dark:text-emerald-100 dark:hover:bg-white/10"
            @click="emit('clear')"
          >
            Clear
          </button>
        </label>
        <select
          :value="radius"
          class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-black text-slate-900 outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100 dark:border-white/10 dark:bg-slate-950 dark:text-emerald-100"
          @change="updateRadius"
        >
          <option :value="25">25 mi</option>
          <option :value="50">50 mi</option>
          <option :value="100">100 mi</option>
          <option value="all">All jobs</option>
        </select>
        <button type="submit" class="btn-primary min-h-0 px-4 py-2 text-sm">
          Search
        </button>
      </form>

      <div v-if="focused" class="rounded-3xl border border-slate-200 bg-white p-2 shadow-sm dark:border-white/10 dark:bg-white/[0.04]">
        <p v-if="suggestionsLoading" class="px-3 py-2 text-xs font-bold text-slate-500 dark:text-emerald-100">
          Finding places...
        </p>
        <button
          v-for="suggestion in suggestions"
          :key="`${suggestion.label}-${suggestion.value}`"
          type="button"
          class="flex w-full items-center gap-3 rounded-2xl px-3 py-2 text-left transition hover:bg-emerald-50 dark:hover:bg-emerald-300/10"
          @click="emit('choose-suggestion', suggestion)"
        >
          <span class="grid size-8 place-items-center rounded-full bg-slate-100 text-sm text-slate-700 dark:bg-slate-900 dark:text-emerald-100">
            {{ suggestion.icon === 'home' ? '⌂' : '⌖' }}
          </span>
          <span class="min-w-0">
            <span class="block truncate text-sm font-black text-slate-900 dark:text-white">
              {{ suggestion.label }}
            </span>
            <span v-if="suggestion.sublabel" class="block truncate text-xs font-semibold text-slate-500 dark:text-emerald-100">
              {{ suggestion.sublabel }}
            </span>
          </span>
        </button>
        <p v-if="!suggestionsLoading && query.trim().length >= 2 && !suggestions.length" class="px-3 py-2 text-xs font-bold text-slate-500 dark:text-emerald-100">
          No places found. Try a postcode like BB9 or a nearby town.
        </p>
      </div>

      <p class="px-1 text-xs font-bold text-slate-500 dark:text-emerald-100">
        {{ marketplaceLabel }}
      </p>
    </header>

    <p v-if="error" class="rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-700 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-200">
      {{ error }}
    </p>

    <div v-if="loading && !jobs.length" class="rounded-2xl border bg-white p-4 text-sm text-slate-600 dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100">
      Loading runs...
    </div>

    <div v-else-if="!jobs.length" class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-600 dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100">
      {{ emptyMessage }}
    </div>

    <div v-else class="space-y-2">
      <article
        v-for="job in jobs"
        :key="job.id"
        class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-200 hover:shadow-xl dark:border-white/10 dark:bg-white/[0.06] dark:hover:bg-white/[0.09]"
      >
        <div class="flex items-start justify-between gap-3">
          <button type="button" class="min-w-0 flex-1 text-left" @click="emit('open-job', job)">
            <p class="truncate text-base font-black text-slate-950 dark:text-white">
              {{ job.pickup_postcode || job.pickup_label || '--' }} to {{ job.dropoff_postcode || job.dropoff_label || '--' }}
            </p>
            <p class="mt-1 truncate text-xs font-semibold text-slate-600 dark:text-emerald-100">
              {{ job.vehicle_make || 'Vehicle' }} · {{ formatTransportType(job.transport_type) }}
            </p>
            <p v-if="formatDriverDistance(job)" class="mt-1 text-xs font-black text-emerald-700 dark:text-emerald-300">
              {{ formatDriverDistance(job) }}
            </p>
          </button>

          <div class="shrink-0 text-right">
            <p class="text-lg font-black text-emerald-600 dark:text-emerald-300">
              {{ priceFormatter.format(driverPayoutForJob(job)) }}
            </p>
            <span class="mt-1 inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-black uppercase tracking-wide text-slate-600 dark:bg-white/10 dark:text-emerald-100">
              {{ formatStatusLabel(job.status) }}
            </span>
          </div>
        </div>

        <div class="mt-3 flex gap-2">
          <button
            type="button"
            class="btn-primary min-h-0 flex-1 px-3 py-2 text-xs disabled:opacity-60"
            :disabled="hasApplied(job.id)"
            @click.stop="emit('apply', job)"
          >
            <span v-if="hasApplied(job.id)">Application sent</span>
            <span v-else>Request this run</span>
          </button>
          <button
            type="button"
            class="btn-secondary min-h-0 flex-1 px-3 py-2 text-xs"
            @click="emit('open-job', job)"
          >
            View details
          </button>
        </div>
      </article>
    </div>
  </section>
</template>
