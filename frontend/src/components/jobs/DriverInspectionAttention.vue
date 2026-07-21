<script setup>
import { RouterLink } from 'vue-router';

defineProps({
  to: {
    type: [String, Object],
    required: true
  }
});
</script>

<template>
  <section class="rounded-3xl border border-amber-300 bg-amber-50 p-4 shadow-sm dark:border-amber-300/40 dark:bg-amber-400/10">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="min-w-0">
        <p class="text-xs font-black uppercase tracking-[0.18em] text-amber-700 dark:text-amber-200">Action needed</p>
        <h2 class="mt-1 text-lg font-black text-slate-950 dark:text-white">Upload pre-inspection photos</h2>
        <p class="mt-1 text-sm text-slate-700 dark:text-amber-100">
          Add the required vehicle photos before collection so the dealer can review them.
        </p>
      </div>
      <RouterLink :to="to" class="btn-primary w-full px-4 py-2 text-center text-sm sm:w-auto">
        Upload photos
      </RouterLink>
    </div>
  </section>
</template>
