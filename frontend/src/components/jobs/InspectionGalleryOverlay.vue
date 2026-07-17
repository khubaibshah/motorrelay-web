<script setup>
defineProps({
  open: {
    type: Boolean,
    default: false
  },
  photo: {
    type: Object,
    default: null
  },
  index: {
    type: Number,
    default: 0
  },
  total: {
    type: Number,
    default: 0
  }
});

defineEmits(["close", "previous", "next", "remove", "touch-start", "touch-end"]);
</script>

<template>
  <transition name="fade">
    <div v-if="open && photo" class="fixed inset-0 z-[130] flex flex-col bg-slate-950 text-white">
      <header class="flex items-center justify-between gap-3 px-4 pb-3 pt-[calc(env(safe-area-inset-top)+1rem)]">
        <div>
          <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-300">Inspection gallery</p>
          <h3 class="mt-1 text-lg font-black">Photo {{ index + 1 }} of {{ total }}</h3>
        </div>
        <button
          type="button"
          class="rounded-2xl border border-white/15 px-4 py-2 text-sm font-black text-white"
          @click="$emit('close')"
        >
          Close
        </button>
      </header>

      <div
        class="relative flex min-h-0 flex-1 items-center justify-center px-3 pb-4"
        @touchstart.passive="$emit('touch-start', $event)"
        @touchend.passive="$emit('touch-end', $event)"
      >
        <button
          v-if="total > 1"
          type="button"
          class="absolute left-3 z-10 rounded-full bg-white/10 px-4 py-3 text-2xl font-black text-white backdrop-blur"
          @click="$emit('previous')"
        >
          ‹
        </button>

        <img
          v-if="photo.previewUrl"
          :src="photo.previewUrl"
          :alt="photo.name"
          class="max-h-full max-w-full rounded-3xl object-contain shadow-2xl"
        >
        <div v-else class="rounded-3xl border border-white/10 bg-white/[0.06] p-8 text-center text-sm font-bold text-emerald-100">
          {{ photo.name }}
        </div>

        <button
          v-if="total > 1"
          type="button"
          class="absolute right-3 z-10 rounded-full bg-white/10 px-4 py-3 text-2xl font-black text-white backdrop-blur"
          @click="$emit('next')"
        >
          ›
        </button>
      </div>

      <footer class="grid gap-2 px-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
        <p class="truncate text-center text-sm font-bold text-emerald-100">{{ photo.name }}</p>
        <button
          type="button"
          class="mx-auto rounded-2xl bg-white/10 px-4 py-2 text-xs font-black text-emerald-100"
          @click="$emit('remove')"
        >
          Remove this photo
        </button>
      </footer>
    </div>
  </transition>
</template>
