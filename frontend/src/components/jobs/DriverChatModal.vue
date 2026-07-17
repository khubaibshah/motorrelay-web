<script setup>
defineProps({
  open: {
    type: Boolean,
    default: false
  },
  job: {
    type: Object,
    required: true
  },
  loading: {
    type: Boolean,
    default: false
  },
  sending: {
    type: Boolean,
    default: false
  },
  error: {
    type: String,
    default: ""
  },
  messages: {
    type: Array,
    default: () => []
  },
  currentUserId: {
    type: [Number, String, null],
    default: null
  }
});

const body = defineModel("body", {
  type: String,
  default: ""
});

defineEmits(["close", "send"]);
</script>

<template>
  <transition name="fade">
    <div
      v-if="open"
      class="fixed inset-0 z-[140] flex items-center justify-center bg-slate-900/70 px-4"
      @click.self="$emit('close')"
    >
      <div class="flex max-h-[88vh] w-full max-w-lg flex-col rounded-3xl bg-white p-4 shadow-2xl dark:bg-slate-950">
        <header class="flex items-start justify-between gap-3 border-b border-slate-100 pb-3 dark:border-white/10">
          <div>
            <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-300">Driver chat</p>
            <h3 class="mt-1 text-xl font-black text-slate-950 dark:text-white">{{ job.title || `Run #${job.id}` }}</h3>
          </div>
          <button type="button" class="btn-secondary px-3 py-2 text-sm" @click="$emit('close')">
            Close
          </button>
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto py-3">
          <p v-if="loading" class="rounded-2xl bg-slate-50 p-3 text-sm text-slate-600 dark:bg-white/[0.06] dark:text-emerald-100">
            Loading chat...
          </p>
          <p v-else-if="error" class="rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-700">
            {{ error }}
          </p>
          <div v-else-if="!messages.length" class="rounded-2xl bg-slate-50 p-3 text-sm text-slate-600 dark:bg-white/[0.06] dark:text-emerald-100">
            No messages yet. Send a quick update to the dealer below.
          </div>
          <div v-else class="space-y-2">
            <article
              v-for="message in messages"
              :key="message.id"
              class="rounded-2xl p-3 text-sm"
              :class="message.user?.id === currentUserId
                ? 'ml-8 bg-emerald-600 text-white'
                : 'mr-8 bg-slate-100 text-slate-800 dark:bg-white/[0.08] dark:text-emerald-100'"
            >
              <p class="text-[11px] font-black uppercase tracking-wide opacity-70">{{ message.user?.name || "User" }}</p>
              <p class="mt-1 whitespace-pre-wrap">{{ message.body || "Update sent." }}</p>
            </article>
          </div>
        </div>

        <form class="border-t border-slate-100 pt-3 dark:border-white/10" @submit.prevent="$emit('send')">
          <textarea
            v-model="body"
            rows="3"
            placeholder="Send the dealer a quick update..."
            class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-900 dark:text-emerald-100"
          />
          <div class="mt-2 flex justify-end">
            <button type="submit" class="btn-primary px-4 py-2 text-sm" :disabled="sending || !body.trim()">
              {{ sending ? "Sending..." : "Send message" }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </transition>
</template>
