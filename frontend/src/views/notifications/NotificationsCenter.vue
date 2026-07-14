<script setup>
import { computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useNotificationsStore } from '@/stores/notifications';

const auth = useAuthStore();
const notifications = useNotificationsStore();
const router = useRouter();

onMounted(async () => {
  if (!auth.user && auth.token) {
    await auth.fetchMe().catch(() => null);
  }

  await notifications.refresh({ showToasts: false }).catch(() => null);
});

const groupedNotifications = computed(() => notifications.recentItems);

function formatDate(value) {
  if (!value) return '--';

  try {
    return new Intl.DateTimeFormat('en-GB', {
      day: 'numeric',
      month: 'short',
      hour: '2-digit',
      minute: '2-digit'
    }).format(new Date(value));
  } catch {
    return value;
  }
}

function isInternalLink(url) {
  return typeof url === 'string' && url.startsWith('/');
}

async function openNotification(notification) {
  if (!notification?.id) return;

  const url = notification.url || '/notifications';
  await notifications.openNotification(notification.id).catch(() => null);

  if (isInternalLink(url)) {
    await router.push(url);
    return;
  }

  if (typeof window !== 'undefined') {
    window.location.href = url;
  }
}

async function clearAllNotifications() {
  if (!notifications.items.length) return;
  await notifications.clearAll().catch(() => null);
}
</script>

<template>
  <div class="space-y-6">
    <section class="section-card">
      <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700">Notifications</p>
          <h1 class="mt-1 text-3xl font-black tracking-tight text-slate-950">Notification center</h1>
          <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
            Track run changes, payment updates, proof approvals, and invoice events here.
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
          <span class="rounded-full border border-slate-200 bg-white/80 px-3 py-1 text-sm font-bold text-slate-700 dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100">
            {{ notifications.unreadCount }} unread
          </span>
          <button
            type="button"
            class="btn-secondary"
            :disabled="!notifications.unreadCount"
            @click="notifications.markAllRead()"
          >
            Mark all read
          </button>
          <button
            type="button"
            class="btn-secondary border-rose-200 text-rose-700 hover:bg-rose-50 hover:text-rose-800 dark:border-rose-400/30 dark:text-rose-200 dark:hover:bg-rose-400/10"
            :disabled="!notifications.items.length"
            @click="clearAllNotifications"
          >
            Clear all
          </button>
        </div>
      </div>
    </section>

    <section class="mx-auto max-w-3xl space-y-2">
      <div v-if="notifications.loading" class="rounded-2xl border bg-white p-4 text-sm text-slate-600 dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100">
        Loading notifications...
      </div>

      <div
        v-else-if="!groupedNotifications.length"
        class="rounded-2xl border border-dashed border-slate-200 bg-white p-5 text-sm text-slate-600 dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100"
      >
        No notifications yet.
      </div>

      <article
        v-for="notification in groupedNotifications"
        :key="notification.id"
        class="rounded-2xl border bg-white transition hover:-translate-y-0.5 hover:shadow-md dark:bg-white/[0.06]"
        :class="notification.read_at ? 'border-slate-200 dark:border-white/10' : 'border-emerald-200 bg-emerald-50/70 dark:border-emerald-400/40 dark:bg-emerald-400/10'"
      >
        <button
          type="button"
          class="w-full p-3 text-left sm:p-4"
          @click="openNotification(notification)"
        >
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0 space-y-1">
            <div class="flex items-center gap-2">
              <span
                class="inline-flex h-2 w-2 shrink-0 rounded-full"
                :class="notification.read_at ? 'bg-slate-300' : 'bg-emerald-500'"
              />
              <h2 class="truncate text-sm font-black text-slate-950 dark:text-white">
                {{ notification.title || 'Notification' }}
              </h2>
            </div>
            <p class="line-clamp-2 text-xs leading-5 text-slate-600 dark:text-emerald-100">
              {{ notification.body || 'View the latest update.' }}
            </p>
            <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500 dark:text-emerald-100">
              <span v-if="notification.data?.job_title" class="rounded-full bg-slate-100 px-2 py-1 font-semibold text-slate-700 dark:bg-white/10 dark:text-emerald-100">
                {{ notification.data.job_title }}
              </span>
              <span>{{ formatDate(notification.created_at) }}</span>
            </div>
          </div>
          <div class="flex shrink-0 items-center gap-2">
            <span class="rounded-full px-3 py-1 text-xs font-bold" :class="notification.read_at ? 'bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-emerald-100' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-400 dark:text-slate-950'">
              {{ notification.read_at ? 'Read' : 'New' }}
            </span>
          </div>
        </div>
        </button>
      </article>
    </section>
  </div>
</template>
