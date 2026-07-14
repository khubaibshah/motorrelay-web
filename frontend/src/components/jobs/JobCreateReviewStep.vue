<script setup>
defineProps({
  form: { type: Object, required: true },
  reviewSections: { type: Array, default: () => [] },
  submitting: { type: Boolean, default: false },
  isEdit: { type: Boolean, default: false },
  formatMoney: { type: Function, required: true }
});

defineEmits(['back', 'submit', 'go-to-step']);
</script>

<template>
  <section class="space-y-4 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5 dark:border-white/10 dark:bg-white/[0.06]">
    <header>
      <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700">Review</p>
      <h2 class="mt-1 text-xl font-black text-slate-950">Confirm and create</h2>
    </header>

    <div class="space-y-2">
      <div
        v-for="section in reviewSections"
        :key="section.key"
        class="flex items-start justify-between gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2.5 dark:border-white/10 dark:bg-slate-950"
      >
        <div class="min-w-0 flex-1">
          <p class="text-[11px] font-black uppercase tracking-wide text-slate-400">{{ section.label }}</p>
          <div v-if="section.lines" class="mt-0.5 space-y-0.5 text-sm font-semibold leading-5 text-slate-950 dark:text-emerald-100">
            <p v-for="line in section.lines" :key="line" class="break-words">{{ line }}</p>
          </div>
          <p v-else class="mt-0.5 truncate text-sm font-semibold leading-5 text-slate-950 dark:text-emerald-100">{{ section.value }}</p>
        </div>
        <button
          type="button"
          class="shrink-0 rounded-full border border-slate-200 bg-white px-3 py-1 text-[11px] font-bold text-slate-600 hover:border-emerald-200 hover:text-emerald-700 dark:border-white/10 dark:bg-white/10 dark:text-emerald-100 dark:hover:border-emerald-400/50 dark:hover:text-emerald-300"
          @click="$emit('go-to-step', section.step)"
        >
          Edit
        </button>
      </div>
    </div>

    <div class="flex items-center justify-between gap-3">
      <button type="button" class="btn-secondary px-5" @click="$emit('back')">Back</button>
      <button
        type="button"
        class="btn-primary px-5 py-3"
        :disabled="submitting"
        @click="$emit('submit')"
      >
        <span v-if="submitting">{{ isEdit ? 'Saving...' : 'Opening checkout...' }}</span>
        <span v-else>{{ isEdit ? 'Save changes' : 'Create and pay' }}</span>
      </button>
    </div>
  </section>
</template>
