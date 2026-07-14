import { defineStore } from 'pinia';
import {
  deleteAllNotifications,
  deleteNotification,
  fetchNotifications,
  markAllNotificationsRead,
  markNotificationRead
} from '@/services/notifications';

const SEEN_KEY = 'mr_seen_notification_ids';
const TOAST_DURATION_MS = 6500;
const toastTimers = new Map();

function readSeenIds() {
  if (typeof window === 'undefined') return [];

  try {
    const raw = window.localStorage.getItem(SEEN_KEY);
    const parsed = raw ? JSON.parse(raw) : [];
    return Array.isArray(parsed) ? parsed.filter((id) => typeof id === 'string') : [];
  } catch {
    return [];
  }
}

function persistSeenIds(ids) {
  if (typeof window === 'undefined') return;
  window.localStorage.setItem(SEEN_KEY, JSON.stringify(Array.from(new Set(ids)).slice(-100)));
}

function clearToastTimer(id) {
  const timer = toastTimers.get(id);
  if (timer) {
    clearTimeout(timer);
    toastTimers.delete(id);
  }
}

export const useNotificationsStore = defineStore('notifications', {
  state: () => ({
    items: [],
    unreadCount: 0,
    loading: false,
    toasts: [],
    seenToastIds: readSeenIds(),
    initialized: false,
    pollHandle: null,
    lastSyncedAt: null
  }),
  getters: {
    recentItems: (state) => state.items,
    hasUnread: (state) => state.unreadCount > 0
  },
  actions: {
    async refresh({ showToasts = true } = {}) {
      this.loading = true;

      try {
        const payload = await fetchNotifications();
        const items = Array.isArray(payload?.data) ? payload.data : [];
        const unreadCount = Number(payload?.unread_count ?? 0) || 0;

        this.items = items;
        this.unreadCount = unreadCount;
        this.lastSyncedAt = new Date().toISOString();

        if (showToasts) {
          const unseen = items.filter((item) => !item.read_at && !this.seenToastIds.includes(item.id));
          unseen.slice(0, 3).forEach((item) => this.enqueueToast(item));
        }
      } finally {
        this.loading = false;
      }
    },
    enqueueToast(notification) {
      if (!notification?.id) return;

      this.seenToastIds = Array.from(new Set([...this.seenToastIds, notification.id]));
      persistSeenIds(this.seenToastIds);

      if (!this.toasts.some((toast) => toast.id === notification.id)) {
        this.toasts.unshift({
          id: notification.id,
          title: notification.title || 'Notification',
          body: notification.body || '',
          url: notification.url || null,
          action_label: notification.action_label || 'Open',
          created_at: notification.created_at || null
        });
      }

      clearToastTimer(notification.id);
      toastTimers.set(
        notification.id,
        setTimeout(() => {
          this.dismissToast(notification.id);
        }, TOAST_DURATION_MS)
      );
    },
    dismissToast(notificationId) {
      clearToastTimer(notificationId);
      this.toasts = this.toasts.filter((toast) => toast.id !== notificationId);
    },
    async openNotification(notificationId) {
      const payload = await markNotificationRead(notificationId);
      this.unreadCount = Number(payload?.unread_count ?? this.unreadCount) || 0;
      this.items = this.items.map((item) =>
        item.id === notificationId ? { ...item, read_at: payload?.data?.read_at || new Date().toISOString() } : item
      );
      this.dismissToast(notificationId);
      return payload;
    },
    async markAllRead() {
      const payload = await markAllNotificationsRead();
      this.unreadCount = Number(payload?.unread_count ?? 0) || 0;
      this.items = this.items.map((item) => ({ ...item, read_at: item.read_at || new Date().toISOString() }));
      this.toasts.forEach((toast) => this.dismissToast(toast.id));
      this.toasts = [];
    },
    async clearNotification(notificationId) {
      const existingItems = this.items;
      const existingUnreadCount = this.unreadCount;
      const removedItem = this.items.find((item) => item.id === notificationId);

      this.items = this.items.filter((item) => item.id !== notificationId);
      if (removedItem && !removedItem.read_at) {
        this.unreadCount = Math.max(Number(this.unreadCount || 0) - 1, 0);
      }
      this.dismissToast(notificationId);

      try {
        const payload = await deleteNotification(notificationId);
        this.unreadCount = Number(payload?.unread_count ?? this.items.filter((item) => !item.read_at).length) || 0;
        return payload;
      } catch (error) {
        this.items = existingItems;
        this.unreadCount = existingUnreadCount;
        throw error;
      }
    },
    async clearAll() {
      const payload = await deleteAllNotifications();
      this.items = [];
      this.unreadCount = Number(payload?.unread_count ?? 0) || 0;
      this.toasts.forEach((toast) => this.dismissToast(toast.id));
      this.toasts = [];
      return payload;
    },
    startPolling(intervalMs = 30000) {
      if (this.pollHandle || typeof window === 'undefined') return;

      this.pollHandle = window.setInterval(() => {
        this.refresh({ showToasts: true }).catch(() => null);
      }, intervalMs);
    },
    stopPolling() {
      if (this.pollHandle && typeof window !== 'undefined') {
        window.clearInterval(this.pollHandle);
      }
      this.pollHandle = null;
    }
  }
});
