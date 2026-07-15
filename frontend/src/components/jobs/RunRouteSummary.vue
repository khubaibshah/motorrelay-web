<script setup>
import { computed } from 'vue';

const props = defineProps({
  job: {
    type: Object,
    required: true
  }
});

const pickupTitle = computed(() => props.job.pickup_label || props.job.pickup_postcode || 'Pickup location');
const dropoffTitle = computed(() => props.job.dropoff_label || props.job.dropoff_postcode || 'Drop-off location');

const showPickupPostcode = computed(() => props.job.pickup_label && props.job.pickup_label !== props.job.pickup_postcode);
const showDropoffPostcode = computed(() => props.job.dropoff_label && props.job.dropoff_label !== props.job.dropoff_postcode);

const distanceLabel = computed(() => (props.job.distance_mi ? `${props.job.distance_mi} mi` : '--'));

const transportLabel = computed(() => {
  const raw = String(props.job.transport_type || '').toLowerCase();

  if (raw === 'drive_away') return 'Drive-away';
  if (raw === 'trailer') return 'Trailer';

  return props.job.transport_type || '--';
});
</script>

<template>
  <section class="tile space-y-3 p-4">
    <div class="grid items-stretch gap-3 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]">
      <div class="rounded-2xl bg-slate-50 p-3 dark:bg-white/[0.06]">
        <h2 class="text-xs font-black uppercase tracking-wide text-slate-500 dark:text-emerald-100">Pickup</h2>
        <p class="mt-1 break-words text-lg font-black text-slate-950 dark:text-white">{{ pickupTitle }}</p>
        <p v-if="showPickupPostcode" class="mt-1 text-sm text-slate-600 dark:text-emerald-100">
          {{ job.pickup_postcode || '--' }}
        </p>
      </div>

      <div class="flex items-center justify-center">
        <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-black uppercase tracking-wide text-emerald-700 dark:bg-emerald-400 dark:text-slate-950">
          to
        </span>
      </div>

      <div class="rounded-2xl bg-slate-50 p-3 dark:bg-white/[0.06]">
        <h2 class="text-xs font-black uppercase tracking-wide text-slate-500 dark:text-emerald-100">Drop-off</h2>
        <p class="mt-1 break-words text-lg font-black text-slate-950 dark:text-white">{{ dropoffTitle }}</p>
        <p v-if="showDropoffPostcode" class="mt-1 text-sm text-slate-600 dark:text-emerald-100">
          {{ job.dropoff_postcode || '--' }}
        </p>
      </div>
    </div>

    <div class="grid grid-cols-2 gap-3 text-sm">
      <div class="rounded-2xl bg-slate-50 p-3 dark:bg-white/[0.06]">
        <p class="text-xs font-black uppercase tracking-wide text-slate-500 dark:text-emerald-100">Distance</p>
        <p class="mt-1 text-base font-black text-slate-950 dark:text-white">{{ distanceLabel }}</p>
      </div>

      <div class="rounded-2xl bg-slate-50 p-3 dark:bg-white/[0.06]">
        <p class="text-xs font-black uppercase tracking-wide text-slate-500 dark:text-emerald-100">Transport</p>
        <p class="mt-1 text-base font-black text-slate-950 dark:text-white">{{ transportLabel }}</p>
      </div>
    </div>
  </section>
</template>
