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
  if (auth.role === 'dealer') return { to: '/dealer', label: 'Dealer dashboard' };
  if (auth.role === 'admin') return { to: '/jobs', label: 'Review jobs' };
  return { to: '/signup', label: 'Create account' };
});

const statCards = computed(() => {
  const assigned = Array.isArray(auth.assignedJobs) ? auth.assignedJobs.length : 0;
  const posted = Array.isArray(auth.postedJobs) ? auth.postedJobs.length : 0;
  const completed = Array.isArray(auth.completedJobs) ? auth.completedJobs.length : 0;

  if (auth.role === 'driver') {
    return [
      { label: 'Assigned runs', value: assigned },
      { label: 'Completed', value: completed },
      { label: 'Plan', value: auth.planDisplayLabel || 'Driver' }
    ];
  }

  if (auth.role === 'dealer') {
    return [
      { label: 'Posted jobs', value: posted },
      { label: 'Completed', value: completed },
      { label: 'Plan', value: auth.planDisplayLabel || 'Dealer' }
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

const onboardingSteps = computed(() => {
  if (auth.role === 'driver') {
    return [
      { title: '1. Find a job', text: 'Open the Jobs page and request a vehicle movement you can do.', to: '/jobs', action: 'Browse jobs' },
      { title: '2. Wait for assignment', text: 'The dealer reviews requests and chooses the driver.', to: '/driver', action: 'Check dashboard' },
      { title: '3. Complete the run', text: 'Deliver the vehicle, upload delivery proof, then track invoice status.', to: '/profile', action: 'View profile' }
    ];
  }

  if (auth.role === 'dealer') {
    return [
      { title: '1. Create a job', text: 'Add pickup, drop-off, vehicle details, and the price.', to: '/jobs/new', action: 'Create job' },
      { title: '2. Pick a driver', text: 'Review driver requests and assign the best person for the run.', to: '/jobs', action: 'Manage jobs' },
      { title: '3. Approve completion', text: 'Check delivery proof, approve the completed run, and download paperwork.', to: '/invoices', action: 'View invoices' }
    ];
  }

  if (auth.role === 'admin') {
    return [
      { title: '1. Review activity', text: 'Check platform jobs, users, and application status.', to: '/admin', action: 'Open admin' },
      { title: '2. Monitor jobs', text: 'Use the jobs board to spot stuck or stale runs.', to: '/jobs', action: 'Review jobs' },
      { title: '3. Check health', text: 'Use system health for operational issues.', to: '/admin/system-health', action: 'System health' }
    ];
  }

  return [
    { title: '1. Choose your role', text: 'Create a driver account to find work or a dealer account to post jobs.', to: '/signup', action: 'Create account' },
    { title: '2. Sign in', text: 'Use your account to access the correct dashboard.', to: '/login', action: 'Sign in' },
    { title: '3. Start moving vehicles', text: 'Drivers request jobs. Dealers assign drivers and approve completion.', to: '/jobs', action: 'View jobs' }
  ];
});

const standoutFeatures = [
  {
    title: 'Vetted driver network',
    text: 'Drivers submit identity and licence documents so dealers know who is moving their vehicles.'
  },
  {
    title: 'Proof-first completion',
    text: 'Every completed job needs delivery proof before approval and invoice download.'
  },
  {
    title: 'Operational control',
    text: 'Admin tools track users, jobs, conversations, billing signals, and account change requests.'
  }
];

const jobsToDisplay = computed(() => jobs.value.slice(0, 3));

const dealerJobsNeedingAttention = computed(() => {
  if (auth.role !== 'dealer') return [];

  const activeStatuses = new Set([
    'open',
    'pending',
    'in_progress',
    'accepted',
    'collected',
    'in_transit',
    'completion_pending',
    'delivered'
  ]);

  return postedJobs.value
    .filter((job) => activeStatuses.has(String(job?.status ?? '').toLowerCase()))
    .sort((a, b) => {
      const aDate = new Date(a?.pickup_at ?? a?.goes_live_at ?? a?.created_at ?? 0).getTime();
      const bDate = new Date(b?.pickup_at ?? b?.goes_live_at ?? b?.created_at ?? 0).getTime();
      return aDate - bDate;
    })
    .slice(0, 5);
});

const openJobsEmptyText = computed(() => {
  if (auth.role === 'driver') {
    return 'No open jobs right now. Check back later for new dealer jobs.';
  }

  if (auth.role === 'dealer') {
    return 'No open jobs yet. Create your first job to start receiving driver requests.';
  }

  return 'No open jobs yet. Jobs will appear here when dealers post them.';
});

function nextAction(job) {
  if (!job?.assigned_to_id) return 'Review requests';
  const paymentStatus = String(job?.payment_status || 'unpaid').toLowerCase();
  const completionStatus = String(job?.completion_status || 'not_submitted').toLowerCase();
  if (paymentStatus === 'unpaid') return 'Take payment';
  if (paymentStatus === 'checkout_pending') return 'Refresh payment';
  if (completionStatus === 'submitted') return 'Approve proof';
  if (paymentStatus === 'paid' && completionStatus === 'approved' && !job?.stripe_transfer_id) return 'Release payout';
  if (paymentStatus === 'payout_released') return 'Paid out';
  return 'Track job';
}

function formatRun(job) {
  const pickup = job?.pickup_label || job?.pickup_postcode || job?.pickup_city || 'Pickup';
  const dropoff = job?.dropoff_label || job?.dropoff_postcode || job?.dropoff_city || 'Drop-off';
  return `${pickup} to ${dropoff}`;
}

function formatDate(value) {
  if (!value) return '--';
  try {
    return new Intl.DateTimeFormat('en-GB', {
      weekday: 'short',
      day: 'numeric',
      month: 'short',
      hour: '2-digit',
      minute: '2-digit'
    }).format(new Date(value));
  } catch {
    return value;
  }
}
</script>

<template>
  <div class="space-y-6">
    <section class="section-card overflow-hidden">
      <div class="grid gap-6 lg:grid-cols-[1.25fr_0.75fr] lg:items-center lg:gap-8">
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

          <dl class="grid gap-3 sm:grid-cols-3">
            <div
              v-for="stat in statCards"
              :key="stat.label"
              class="rounded-2xl border border-slate-200/80 bg-white/70 p-4 shadow-sm"
            >
              <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ stat.label }}</dt>
              <dd class="mt-2 break-words text-xl font-black text-slate-950 sm:text-2xl">{{ stat.value }}</dd>
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

    <section class="section-card space-y-4">
      <div>
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-700">How to use this app</p>
        <h2 class="mt-1 text-xl font-black text-slate-950">Your next steps</h2>
      </div>

      <div class="grid gap-3 md:grid-cols-3">
        <RouterLink
          v-for="step in onboardingSteps"
          :key="step.title"
          :to="step.to"
          class="rounded-2xl border border-slate-200 bg-white/80 p-4 transition hover:-translate-y-0.5 hover:border-emerald-200 hover:shadow-lg"
        >
          <h3 class="font-black text-slate-950">{{ step.title }}</h3>
          <p class="mt-2 text-sm leading-6 text-slate-600">{{ step.text }}</p>
          <span class="mt-4 inline-flex text-sm font-bold text-emerald-700">{{ step.action }}</span>
        </RouterLink>
      </div>
    </section>

    <section class="section-card space-y-4">
      <div>
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-700">What makes this stand out</p>
        <h2 class="mt-1 text-xl font-black text-slate-950">Trust, proof, and payments in one workflow</h2>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
          The strongest version of MotorRelay is not just a job board. It should be the safe workflow dealers use to post work, choose vetted drivers, approve evidence, and release payment.
        </p>
      </div>

      <div class="grid gap-3 md:grid-cols-4">
        <article
          v-for="feature in standoutFeatures"
          :key="feature.title"
          class="rounded-2xl border border-slate-200 bg-white/80 p-4"
        >
          <h3 class="font-black text-slate-950">{{ feature.title }}</h3>
          <p class="mt-2 text-sm leading-6 text-slate-600">{{ feature.text }}</p>
        </article>
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

    <section v-if="auth.role === 'dealer'" class="section-card">
      <div class="flex items-center justify-between gap-3">
        <div>
          <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-700">Dealer operations</p>
          <h2 class="mt-1 text-xl font-black text-slate-950">Jobs needing attention</h2>
          <p class="mt-2 text-sm text-slate-600">
            Active dealer jobs with only the essential details shown.
          </p>
        </div>
        <RouterLink to="/jobs" class="text-sm font-bold text-emerald-700 hover:text-emerald-800">
          Manage jobs
        </RouterLink>
      </div>

      <div
        v-if="!dealerJobsNeedingAttention.length"
        class="mt-5 rounded-3xl border border-dashed border-slate-200 bg-slate-50 p-5 text-sm text-slate-600"
      >
        You have no active jobs right now.
      </div>

      <div v-else class="mt-5 space-y-3">
        <article
          v-for="job in dealerJobsNeedingAttention"
          :key="job.id"
          class="rounded-3xl border border-slate-200 bg-slate-50/80 p-4 transition hover:-translate-y-0.5 hover:bg-white hover:shadow-lg"
        >
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h3 class="text-base font-black text-slate-950">
                {{ job.title || `Job #${job.id}` }}
              </h3>
              <p class="text-sm text-slate-500">{{ formatRun(job) }}</p>
            </div>
            <span class="badge bg-slate-100 text-slate-700">
              {{ nextAction(job) }}
            </span>
          </div>
          <div class="mt-3 flex flex-wrap items-center gap-3 text-xs text-slate-500">
            <span>Driver: {{ job.assigned_to?.name || job.driver_name || 'Not assigned' }}</span>
            <span v-if="job.goes_live_at">Goes live {{ formatDate(job.goes_live_at) }}</span>
            <span v-else-if="job.pickup_at">Pickup {{ formatDate(job.pickup_at) }}</span>
            <span>Updated {{ formatDate(job.updated_at || job.created_at) }}</span>
          </div>
          <div class="mt-4 flex flex-wrap gap-2">
            <RouterLink :to="`/jobs/${job.id}`" class="btn-secondary px-3 py-2 text-xs">
              View job
            </RouterLink>
            <RouterLink :to="`/jobs/${job.id}/edit`" class="btn-secondary px-3 py-2 text-xs">
              Edit run
            </RouterLink>
          </div>
        </article>
      </div>
    </section>
  </div>
</template>
