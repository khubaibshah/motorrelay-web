<script setup>
defineProps({
  form: { type: Object, required: true },
  verifiedVehicle: { type: Object, default: null },
  validationState: { type: Object, required: true },
  isEdit: { type: Boolean, default: false },
  vehicleLookupLoading: { type: Boolean, default: false }
});

defineEmits(['lookup-vehicle', 'change-vehicle', 'next']);
</script>

<template>
  <section class="space-y-5 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.06]">
    <header>
      <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700">Vehicle</p>
      <h2 class="mt-1 text-xl font-black text-slate-950">What is being moved?</h2>
    </header>

    <div class="flex items-end gap-3">
      <label class="block min-w-0 flex-1">
        <span class="text-sm font-bold text-slate-700">Licence plate</span>
        <input
          v-model="form.title"
          type="text"
          required
          placeholder="e.g. AB12 CDE"
          :readonly="isEdit || Boolean(verifiedVehicle)"
          @blur="!isEdit && !verifiedVehicle && $emit('lookup-vehicle')"
          class="mt-2 w-full rounded-2xl border px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-950 dark:text-emerald-100 dark:placeholder:text-emerald-100/40"
          :class="[
            verifiedVehicle ? 'bg-slate-100 font-black text-slate-700 dark:bg-white/10 dark:text-emerald-100' : '',
            validationState.vehicle && !verifiedVehicle ? 'border-rose-400 bg-rose-50 ring-2 ring-rose-200 dark:border-rose-400 dark:bg-rose-400/10 dark:ring-rose-400/30' : ''
          ]"
        />
      </label>

      <button
        v-if="!isEdit && !verifiedVehicle"
        type="button"
        class="btn-secondary shrink-0 px-5"
        :disabled="vehicleLookupLoading || !form.title"
        @click="$emit('lookup-vehicle')"
      >
        <span v-if="vehicleLookupLoading">Checking...</span>
        <span v-else>Check plate</span>
      </button>
      <button
        v-else-if="!isEdit"
        type="button"
        class="btn-secondary shrink-0 px-5"
        :disabled="vehicleLookupLoading"
        @click="$emit('change-vehicle')"
      >
        Change plate
      </button>
    </div>

    <div
      v-if="verifiedVehicle"
      class="grid gap-3 rounded-3xl border border-emerald-200 bg-emerald-50/70 p-4 sm:grid-cols-3 dark:border-emerald-400/30 dark:bg-emerald-400/10"
    >
      <div>
        <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">Verified plate</p>
        <p class="mt-1 text-lg font-black text-slate-950 dark:text-emerald-300">{{ verifiedVehicle.registration }}</p>
      </div>
      <div>
        <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">Vehicle</p>
        <p class="mt-1 text-lg font-black text-slate-950 dark:text-emerald-300">{{ form.vehicle_make || '--' }}</p>
      </div>
      <div>
        <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">Details</p>
        <p class="mt-1 text-lg font-black text-slate-950 dark:text-emerald-300">{{ verifiedVehicle.vehicle_type || '--' }}</p>
      </div>
    </div>

    <p
      v-else
      class="hidden rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-600 md:block dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100"
      :class="validationState.vehicle ? 'border-rose-400 bg-rose-50 text-rose-700 dark:border-rose-400 dark:bg-rose-400/10 dark:text-rose-200' : ''"
    >
      Enter the registration plate and MotorRelay will pull the vehicle details automatically. Dealers cannot type vehicle details manually.
    </p>

    <div class="flex justify-end">
      <button type="button" class="btn-primary px-5 py-3" :disabled="vehicleLookupLoading" @click="$emit('next')">
        Next
      </button>
    </div>
  </section>
</template>
