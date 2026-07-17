<script setup>
const props = defineProps({
  location: {
    type: Object,
    required: true
  },
  mapSrc: {
    type: String,
    required: true
  },
  updatedLabel: {
    type: String,
    required: true
  }
});
</script>

<template>
  <section class="tile overflow-hidden border-emerald-200 bg-emerald-50/40 p-0 dark:border-emerald-400/30 dark:bg-emerald-400/10">
    <div class="flex items-center justify-between gap-3 px-4 py-3">
      <div>
        <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-300">
          Live tracking
        </p>
        <h2 class="mt-0.5 text-base font-black text-slate-950 dark:text-white">Driver location</h2>
      </div>
      <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-black text-emerald-700 dark:bg-emerald-300 dark:text-slate-950">
        Live
      </span>
    </div>

    <div class="relative h-52 bg-slate-200 dark:bg-slate-900">
      <iframe
        :key="`${props.location?.lat}-${props.location?.lng}`"
        :src="mapSrc"
        class="h-full w-full border-0"
        loading="lazy"
        referrerpolicy="no-referrer-when-downgrade"
        title="Driver live location map"
      />
    </div>

    <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-xs font-bold text-slate-600 dark:text-emerald-100">
      <span>{{ updatedLabel }}</span>
      <a
        :href="`https://www.google.com/maps/search/?api=1&query=${props.location?.lat},${props.location?.lng}`"
        target="_blank"
        rel="noopener"
        class="text-emerald-700 underline dark:text-emerald-300"
      >
        Open map
      </a>
    </div>
  </section>
</template>
