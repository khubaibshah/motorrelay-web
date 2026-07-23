<script setup>
import { computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useThemeStore } from '@/stores/theme';
import { RouterLink } from 'vue-router';
import AccountSettingsBlock from '@/components/AccountSettingsBlock.vue';
import DriverPayoutConnectCard from '@/components/profile/DriverPayoutConnectCard.vue';
import StripeIdentityCard from '@/components/profile/StripeIdentityCard.vue';

const auth = useAuthStore();
const theme = useThemeStore();
const router = useRouter();

const isDriver = computed(() => auth.role === 'driver');

const initials = computed(() => {
  if (!auth.user?.name) return 'MR';
  return auth.user.name
    .split(' ')
    .map((part) => part[0])
    .join('')
    .toUpperCase()
    .slice(0, 2);
});

onMounted(() => {
  if (auth.token) {
    auth.fetchMe().catch(() => null);
  }
});

async function handleLogout() {
  await auth.logout();
  if (typeof window !== 'undefined') {
    window.location.assign('/login');
    return;
  }
  await router.replace({ name: 'login' });
}

</script>

<template>
  <div class="grid gap-4 lg:grid-cols-[2fr_1fr]">
    <div class="space-y-4">
      <section class="tile space-y-4 p-4 md:p-5">
        <header class="flex items-start gap-3">
          <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-base font-bold text-emerald-700">
            {{ initials }}
          </div>
          <div class="min-w-0 flex-1">
            <h1 class="truncate text-xl font-black text-slate-950 md:text-2xl">
              {{ auth.user?.name || 'New MotorRelay user' }}
            </h1>
            <p class="truncate text-xs text-slate-500 md:text-sm">
              {{ auth.user?.email || 'email@motorrelay.com' }}
            </p>
          </div>
        </header>
      </section>

      <DriverPayoutConnectCard v-if="isDriver" />

      <StripeIdentityCard v-if="auth.user && auth.role !== 'admin'" />

      <AccountSettingsBlock />

    </div>

    <aside class="tile space-y-4 p-6">
      <div class="flex items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white/70 px-4 py-3 dark:border-white/10 dark:bg-white/[0.06]">
        <div>
          <p class="text-sm font-bold text-slate-900 dark:text-emerald-300">Dark mode</p>
          <p class="text-xs text-slate-500 dark:text-emerald-100">{{ theme.isDark ? 'On' : 'Off' }}</p>
        </div>
        <button
          type="button"
          role="switch"
          :aria-checked="theme.isDark"
          class="relative h-8 w-14 rounded-full p-1 transition"
          :class="theme.isDark ? 'bg-emerald-400' : 'bg-slate-300'"
          @click="theme.toggle"
        >
          <span
            class="block h-6 w-6 rounded-full bg-white shadow-md transition"
            :class="theme.isDark ? 'translate-x-6' : 'translate-x-0'"
          />
          <span class="sr-only">Toggle dark mode</span>
        </button>
      </div>
      <RouterLink to="/invoices" class="btn-secondary w-full">
        Invoices
      </RouterLink>
      <RouterLink to="/legal" class="btn-secondary w-full">
        Legal
      </RouterLink>
      <button
        type="button"
        class="w-full rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100 hover:text-rose-800"
        @click="handleLogout"
      >
        Logout
      </button>
    </aside>

  </div>
</template>
