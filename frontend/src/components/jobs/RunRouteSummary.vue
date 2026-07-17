<script setup>
import { computed } from 'vue';

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
</script>

<template>
  <section class="tile space-y-3" :class="compact ? 'p-3' : 'p-4'">
    <div class="grid items-stretch gap-3 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]">
      <div class="rounded-2xl bg-slate-50 dark:bg-white/[0.06]" :class="compact ? 'p-2.5' : 'p-3'">
        <h2 class="text-xs font-black uppercase tracking-wide text-slate-500 dark:text-emerald-100">Pickup</h2>
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
        <h2 class="text-xs font-black uppercase tracking-wide text-slate-500 dark:text-emerald-100">Drop-off</h2>
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
