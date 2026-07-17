<script setup>
defineProps({
  open: {
    type: Boolean,
    default: false
  },
  form: {
    type: Object,
    required: true
  },
  error: {
    type: String,
    default: ""
  },
  submitting: {
    type: Boolean,
    default: false
  }
});

defineEmits(["close", "submit", "use-current-location"]);
</script>

<template>
  <transition name="fade">
    <div
      v-if="open"
      class="fixed inset-0 z-[140] flex items-center justify-center bg-slate-900/70 px-4"
      @click.self="$emit('close')"
    >
      <form
        class="max-h-[90vh] w-full max-w-lg space-y-4 overflow-y-auto rounded-3xl bg-white p-5 shadow-2xl dark:bg-slate-950"
        @submit.prevent="$emit('submit')"
      >
        <header>
          <p class="text-xs font-black uppercase tracking-[0.18em] text-amber-600">Report issue</p>
          <h3 class="mt-1 text-xl font-black text-slate-950 dark:text-white">What happened?</h3>
          <p class="mt-1 text-sm text-slate-600 dark:text-emerald-100">
            This will notify the dealer and add the issue to the job chat.
          </p>
        </header>

        <label class="block">
          <span class="text-sm font-bold text-slate-700 dark:text-emerald-100">Issue type</span>
          <select v-model="form.type" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-900 dark:text-emerald-100">
            <option value="vehicle_breakdown">Vehicle breakdown</option>
            <option value="accident">Accident</option>
            <option value="access_issue">Cannot access vehicle</option>
            <option value="dealer_unavailable">Dealer/customer unavailable</option>
            <option value="wrong_address">Wrong address</option>
            <option value="other">Other issue</option>
          </select>
        </label>

        <div class="grid gap-2 sm:grid-cols-3">
          <label class="flex items-center gap-2 rounded-2xl border border-slate-200 p-3 text-sm font-semibold dark:border-white/10 dark:text-emerald-100">
            <input v-model="form.recovery_required" type="checkbox" class="h-4 w-4">
            Recovery needed
          </label>
          <label class="flex items-center gap-2 rounded-2xl border border-slate-200 p-3 text-sm font-semibold dark:border-white/10 dark:text-emerald-100">
            <input v-model="form.vehicle_safe" type="checkbox" class="h-4 w-4">
            Vehicle safe
          </label>
          <label class="flex items-center gap-2 rounded-2xl border border-slate-200 p-3 text-sm font-semibold dark:border-white/10 dark:text-emerald-100">
            <input v-model="form.blocking_road" type="checkbox" class="h-4 w-4">
            Blocking road
          </label>
        </div>

        <label class="block">
          <span class="text-sm font-bold text-slate-700 dark:text-emerald-100">Location</span>
          <div class="mt-2 flex gap-2">
            <input
              v-model="form.location_label"
              type="text"
              placeholder="e.g. hard shoulder near M65 J12"
              class="min-w-0 flex-1 rounded-2xl border border-slate-200 px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-900 dark:text-emerald-100"
            >
            <button type="button" class="btn-secondary px-4 py-2 text-sm" @click="$emit('use-current-location')">
              Use GPS
            </button>
          </div>
        </label>

        <label class="block">
          <span class="text-sm font-bold text-slate-700 dark:text-emerald-100">Details</span>
          <textarea
            v-model="form.description"
            rows="4"
            placeholder="Tell the dealer what happened and what help you need."
            class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-900 dark:text-emerald-100"
          />
        </label>

        <p v-if="error" class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-700">
          {{ error }}
        </p>

        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" class="btn-secondary px-4 py-2 text-sm" @click="$emit('close')">Cancel</button>
          <button type="submit" class="btn-primary px-4 py-2 text-sm" :disabled="submitting">
            {{ submitting ? "Reporting..." : "Report issue" }}
          </button>
        </div>
      </form>
    </div>
  </transition>
</template>
