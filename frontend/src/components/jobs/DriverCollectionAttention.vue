<script setup>
defineProps({
  trackingShared: {
    type: Boolean,
    default: false
  },
  sharing: {
    type: Boolean,
    default: false
  }
});

defineEmits(['share-location']);
</script>

<template>
  <section class="rounded-3xl border border-emerald-300 bg-emerald-50 p-4 shadow-sm dark:border-emerald-300/40 dark:bg-emerald-400/10">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-200">Next action</p>
        <h2 class="mt-1 text-lg font-black text-slate-950 dark:text-white">
          {{ trackingShared ? 'Ready to collect vehicle' : 'Share live location before collection' }}
        </h2>
        <p class="mt-1 text-sm text-slate-700 dark:text-emerald-100">
          {{ trackingShared
            ? 'Live tracking is active. Use Mark vehicle collected when you have the vehicle.'
            : 'Share your live location so the dealer can follow the collection journey.' }}
        </p>
      </div>
      <button
        v-if="!trackingShared"
        type="button"
        class="btn-primary w-full px-4 py-2 text-sm sm:w-auto"
        :disabled="sharing"
        @click="$emit('share-location')"
      >
        {{ sharing ? 'Sharing location...' : 'Share live location' }}
      </button>
    </div>
  </section>
</template>
