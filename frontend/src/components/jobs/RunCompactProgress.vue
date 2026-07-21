<script setup>
defineProps({
  currentLabel: {
    type: String,
    default: 'Active run'
  },
  progressPercent: {
    type: Number,
    default: 0
  },
  completedCount: {
    type: Number,
    default: 0
  },
  totalCount: {
    type: Number,
    default: 0
  },
  photosUploaded: {
    type: Boolean,
    default: false
  },
  locationShared: {
    type: Boolean,
    default: false
  },
  statusLabel: {
    type: String,
    default: ''
  },
  steps: {
    type: Array,
    default: () => []
  }
});
</script>

<template>
  <section class="tile space-y-2 p-3">
    <div class="flex flex-col gap-1.5 sm:flex-row sm:items-start sm:justify-between sm:gap-3">
      <div class="min-w-0">
        <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-300">Run progress</p>
        <h2 class="mt-0.5 whitespace-normal break-words text-base font-black leading-snug text-slate-950 dark:text-white">{{ currentLabel }}</h2>
      </div>
      <div class="flex shrink-0 items-center gap-2 sm:flex-col sm:items-end sm:gap-0 sm:text-right">
        <p class="text-xs font-black text-slate-500 dark:text-emerald-100">{{ completedCount }} / {{ totalCount || 1 }}</p>
        <p v-if="statusLabel" class="mt-0.5 rounded-full bg-slate-100 px-2 py-0.5 text-[0.65rem] font-black text-slate-700 dark:bg-white/10 dark:text-emerald-100">
          {{ statusLabel }}
        </p>
      </div>
    </div>

    <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-white/10">
      <div
        class="h-full rounded-full bg-gradient-to-r from-emerald-500 to-sky-500 transition-all duration-500"
        :style="{ width: `${Math.min(Math.max(progressPercent, 0), 100)}%` }"
      />
    </div>

    <ol v-if="steps.length" class="grid gap-1.5 sm:grid-cols-2">
      <li
        v-for="(step, index) in steps"
        :key="`${index}-${step.label}`"
        class="flex min-w-0 items-center gap-2 rounded-xl px-2.5 py-2"
        :class="step.complete ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-400 dark:text-slate-950' : 'bg-slate-50 text-slate-600 dark:bg-white/[0.06] dark:text-emerald-100'"
      >
        <span class="inline-flex size-5 shrink-0 items-center justify-center rounded-full text-[0.65rem] font-black" :class="step.complete ? 'bg-emerald-600 text-white dark:bg-slate-950 dark:text-emerald-300' : 'bg-slate-200 text-slate-500 dark:bg-white/10 dark:text-emerald-100'">
          {{ step.complete ? '✓' : index + 1 }}
        </span>
        <span class="min-w-0 truncate text-xs font-black">{{ step.label }}</span>
      </li>
    </ol>

    <div class="grid grid-cols-2 gap-1.5">
      <div class="rounded-xl px-2.5 py-2" :class="photosUploaded ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-400 dark:text-slate-950' : 'bg-slate-50 text-slate-500 dark:bg-white/[0.06] dark:text-emerald-100'">
        <p class="text-[0.65rem] font-black uppercase tracking-wide">Photos</p>
        <p class="text-xs font-black">{{ photosUploaded ? 'Uploaded' : 'Needed' }}</p>
      </div>
      <div class="rounded-xl px-2.5 py-2" :class="locationShared ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-400 dark:text-slate-950' : 'bg-slate-50 text-slate-500 dark:bg-white/[0.06] dark:text-emerald-100'">
        <p class="text-[0.65rem] font-black uppercase tracking-wide">Location</p>
        <p class="text-xs font-black">{{ locationShared ? 'Shared' : 'Not shared' }}</p>
      </div>
    </div>
  </section>
</template>
