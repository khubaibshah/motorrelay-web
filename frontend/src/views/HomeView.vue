<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useNotificationsStore } from '@/stores/notifications';
import { useDriverStore } from '@/stores/driver';
import { useDealerStore } from '@/stores/dealer';
import { useJobsStore } from '@/stores/jobs';
import { formatSentenceStatus } from '@/utils/statusLabels';

const auth = useAuthStore();
const notifications = useNotificationsStore();
const jobsStore = useJobsStore();
const driverStore = useDriverStore();
const dealerStore = useDealerStore();
const jobs = computed(() => jobsStore.highlights);
const loading = ref(false);
let dealerApplicationRefreshTimer = null;

const priceFormatter = new Intl.NumberFormat('en-GB', {
  style: 'currency',
  currency: 'GBP',
  maximumFractionDigits: 0
});

onMounted(async () => {
  if (typeof window !== 'undefined') {
    window.addEventListener('motorrelay:job-event', handleRealtimeJobEvent);
    window.addEventListener('motorrelay:notification', handleRealtimeNotification);
  }

  if ((!auth.user || auth.role === 'dealer') && auth.token) {
    await auth.fetchMe().catch(() => null);
  }
  dealerStore.syncFromProfile(auth.jobs?.posted ?? []);

  loading.value = true;
  try {
    await jobsStore.fetchHighlights();

    if (auth.role === 'driver') {
      await driverStore.fetchOverview();
    }
  } catch (error) {
    console.error('Failed to load highlight jobs', error);
    jobsStore.highlights = [];
    driverStore.reset();
    dealerStore.reset();
  } finally {
    loading.value = false;
  }
});

onBeforeUnmount(() => {
  if (typeof window !== 'undefined') {
    window.removeEventListener('motorrelay:job-event', handleRealtimeJobEvent);
    window.removeEventListener('motorrelay:notification', handleRealtimeNotification);
    window.clearTimeout(dealerApplicationRefreshTimer);
  }
});

watch(
  () => notifications.items.map((notification) => notification?.id).join('|'),
  () => {
    notifications.items
      .filter(isDealerApplicationNotification)
      .forEach((notification) => handleDealerApplicationUpdate({ notification }));
  }
);

const roleLabel = computed(() => {
  if (auth.role === 'driver') return 'Driver workspace';
  if (auth.role === 'dealer') return 'Dealer workspace';
  if (auth.role === 'admin') return 'Admin workspace';
  return 'Vehicle logistics';
});

const postedJobs = computed(() => {
  return dealerStore.postedJobs;
});

const sortByRecentActivity = (items) =>
  [...items].sort((a, b) => {
    const aTime = new Date(a?.updated_at ?? a?.created_at ?? 0).getTime();
    const bTime = new Date(b?.updated_at ?? b?.created_at ?? 0).getTime();
    return bTime - aTime;
  });

const dealerApplicationJobs = computed(() =>
  sortByRecentActivity(
    postedJobs.value.filter((job) => applicationCount(job) > 0)
  )
);
const visibleDealerApplicationJobs = computed(() => {
  const jobsById = new Map();

  [...dealerStore.applicationJobs, ...dealerApplicationJobs.value].forEach((job) => {
    if (!job?.id) return;
    jobsById.set(Number(job.id), {
      ...(jobsById.get(Number(job.id)) ?? {}),
      ...job,
      pending_applications_count: Math.max(
        applicationCount(jobsById.get(Number(job.id)) ?? {}),
        applicationCount(job)
      )
    });
  });

  return sortByRecentActivity(Array.from(jobsById.values()));
});
const driverUpcomingRuns = computed(() => sortByRecentActivity(driverStore.activeJobs).slice(0, 3));
const driverCurrentJob = computed(() => driverUpcomingRuns.value[0] || null);
const hasDriverCurrentJob = computed(() => Boolean(driverCurrentJob.value));
const driverPendingApplications = computed(() => {
  return Array.isArray(driverStore.pendingApplications) ? driverStore.pendingApplications : [];
});
const driverCompletedRuns = computed(() => {
  return Array.isArray(driverStore.completedJobs) ? driverStore.completedJobs : [];
});
const driverStats = computed(() => driverStore.stats);

const primaryAction = computed(() => {
  if (auth.role === 'driver' && driverCurrentJob.value) return { to: `/jobs/${driverCurrentJob.value.id}`, label: 'Continue current run' };
  if (auth.role === 'driver') return { to: '/jobs', label: 'Browse available runs' };
  if (auth.role === 'dealer') return { to: '/jobs/new', label: 'Create run' };
  if (auth.role === 'admin') return { to: '/admin', label: 'Open admin' };
  return { to: '/login', label: 'Sign in' };
});

const heroTitle = computed(() => {
  if (auth.role === 'driver' && driverCurrentJob.value) return 'Keep your current run moving.';
  if (auth.role === 'driver') return 'Ready for your next run?';
  return 'Move vehicles with less chasing.';
});

const heroText = computed(() => {
  if (auth.role === 'driver' && driverCurrentJob.value) {
    return 'Open your assigned run, check the next step, and keep the dealer updated from one clean workspace.';
  }
  if (auth.role === 'driver') {
    return 'Browse paid dealer runs, request the ones that work for you, and manage active deliveries from one place.';
  }
  return 'MotorRelay brings runs, drivers, live tracking, expenses, messages, inspection photos, and invoices into one clean workspace.';
});

const quickLinks = computed(() => {
  if (auth.role === 'driver') {
    return [];
  }

  if (auth.role === 'dealer') {
    return [];
  }

  if (auth.role === 'admin') {
    return [
      { to: '/admin', title: 'Overview', text: 'Monitor platform activity and health.' },
      { to: '/admin/applications', title: 'Applications', text: 'Review driver and dealer activity.' },
      { to: '/admin/system-health', title: 'System health', text: 'Spot stale runs and conversations.' }
    ];
  }

  return [
    { to: '/signup', title: 'Drivers', text: 'Find work, submit proof, and manage invoices.' },
    { to: '/signup', title: 'Dealers', text: 'Post runs and assign trusted drivers.' },
    { to: '/login', title: 'Operations', text: 'Track messages, expenses, and paperwork.' }
  ];
});

const liveBoardJobs = computed(() => {
  if (auth.role === 'dealer') {
    return visibleDealerApplicationJobs.value;
  }

  return jobsToDisplay.value;
});
const liveBoardTitle = computed(() => {
  if (auth.role === 'dealer') return 'Your runs';
  return 'Open runs';
});
const liveBoardEmptyText = computed(() => {
  if (auth.role === 'dealer') {
    return 'No driver applications to review right now.';
  }

  return openJobsEmptyText.value;
});
const liveBoardCountText = computed(() => {
  if (loading.value) return 'Syncing';
  return `${liveBoardJobs.value.length} shown`;
});

function applicationCount(job) {
  return Number(job?.pending_applications_count ?? job?.applications_count ?? 0);
}

function applicationCountLabel(job) {
  const count = applicationCount(job);
  return `${count} ${count === 1 ? 'app' : 'apps'}`;
}

function isDealerApplicationEvent(detail = {}) {
  const eventName = String(
    detail?.event
    ?? detail?.notification?.data?.event
    ?? detail?.notification?.event
    ?? ''
  );

  return eventName === 'driver_applied' || eventName.includes('application');
}

function isDealerApplicationNotification(notification = {}) {
  return isDealerApplicationEvent({
    event: notification?.data?.event ?? notification?.event,
    notification
  });
}

function handleRealtimeJobEvent(event) {
  if (auth.role !== 'dealer' || !auth.token) return;
  if (!isDealerApplicationEvent(event?.detail)) return;
  handleDealerApplicationUpdate(event?.detail);
}

function handleRealtimeNotification(event) {
  if (auth.role !== 'dealer' || !auth.token) return;
  if (!isDealerApplicationNotification(event?.detail)) return;
  handleDealerApplicationUpdate({ notification: event?.detail });
}

function notificationJobId(detail = {}) {
  return Number(
    detail?.job_id
    ?? detail?.notification?.data?.job_id
    ?? detail?.notification?.job_id
    ?? 0
  );
}

function notificationJobTitle(detail = {}) {
  return (
    detail?.job_title
    ?? detail?.notification?.data?.job_title
    ?? detail?.notification?.job_title
    ?? null
  );
}

function upsertDealerApplicationJob(job) {
  dealerStore.upsertApplicationJob(job);
}

function syncAuthPostedJob(job) {
  dealerStore.upsertApplicationJob(job);
}

async function handleDealerApplicationUpdate(detail = {}) {
  const jobId = notificationJobId(detail);

  if (jobId) {
    const fallbackTitle = notificationJobTitle(detail);
    upsertDealerApplicationJob({
      id: jobId,
      title: fallbackTitle || `Run #${jobId}`,
      status: detail?.notification?.data?.job_status ?? detail?.job_status ?? 'open',
      pending_applications_count: 1,
      applications_count: 1
    });

    dealerStore.refreshApplicationJob(jobId)
      .then((job) => upsertDealerApplicationJob(job))
      .catch((error) => {
        console.warn('Failed to fetch applied run for dealer home', error);
      });
  }

  scheduleDealerApplicationRefresh();
}

function scheduleDealerApplicationRefresh() {
  if (typeof window === 'undefined') return;

  window.clearTimeout(dealerApplicationRefreshTimer);
  dealerApplicationRefreshTimer = window.setTimeout(() => {
    auth.fetchMe().catch((error) => {
      console.warn('Failed to refresh dealer application runs', error);
    });
  }, 150);
}

const jobsToDisplay = computed(() => jobs.value.slice(0, 3));

const openJobsEmptyText = computed(() => {
  if (auth.role === 'driver') {
    return 'No open runs right now. Check back later for new dealer runs.';
  }

  if (auth.role === 'dealer') {
    return 'No open runs yet. Create your first run to start receiving driver requests.';
  }

  return 'No open runs yet. Runs will appear here when dealers post them.';
});
</script>

<template>
  <div class="space-y-6">
    <section class="section-card overflow-hidden">
      <div class="space-y-6">
        <div class="space-y-5 sm:space-y-6">
          <div class="inline-flex max-w-full items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-[11px] font-black uppercase tracking-[0.14em] text-emerald-700 sm:text-xs sm:tracking-[0.18em]">
            {{ roleLabel }}
          </div>

          <div class="max-w-3xl space-y-4">
            <h1 class="text-3xl font-black tracking-tight text-slate-950 sm:text-5xl lg:text-6xl">
              {{ heroTitle }}
            </h1>
          </div>

          <div v-if="auth.role !== 'driver'" class="grid gap-3 sm:flex sm:flex-wrap">
            <RouterLink :to="primaryAction.to" class="btn-primary w-full sm:w-auto">
              {{ primaryAction.label }}
            </RouterLink>
          </div>
        </div>
      </div>
    </section>

    <aside v-if="auth.role === 'driver'" class="rounded-3xl bg-slate-950 p-4 text-white shadow-2xl shadow-slate-950/20 sm:p-5">
      <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-300">Driver workspace</p>
            <h2 class="mt-1 text-xl font-bold text-emerald-300">Your next runs</h2>
          </div>
          <span class="rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-slate-200">
            {{ loading ? 'Syncing' : `${driverUpcomingRuns.length} upcoming` }}
          </span>
        </div>

        <RouterLink
          v-if="driverCurrentJob"
          :to="`/jobs/${driverCurrentJob.id}`"
          class="block rounded-2xl border border-emerald-300/30 bg-emerald-400/10 p-4 transition hover:-translate-y-0.5 hover:bg-emerald-400/15"
        >
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="text-xs font-black uppercase tracking-[0.16em] text-emerald-300">Next run</p>
              <p class="mt-1 truncate text-lg font-black text-white">
                {{ driverCurrentJob.title || `Run #${driverCurrentJob.id}` }}
              </p>
              <p class="mt-1 truncate text-sm text-emerald-100">
                {{ driverCurrentJob.pickup_postcode || driverCurrentJob.pickup_label || 'Pickup' }}
                to
                {{ driverCurrentJob.dropoff_postcode || driverCurrentJob.dropoff_label || 'Drop-off' }}
              </p>
            </div>
            <span class="shrink-0 rounded-full bg-emerald-400 px-3 py-1 text-sm font-black text-slate-950 dark:text-slate-950">
              {{ priceFormatter.format(Number(driverCurrentJob.driver_payout_amount || driverCurrentJob.price || 0)) }}
            </span>
          </div>
        </RouterLink>

        <div v-if="driverUpcomingRuns.length > 1" class="space-y-2">
          <p class="text-xs font-black uppercase tracking-[0.16em] text-slate-400">Upcoming</p>
          <RouterLink
            v-for="job in driverUpcomingRuns.slice(1)"
            :key="job.id"
            :to="`/jobs/${job.id}`"
            class="flex items-center justify-between gap-3 rounded-2xl border border-white/10 bg-white/[0.06] px-3 py-2.5 transition hover:bg-white/[0.1]"
          >
            <div class="min-w-0">
              <p class="truncate text-sm font-black text-white">{{ job.title || `Run #${job.id}` }}</p>
              <p class="mt-0.5 truncate text-xs text-slate-300">
                {{ job.pickup_postcode || 'Pickup' }} to {{ job.dropoff_postcode || 'Drop-off' }}
              </p>
            </div>
            <span class="shrink-0 text-xs font-bold text-emerald-300">{{ formatSentenceStatus(job.status) }}</span>
          </RouterLink>
        </div>

        <div
          v-if="!loading && !driverUpcomingRuns.length"
          class="rounded-2xl border border-white/10 bg-white/[0.06] p-4 text-sm text-slate-300"
        >
          No upcoming runs right now.
        </div>

        <div class="grid grid-cols-2 gap-2">
          <div class="rounded-2xl border border-white/10 bg-white/[0.06] p-3">
            <p class="text-xs font-black uppercase tracking-wide text-slate-400">Pending</p>
            <p class="mt-1 text-xl font-black text-white">{{ driverPendingApplications.length }}</p>
          </div>
          <div class="rounded-2xl border border-white/10 bg-white/[0.06] p-3">
            <p class="text-xs font-black uppercase tracking-wide text-slate-400">Completed</p>
            <p class="mt-1 text-xl font-black text-white">{{ driverStats.completed_count ?? driverCompletedRuns.length }}</p>
          </div>
        </div>

        <div class="grid gap-2 sm:grid-cols-2">
          <RouterLink :to="primaryAction.to" class="rounded-xl bg-emerald-400 px-4 py-3 text-center text-sm font-bold text-slate-950 shadow-sm transition hover:-translate-y-0.5 hover:bg-emerald-300 dark:text-slate-950">
            {{ hasDriverCurrentJob ? 'Open next run' : 'Browse marketplace' }}
          </RouterLink>
          <RouterLink to="/driver" class="rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-center text-sm font-bold text-emerald-100 transition hover:bg-white/[0.1]">
            Driver page
          </RouterLink>
        </div>

      </div>
    </aside>

    <aside v-else class="rounded-3xl bg-slate-950 p-4 text-white shadow-2xl shadow-slate-950/20 sm:p-5">
      <div class="flex flex-col gap-3">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-300">
              {{ auth.role === 'dealer' ? 'Dealer workspace' : 'Live board' }}
            </p>
            <h2 class="mt-1 text-xl font-bold text-emerald-300">{{ liveBoardTitle }}</h2>
          </div>
          <span class="rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-slate-200">
            {{ liveBoardCountText }}
          </span>
        </div>

      </div>

      <div class="mt-5 space-y-3">
        <RouterLink
          v-for="job in liveBoardJobs"
          :key="job.id"
          :to="auth.role === 'dealer' ? `/jobs/${job.id}?section=applications` : `/jobs/${job.id}`"
          class="block rounded-2xl border border-white/10 bg-white/[0.06] p-3 transition hover:-translate-y-0.5 hover:bg-white/[0.1]"
        >
          <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
              <p class="truncate text-sm font-black text-white">
                {{ job.title || `Run #${job.id}` }}
              </p>
              <p class="mt-0.5 truncate text-xs text-slate-400">
                {{ job.pickup_postcode || 'Pickup' }} to {{ job.dropoff_postcode || 'Drop-off' }}
              </p>
            </div>
            <span class="shrink-0 rounded-full bg-emerald-400 px-3 py-1 text-xs font-black text-slate-950 dark:text-slate-950">
              {{ applicationCountLabel(job) }}
            </span>
          </div>
        </RouterLink>

        <div
          v-if="!loading && !liveBoardJobs.length"
          class="rounded-2xl border border-white/10 bg-white/[0.06] p-4 text-sm text-slate-300"
        >
          {{ liveBoardEmptyText }}
        </div>
      </div>
    </aside>

    <section v-if="quickLinks.length" class="grid gap-4 md:grid-cols-3">
      <RouterLink
        v-for="link in quickLinks"
        :key="link.title"
        :to="link.to"
        class="tile group flex min-h-[140px] flex-col justify-between p-4 transition hover:-translate-y-1 hover:shadow-2xl sm:min-h-[150px] sm:p-5"
      >
        <div>
          <h2 class="text-lg font-black text-slate-950">{{ link.title }}</h2>
          <p class="mt-2 text-sm leading-6 text-slate-600">{{ link.text }}</p>
        </div>
        <span class="mt-5 text-sm font-bold text-emerald-700 group-hover:text-emerald-800">
          Open workspace
        </span>
      </RouterLink>
    </section>

  </div>
</template>
