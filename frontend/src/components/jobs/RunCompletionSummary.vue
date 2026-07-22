<script setup>
defineProps({
  heading: {
    type: String,
    default: 'Run completed'
  },
  statusDescription: {
    type: String,
    required: true
  },
  completionStatusLabel: {
    type: String,
    required: true
  },
  submittedAt: {
    type: String,
    required: true
  },
  approvedAt: {
    type: String,
    required: true
  },
  hasDeliveryProof: {
    type: Boolean,
    default: false
  },
  notes: {
    type: String,
    default: ""
  },
  invoiceFinalized: {
    type: Boolean,
    default: false
  },
  invoiceTo: {
    type: [String, Object],
    default: ""
  },
  reportAvailable: {
    type: Boolean,
    default: false
  },
  reportDownloading: {
    type: Boolean,
    default: false
  }
});

defineEmits(["download-report"]);
</script>

<template>
  <section class="tile space-y-3 p-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <p class="text-xs font-black uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Completion</p>
        <h2 class="mt-1 text-lg font-black text-slate-950 dark:text-white">{{ heading }}</h2>
        <p class="mt-1 text-sm text-slate-600 dark:text-emerald-100">{{ statusDescription }}</p>
      </div>
      <span class="badge bg-emerald-100 text-emerald-700 dark:bg-emerald-400 dark:text-slate-950">
        {{ completionStatusLabel }}
      </span>
    </div>

    <div class="grid gap-2 text-xs sm:grid-cols-3">
      <div class="rounded-2xl bg-slate-50 p-3 dark:bg-white/[0.06]">
        <span class="font-black uppercase tracking-wide text-slate-500 dark:text-emerald-100">Submitted</span>
        <p class="mt-1 text-sm font-bold text-slate-950 dark:text-white">{{ submittedAt }}</p>
      </div>
      <div class="rounded-2xl bg-slate-50 p-3 dark:bg-white/[0.06]">
        <span class="font-black uppercase tracking-wide text-slate-500 dark:text-emerald-100">Approved</span>
        <p class="mt-1 text-sm font-bold text-slate-950 dark:text-white">{{ approvedAt }}</p>
      </div>
      <div class="rounded-2xl bg-slate-50 p-3 dark:bg-white/[0.06]">
        <span class="font-black uppercase tracking-wide text-slate-500 dark:text-emerald-100">Inspection</span>
        <p class="mt-1 text-sm font-bold text-slate-950 dark:text-white">{{ hasDeliveryProof ? "Uploaded" : "Not uploaded" }}</p>
      </div>
    </div>

    <p v-if="notes" class="rounded-2xl bg-slate-50 p-3 text-sm text-slate-600 dark:bg-white/[0.06] dark:text-emerald-100">
      {{ notes }}
    </p>

    <div class="grid gap-2 sm:flex sm:flex-wrap">
      <button
        v-if="reportAvailable"
        type="button"
        class="btn-primary w-full px-4 py-2 text-sm sm:w-auto"
        :disabled="reportDownloading"
        @click="$emit('download-report')"
      >
        <span v-if="reportDownloading">Preparing report...</span>
        <span v-else>Download full report</span>
      </button>
      <RouterLink
        v-if="invoiceFinalized"
        :to="invoiceTo"
        class="btn-secondary w-full px-4 py-2 text-center text-sm sm:w-auto"
      >
        View this invoice
      </RouterLink>
    </div>
  </section>
</template>
