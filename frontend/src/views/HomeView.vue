<script setup>
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { fetchJobHighlights, fetchJobs } from '@/services/jobs';

const auth = useAuthStore();
const router = useRouter();
const jobs = ref([]);
const driverActiveJobs = ref([]);
const loading = ref(false);
const driverSearchPostcode = ref('');
const driverSearchTransport = ref('all');

const priceFormatter = new Intl.NumberFormat('en-GB', {
  style: 'currency',
  currency: 'GBP',
  maximumFractionDigits: 0
});

onMounted(async () => {
  if (!auth.user && auth.token) {
    await auth.fetchMe().catch(() => null);
  }

  loading.value = true;
  try {
    const payload = await fetchJobHighlights();
    jobs.value = Array.isArray(payload?.jobs) ? payload.jobs : [];

    if (auth.role === 'driver') {
      const activePayload = await fetchJobs({ scope: 'current' });
      const activeList = Array.isArray(activePayload?.data)
        ? activePayload.data
        : Array.isArray(activePayload?.jobs)
          ? activePayload.jobs
          : [];
      driverActiveJobs.value = activeList;
    }
  } catch (error) {
    console.error('Failed to load highlight jobs', error);
    jobs.value = [];
    driverActiveJobs.value = [];
  } finally {
    loading.value = false;
  }
});

const roleLabel = computed(() => {
  if (auth.role === 'driver') return 'Driver workspace';
  if (auth.role === 'dealer') return 'Dealer workspace';
  if (auth.role === 'admin') return 'Admin workspace';
  return 'Vehicle logistics';
});

const postedJobs = computed(() => {
  const list = Array.isArray(auth.postedJobs) ? auth.postedJobs : auth.jobs?.posted;
  return Array.isArray(list) ? [...list] : [];
});

const completedJobs = computed(() => {
  const list = Array.isArray(auth.completedJobs) ? auth.completedJobs : auth.jobs?.completed;
  return Array.isArray(list) ? [...list] : [];
});

const openStatuses = ['open', 'pending'];
const activeStatuses = ['accepted', 'in_progress', 'collected', 'in_transit', 'completion_pending', 'delivered'];
const sortByRecentActivity = (items) =>
  [...items].sort((a, b) => {
    const aTime = new Date(a?.updated_at ?? a?.created_at ?? 0).getTime();
    const bTime = new Date(b?.updated_at ?? b?.created_at ?? 0).getTime();
    return bTime - aTime;
  });

const dealerOpenJobs = computed(() =>
  sortByRecentActivity(postedJobs.value.filter((job) => openStatuses.includes(String(job?.status || '').toLowerCase())))
);
const dealerActiveJobs = computed(() =>
  sortByRecentActivity(postedJobs.value.filter((job) => activeStatuses.includes(String(job?.status || '').toLowerCase())))
);
const dealerCompletedJobs = computed(() => sortByRecentActivity(completedJobs.value));
const driverCurrentJob = computed(() => sortByRecentActivity(driverActiveJobs.value)[0] || null);
const hasDriverCurrentJob = computed(() => Boolean(driverCurrentJob.value));

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

const liveBoardMode = ref('open');
const liveBoardJobs = computed(() => {
  if (auth.role === 'dealer') {
    if (liveBoardMode.value === 'active') return dealerActiveJobs.value;
    if (liveBoardMode.value === 'completed') return dealerCompletedJobs.value;
    return dealerOpenJobs.value;
  }

  return jobsToDisplay.value;
});
const liveBoardTitle = computed(() => {
  if (auth.role === 'dealer') return 'Your runs';
  return 'Open runs';
});
const liveBoardEmptyText = computed(() => {
  if (auth.role === 'dealer') {
    if (liveBoardMode.value === 'active') return 'No active runs yet.';
    if (liveBoardMode.value === 'completed') return 'No completed runs yet.';
    return 'No open runs yet. Create your first run to start receiving driver requests.';
  }

  return openJobsEmptyText.value;
});
const liveBoardCountText = computed(() => {
  if (loading.value) return 'Syncing';
  return `${liveBoardJobs.value.length} shown`;
});

const jobsToDisplay = computed(() => jobs.value.slice(0, 3));

function driverNextAction(job) {
  const status = String(job?.status || '').toLowerCase();
  if (['accepted', 'in_progress'].includes(status)) return 'Collect vehicle';
  if (['collected', 'in_transit'].includes(status)) return 'Continue delivery';
  if (['completion_pending', 'delivered'].includes(status)) return 'Await dealer approval';
  return 'Open run';
}

function submitDriverJobSearch() {
  router.push({
    name: 'jobs',
    query: {
      ...(driverSearchPostcode.value.trim() ? { search: driverSearchPostcode.value.trim() } : {}),
      ...(driverSearchTransport.value !== 'all' ? { transport_type: driverSearchTransport.value } : {}),
      tab: 'available'
    }
  });
}

function formatDate(value) {
  if (!value) return '--';

  try {
    return new Intl.DateTimeFormat('en-GB', {
      day: 'numeric',
      month: 'short'
    }).format(new Date(value));
  } catch {
    return value;
  }
}

function paymentLabel(job) {
  const status = String(job?.payment_status || 'unpaid').replace(/_/g, ' ');
  return status.charAt(0).toUpperCase() + status.slice(1);
}

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
          <div class="inline-flex max-w-full items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.14em] text-emerald-700 sm:text-xs sm:tracking-[0.18em]">
            {{ roleLabel }}
          </div>

          <div class="max-w-3xl space-y-4">
            <h1 class="text-3xl font-black tracking-tight text-slate-950 sm:text-5xl lg:text-6xl">
              {{ heroTitle }}
            </h1>
            <p v-if="auth.role !== 'driver'" class="max-w-2xl text-sm leading-6 text-slate-600 sm:text-lg sm:leading-7">
              {{ heroText }}
            </p>
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
      <div class="flex flex-col gap-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-300">Driver workspace</p>
            <h2 class="mt-1 text-xl font-bold text-emerald-300">
              {{ hasDriverCurrentJob ? 'Current run' : 'Run opportunities' }}
            </h2>
          </div>
          <span class="rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-slate-200">
            {{ loading ? 'Syncing' : hasDriverCurrentJob ? '1 active' : 'Search runs' }}
          </span>
        </div>

        <form class="rounded-2xl border border-white/10 bg-white/[0.06] p-4" @submit.prevent="submitDriverJobSearch">
          <p class="text-sm font-bold text-white">Find a run</p>
          <div class="mt-3 grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,0.8fr)]">
            <label class="block min-w-0">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-400">Postcode or route</span>
              <input
                v-model="driverSearchPostcode"
                type="search"
                placeholder="e.g. M1, LS1, London"
                class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950 px-4 py-3 text-sm text-emerald-100 outline-none transition placeholder:text-emerald-100/40 focus:border-emerald-400 focus:ring-2 focus:ring-emerald-400/20"
              >
            </label>
            <label class="block min-w-0">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-400">Transport</span>
              <select
                v-model="driverSearchTransport"
                class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950 px-4 py-3 text-sm text-emerald-100 outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-400/20"
              >
                <option value="all">Any transport</option>
                <option value="drive_away">Drive-away</option>
                <option value="trailer">Trailer</option>
              </select>
            </label>
          </div>
          <button type="submit" class="mt-3 w-full rounded-xl bg-emerald-400 px-4 py-3 text-sm font-bold text-slate-950 shadow-sm transition hover:-translate-y-0.5 hover:bg-emerald-300 dark:text-slate-950">
            Search available runs
          </button>
        </form>

        <RouterLink
          v-if="driverCurrentJob"
          :to="`/jobs/${driverCurrentJob.id}`"
          class="block rounded-2xl border border-emerald-400/30 bg-emerald-400/10 p-4 transition hover:-translate-y-0.5 hover:bg-emerald-400/15"
        >
          <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
              <p class="font-semibold text-white">
                {{ driverCurrentJob.pickup_label || driverCurrentJob.pickup_postcode || 'Pickup' }}
                <span class="text-emerald-200">to</span>
                {{ driverCurrentJob.dropoff_label || driverCurrentJob.dropoff_postcode || 'Drop-off' }}
              </p>
              <p class="mt-1 text-xs text-emerald-100">{{ driverNextAction(driverCurrentJob) }}</p>
            </div>
            <span class="w-fit rounded-full bg-emerald-400 px-3 py-1 text-sm font-black text-slate-950 dark:text-slate-950">
              Open run
            </span>
          </div>
        </RouterLink>

        <RouterLink v-if="hasDriverCurrentJob" to="/jobs" class="btn-primary w-full">
          Open all runs
        </RouterLink>
      </div>
    </aside>

    <aside v-else class="rounded-3xl bg-slate-950 p-4 text-white shadow-2xl shadow-slate-950/20 sm:p-5">
      <div class="flex flex-col gap-3">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-300">
              {{ auth.role === 'dealer' ? 'Dealer workspace' : 'Live board' }}
            </p>
            <h2 class="mt-1 text-xl font-bold text-emerald-300">{{ liveBoardTitle }}</h2>
          </div>
          <span class="rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-slate-200">
            {{ liveBoardCountText }}
          </span>
        </div>

        <div class="flex flex-wrap gap-2">
          <button
            type="button"
            class="rounded-full px-3 py-1.5 text-xs font-bold transition"
            :class="liveBoardMode === 'open' ? 'bg-emerald-400 text-slate-950 dark:text-slate-950' : 'bg-white/10 text-slate-200 hover:bg-white/15'"
            @click="liveBoardMode = 'open'"
          >
            {{ auth.role === 'dealer' ? 'Open' : 'Open runs' }}
          </button>
          <button
            v-if="auth.role === 'dealer'"
            type="button"
            class="rounded-full px-3 py-1.5 text-xs font-bold transition"
            :class="liveBoardMode === 'active' ? 'bg-emerald-400 text-slate-950 dark:text-slate-950' : 'bg-white/10 text-slate-200 hover:bg-white/15'"
            @click="liveBoardMode = 'active'"
          >
            Active
          </button>
          <button
            v-if="auth.role === 'dealer'"
            type="button"
            class="rounded-full px-3 py-1.5 text-xs font-bold transition"
            :class="liveBoardMode === 'completed' ? 'bg-emerald-400 text-slate-950 dark:text-slate-950' : 'bg-white/10 text-slate-200 hover:bg-white/15'"
            @click="liveBoardMode = 'completed'"
          >
            Completed
          </button>
        </div>
      </div>

      <div class="mt-5 space-y-3">
        <RouterLink
          v-for="job in liveBoardJobs"
          :key="job.id"
          :to="`/jobs/${job.id}`"
          class="block rounded-2xl border border-white/10 bg-white/[0.06] p-4 transition hover:-translate-y-0.5 hover:bg-white/[0.1]"
        >
          <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
              <p class="font-semibold text-white">
                {{ job.pickup_label || job.pickup_postcode || 'Pickup' }}
                <span class="text-slate-500">to</span>
                {{ job.dropoff_label || job.dropoff_postcode || 'Drop-off' }}
              </p>
              <p class="mt-1 text-xs text-slate-400">{{ job.status || 'open' }}</p>
            </div>
            <span class="w-fit rounded-full bg-emerald-400 px-3 py-1 text-sm font-black text-slate-950 dark:text-slate-950">
              {{ priceFormatter.format(Number(job.price || 0)) }}
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
