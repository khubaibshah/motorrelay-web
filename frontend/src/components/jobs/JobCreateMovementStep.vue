<script setup>
defineProps({
  form: { type: Object, required: true },
  transportOptions: { type: Array, required: true },
  validationState: { type: Object, required: true }
});

defineEmits(['select-transport', 'back', 'next']);
</script>

<template>
  <section class="space-y-4 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm md:p-5">
    <header class="space-y-1">
      <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700">Movement</p>
      <h2 class="text-xl font-black text-slate-950">Transport and timing</h2>
      <p class="text-sm text-slate-600">
        Choose how the vehicle moves, then add the timings the driver needs to see.
      </p>
    </header>

    <div>
      <p class="text-sm font-bold text-slate-700">Transport type</p>
      <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
        <button
          v-for="option in transportOptions"
          :key="option.value"
          type="button"
          :class="[
            'min-w-0 rounded-2xl border p-3 text-left transition hover:-translate-y-0.5 hover:shadow-md',
            form.transport_type === option.value
              ? 'border-emerald-300 bg-emerald-50 text-emerald-900 ring-1 ring-emerald-200'
              : 'border-slate-200 bg-white text-slate-700 hover:border-emerald-200'
          ]"
          @click="$emit('select-transport', option.value)"
        >
          <span class="block font-black">{{ option.label }}</span>
          <span class="mt-1 block text-xs leading-5 text-slate-500">{{ option.helper }}</span>
        </button>
      </div>
    </div>

    <div class="grid gap-3 md:grid-cols-2">
      <div
        class="min-w-0 rounded-3xl border border-slate-200 bg-slate-50 p-3"
        :class="validationState.pickup_at ? 'border-rose-400 bg-rose-50' : ''"
      >
        <label class="block min-w-0">
          <span class="text-xs font-black uppercase tracking-wide text-slate-500">Pickup ready</span>
          <input
            v-model="form.pickup_at"
            type="datetime-local"
            class="mt-2 block w-full max-w-full min-w-0 box-border rounded-2xl border border-slate-200 bg-white px-3 py-3 text-sm shadow-sm outline-none transition focus:border-emerald-300 focus:ring-2 focus:ring-emerald-200"
            :class="validationState.pickup_at ? 'border-rose-400 bg-rose-50 ring-2 ring-rose-200' : ''"
          />
        </label>
      </div>

      <div
        class="min-w-0 rounded-3xl border border-slate-200 bg-slate-50 p-3"
        :class="validationState.delivery_at ? 'border-rose-400 bg-rose-50' : ''"
      >
        <label class="block min-w-0">
          <span class="text-xs font-black uppercase tracking-wide text-slate-500">Delivery due</span>
          <input
            v-model="form.delivery_at"
            type="datetime-local"
            class="mt-2 block w-full max-w-full min-w-0 box-border rounded-2xl border border-slate-200 bg-white px-3 py-3 text-sm shadow-sm outline-none transition focus:border-emerald-300 focus:ring-2 focus:ring-emerald-200"
            :class="validationState.delivery_at ? 'border-rose-400 bg-rose-50 ring-2 ring-rose-200' : ''"
          />
        </label>
      </div>
    </div>

    <div class="flex items-center justify-between gap-3">
      <button type="button" class="btn-secondary px-5" @click="$emit('back')">Back</button>
      <button type="button" class="btn-primary px-5 py-3" @click="$emit('next')">Next</button>
    </div>
  </section>
</template>
