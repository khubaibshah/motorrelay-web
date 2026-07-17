<script setup>
defineProps({
  incidents: {
    type: Array,
    default: () => []
  },
  canSendRecovery: {
    type: Boolean,
    default: false
  },
  canConfirmRecoveryCompleted: {
    type: Boolean,
    default: false
  },
  recoverySendingId: {
    type: [Number, String, null],
    default: null
  },
  recoveryCompletingId: {
    type: [Number, String, null],
    default: null
  },
  recoverySendError: {
    type: String,
    default: ""
  },
  recoveryCompleteError: {
    type: String,
    default: ""
  }
});

const emit = defineEmits(["confirm-recovery"]);

function formatDateTime(value) {
  if (!value) return "Not recorded";

  return new Intl.DateTimeFormat("en-GB", {
    dateStyle: "medium",
    timeStyle: "short"
  }).format(new Date(value));
}

function incidentLabel(type) {
  return String(type || "").replaceAll("_", " ");
}

function recoveryLabel(incident) {
  if (incident.recovery_completed_at) return "Recovery happened";
  if (incident.recovery_sent_at) return "Recovery sent";
  return "Recovery requested";
}

function recoveryBadgeClass(incident) {
  if (incident.recovery_completed_at) {
    return "bg-slate-900 text-white dark:bg-white dark:text-slate-950";
  }

  if (incident.recovery_sent_at) {
    return "bg-emerald-100 text-emerald-700 dark:bg-emerald-300 dark:text-slate-950";
  }

  return "bg-rose-100 text-rose-700 dark:bg-rose-400/20 dark:text-rose-100";
}
</script>

<template>
  <section class="tile space-y-2 border-amber-200 bg-amber-50/50 p-3 dark:border-amber-400/30 dark:bg-amber-400/10">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <p class="text-xs font-black uppercase tracking-wide text-amber-700 dark:text-amber-200">Reported issues</p>
        <h2 class="mt-0.5 text-base font-black text-slate-950 dark:text-white">Incident history</h2>
      </div>
      <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-800 dark:bg-amber-300 dark:text-slate-950">
        {{ incidents.length }} logged
      </span>
    </div>

    <p v-if="recoverySendError" class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm font-bold text-rose-700 dark:border-rose-400/30 dark:bg-rose-400/10 dark:text-rose-100">
      {{ recoverySendError }}
    </p>
    <p v-if="recoveryCompleteError" class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm font-bold text-rose-700 dark:border-rose-400/30 dark:bg-rose-400/10 dark:text-rose-100">
      {{ recoveryCompleteError }}
    </p>

    <div class="space-y-2">
      <article
        v-for="incident in incidents"
        :key="incident.id"
        class="rounded-xl border border-amber-200 bg-white p-2.5 text-sm dark:border-amber-400/20 dark:bg-white/[0.06]"
      >
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div>
            <p class="font-black capitalize text-slate-950 dark:text-white">{{ incidentLabel(incident.type) }}</p>
            <p class="mt-1 text-xs text-slate-600 dark:text-emerald-100">
              Reported by {{ incident.reported_by?.name || "driver" }} · {{ formatDateTime(incident.created_at) }}
            </p>
          </div>

          <div v-if="incident.recovery_required" class="flex flex-wrap items-center justify-end gap-2">
            <span class="rounded-full px-2.5 py-1 text-xs font-bold" :class="recoveryBadgeClass(incident)">
              {{ recoveryLabel(incident) }}
            </span>
            <button
              v-if="canSendRecovery && !incident.recovery_sent_at"
              type="button"
              class="btn-primary w-full px-4 py-2 text-sm sm:w-auto"
              :disabled="recoverySendingId === incident.id"
              @click="emit('confirm-recovery', 'send', incident)"
            >
              {{ recoverySendingId === incident.id ? "Sending..." : "Send recovery" }}
            </button>
            <button
              v-if="canConfirmRecoveryCompleted && incident.recovery_sent_at && !incident.recovery_completed_at"
              type="button"
              class="btn-primary w-full px-4 py-2 text-sm sm:w-auto"
              :disabled="recoveryCompletingId === incident.id"
              @click="emit('confirm-recovery', 'complete', incident)"
            >
              {{ recoveryCompletingId === incident.id ? "Confirming..." : "Recovery happened" }}
            </button>
          </div>
        </div>

        <p v-if="incident.description" class="mt-2 text-slate-700 dark:text-emerald-100">{{ incident.description }}</p>
        <p v-if="incident.location_label" class="mt-1 text-xs text-slate-500 dark:text-emerald-100">
          Location: {{ incident.location_label }}
        </p>
        <p v-if="incident.recovery_sent_at" class="mt-2 text-xs font-bold text-emerald-700 dark:text-emerald-200">
          Recovery confirmed by {{ incident.recovery_sent_by?.name || "dealer" }} · {{ formatDateTime(incident.recovery_sent_at) }}
        </p>
        <p v-if="incident.recovery_completed_at" class="mt-1 text-xs font-bold text-slate-700 dark:text-emerald-100">
          Recovery happened confirmed by {{ incident.recovery_completed_by?.name || "driver" }} · {{ formatDateTime(incident.recovery_completed_at) }}
        </p>
      </article>
    </div>
  </section>
</template>
