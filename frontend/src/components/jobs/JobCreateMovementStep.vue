<script setup>
function formatDateTime(value) {
  if (!value) return '';

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';

  return new Intl.DateTimeFormat('en-GB', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false
  }).format(date).replace(',', ' ·');
}

defineProps({
  form: { type: Object, required: true },
  transportOptions: { type: Array, required: true },
  validationState: { type: Object, required: true }
});

defineEmits(['select-transport', 'back', 'next']);

const listingTypeOptions = [
  { value: 'private', label: 'Private job', helper: 'A direct private vehicle movement.' },
  { value: 'auction', label: 'Auction job', helper: 'Requires the auction collection reference.' }
];
</script>

<template>
  <section class="space-y-4 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm md:p-5 dark:border-white/10 dark:bg-white/[0.06]">
    <header class="space-y-1">
      <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700">Movement</p>
      <h2 class="text-xl font-black text-slate-950">Transport and timing</h2>
      <p class="text-sm text-slate-600">
        Choose how the vehicle moves, then add the timings the driver needs to see.
      </p>
    </header>

    <div>
      <p class="text-sm font-bold text-slate-700">Transport type</p>
      <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
        <button
          v-for="option in transportOptions"
          :key="option.value"
          type="button"
          :class="[
            'min-w-0 rounded-2xl border p-3 text-left transition hover:-translate-y-0.5 hover:shadow-md',
            form.transport_type === option.value
              ? 'border-emerald-300 bg-emerald-50 text-emerald-900 ring-1 ring-emerald-200 dark:border-emerald-400/50 dark:bg-emerald-400/10 dark:text-emerald-300 dark:ring-emerald-400/30'
              : 'border-slate-200 bg-white text-slate-700 hover:border-emerald-200 dark:border-white/10 dark:bg-slate-950 dark:text-emerald-100 dark:hover:border-emerald-400/50'
          ]"
          @click="$emit('select-transport', option.value)"
        >
          <span class="block font-black">{{ option.label }}</span>
          <span class="mt-1 block text-xs leading-5 text-slate-500 dark:text-emerald-100">{{ option.helper }}</span>
        </button>
      </div>
    </div>

    <div class="space-y-2">
      <div>
        <p class="text-sm font-bold text-slate-700">Job type</p>
        <p class="mt-1 text-xs text-slate-500">Auction references help the driver collect the correct vehicle.</p>
      </div>
      <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
        <button
          v-for="option in listingTypeOptions"
          :key="option.value"
          type="button"
          :class="[
            'min-w-0 rounded-2xl border p-3 text-left transition hover:-translate-y-0.5 hover:shadow-md',
            form.listing_type === option.value
              ? 'border-emerald-300 bg-emerald-50 text-emerald-900 ring-1 ring-emerald-200 dark:border-emerald-400/50 dark:bg-emerald-400/10 dark:text-emerald-300 dark:ring-emerald-400/30'
              : 'border-slate-200 bg-white text-slate-700 hover:border-emerald-200 dark:border-white/10 dark:bg-slate-950 dark:text-emerald-100 dark:hover:border-emerald-400/50'
          ]"
          @click="form.listing_type = option.value"
        >
          <span class="block font-black">{{ option.label }}</span>
          <span class="mt-1 block text-xs leading-5 text-slate-500 dark:text-emerald-100">{{ option.helper }}</span>
        </button>
      </div>
      <p v-if="validationState.listing_type" class="text-xs font-bold text-rose-600">Choose a job type.</p>
      <label v-if="form.listing_type === 'auction'" class="block">
        <span class="text-xs font-black uppercase tracking-wide text-slate-500">Auction reference</span>
        <input
          v-model="form.auction_reference"
          type="text"
          maxlength="100"
          placeholder="e.g. ABC-12345"
          class="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-base font-semibold text-slate-900 outline-none focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200 dark:border-white/10 dark:bg-slate-950 dark:text-white"
          :class="validationState.auction_reference ? 'border-rose-400 ring-2 ring-rose-200' : ''"
        />
        <span v-if="validationState.auction_reference" class="mt-1 block text-xs font-bold text-rose-600">Enter the auction reference for collection.</span>
      </label>
    </div>

    <div class="grid gap-3 md:grid-cols-2">
      <div
        class="min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-slate-50 p-3 dark:border-white/10 dark:bg-slate-950"
        :class="validationState.pickup_at ? 'border-rose-400 bg-rose-50 dark:border-rose-400 dark:bg-rose-400/10' : ''"
      >
        <label class="block min-w-0">
          <span class="text-xs font-black uppercase tracking-wide text-slate-500">Pickup ready</span>
          <div class="relative mt-2 min-h-14 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition focus-within:border-emerald-300 focus-within:ring-2 focus-within:ring-emerald-200 dark:border-white/10 dark:bg-white/[0.06]">
            <div class="pointer-events-none flex min-h-14 items-center justify-between gap-3 px-4 py-3">
              <span class="truncate text-base font-semibold text-slate-800 dark:text-emerald-100">
                {{ formatDateTime(form.pickup_at) || 'Select date and time' }}
              </span>
              <span class="shrink-0 text-lg text-emerald-700 dark:text-emerald-300" aria-hidden="true">⌄</span>
            </div>
            <input
              v-model="form.pickup_at"
              type="datetime-local"
              aria-label="Pickup ready date and time"
              class="absolute inset-0 h-full w-full cursor-pointer opacity-0"
            />
          </div>
        </label>
      </div>

      <div
        class="min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-slate-50 p-3 dark:border-white/10 dark:bg-slate-950"
        :class="validationState.delivery_at ? 'border-rose-400 bg-rose-50 dark:border-rose-400 dark:bg-rose-400/10' : ''"
      >
        <label class="block min-w-0">
          <span class="text-xs font-black uppercase tracking-wide text-slate-500">Delivery due</span>
          <div class="relative mt-2 min-h-14 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition focus-within:border-emerald-300 focus-within:ring-2 focus-within:ring-emerald-200 dark:border-white/10 dark:bg-white/[0.06]">
            <div class="pointer-events-none flex min-h-14 items-center justify-between gap-3 px-4 py-3">
              <span class="truncate text-base font-semibold text-slate-800 dark:text-emerald-100">
                {{ formatDateTime(form.delivery_at) || 'Select date and time' }}
              </span>
              <span class="shrink-0 text-lg text-emerald-700 dark:text-emerald-300" aria-hidden="true">⌄</span>
            </div>
            <input
              v-model="form.delivery_at"
              type="datetime-local"
              aria-label="Delivery due date and time"
              class="absolute inset-0 h-full w-full cursor-pointer opacity-0"
            />
          </div>
        </label>
      </div>
    </div>

    <div class="flex items-center justify-between gap-3">
      <button type="button" class="btn-secondary px-5" @click="$emit('back')">Back</button>
      <button type="button" class="btn-primary px-5 py-3" @click="$emit('next')">Next</button>
    </div>
  </section>
</template>
