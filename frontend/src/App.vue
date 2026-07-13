<template>
  <div class="flex min-h-screen flex-col text-slate-900">
    <div
      v-if="notifications.toasts.length"
      class="fixed right-3 top-20 z-[60] flex w-[calc(100vw-1.5rem)] max-w-sm flex-col gap-3 sm:right-6 sm:top-24 sm:w-full"
    >
      <div
        v-for="toast in notifications.toasts"
        :key="toast.id"
        class="rounded-3xl border border-slate-200 bg-white p-4 shadow-2xl shadow-slate-950/15"
      >
        <div class="flex items-start justify-between gap-3">
          <button type="button" class="min-w-0 flex-1 text-left" @click="openToast(toast)">
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-700">Notification</p>
            <h2 class="mt-1 text-sm font-black text-slate-950">{{ toast.title }}</h2>
            <p class="mt-1 text-sm leading-6 text-slate-600">{{ toast.body }}</p>
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

    <header class="sticky top-0 z-50 border-b border-white/70 bg-white/75 backdrop-blur-xl">
      <nav class="mx-auto grid max-w-7xl grid-cols-[1fr_auto_1fr] items-center gap-3 px-3 py-3 sm:px-6 lg:px-8 md:flex md:justify-between">
        <div class="flex min-w-0 items-center justify-start gap-2 sm:gap-3 md:flex">
          <div v-if="showBackButton" class="md:hidden">
            <BackButton />
          </div>
          <div v-else class="h-10 w-10 md:hidden" aria-hidden="true"></div>
          <RouterLink to="/" class="group hidden min-w-0 items-center gap-2 sm:gap-3 md:flex">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-slate-950 shadow-lg shadow-slate-950/15 ring-1 ring-white/40 transition group-hover:-translate-y-0.5 sm:h-11 sm:w-11">
              <img
                src="@/assets/logo-icon.svg"
                alt="MotorRelay logo"
                class="h-7 w-7 sm:h-8 sm:w-8"
              />
            </span>
            <div class="flex min-w-0 flex-col leading-tight text-slate-900">
              <span class="truncate text-sm font-black tracking-tight sm:text-base">MotorRelay</span>
              <span class="hidden text-[11px] font-bold uppercase tracking-[0.22em] text-emerald-600 sm:block">Move Smarter</span>
            </div>
          </RouterLink>
        </div>

        <RouterLink to="/" class="group col-start-2 flex min-w-0 items-center justify-center gap-2 md:hidden">
          <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-slate-950 shadow-lg shadow-slate-950/15 ring-1 ring-white/40 transition group-hover:-translate-y-0.5 sm:h-11 sm:w-11">
            <img
              src="@/assets/logo-icon.svg"
              alt="MotorRelay logo"
              class="h-7 w-7 sm:h-8 sm:w-8"
            />
          </span>
        </RouterLink>

        <div class="hidden items-center gap-2 md:flex">
          <div class="flex items-center gap-1 rounded-2xl border border-slate-200/80 bg-white/70 p-1 text-sm font-semibold text-slate-600 shadow-sm">
            <RouterLink
              v-for="item in desktopNavLinks"
              :key="item.to"
              :to="item.to"
              :class="[
                baseLinkClass,
                'flex items-center gap-2',
                isNavActive(item) ? activeLinkClass : inactiveLinkClass
              ]"
            >
              <span>{{ item.label }}</span>
            </RouterLink>
          </div>

          <div v-if="visibleNavLinks.some((item) => item.icon === 'notifications')" ref="notificationMenuRef" class="relative">
            <button
              type="button"
              class="inline-flex items-center gap-2 rounded-2xl border border-slate-200/80 bg-white/80 px-4 py-2 text-sm font-semibold text-slate-800 shadow-sm transition hover:border-emerald-200 hover:text-slate-950"
              :class="notificationDropdownOpen ? 'ring-2 ring-emerald-200' : ''"
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
              class="absolute right-0 top-full z-50 mt-3 w-[22rem] overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-2xl shadow-slate-950/15"
            >
              <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                <div>
                  <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-700">Notifications</p>
                  <p class="text-sm font-semibold text-slate-900">{{ notificationCount }} unread</p>
                </div>
                <button
                  type="button"
                  class="rounded-full px-2 py-1 text-xs font-bold text-slate-500 hover:bg-slate-100 hover:text-slate-700"
                  @click="closeNotificationDropdown"
                >
                  Close
                </button>
              </div>

              <div class="max-h-96 overflow-auto p-2">
                <div v-if="!recentNotifications.length" class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-5 text-sm text-slate-600">
                  No notifications yet.
                </div>

                <button
                  v-for="notification in recentNotifications"
                  :key="notification.id"
                  type="button"
                  class="mb-2 w-full rounded-2xl border p-3 text-left transition last:mb-0 hover:border-emerald-200 hover:bg-emerald-50/50"
                  :class="notification.read_at ? 'border-slate-200 bg-white' : 'border-emerald-200 bg-emerald-50/70'"
                  @click="openToast(notification)"
                >
                  <div class="flex items-start gap-3">
                    <span
                      class="mt-1 inline-flex h-2.5 w-2.5 shrink-0 rounded-full"
                      :class="notification.read_at ? 'bg-slate-300' : 'bg-emerald-500'"
                    />
                    <div class="min-w-0 flex-1">
                      <p class="text-sm font-black text-slate-950">{{ notification.title }}</p>
                      <p class="mt-1 line-clamp-2 text-xs leading-5 text-slate-600">{{ notification.body }}</p>
                    </div>
                  </div>
                </button>
              </div>

              <div class="border-t border-slate-100 p-3">
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
            class="btn-primary shrink-0 px-3 py-2 text-xs sm:px-4 sm:text-sm"
          >
            Login
          </RouterLink>
          <div v-else class="h-10 w-10" aria-hidden="true"></div>
        </div>

        <RouterLink
          v-if="!showLogin"
          to="/profile"
          class="hidden shrink-0 items-center justify-center rounded-2xl border border-slate-200/80 bg-white/80 p-2 text-slate-800 shadow-sm transition hover:border-emerald-200 hover:text-slate-950 md:inline-flex"
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

    <main :class="mainContainerClass">
      <nav v-if="breadcrumbs.length" class="mb-5 flex flex-wrap items-center gap-2 text-sm text-slate-500">
        <template v-for="(crumb, index) in breadcrumbs" :key="crumb.href ?? index">
          <RouterLink
            v-if="index !== breadcrumbs.length - 1 && crumb.href"
            :to="crumb.href"
            class="font-semibold text-emerald-700 hover:text-emerald-800"
          >
            {{ crumb.label }}
          </RouterLink>
          <span v-else class="font-medium text-slate-700">
            {{ crumb.label }}
          </span>
          <span v-if="index !== breadcrumbs.length - 1" aria-hidden="true" class="text-slate-300">/</span>
        </template>
      </nav>
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
import BackButton from '@/components/BackButton.vue';

const auth = useAuthStore();
const notifications = useNotificationsStore();
const router = useRouter();
const route = useRoute();
const notificationMenuRef = ref(null);
const notificationDropdownOpen = ref(false);
const baseMainClasses = 'mx-auto w-full flex-1 max-w-7xl px-3 pb-28 pt-5 sm:px-6 sm:pb-10 sm:pt-7 lg:px-8';

const navLinks = [
  { to: '/', label: 'Home', exact: true, icon: 'home', showInBottomNav: true },
  { to: '/driver', label: 'Driver', roles: ['driver'], icon: 'jobs', showInBottomNav: true },
  { to: '/invoices', label: 'Invoices', roles: ['driver', 'dealer', 'admin'] },
  { to: '/jobs', label: 'Jobs', icon: 'jobs', showInBottomNav: true },
  { to: '/membership', label: 'Membership' },
  { to: '/messages', label: 'Messages', icon: 'messages', showInBottomNav: true },
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
const desktopNavLinks = computed(() => visibleNavLinks.value.filter((link) => link.icon !== 'notifications'));
const showLogin = computed(() => !auth.isAuthenticated);

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
const activeLinkClass = 'bg-slate-950 text-white shadow-sm';
const inactiveLinkClass = 'hover:bg-slate-100 hover:text-slate-950';
const notificationCount = computed(() => Number(notifications.unreadCount || 0));
const recentNotifications = computed(() => notifications.recentItems.slice(0, 5));
const hasNotifications = computed(() => notificationCount.value > 0);

function isNavActive(item) {
  if (item.exact) {
    return route.path === item.to;
  }
  return route.path.startsWith(item.to);
}

const breadcrumbs = computed(() => {
  const crumbs = [];

  route.matched.forEach((record) => {
    if (!record.meta || record.meta.breadcrumb === undefined) return;

    const value =
      typeof record.meta.breadcrumb === 'function'
        ? record.meta.breadcrumb(route)
        : record.meta.breadcrumb;

    const entries = Array.isArray(value) ? value : [{ label: value }];

    entries.forEach((entry) => {
      if (entry === null || entry === undefined || entry === false) return;

      const normalized =
        typeof entry === 'string' ? { label: entry } : { ...entry };

      if (!normalized.label) return;

      let href = null;
      if (normalized.to) {
        href = normalized.to;
      } else if (normalized.name) {
        href = router.resolve({ name: normalized.name, params: route.params }).href;
      } else if (record.name) {
        href = router.resolve({ name: record.name, params: route.params }).href;
      }

      crumbs.push({ label: normalized.label, href });
    });
  });

  if (crumbs.length) {
    crumbs[crumbs.length - 1].href = null;
  }

  return crumbs;
});

const mainContainerClass = computed(() => baseMainClasses);
const showBackButton = computed(() => route.path !== '/');

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
  router.push({ name: 'login' });
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
