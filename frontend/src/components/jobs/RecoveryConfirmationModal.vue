<script setup>
defineProps({
  confirmation: {
    type: Object,
    required: true
  },
  recoverySendingId: {
    type: [Number, String, null],
    default: null
  },
  recoveryCompletingId: {
    type: [Number, String, null],
    default: null
  }
});

defineEmits(["close", "confirm"]);

function incidentLabel(incident) {
  return String(incident?.type || "").replaceAll("_", " ");
}
</script>

<template>
  <transition name="fade">
    <div
      v-if="confirmation.open"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 px-4"
      @click.self="$emit('close')"
    >
      <div class="w-full max-w-md space-y-4 rounded-3xl bg-white p-5 shadow-2xl dark:bg-slate-950">
        <header>
          <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-600 dark:text-emerald-300">
            {{ confirmation.mode === "send" ? "Send recovery" : "Confirm recovery" }}
          </p>
          <h3 class="mt-1 text-xl font-black text-slate-950 dark:text-white">
            {{ confirmation.mode === "send" ? "Confirm recovery is being sent?" : "Has recovery happened?" }}
          </h3>
          <p class="mt-2 text-sm text-slate-600 dark:text-emerald-100">
            <template v-if="confirmation.mode === 'send'">
              This will tell the driver that recovery has been sent and add the update to the job chat.
            </template>
            <template v-else>
              This will tell the dealer recovery has happened and mark the reported issue as handled.
            </template>
          </p>
        </header>

        <div v-if="confirmation.incident" class="rounded-2xl bg-slate-50 p-3 text-sm dark:bg-white/[0.06]">
          <p class="font-black capitalize text-slate-950 dark:text-white">
            {{ incidentLabel(confirmation.incident) }}
          </p>
          <p v-if="confirmation.incident.description" class="mt-1 text-slate-600 dark:text-emerald-100">
            {{ confirmation.incident.description }}
          </p>
        </div>

        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" class="btn-secondary px-4 py-2 text-sm" @click="$emit('close')">
            Cancel
          </button>
          <button
            type="button"
            class="btn-primary px-4 py-2 text-sm"
            :disabled="Boolean(recoverySendingId || recoveryCompletingId)"
            @click="$emit('confirm')"
          >
            <template v-if="confirmation.mode === 'send'">
              {{ recoverySendingId ? "Sending..." : "Send recovery" }}
            </template>
            <template v-else>
              {{ recoveryCompletingId ? "Confirming..." : "Yes, recovery happened" }}
            </template>
          </button>
        </div>
      </div>
    </div>
  </transition>
</template>
