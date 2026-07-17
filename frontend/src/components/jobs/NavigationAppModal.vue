<script setup>
defineProps({
  open: {
    type: Boolean,
    default: false
  },
  destination: {
    type: String,
    default: ""
  },
  links: {
    type: Array,
    default: () => []
  }
});

defineEmits(["close"]);
</script>

<template>
  <transition name="fade">
    <div
      v-if="open"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 px-4"
      @click.self="$emit('close')"
    >
      <div class="w-full max-w-sm space-y-4 rounded-2xl bg-white p-6 shadow-2xl">
        <header class="space-y-1">
          <h3 class="text-lg font-semibold text-slate-900">Navigation</h3>
          <p class="text-sm text-slate-600">
            Choose an app to start directions to {{ destination || "the drop-off location" }}.
          </p>
        </header>
        <div class="space-y-2">
          <a
            v-for="link in links"
            :key="link.id"
            :href="link.href"
            target="_blank"
            rel="noopener"
            class="flex items-center justify-between rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
            @click="$emit('close')"
          >
            {{ link.label }}
            <span aria-hidden="true">↗</span>
          </a>
          <p v-if="!links.length" class="text-xs text-slate-500">
            We could not determine a destination for this run yet.
          </p>
        </div>
        <button
          type="button"
          class="w-full rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
          @click="$emit('close')"
        >
          Close
        </button>
      </div>
    </div>
  </transition>
</template>
