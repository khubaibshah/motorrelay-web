<template>
  <div class="relative flex h-[100dvh] min-h-0 flex-col overflow-hidden text-slate-900 dark:text-slate-100">
    <div
      v-if="notifications.toasts.length"
      class="fixed right-3 top-20 z-[60] flex w-[calc(100vw-1.5rem)] max-w-sm flex-col gap-3 sm:right-6 sm:top-24 sm:w-full"
    >
      <div
        v-for="toast in notifications.toasts"
        :key="toast.id"
        class="rounded-3xl border border-slate-200 bg-white p-4 shadow-2xl shadow-slate-950/15 dark:border-white/10 dark:bg-slate-950 dark:shadow-black/30"
      >
        <div class="flex items-start justify-between gap-3">
          <button type="button" class="min-w-0 flex-1 text-left" @click="openToast(toast)">
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-700">Notification</p>
            <h2 class="mt-1 text-sm font-black text-slate-950 dark:text-white">{{ toast.title }}</h2>
            <p class="mt-1 text-sm leading-6 text-slate-600 dark:text-slate-300">{{ toast.body }}</p>
          </button>
          <button
            type="button"
            class="rounded-full px-2 py-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
            @click="notifications.dismissToast(toast.id)"
          >
            ✕
          </button>
        </div>
      </div>
    </div>

    <header class="z-50 shrink-0 border-b border-white/10 bg-[#004c3f] pt-[env(safe-area-inset-top)] shadow-lg shadow-slate-950/10">
      <nav class="mx-auto grid max-w-7xl grid-cols-[1fr_auto_1fr] items-center gap-3 px-3 py-2.5 sm:px-6 lg:px-8 md:flex md:justify-between">
        <div class="flex min-w-0 items-center justify-start gap-2 sm:gap-3 md:flex">
          <div class="h-12 w-16 md:hidden" aria-hidden="true"></div>
          <RouterLink to="/" class="group hidden min-w-0 items-center md:flex">
            <span class="text-xl font-black tracking-tight text-white transition group-hover:-translate-y-0.5 sm:text-2xl">
              MotorRelay
            </span>
          </RouterLink>
        </div>

        <RouterLink to="/" class="group col-start-2 flex min-w-0 items-center justify-center md:hidden">
          <span class="text-xl font-black tracking-tight text-white transition group-hover:-translate-y-0.5 sm:text-2xl">
            MotorRelay
          </span>
        </RouterLink>

        <div class="hidden items-center gap-2 md:flex">
          <div class="flex items-center gap-1 rounded-2xl border border-white/15 bg-white/10 p-1 text-sm font-semibold text-white shadow-sm">
            <RouterLink
              v-for="item in desktopNavLinks"
              :key="item.to"
              :to="item.to"
              :class="[
                baseLinkClass,
                item.icon === 'profile' ? 'inline-flex items-center justify-center px-3' : 'flex items-center gap-2',
                isNavActive(item) ? activeLinkClass : inactiveLinkClass
              ]"
              :aria-label="item.icon === 'profile' ? 'Profile' : null"
              :title="item.icon === 'profile' ? 'Profile' : null"
            >
              <template v-if="item.icon === 'profile'">
                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                  <circle cx="12" cy="8.75" r="3.25" />
                  <path stroke-linecap="round" stroke-linejoin="round" d="M5.75 19a6.25 6.25 0 0 1 12.5 0" />
                </svg>
                <span class="sr-only">Profile</span>
              </template>
              <span v-else>{{ item.label }}</span>
            </RouterLink>
          </div>

          <div v-if="visibleNavLinks.some((item) => item.icon === 'notifications')" ref="notificationMenuRef" class="relative">
            <button
              type="button"
              class="inline-flex items-center gap-2 rounded-2xl border border-white/15 bg-white/10 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-white/15"
              :class="notificationDropdownOpen ? 'ring-2 ring-white/30' : ''"
              @click="toggleNotificationDropdown"
            >
              <span class="relative inline-flex h-5 w-5 items-center justify-center">
                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M14.25 18.75a2.25 2.25 0 0 1-4.5 0m7.5-4.5V10.5a5.25 5.25 0 1 0-10.5 0v3.75l-1.5 1.5v.75h13.5v-.75l-1.5-1.5Z" />
                </svg>
                <span
                  v-if="hasNotifications"
                  class="absolute -right-2 -top-2 inline-flex min-w-5 items-center justify-center rounded-full bg-rose-500 px-1.5 py-0.5 text-[10px] font-black leading-none text-white"
                >
                  {{ notificationCount }}
                </span>
              </span>
              <span>Notifications</span>
            </button>

            <div
              v-if="notificationDropdownOpen"
            class="absolute right-0 top-full z-50 mt-3 w-[22rem] overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-2xl shadow-slate-950/15 dark:border-white/10 dark:bg-slate-950 dark:shadow-black/30"
            >
              <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3 dark:border-slate-800">
                <div>
                  <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-700">Notifications</p>
                  <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ notificationCount }} unread</p>
                </div>
                <button
                  type="button"
                  class="rounded-full px-2 py-1 text-xs font-bold text-slate-500 hover:bg-slate-100 hover:text-slate-700 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200"
                  @click="closeNotificationDropdown"
                >
                  Close
                </button>
              </div>

              <div class="max-h-96 overflow-auto p-2">
                <div v-if="!recentNotifications.length" class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-5 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-300">
                  No notifications yet.
                </div>

                <button
                  v-for="notification in recentNotifications"
                  :key="notification.id"
                  type="button"
                  class="mb-2 w-full rounded-2xl border p-3 text-left transition last:mb-0 hover:border-emerald-200 hover:bg-emerald-50/50"
                  :class="notification.read_at ? 'border-slate-200 bg-white dark:border-white/10 dark:bg-white/[0.06]' : 'border-emerald-200 bg-emerald-50/70 dark:border-emerald-500/40 dark:bg-emerald-500/10'"
                  @click="openToast(notification)"
                >
                  <div class="flex items-start gap-3">
                    <span
                      class="mt-1 inline-flex h-2.5 w-2.5 shrink-0 rounded-full"
                      :class="notification.read_at ? 'bg-slate-300' : 'bg-emerald-500'"
                    />
                    <div class="min-w-0 flex-1">
                      <p class="text-sm font-black text-slate-950 dark:text-white">{{ notification.title }}</p>
                      <p class="mt-1 line-clamp-2 text-xs leading-5 text-slate-600 dark:text-emerald-100">{{ notification.body }}</p>
                    </div>
                  </div>
                </button>
              </div>

              <div class="border-t border-slate-100 p-3 dark:border-slate-800">
                <RouterLink
                  to="/notifications"
                  class="btn-primary flex w-full items-center justify-center"
                  @click="closeNotificationDropdown"
                >
                  View notification center
                </RouterLink>
              </div>
            </div>
          </div>
        </div>

        <div class="col-start-3 flex justify-end md:hidden">
          <RouterLink
            v-if="showLogin"
            to="/login"
            class="shrink-0 rounded-xl bg-white px-3 py-2 text-xs font-bold text-[#004c3f] shadow-sm transition hover:bg-emerald-50 sm:px-4 sm:text-sm"
          >
            Login
          </RouterLink>
          <div v-else class="h-10 w-10" aria-hidden="true"></div>
        </div>

        <RouterLink
          v-if="!showLogin"
          to="/profile"
          class="hidden shrink-0 items-center justify-center rounded-2xl border border-white/15 bg-white/10 p-2 text-white shadow-sm transition hover:bg-white/15 md:inline-flex"
          aria-label="Profile"
          title="Profile"
        >
          <span class="sr-only">Profile</span>
          <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <circle cx="12" cy="8.75" r="3.25" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M5.75 19a6.25 6.25 0 0 1 12.5 0" />
          </svg>
        </RouterLink>
      </nav>
    </header>

    <main ref="mainScrollRef" :class="mainContainerClass">
      <RouterView />
    </main>

    <BottomNav v-if="bottomNavItems.length" :items="bottomNavItems" :badge-count="notificationCount" />
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useNotificationsStore } from '@/stores/notifications';
import BottomNav from '@/components/BottomNav.vue';

const auth = useAuthStore();
const notifications = useNotificationsStore();
const router = useRouter();
const route = useRoute();
const notificationMenuRef = ref(null);
const mainScrollRef = ref(null);
const notificationDropdownOpen = ref(false);
const baseMainClasses = 'mx-auto min-h-0 w-full flex-1 max-w-7xl overflow-y-auto overscroll-y-contain px-3 pt-6 sm:px-6 sm:pt-8 lg:px-8';

const navLinks = [
  { to: '/', label: 'Home', exact: true, icon: 'home', showInBottomNav: true },
  { to: '/driver', label: 'Driver', roles: ['driver'], icon: 'jobs', showInBottomNav: true },
  { to: '/invoices', label: 'Invoices', roles: ['driver', 'dealer', 'admin'] },
  { to: '/jobs', label: 'Runs', icon: 'jobs', showInBottomNav: true },
  { to: '/membership', label: 'Membership' },
  { to: '/messages', label: 'Chat', icon: 'messages', showInBottomNav: true },
  { to: '/notifications', label: 'Notifications', icon: 'notifications', showInBottomNav: true },
  { to: '/admin', label: 'Admin', roles: ['admin'], icon: 'admin', showInBottomNav: true },
  { to: '/planner', label: 'Planner', condition: () => auth.hasPlannerAccess },
  { to: '/profile', label: 'Profile', icon: 'profile', showInBottomNav: true }
];

const roleNavMap = {
  driver: new Set(['/', '/driver', '/jobs', '/messages', '/notifications', '/profile', '/invoices', '/planner']),
  dealer: new Set(['/', '/jobs', '/messages', '/notifications', '/profile', '/invoices', '/planner']),
  admin: new Set(['/', '/admin', '/jobs', '/messages', '/notifications', '/profile', '/invoices', '/planner'])
};

function canShowLink(link, role) {
  if (link.roles && !link.roles.includes(role)) {
    return false;
  }
  if (typeof link.condition === 'function' && !link.condition()) {
    return false;
  }
  if (roleNavMap[role]) {
    return roleNavMap[role].has(link.to);
  }
  return true;
}

const visibleNavLinks = computed(() => {
  if (!auth.isAuthenticated) return [];
  const role = auth.role || null;
  return navLinks.filter((link) => canShowLink(link, role));
});
const desktopNavLinks = computed(() =>
  visibleNavLinks.value.filter((link) => link.icon !== 'notifications' && link.icon !== 'profile')
);
const showLogin = computed(
  () => !auth.isAuthenticated && !['login', 'signup'].includes(String(route.name))
);

const bottomNavItems = computed(() => {
  if (!auth.isAuthenticated) return [];
  const role = auth.role || null;

  return navLinks
    .filter((link) => link.showInBottomNav)
    .filter((link) => canShowLink(link, role))
    .map((link) => ({
      to: link.to,
      label: link.label,
      icon: link.icon,
      exact: link.exact ?? false
    }));
});

const baseLinkClass = 'rounded-xl px-3 py-2 transition';
const activeLinkClass = 'bg-white text-[#004c3f] shadow-sm';
const inactiveLinkClass = 'text-white/85 hover:bg-white/10 hover:text-white';
const notificationCount = computed(() => Number(notifications.unreadCount || 0));
const recentNotifications = computed(() => notifications.recentItems.slice(0, 5));
const hasNotifications = computed(() => notificationCount.value > 0);

function isNavActive(item) {
  if (item.exact) {
    return route.path === item.to;
  }
  return route.path.startsWith(item.to);
}

const mainContainerClass = computed(() => [
  baseMainClasses,
  bottomNavItems.value.length
    ? 'pb-[calc(7rem+env(safe-area-inset-bottom))] sm:pb-10'
    : 'pb-[max(2rem,env(safe-area-inset-bottom))] sm:pb-10'
]);

function closeNotificationDropdown() {
  notificationDropdownOpen.value = false;
}

async function toggleNotificationDropdown() {
  notificationDropdownOpen.value = !notificationDropdownOpen.value;

  if (notificationDropdownOpen.value) {
    await notifications.refresh({ showToasts: false }).catch(() => null);
  }
}

async function handleLogout() {
  await auth.logout();
  if (typeof window !== 'undefined') {
    window.location.assign('/login');
    return;
  }
  router.replace({ name: 'login' });
}

async function openToast(toast) {
  if (!toast?.id) return;
  await notifications.openNotification(toast.id).catch(() => null);

  if (toast.url && typeof toast.url === 'string' && toast.url.startsWith('/')) {
    await router.push(toast.url);
    return;
  }

  if (toast.url && typeof window !== 'undefined') {
    window.location.href = toast.url;
  }
}

function handleDocumentPointerDown(event) {
  if (!notificationMenuRef.value) return;
  if (notificationMenuRef.value.contains(event.target)) return;
  closeNotificationDropdown();
}

watch(
  () => route.fullPath,
  () => {
    closeNotificationDropdown();
    mainScrollRef.value?.scrollTo({ top: 0, behavior: 'auto' });
  }
);

watch(
  () => auth.isAuthenticated,
  async (isAuthenticated) => {
    notifications.stopPolling();

    if (!isAuthenticated) {
      notifications.items = [];
      notifications.unreadCount = 0;
      notifications.toasts = [];
      return;
    }

    await notifications.refresh({ showToasts: true }).catch(() => null);
    notifications.startPolling();
  },
  { immediate: true }
);

onMounted(() => {
  document.addEventListener('pointerdown', handleDocumentPointerDown);
});

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', handleDocumentPointerDown);
  notifications.stopPolling();
});
</script>
