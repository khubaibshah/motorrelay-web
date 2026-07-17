<script setup>
defineProps({
  open: {
    type: Boolean,
    default: false
  },
  mode: {
    type: String,
    default: ''
  },
  message: {
    type: String,
    default: ''
  },
  pending: {
    type: Boolean,
    default: false
  },
  note: {
    type: String,
    default: ''
  }
});

const emit = defineEmits(['close', 'confirm', 'update:note']);
</script>

<template>
  <div
    v-if="open"
    class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4"
  >
    <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
      <h3 class="text-lg font-semibold text-slate-900">
        {{
          mode === 'deliver'
            ? 'Mark run as delivered'
            : mode === 'invoice'
            ? 'Send invoice'
            : 'Cancel run'
        }}
      </h3>
      <p class="mt-3 text-sm text-slate-600">
        {{ message }}
      </p>

      <div v-if="mode === 'cancel'" class="mt-4 space-y-2">
        <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">
          Optional note
        </label>
        <textarea
          :value="note"
          rows="3"
          class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200"
          placeholder="Let them know why you're cancelling"
          @input="emit('update:note', $event.target.value)"
        ></textarea>
      </div>

      <div class="mt-6 flex justify-end gap-2">
        <button
          type="button"
          class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-60"
          :disabled="pending"
          @click="emit('close')"
        >
          Close
        </button>
        <button
          type="button"
          class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
          :disabled="pending"
          @click="emit('confirm')"
        >
          <span v-if="pending">Working...</span>
          <span v-else>Confirm</span>
        </button>
      </div>
    </div>
  </div>
</template>
