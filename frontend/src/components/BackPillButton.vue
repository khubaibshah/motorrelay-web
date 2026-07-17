<script setup>
import { useRouter } from 'vue-router';

const props = defineProps({
  label: {
    type: String,
    default: 'Back'
  },
  to: {
    type: [String, Object],
    default: null
  }
});

const router = useRouter();

function handleBack() {
  if (props.to) {
    router.push(props.to);
    return;
  }

  if (typeof window !== 'undefined' && window.history.length > 1) {
    router.back();
    return;
  }

  router.push({ name: 'home' });
}
</script>

<template>
  <button
    type="button"
    class="inline-flex w-fit items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-2 text-sm font-black text-slate-700 shadow-sm transition hover:border-emerald-200 hover:text-emerald-700 dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100 dark:hover:bg-white/10 dark:hover:text-emerald-300"
    @click="handleBack"
  >
    <span aria-hidden="true">←</span>
    {{ label }}
  </button>
</template>
