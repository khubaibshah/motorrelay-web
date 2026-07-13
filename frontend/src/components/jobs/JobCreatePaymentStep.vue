<script setup>
defineProps({
  form: { type: Object, required: true },
  validationState: { type: Object, required: true },
  jobPrice: { type: Number, required: true },
  estimatedDriverPayout: { type: Number, required: true },
  submitting: { type: Boolean, default: false },
  isEdit: { type: Boolean, default: false },
  formatMoney: { type: Function, required: true }
});

defineEmits(['back', 'submit']);
</script>

<template>
  <section class="space-y-5 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
    <header>
      <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700">Payment</p>
      <h2 class="mt-1 text-xl font-black text-slate-950">Price breakdown</h2>
      <p class="mt-1 text-sm text-slate-600">
        The dealer pays on creation. MotorRelay releases the driver payout after delivery proof is approved.
      </p>
    </header>

    <label class="block">
      <span class="text-sm font-bold text-slate-700">Dealer charge (GBP)</span>
      <input
        v-model="form.price"
        type="number"
        min="0"
        required
        placeholder="e.g. 120"
        class="mt-2 w-full rounded-2xl border px-4 py-3 text-sm"
        :class="validationState.price ? 'border-rose-400 bg-rose-50 ring-2 ring-rose-200' : ''"
      />
    </label>

    <dl class="grid gap-3 md:grid-cols-2">
      <div class="rounded-2xl bg-slate-50 p-4">
        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Dealer charge</dt>
        <dd class="mt-1 text-xl font-black text-slate-950">{{ formatMoney(jobPrice) }}</dd>
      </div>
      <div class="rounded-2xl bg-slate-950 p-4 text-white">
        <dt class="text-xs font-bold uppercase tracking-wide text-slate-400">Driver receives</dt>
        <dd class="mt-1 text-2xl font-black">{{ formatMoney(estimatedDriverPayout) }}</dd>
      </div>
    </dl>

    <div class="flex items-center justify-between gap-3">
      <button type="button" class="btn-secondary px-5" @click="$emit('back')">Back</button>
      <button
        type="submit"
        class="btn-primary px-5 py-3"
        :disabled="submitting"
      >
        <span v-if="submitting">{{ isEdit ? 'Saving...' : 'Opening checkout...' }}</span>
        <span v-else>{{ isEdit ? 'Save changes' : 'Create and pay' }}</span>
      </button>
    </div>
  </section>
</template>
