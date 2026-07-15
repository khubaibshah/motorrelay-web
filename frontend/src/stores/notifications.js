import { defineStore } from 'pinia';
import {
  deleteAllNotifications,
  deleteNotification,
  fetchNotifications,
  markAllNotificationsRead,
  markNotificationRead
} from '@/services/notifications';
import { createEchoClient, disconnectEchoClient } from '@/services/realtime';

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
    realtimeChannel: null,
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
    receiveRealtimeNotification(payload = {}) {
      const notification = {
        id: payload.id || payload.notification_id || `${payload.type || 'notification'}-${Date.now()}`,
        type: payload.type || 'notification',
        title: payload.title || 'Notification',
        body: payload.body || '',
        action_label: payload.action_label || (payload.url ? 'Open' : null),
        url: payload.url || null,
        read_at: null,
        created_at: payload.created_at || new Date().toISOString(),
        data: payload
      };

      const existingIndex = this.items.findIndex((item) => item.id === notification.id);
      if (existingIndex >= 0) {
        this.items.splice(existingIndex, 1, { ...this.items[existingIndex], ...notification });
      } else {
        this.items.unshift(notification);
      }

      this.items = this.items.slice(0, 50);
      this.unreadCount = this.items.filter((item) => !item.read_at).length;
      this.lastSyncedAt = new Date().toISOString();
      this.enqueueToast(notification);

      if (!payload.id) {
        this.refresh({ showToasts: false }).catch(() => null);
      }
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
    },
    startRealtime(userId) {
      if (!userId || this.realtimeChannel) return;

      const echo = createEchoClient();
      if (!echo) return;

      this.realtimeChannel = `App.Models.User.${userId}`;
      echo.private(this.realtimeChannel).notification((notification) => {
        this.receiveRealtimeNotification(notification);
      });
    },
    stopRealtime() {
      if (this.realtimeChannel) {
        const echo = createEchoClient();
        echo?.leave(this.realtimeChannel);
      }
      this.realtimeChannel = null;
      disconnectEchoClient();
    }
  }
});
