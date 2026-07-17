<script setup>
import { computed } from 'vue';
import { RouterLink } from 'vue-router';

const props = defineProps({
  googleHref: {
    type: String,
    default: ''
  },
  wazeHref: {
    type: String,
    default: ''
  },
  issueTo: {
    type: [String, Object],
    default: null
  },
  photosTo: {
    type: [String, Object],
    default: null
  },
  showIssue: {
    type: Boolean,
    default: true
  },
  showPhotos: {
    type: Boolean,
    default: true
  }
});

const actions = computed(() => [
  {
    id: 'google',
    label: 'Google',
    href: props.googleHref,
    icon: 'map'
  },
  {
    id: 'waze',
    label: 'Waze',
    href: props.wazeHref,
    icon: 'route'
  },
  {
    id: 'issue',
    label: 'Issue',
    to: props.issueTo,
    icon: 'alert',
    visible: props.showIssue
  },
  {
    id: 'photos',
    label: 'Photos',
    to: props.photosTo,
    icon: 'photo',
    visible: props.showPhotos
  }
].filter((action) => action.visible !== false && (action.href || action.to)));
</script>

<template>
  <nav
    v-if="actions.length"
    class="grid gap-1.5 rounded-[1.35rem] border border-slate-200 bg-white/90 p-1.5 shadow-sm dark:border-white/10 dark:bg-white/[0.06]"
    :class="actions.length >= 4 ? 'grid-cols-4' : 'grid-cols-3'"
    aria-label="Run quick actions"
  >
    <template
      v-for="action in actions"
      :key="action.id"
    >
      <a
        v-if="action.href"
        :href="action.href"
        target="_blank"
        rel="noopener"
        class="group flex min-w-0 flex-col items-center justify-center gap-1 rounded-2xl px-1.5 py-2 text-center text-[0.68rem] font-black text-slate-600 transition hover:bg-emerald-50 hover:text-emerald-700 dark:text-emerald-100 dark:hover:bg-emerald-400/10 dark:hover:text-emerald-300"
      >
        <span class="inline-flex size-8 items-center justify-center rounded-full bg-slate-100 text-slate-700 transition group-hover:bg-emerald-100 group-hover:text-emerald-700 dark:bg-white/10 dark:text-emerald-100 dark:group-hover:bg-emerald-400 dark:group-hover:text-slate-950">
          <svg v-if="action.icon === 'map'" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 18 3 21V6l6-3 6 3 6-3v15l-6 3-6-3Z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 3v15M15 6v15" />
          </svg>
          <svg v-else viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 19a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM18 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.5 14.5 15.5 9.5" />
          </svg>
        </span>
        <span class="truncate">{{ action.label }}</span>
      </a>

      <RouterLink
        v-else
        :to="action.to"
        class="group flex min-w-0 flex-col items-center justify-center gap-1 rounded-2xl px-1.5 py-2 text-center text-[0.68rem] font-black text-slate-600 transition hover:bg-emerald-50 hover:text-emerald-700 dark:text-emerald-100 dark:hover:bg-emerald-400/10 dark:hover:text-emerald-300"
      >
        <span class="inline-flex size-8 items-center justify-center rounded-full bg-slate-100 text-slate-700 transition group-hover:bg-emerald-100 group-hover:text-emerald-700 dark:bg-white/10 dark:text-emerald-100 dark:group-hover:bg-emerald-400 dark:group-hover:text-slate-950">
          <svg v-if="action.icon === 'alert'" viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 4.3 2.7 18a2 2 0 0 0 1.7 3h15.2a2 2 0 0 0 1.7-3L13.7 4.3a2 2 0 0 0-3.4 0Z" />
          </svg>
          <svg v-else viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h3l2-2h6l2 2h3v12H4V7Z" />
            <circle cx="12" cy="13" r="3" />
          </svg>
        </span>
        <span class="truncate">{{ action.label }}</span>
      </RouterLink>
    </template>
  </nav>
</template>
