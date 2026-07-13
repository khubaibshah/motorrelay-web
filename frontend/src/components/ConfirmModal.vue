<script setup>
defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, required: true },
  description: { type: String, default: '' },
  cancelText: { type: String, default: 'Cancel' },
  confirmText: { type: String, default: 'Confirm' },
  loadingText: { type: String, default: 'Working...' },
  loading: { type: Boolean, default: false },
  confirmTone: { type: String, default: 'rose' },
  iconText: { type: String, default: '!' },
  iconClass: { type: String, default: 'bg-rose-100 text-rose-700' }
});

defineEmits(['cancel', 'confirm']);
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 px-4 py-6 backdrop-blur-sm"
      @click.self="$emit('cancel')"
    >
      <div
        class="w-full max-w-md rounded-3xl border border-white/70 bg-white p-6 shadow-2xl"
        role="dialog"
        aria-modal="true"
        :aria-label="title"
      >
        <div class="flex items-start gap-4">
          <div
            class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-2xl font-black"
            :class="iconClass"
          >
            {{ iconText }}
          </div>
          <div class="min-w-0 flex-1">
            <h2 class="text-lg font-black text-slate-950">{{ title }}</h2>
            <p v-if="description" class="mt-2 text-sm leading-6 text-slate-600">
              {{ description }}
            </p>
            <slot />
          </div>
        </div>

        <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
          <button
            type="button"
            class="btn-secondary w-full sm:w-auto"
            :disabled="loading"
            @click="$emit('cancel')"
          >
            {{ cancelText }}
          </button>
          <button
            type="button"
            class="btn-primary w-full sm:w-auto"
            :class="{
              'bg-rose-600 hover:bg-rose-700': confirmTone === 'rose',
              'bg-emerald-600 hover:bg-emerald-700': confirmTone === 'emerald'
            }"
            :disabled="loading"
            @click="$emit('confirm')"
          >
            <span v-if="loading">{{ loadingText }}</span>
            <span v-else>{{ confirmText }}</span>
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
