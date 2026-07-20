<script setup>
const props = defineProps({
  form: { type: Object, required: true },
  validationState: { type: Object, required: true }
});

const emit = defineEmits(['back', 'next']);

const listingTypeOptions = [
  { value: 'private', label: 'Private job', helper: 'A direct private vehicle movement.' },
  { value: 'auction', label: 'Auction job', helper: 'Requires the auction collection reference.' }
];
</script>

<template>
  <section class="space-y-4 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm md:p-5 dark:border-white/10 dark:bg-white/[0.06]">
    <header class="space-y-1">
      <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700">Job type</p>
      <h2 class="text-xl font-black text-slate-950">Private or auction?</h2>
      <p class="text-sm text-slate-600">Tell the driver what kind of collection this run involves.</p>
    </header>

    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
      <button
        v-for="option in listingTypeOptions"
        :key="option.value"
        type="button"
        :class="[
          'min-w-0 rounded-2xl border p-4 text-left transition hover:-translate-y-0.5 hover:shadow-md',
          props.form.listing_type === option.value
            ? 'border-emerald-300 bg-emerald-50 text-emerald-900 ring-1 ring-emerald-200 dark:border-emerald-400/50 dark:bg-emerald-400/10 dark:text-emerald-300 dark:ring-emerald-400/30'
            : 'border-slate-200 bg-white text-slate-700 hover:border-emerald-200 dark:border-white/10 dark:bg-slate-950 dark:text-emerald-100 dark:hover:border-emerald-400/50'
        ]"
        @click="props.form.listing_type = option.value"
      >
        <span class="block font-black">{{ option.label }}</span>
        <span class="mt-1 block text-xs leading-5 text-slate-500 dark:text-emerald-100">{{ option.helper }}</span>
      </button>
    </div>

    <p v-if="validationState.listing_type" class="text-xs font-bold text-rose-600">Choose a job type.</p>

    <label v-if="props.form.listing_type === 'auction'" class="block">
      <span class="text-xs font-black uppercase tracking-wide text-slate-500">Auction reference</span>
      <input
        v-model="props.form.auction_reference"
        type="text"
        maxlength="100"
        placeholder="e.g. ABC-12345"
        class="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-base font-semibold text-slate-900 outline-none focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200 dark:border-white/10 dark:bg-slate-950 dark:text-white"
        :class="validationState.auction_reference ? 'border-rose-400 ring-2 ring-rose-200' : ''"
      />
      <span v-if="validationState.auction_reference" class="mt-1 block text-xs font-bold text-rose-600">Enter the auction reference for collection.</span>
    </label>

    <label class="block">
      <span class="text-xs font-black uppercase tracking-wide text-slate-500">Vehicle information for the driver</span>
      <textarea
        v-model="props.form.notes"
        rows="4"
        maxlength="2000"
        placeholder="Add an assessment summary, collection instructions, faults, keys, or anything important for the driver."
        class="mt-2 w-full resize-y rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-900 outline-none focus:border-emerald-400 focus:ring-2 focus:ring-emerald-200 dark:border-white/10 dark:bg-slate-950 dark:text-white"
      ></textarea>
      <span class="mt-1 block text-xs text-slate-500">Optional, but useful for assessment notes or special collection information.</span>
    </label>

    <div class="flex items-center justify-between gap-3">
      <button type="button" class="btn-secondary px-5" @click="emit('back')">Back</button>
      <button type="button" class="btn-primary px-5 py-3" @click="emit('next')">Next</button>
    </div>
  </section>
</template>
