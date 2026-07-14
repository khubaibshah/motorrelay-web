<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useNotificationsStore } from '@/stores/notifications';

const auth = useAuthStore();
const notifications = useNotificationsStore();
const router = useRouter();
const openActionsId = ref(null);
const touchState = ref({ id: null, startX: 0, startY: 0, swiping: false });
const swipeOffsets = ref({});
const deletingIds = ref(new Set());
const swipeActionWidth = 92;

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

  if (openActionsId.value === notification.id) {
    closeSwipe(notification.id);
    return;
  }

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

function startNotificationTouch(notification, event) {
  const touch = event.touches?.[0];
  if (!touch) return;
  if (openActionsId.value && openActionsId.value !== notification.id) {
    closeSwipe(openActionsId.value);
  }
  touchState.value = {
    id: notification.id,
    startX: touch.clientX,
    startY: touch.clientY,
    swiping: false
  };
}

function moveNotificationTouch(notification, event) {
  const touch = event.touches?.[0];
  if (!touch || touchState.value.id !== notification.id) return;

  const diffX = touch.clientX - touchState.value.startX;
  const diffY = touch.clientY - touchState.value.startY;

  if (!touchState.value.swiping && Math.abs(diffX) > 8 && Math.abs(diffX) > Math.abs(diffY)) {
    touchState.value = { ...touchState.value, swiping: true };
  }

  if (!touchState.value.swiping) return;
  event.preventDefault();

  const openBase = openActionsId.value === notification.id ? swipeActionWidth : 0;
  const offset = Math.max(0, Math.min(swipeActionWidth, openBase - diffX));
  swipeOffsets.value = {
    ...swipeOffsets.value,
    [notification.id]: offset
  };
}

function endNotificationTouch(notification, event) {
  const touch = event.changedTouches?.[0];
  if (!touch || touchState.value.id !== notification.id) return;

  const diffX = touch.clientX - touchState.value.startX;
  const diffY = touch.clientY - touchState.value.startY;
  const offset = swipeOffsets.value[notification.id] || 0;

  if (Math.abs(diffX) > 24 && Math.abs(diffX) > Math.abs(diffY)) {
    if (diffX < 0 || offset > swipeActionWidth / 2) {
      openSwipe(notification.id);
    } else {
      closeSwipe(notification.id);
    }
  }

  touchState.value = { id: null, startX: 0, startY: 0, swiping: false };
}

async function markRead(notification) {
  if (!notification?.id || notification.read_at) return;
  await notifications.openNotification(notification.id).catch(() => null);
  openActionsId.value = null;
}

async function clearNotification(notification) {
  if (!notification?.id || deletingIds.value.has(notification.id)) return;
  deletingIds.value = new Set([...deletingIds.value, notification.id]);
  closeSwipe(notification.id);
  await notifications.clearNotification(notification.id).catch(() => null);
  const nextDeletingIds = new Set(deletingIds.value);
  nextDeletingIds.delete(notification.id);
  deletingIds.value = nextDeletingIds;
}

async function clearAllNotifications() {
  if (!notifications.items.length) return;
  await notifications.clearAll().catch(() => null);
  openActionsId.value = null;
  swipeOffsets.value = {};
}

function openSwipe(notificationId) {
  openActionsId.value = notificationId;
  swipeOffsets.value = {
    ...swipeOffsets.value,
    [notificationId]: swipeActionWidth
  };
}

function closeSwipe(notificationId) {
  if (openActionsId.value === notificationId) {
    openActionsId.value = null;
  }
  swipeOffsets.value = {
    ...swipeOffsets.value,
    [notificationId]: 0
  };
}

function notificationOffset(notificationId) {
  if (swipeOffsets.value[notificationId] !== undefined) return swipeOffsets.value[notificationId];
  return openActionsId.value === notificationId ? swipeActionWidth : 0;
}
</script>

<template>
  <div class="space-y-6">
    <section class="section-card">
      <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-700">Notifications</p>
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

    <section class="space-y-3">
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
        class="relative overflow-hidden rounded-3xl border bg-rose-600 transition hover:-translate-y-0.5 hover:shadow-lg"
        :class="notification.read_at ? 'border-slate-200 dark:border-white/10' : 'border-emerald-200 bg-emerald-50/70 dark:border-emerald-400/40 dark:bg-emerald-400/10'"
        @touchstart.passive="startNotificationTouch(notification, $event)"
        @touchmove="moveNotificationTouch(notification, $event)"
        @touchend.passive="endNotificationTouch(notification, $event)"
      >
        <button
          type="button"
          class="absolute inset-y-0 right-0 z-50 flex w-[92px] touch-manipulation items-center justify-center bg-rose-600 px-3 text-sm font-black text-white"
          :disabled="deletingIds.has(notification.id)"
          @pointerdown.stop.prevent="clearNotification(notification)"
          @touchstart.stop.prevent="clearNotification(notification)"
          @click.stop.prevent="clearNotification(notification)"
        >
          {{ deletingIds.has(notification.id) ? 'Deleting' : 'Delete' }}
        </button>
        <button
          type="button"
          class="relative z-10 w-full rounded-3xl bg-white p-4 text-left transition-transform duration-200 dark:bg-slate-950"
          :class="[
            notification.read_at ? '' : 'bg-emerald-50 dark:bg-emerald-400/10',
            openActionsId === notification.id ? 'pointer-events-none' : ''
          ]"
          :style="{ transform: `translateX(-${notificationOffset(notification.id)}px)` }"
          @click="openNotification(notification)"
        >
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div class="min-w-0 space-y-1">
            <div class="flex items-center gap-2">
              <span
                class="inline-flex h-2.5 w-2.5 rounded-full"
                :class="notification.read_at ? 'bg-slate-300' : 'bg-emerald-500'"
              />
              <h2 class="text-base font-black text-slate-950 dark:text-white">
                {{ notification.title || 'Notification' }}
              </h2>
            </div>
            <p class="text-sm leading-6 text-slate-600 dark:text-emerald-100">
              {{ notification.body || 'View the latest update.' }}
            </p>
            <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500 dark:text-emerald-100">
              <span v-if="notification.data?.job_title" class="rounded-full bg-slate-100 px-2 py-1 font-semibold text-slate-700 dark:bg-white/10 dark:text-emerald-100">
                {{ notification.data.job_title }}
              </span>
              <span>{{ formatDate(notification.created_at) }}</span>
            </div>
          </div>
          <div class="flex shrink-0 flex-wrap items-center gap-2">
            <span class="rounded-full px-3 py-1 text-xs font-bold" :class="notification.read_at ? 'bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-emerald-100' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-400 dark:text-slate-950'">
              {{ notification.read_at ? 'Read' : 'New' }}
            </span>
            <span
              v-if="notification.action_label"
              class="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-bold text-slate-700 dark:border-white/10 dark:bg-white/10 dark:text-emerald-100"
            >
              {{ notification.action_label }}
            </span>
          </div>
        </div>
        </button>
      </article>
    </section>
  </div>
</template>
