<script setup>
defineProps({
  form: { type: Object, required: true },
  addressLookup: { type: Object, required: true },
  validationState: { type: Object, required: true }
});

defineEmits(['lookup-addresses', 'select-address', 'change-address', 'use-postcode-only', 'back', 'next']);
</script>

<template>
  <section class="space-y-4 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm md:p-5">
    <header class="space-y-1">
      <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700">Route</p>
      <h2 class="text-xl font-black text-slate-950">Pickup and drop-off</h2>
      <p class="text-sm text-slate-600">
        Enter each postcode, choose the exact address, then MotorRelay locks it into the job.
      </p>
    </header>

    <div class="grid gap-3 md:grid-cols-2">
      <div class="space-y-3 rounded-3xl border border-slate-200 bg-slate-50 p-3">
        <p class="text-xs font-black uppercase tracking-wide text-slate-500">Pickup</p>
        <div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
          <label class="block min-w-0">
            <span class="text-sm font-bold text-slate-700">Postcode</span>
            <input
              v-model="form.pickup_postcode"
              type="text"
              required
              :readonly="Boolean(form.pickup_label)"
              placeholder="e.g. M1 2AB"
              class="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm shadow-sm outline-none transition focus:border-emerald-300 focus:ring-2 focus:ring-emerald-200"
              :class="[
                form.pickup_label ? 'bg-slate-100 font-black text-slate-700' : '',
                validationState.pickup_postcode && !form.pickup_label ? 'border-rose-400 bg-rose-50 ring-2 ring-rose-200' : ''
              ]"
            />
          </label>
          <button
            v-if="!form.pickup_label"
            type="button"
            class="btn-secondary shrink-0 px-4 py-3 text-sm"
            :disabled="addressLookup.pickup.loading || !form.pickup_postcode"
            @click="$emit('lookup-addresses', 'pickup')"
          >
            <span v-if="addressLookup.pickup.loading">Finding...</span>
            <span v-else>Find</span>
          </button>
          <button
            v-else
            type="button"
            class="btn-secondary shrink-0 px-4 py-3 text-sm"
            @click="$emit('change-address', 'pickup')"
          >
            Change
          </button>
        </div>

        <p v-if="addressLookup.pickup.error" class="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
          {{ addressLookup.pickup.error }}
        </p>
        <button
          v-if="addressLookup.pickup.error && !form.pickup_label"
          type="button"
          class="btn-secondary w-full"
          @click="$emit('use-postcode-only', 'pickup')"
        >
          Use postcode only for testing
        </button>

        <label v-if="addressLookup.pickup.addresses.length && !form.pickup_label" class="block">
          <span class="text-sm font-bold text-slate-700">Exact address</span>
          <select
            class="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm shadow-sm outline-none transition focus:border-emerald-300 focus:ring-2 focus:ring-emerald-200"
            @change="$emit('select-address', 'pickup', $event.target.value)"
          >
            <option value="">Choose the exact pickup address</option>
            <option v-for="address in addressLookup.pickup.addresses" :key="address.id" :value="address.id">
              {{ address.label }}{{ address.secondary ? ` â€” ${address.secondary}` : '' }}
            </option>
          </select>
        </label>

        <div
          v-if="form.pickup_label"
          class="rounded-3xl border border-emerald-200 bg-emerald-50/70 p-3"
          :class="validationState.pickup_label ? 'border-rose-400 bg-rose-50 text-rose-700' : ''"
        >
          <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">Pickup locked</p>
          <p class="mt-1 text-base font-black text-slate-950">{{ form.pickup_label }}</p>
          <p class="mt-1 text-sm text-slate-600">{{ form.pickup_postcode }}</p>
        </div>
      </div>

      <div class="space-y-3 rounded-3xl border border-slate-200 bg-slate-50 p-3">
        <p class="text-xs font-black uppercase tracking-wide text-slate-500">Drop-off</p>
        <div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
          <label class="block min-w-0">
            <span class="text-sm font-bold text-slate-700">Postcode</span>
            <input
              v-model="form.dropoff_postcode"
              type="text"
              required
              :readonly="Boolean(form.dropoff_label)"
              placeholder="e.g. LS1 4XY"
              class="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm shadow-sm outline-none transition focus:border-emerald-300 focus:ring-2 focus:ring-emerald-200"
              :class="[
                form.dropoff_label ? 'bg-slate-100 font-black text-slate-700' : '',
                validationState.dropoff_postcode && !form.dropoff_label ? 'border-rose-400 bg-rose-50 ring-2 ring-rose-200' : ''
              ]"
            />
          </label>
          <button
            v-if="!form.dropoff_label"
            type="button"
            class="btn-secondary shrink-0 px-4 py-3 text-sm"
            :disabled="addressLookup.dropoff.loading || !form.dropoff_postcode"
            @click="$emit('lookup-addresses', 'dropoff')"
          >
            <span v-if="addressLookup.dropoff.loading">Finding...</span>
            <span v-else>Find</span>
          </button>
          <button
            v-else
            type="button"
            class="btn-secondary shrink-0 px-4 py-3 text-sm"
            @click="$emit('change-address', 'dropoff')"
          >
            Change
          </button>
        </div>

        <p v-if="addressLookup.dropoff.error" class="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
          {{ addressLookup.dropoff.error }}
        </p>
        <button
          v-if="addressLookup.dropoff.error && !form.dropoff_label"
          type="button"
          class="btn-secondary w-full"
          @click="$emit('use-postcode-only', 'dropoff')"
        >
          Use postcode only for testing
        </button>

        <label v-if="addressLookup.dropoff.addresses.length && !form.dropoff_label" class="block">
          <span class="text-sm font-bold text-slate-700">Exact address</span>
          <select
            class="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm shadow-sm outline-none transition focus:border-emerald-300 focus:ring-2 focus:ring-emerald-200"
            @change="$emit('select-address', 'dropoff', $event.target.value)"
          >
            <option value="">Choose the exact drop-off address</option>
            <option v-for="address in addressLookup.dropoff.addresses" :key="address.id" :value="address.id">
              {{ address.label }}{{ address.secondary ? ` â€” ${address.secondary}` : '' }}
            </option>
          </select>
        </label>

        <div
          v-if="form.dropoff_label"
          class="rounded-3xl border border-sky-200 bg-sky-50/70 p-3"
          :class="validationState.dropoff_label ? 'border-rose-400 bg-rose-50 text-rose-700' : ''"
        >
          <p class="text-xs font-bold uppercase tracking-wide text-sky-700">Drop-off locked</p>
          <p class="mt-1 text-base font-black text-slate-950">{{ form.dropoff_label }}</p>
          <p class="mt-1 text-sm text-slate-600">{{ form.dropoff_postcode }}</p>
        </div>
      </div>
    </div>

    <div class="flex items-center justify-between gap-3">
      <button type="button" class="btn-secondary px-5" @click="$emit('back')">Back</button>
      <button
        type="button"
        class="btn-primary px-5 py-3"
        :disabled="addressLookup.pickup.loading || addressLookup.dropoff.loading"
        @click="$emit('next')"
      >
        Next
      </button>
    </div>
  </section>
</template>
