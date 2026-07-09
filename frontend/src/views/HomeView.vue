<script setup>
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { fetchJobHighlights } from '@/services/jobs';

const auth = useAuthStore();
const jobs = ref([]);
const loading = ref(false);

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
  } catch (error) {
    console.error('Failed to load highlight jobs', error);
    jobs.value = [];
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

const primaryAction = computed(() => {
  if (auth.role === 'driver') return { to: '/jobs', label: 'Browse jobs' };
  if (auth.role === 'dealer') return { to: '/jobs/new', label: 'Create job' };
  if (auth.role === 'admin') return { to: '/admin', label: 'Open admin' };
  return { to: '/login', label: 'Sign in' };
});

const secondaryAction = computed(() => {
  if (auth.role === 'driver') return { to: '/driver', label: 'Driver dashboard' };
  if (auth.role === 'dealer') return { to: '/jobs', label: 'View jobs' };
  if (auth.role === 'admin') return { to: '/jobs', label: 'Review jobs' };
  return { to: '/signup', label: 'Create account' };
});

const statCards = computed(() => {
  const assigned = Array.isArray(auth.assignedJobs) ? auth.assignedJobs.length : 0;
  const posted = Array.isArray(auth.postedJobs) ? auth.postedJobs.length : 0;
  const completed = Array.isArray(auth.completedJobs) ? auth.completedJobs.length : 0;
  const openMarketplaceJobs = postedJobs.value.filter((job) => {
    const status = String(job?.status ?? '').toLowerCase();
    return (status === 'open' || status === 'pending') && !job?.assigned_to_id;
  }).length;
  const paymentDueJobs = postedJobs.value.filter((job) => {
    return job?.assigned_to_id && !['paid', 'payout_released'].includes(String(job?.payment_status || 'unpaid'));
  }).length;
  const proofReviewJobs = postedJobs.value.filter((job) => {
    return String(job?.completion_status || '').toLowerCase() === 'submitted';
  }).length;
  const payoutReadyJobs = postedJobs.value.filter((job) => {
    return String(job?.payment_status || '').toLowerCase() === 'paid'
      && String(job?.completion_status || '').toLowerCase() === 'approved'
      && !job?.stripe_transfer_id;
  }).length;

  if (auth.role === 'driver') {
    return [
      { label: 'Assigned runs', value: assigned },
      { label: 'Completed', value: completed },
      { label: 'Plan', value: auth.planDisplayLabel || 'Driver' }
    ];
  }

  if (auth.role === 'dealer') {
    return [
      { label: 'Open jobs', value: openMarketplaceJobs },
      { label: 'Need payment', value: paymentDueJobs },
      { label: 'Proof review', value: proofReviewJobs },
      { label: 'Payout ready', value: payoutReadyJobs }
    ];
  }

  return [
    { label: 'Open marketplace', value: jobs.value.length },
    { label: 'Live tracking', value: 'Built in' },
    { label: 'Invoices', value: 'PDF ready' }
  ];
});

const quickLinks = computed(() => {
  if (auth.role === 'driver') {
    return [
      { to: '/jobs', title: 'Find a run', text: 'Apply for open vehicle movements near you.' },
      { to: '/messages', title: 'Messages', text: 'Keep dealers updated from one inbox.' },
      { to: '/profile', title: 'Scorecard', text: 'Review completed work and earnings.' }
    ];
  }

  if (auth.role === 'dealer') {
    return [];
  }

  if (auth.role === 'admin') {
    return [
      { to: '/admin', title: 'Overview', text: 'Monitor platform activity and health.' },
      { to: '/admin/applications', title: 'Applications', text: 'Review driver and dealer activity.' },
      { to: '/admin/system-health', title: 'System health', text: 'Spot stale jobs and conversations.' }
    ];
  }

  return [
    { to: '/signup', title: 'Drivers', text: 'Find work, submit proof, and manage invoices.' },
    { to: '/signup', title: 'Dealers', text: 'Post jobs and assign trusted drivers.' },
    { to: '/login', title: 'Operations', text: 'Track messages, expenses, and paperwork.' }
  ];
});

const statGridClass = computed(() => (auth.role === 'dealer' ? 'grid grid-cols-2 gap-2 sm:gap-3' : 'grid grid-cols-3 gap-2 sm:gap-3'));

const dealerJobsProgress = computed(() => {
  if (auth.role !== 'dealer') return [];

  const byId = new Map();
  [...postedJobs.value, ...Array.isArray(auth.completedJobs) ? auth.completedJobs : []].forEach((job) => {
    if (job?.id) byId.set(job.id, job);
  });

  return [...byId.values()].sort((a, b) => {
    const aTime = new Date(a?.updated_at ?? a?.created_at ?? 0).getTime();
    const bTime = new Date(b?.updated_at ?? b?.created_at ?? 0).getTime();
    return bTime - aTime;
  });
});

const jobsToDisplay = computed(() => jobs.value.slice(0, 3));

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
    return 'No open jobs right now. Check back later for new dealer jobs.';
  }

  if (auth.role === 'dealer') {
    return 'No open jobs yet. Create your first job to start receiving driver requests.';
  }

  return 'No open jobs yet. Jobs will appear here when dealers post them.';
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
              Move vehicles with less chasing.
            </h1>
            <p class="max-w-2xl text-sm leading-6 text-slate-600 sm:text-lg sm:leading-7">
              MotorRelay brings jobs, drivers, live tracking, expenses, messages, proof of delivery, and invoices into one clean workspace.
            </p>
          </div>

          <div class="grid gap-3 sm:flex sm:flex-wrap">
            <RouterLink :to="primaryAction.to" class="btn-primary w-full sm:w-auto">
              {{ primaryAction.label }}
            </RouterLink>
            <RouterLink :to="secondaryAction.to" class="btn-secondary w-full sm:w-auto">
              {{ secondaryAction.label }}
            </RouterLink>
          </div>

          <dl :class="statGridClass">
            <div
              v-for="stat in statCards"
              :key="stat.label"
              class="min-w-0 rounded-2xl border border-slate-200/80 bg-white/70 p-3 shadow-sm sm:p-4"
            >
              <dt class="text-[10px] font-bold uppercase tracking-wide text-slate-500 sm:text-xs">{{ stat.label }}</dt>
              <dd class="mt-2 break-words text-lg font-black text-slate-950 sm:text-2xl">{{ stat.value }}</dd>
            </div>
          </dl>
        </div>
      </div>
    </section>

    <aside class="rounded-3xl bg-slate-950 p-4 text-white shadow-2xl shadow-slate-950/20 sm:p-5">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-300">Live board</p>
          <h2 class="mt-1 text-xl font-bold">Open jobs</h2>
        </div>
        <span class="rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-slate-200">
          {{ loading ? 'Syncing' : `${jobsToDisplay.length} shown` }}
        </span>
      </div>

      <div class="mt-5 space-y-3">
        <RouterLink
          v-for="job in jobsToDisplay"
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
            <span class="w-fit rounded-full bg-emerald-400 px-3 py-1 text-sm font-black text-slate-950">
              {{ priceFormatter.format(Number(job.price || 0)) }}
            </span>
          </div>
        </RouterLink>

        <div
          v-if="!loading && !jobsToDisplay.length"
          class="rounded-2xl border border-white/10 bg-white/[0.06] p-4 text-sm text-slate-300"
        >
          {{ openJobsEmptyText }}
        </div>
      </div>
    </aside>

    <section v-if="auth.role === 'dealer'" class="section-card space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-700">Job progress</p>
          <h2 class="mt-1 text-xl font-black text-slate-950">All jobs in progress</h2>
          <p class="mt-1 text-sm text-slate-600">Scroll through your jobs, open any job, or jump to the full searchable table.</p>
        </div>
        <RouterLink to="/jobs" class="btn-secondary">
          View full list
        </RouterLink>
      </div>

      <div v-if="dealerJobsProgress.length" class="flex gap-3 overflow-x-auto pb-2">
        <RouterLink
          v-for="job in dealerJobsProgress"
          :key="`home-progress-${job.id}`"
          :to="`/jobs/${job.id}`"
          class="min-w-[18rem] max-w-[18rem] flex-shrink-0 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-200 hover:shadow-md"
        >
          <div class="space-y-3">
            <div class="min-w-0">
              <p class="truncate text-base font-black text-slate-950">{{ job.title || `Job #${job.id}` }}</p>
              <p class="mt-1 truncate text-sm text-slate-600">
                {{ job.pickup_postcode || '--' }} → {{ job.dropoff_postcode || '--' }}
              </p>
            </div>

            <div class="flex flex-wrap gap-2">
              <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700">
                Now: {{ job.status || 'Open' }}
              </span>
              <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-700">
                Payment: {{ paymentLabel(job) }}
              </span>
              <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-500 ring-1 ring-slate-200">
                {{ formatDate(job.updated_at || job.created_at) }}
              </span>
            </div>
          </div>
        </RouterLink>
      </div>

      <div v-else class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
        No jobs yet. Create your first job to start tracking progress.
      </div>
    </section>

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
