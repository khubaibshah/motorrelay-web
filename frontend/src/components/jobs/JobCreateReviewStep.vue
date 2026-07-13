<script setup>
defineProps({
  form: { type: Object, required: true },
  reviewSections: { type: Array, default: () => [] },
  jobPrice: { type: Number, required: true },
  estimatedDriverPayout: { type: Number, required: true },
  submitting: { type: Boolean, default: false },
  isEdit: { type: Boolean, default: false },
  formatMoney: { type: Function, required: true }
});

defineEmits(['back', 'submit', 'go-to-step']);
</script>

<template>
  <section class="space-y-5 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
    <header>
      <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700">Review</p>
      <h2 class="mt-1 text-xl font-black text-slate-950">Confirm and create</h2>
      <p class="mt-1 text-sm text-slate-600">
        Check the details below. Use edit if anything needs changing.
      </p>
    </header>

    <div class="space-y-2">
      <div
        v-for="section in reviewSections"
        :key="section.key"
        class="flex items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3"
      >
        <div class="min-w-0">
          <p class="text-xs font-black uppercase tracking-wide text-slate-400">{{ section.label }}</p>
          <p class="mt-0.5 truncate text-sm font-semibold text-slate-950">{{ section.value }}</p>
        </div>
        <button
          type="button"
          class="shrink-0 rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-bold text-slate-600 hover:border-emerald-200 hover:text-emerald-700"
          @click="$emit('go-to-step', section.step)"
        >
          Edit
        </button>
      </div>
    </div>

    <dl class="grid gap-3 md:grid-cols-2">
      <div class="rounded-2xl bg-slate-50 p-4">
        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Dealer charge</dt>
        <dd class="mt-1 text-lg font-black tabular-nums text-slate-950 sm:text-xl">
          {{ formatMoney(jobPrice) }}
        </dd>
      </div>
      <div class="rounded-2xl bg-slate-950 p-4 text-white">
        <dt class="text-xs font-bold uppercase tracking-wide text-slate-400">Driver receives</dt>
        <dd class="mt-1 text-lg font-black tabular-nums sm:text-xl">
          {{ formatMoney(estimatedDriverPayout) }}
        </dd>
      </div>
    </dl>

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
