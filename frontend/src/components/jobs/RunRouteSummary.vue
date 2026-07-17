<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
  job: {
    type: Object,
    required: true
  },
  compact: {
    type: Boolean,
    default: false
  }
});

const pickupTitle = computed(() => props.job.pickup_label || props.job.pickup_postcode || 'Pickup location');
const dropoffTitle = computed(() => props.job.dropoff_label || props.job.dropoff_postcode || 'Drop-off location');
const pickupCopyText = computed(() => locationCopyText(props.job.pickup_label, props.job.pickup_postcode));
const dropoffCopyText = computed(() => locationCopyText(props.job.dropoff_label, props.job.dropoff_postcode));
const copiedMessage = ref('');
let copiedTimer = null;

const showPickupPostcode = computed(() => props.job.pickup_label && props.job.pickup_label !== props.job.pickup_postcode);
const showDropoffPostcode = computed(() => props.job.dropoff_label && props.job.dropoff_label !== props.job.dropoff_postcode);

const distanceLabel = computed(() => (props.job.distance_mi ? `${props.job.distance_mi} mi` : '--'));
const pickupScheduleLabel = computed(() => formatSchedule(props.job.pickup_ready_at));
const deliveryScheduleLabel = computed(() => formatSchedule(props.job.delivery_due_at));

const transportLabel = computed(() => {
  const raw = String(props.job.transport_type || '').toLowerCase();

  if (raw === 'drive_away') return 'Drive-away';
  if (raw === 'trailer') return 'Trailer';

  return props.job.transport_type || '--';
});

function formatSchedule(value) {
  if (!value) return null;

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return null;

  return new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  }).format(date);
}

function locationCopyText(label, postcode) {
  const parts = [label, postcode]
    .map((value) => String(value || '').trim())
    .filter(Boolean);

  return Array.from(new Set(parts)).join(', ');
}

async function copyText(value) {
  if (!value) return;

  if (navigator?.clipboard?.writeText) {
    await navigator.clipboard.writeText(value);
    return;
  }

  const textarea = document.createElement('textarea');
  textarea.value = value;
  textarea.setAttribute('readonly', '');
  textarea.style.position = 'fixed';
  textarea.style.opacity = '0';
  document.body.appendChild(textarea);
  textarea.select();
  document.execCommand('copy');
  document.body.removeChild(textarea);
}

async function copyLocation(type) {
  const isPickup = type === 'pickup';
  const value = isPickup ? pickupCopyText.value : dropoffCopyText.value;

  try {
    await copyText(value);
    copiedMessage.value = isPickup ? 'Copied pickup location' : 'Copied drop-off location';
    window.clearTimeout(copiedTimer);
    copiedTimer = window.setTimeout(() => {
      copiedMessage.value = '';
    }, 2200);
  } catch (error) {
    console.warn('Failed to copy location', error);
    copiedMessage.value = 'Could not copy location';
  }
}
</script>

<template>
  <section class="tile space-y-3" :class="compact ? 'p-3' : 'p-4'">
    <p
      v-if="copiedMessage"
      class="rounded-2xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-black text-emerald-700 dark:border-emerald-400/30 dark:bg-emerald-400/10 dark:text-emerald-200"
    >
      {{ copiedMessage }}
    </p>

    <div class="grid items-stretch gap-3 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]">
      <div class="rounded-2xl bg-slate-50 dark:bg-white/[0.06]" :class="compact ? 'p-2.5' : 'p-3'">
        <div class="flex items-center justify-between gap-3">
          <h2 class="text-xs font-black uppercase tracking-wide text-slate-500 dark:text-emerald-100">Pickup</h2>
          <button
            type="button"
            class="inline-flex size-8 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 shadow-sm transition hover:border-emerald-200 hover:text-emerald-700 dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100"
            aria-label="Copy pickup location"
            @click="copyLocation('pickup')"
          >
            <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
              <rect x="9" y="9" width="10" height="10" rx="2" />
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 15H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v1" />
            </svg>
          </button>
        </div>
        <p class="mt-1 break-words font-black text-slate-950 dark:text-white" :class="compact ? 'text-base' : 'text-lg'">{{ pickupTitle }}</p>
        <p v-if="showPickupPostcode" class="mt-1 text-sm text-slate-600 dark:text-emerald-100">
          {{ job.pickup_postcode || '--' }}
        </p>
        <p v-if="pickupScheduleLabel" class="mt-2 text-xs font-black uppercase tracking-wide text-emerald-700 dark:text-emerald-300">
          Ready {{ pickupScheduleLabel }}
        </p>
      </div>

      <div class="flex items-center justify-center">
        <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-black uppercase tracking-wide text-emerald-700 dark:bg-emerald-400 dark:text-slate-950">
          to
        </span>
      </div>

      <div class="rounded-2xl bg-slate-50 dark:bg-white/[0.06]" :class="compact ? 'p-2.5' : 'p-3'">
        <div class="flex items-center justify-between gap-3">
          <h2 class="text-xs font-black uppercase tracking-wide text-slate-500 dark:text-emerald-100">Drop-off</h2>
          <button
            type="button"
            class="inline-flex size-8 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 shadow-sm transition hover:border-emerald-200 hover:text-emerald-700 dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100"
            aria-label="Copy drop-off location"
            @click="copyLocation('dropoff')"
          >
            <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
              <rect x="9" y="9" width="10" height="10" rx="2" />
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 15H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v1" />
            </svg>
          </button>
        </div>
        <p class="mt-1 break-words font-black text-slate-950 dark:text-white" :class="compact ? 'text-base' : 'text-lg'">{{ dropoffTitle }}</p>
        <p v-if="showDropoffPostcode" class="mt-1 text-sm text-slate-600 dark:text-emerald-100">
          {{ job.dropoff_postcode || '--' }}
        </p>
        <p v-if="deliveryScheduleLabel" class="mt-2 text-xs font-black uppercase tracking-wide text-emerald-700 dark:text-emerald-300">
          Due {{ deliveryScheduleLabel }}
        </p>
      </div>
    </div>

    <div class="grid grid-cols-2 gap-3 text-sm">
      <div class="rounded-2xl bg-slate-50 dark:bg-white/[0.06]" :class="compact ? 'p-2.5' : 'p-3'">
        <p class="text-xs font-black uppercase tracking-wide text-slate-500 dark:text-emerald-100">Distance</p>
        <p class="mt-1 text-base font-black text-slate-950 dark:text-white">{{ distanceLabel }}</p>
      </div>

      <div class="rounded-2xl bg-slate-50 dark:bg-white/[0.06]" :class="compact ? 'p-2.5' : 'p-3'">
        <p class="text-xs font-black uppercase tracking-wide text-slate-500 dark:text-emerald-100">Transport</p>
        <p class="mt-1 text-base font-black text-slate-950 dark:text-white">{{ transportLabel }}</p>
      </div>
    </div>
  </section>
</template>
