<script setup>
defineProps({
  loading: {
    type: Boolean,
    default: false
  },
  error: {
    type: String,
    default: ''
  },
  notice: {
    type: String,
    default: ''
  }
});

defineEmits(['approve-and-release']);
</script>

<template>
  <section class="rounded-3xl border border-emerald-300 bg-emerald-50 p-4 shadow-sm dark:border-emerald-300/40 dark:bg-emerald-400/10">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="min-w-0">
        <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-200">Action needed</p>
        <h2 class="mt-1 text-lg font-black text-slate-950 dark:text-white">Approve delivery and release payout</h2>
        <p class="mt-1 text-sm text-slate-700 dark:text-emerald-100">
          Confirm the vehicle was delivered. This approves the run and releases the driver payout together.
        </p>
      </div>
      <button
        type="button"
        class="btn-primary w-full px-4 py-2 text-sm sm:w-auto"
        :disabled="loading"
        @click="$emit('approve-and-release')"
      >
        {{ loading ? 'Processing...' : 'Approve & release payout' }}
      </button>
    </div>
    <p v-if="error" class="mt-3 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs font-bold text-rose-700 dark:border-rose-300/30 dark:bg-rose-400/10 dark:text-rose-100">
      {{ error }}
    </p>
    <p v-if="notice" class="mt-3 rounded-xl border border-emerald-200 bg-emerald-100/70 p-3 text-xs font-bold text-emerald-800 dark:border-emerald-300/30 dark:bg-emerald-400/10 dark:text-emerald-100">
      {{ notice }}
    </p>
  </section>
</template>
