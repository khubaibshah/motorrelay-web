<script setup>
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { fetchDriverOverview } from '@/services/jobs';
import { useAuthStore } from '@/stores/auth';
import { formatStatusLabel } from '@/utils/statusLabels';

const auth = useAuthStore();

const overview = ref(null);
const loading = ref(false);
const errorMessage = ref('');
const driverTab = ref('active');

const priceFormatter = new Intl.NumberFormat('en-GB', {
  style: 'currency',
  currency: 'GBP',
  maximumFractionDigits: 0
});

function formatPrice(value) {
  return priceFormatter.format(Number(value || 0));
}

function driverPayoutForJob(job) {
  const storedPayout = Number(job?.driver_payout_amount || 0);
  if (storedPayout > 0) return storedPayout;

  const price = Number(job?.price || 0);
  const storedFee = Number(job?.platform_fee_amount || 0);
  const platformFee = storedFee > 0 ? storedFee : Math.round(price * 0.1 * 100) / 100;

  return Math.max(price - platformFee, 0);
}

function formatDriverPayout(job) {
  return formatPrice(driverPayoutForJob(job));
}

function formatDate(value) {
  if (!value) return '--';
  try {
    return new Intl.DateTimeFormat('en-GB', {
      day: 'numeric',
      month: 'short',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    }).format(new Date(value));
  } catch {
    return value;
  }
}

async function loadOverview() {
  loading.value = true;
  errorMessage.value = '';
  try {
    overview.value = await fetchDriverOverview();
  } catch (error) {
    console.error('Failed to load driver overview', error);
    errorMessage.value = 'We could not load your dashboard. Please try again.';
    overview.value = null;
  } finally {
    loading.value = false;
  }
}

const stats = computed(() => overview.value?.stats ?? {});
const activeJobs = computed(() => overview.value?.active ?? []);
const currentJob = computed(() => activeJobs.value[0] ?? null);
const completedJobs = computed(() => overview.value?.completed ?? []);
const pendingApplications = computed(() => overview.value?.applications ?? []);
const dashboardTabs = computed(() => [
  { key: 'active', label: 'Active runs', count: activeJobs.value.length },
  { key: 'applications', label: 'Pending applications', count: pendingApplications.value.length },
  { key: 'completed', label: 'Recently completed', count: completedJobs.value.length }
]);
const selectedTabEmptyText = computed(() => {
  if (driverTab.value === 'applications') return 'You have no pending applications.';
  if (driverTab.value === 'completed') return 'No completed runs yet.';
  return 'You have no active runs right now.';
});

function jobStatusLabel(status) {
  return formatStatusLabel(status, 'In Progress');
}

function currentJobAction(job) {
  const status = String(job?.status || '').toLowerCase();
  const completionStatus = String(job?.completion_status || '').toLowerCase();

  if ((status === 'in_progress' || status === 'accepted' || status === 'pending') && !job?.delivery_proof_path) {
    return 'Open this run and upload inspection photos before collection.';
  }

  if (status === 'in_progress' || status === 'accepted' || status === 'pending') {
    return 'Open this run and mark the vehicle collected.';
  }

  if (status === 'collected' || status === 'in_transit') {
    return 'Open this run and mark the vehicle delivered.';
  }

  if (status === 'delivered' && completionStatus !== 'submitted') {
    return 'Open this run and submit completion.';
  }

  if (completionStatus === 'submitted' || status === 'completion_pending') {
    return 'Waiting for the dealer to approve your proof.';
  }

  return 'Open this run to see the next step.';
}

onMounted(async () => {
  if (!auth.user && auth.token) {
    await auth.fetchMe().catch(() => null);
  }
  await loadOverview();
});
</script>

<template>
  <div class="space-y-6">
    <div v-if="loading" class="rounded-2xl border bg-white p-4 text-sm text-slate-600 dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100">
      Loading your driver workspace...
    </div>

    <p v-else-if="errorMessage" class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-700 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-200">
      {{ errorMessage }}
    </p>

    <template v-else>
      <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm space-y-5 dark:border-white/10 dark:bg-slate-950">
        <header class="space-y-4">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-300">Driver workspace</p>
              <h2 class="mt-1 text-xl font-black text-slate-950 dark:text-emerald-300">Your runs</h2>
              <p class="mt-2 text-sm text-slate-600 dark:text-emerald-100">
                Everything you need to track work, applications, and completed deliveries.
              </p>
            </div>
            <div class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-left dark:border-white/10 dark:bg-white/[0.06]">
              <p class="text-xs font-black uppercase tracking-wide text-slate-500 dark:text-emerald-100">Total earnings</p>
              <p class="mt-1 text-xl font-black text-slate-950 dark:text-emerald-300">{{ formatPrice(stats.total_earnings ?? 0) }}</p>
            </div>
          </div>

          <nav class="grid grid-cols-3 gap-1 rounded-2xl bg-slate-100 p-1 dark:bg-white/[0.06]" aria-label="Driver run sections">
            <button
              v-for="tab in dashboardTabs"
              :key="tab.key"
              type="button"
              class="rounded-xl px-2 py-2.5 text-center text-xs font-bold transition sm:px-4 sm:text-sm"
              :class="driverTab === tab.key ? 'bg-slate-950 text-white shadow-sm dark:bg-emerald-400 dark:text-slate-950' : 'text-slate-600 hover:bg-white hover:text-slate-950 dark:text-emerald-100 dark:hover:bg-white/10 dark:hover:text-emerald-300'"
              @click="driverTab = tab.key"
            >
              {{ tab.label }}
              <span class="ml-1 opacity-80">({{ tab.count }})</span>
            </button>
          </nav>
        </header>

        <div v-if="driverTab === 'active'" class="space-y-3">
          <div v-if="!activeJobs.length" class="rounded-xl border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-600 dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100">
            {{ selectedTabEmptyText }}
          </div>
          <div class="space-y-3">
            <RouterLink
              v-for="job in activeJobs"
              :key="job.id"
              :to="`/jobs/${job.id}`"
              class="block rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-200 dark:border-white/10 dark:bg-white/[0.06] dark:hover:bg-white/[0.09]"
            >
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                  <p class="text-sm font-semibold text-slate-900 dark:text-white">
                    {{ job.title || `Run #${job.id}` }}
                  </p>
                  <p class="text-xs text-slate-500 dark:text-emerald-100">
                    {{ job.pickup_postcode || '--' }} → {{ job.dropoff_postcode || '--' }}
                  </p>
                  <p class="text-xs text-slate-500 dark:text-emerald-100">
                    Posted by {{ job.posted_by?.name || 'Dealer' }}
                  </p>
                </div>
                <div class="text-right">
                  <div class="text-lg font-bold text-emerald-600 dark:text-emerald-300">
                    {{ formatDriverPayout(job) }}
                  </div>
                  <span class="badge bg-emerald-100 text-emerald-700">{{ jobStatusLabel(job.status) }}</span>
                </div>
              </div>
              <p class="mt-3 rounded-xl bg-slate-50 p-3 text-xs font-semibold text-slate-600 dark:bg-white/10 dark:text-emerald-100">
                {{ currentJobAction(job) }}
              </p>
            </RouterLink>
          </div>
        </div>

        <div v-else-if="driverTab === 'applications'" class="space-y-3">
          <div v-if="!pendingApplications.length" class="rounded-xl border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-600 dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100">
            {{ selectedTabEmptyText }}
          </div>
          <RouterLink
            v-for="application in pendingApplications"
            :key="application.id"
            :to="`/jobs/${application.job?.id}`"
            class="block rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-200 dark:border-white/10 dark:bg-white/[0.06] dark:hover:bg-white/[0.09]"
          >
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="text-sm font-semibold text-slate-900 dark:text-white">
                  {{ application.job?.title || `Run #${application.job?.id}` }}
                </p>
                <p class="text-xs text-slate-500 dark:text-emerald-100">
                  Dealer: {{ application.job?.posted_by?.name || 'Dealer' }}
                </p>
                <p class="text-xs text-slate-500 dark:text-emerald-100">
                  Applied {{ formatDate(application.created_at) }}
                </p>
              </div>
              <div class="text-right">
                <div class="text-lg font-bold text-emerald-600 dark:text-emerald-300">
                  {{ formatDriverPayout(application.job) }}
                </div>
                <span class="badge bg-amber-100 text-amber-700">{{ formatStatusLabel(application.status, 'Pending') }}</span>
              </div>
            </div>
            <p v-if="application.message" class="mt-2 rounded-xl bg-slate-50 p-3 text-sm text-slate-600 dark:bg-white/10 dark:text-emerald-100">
              "{{ application.message }}"
            </p>
          </RouterLink>
        </div>

        <div v-else class="space-y-3">
          <div v-if="!completedJobs.length" class="rounded-xl border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-600 dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100">
            {{ selectedTabEmptyText }}
          </div>
          <RouterLink
            v-for="job in completedJobs.slice(0, 5)"
            :key="job.id"
            :to="`/jobs/${job.id}`"
            class="block rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-200 dark:border-white/10 dark:bg-white/[0.06] dark:hover:bg-white/[0.09]"
          >
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="text-sm font-semibold text-slate-900 dark:text-white">
                  {{ job.title || `Run #${job.id}` }}
                </p>
                <p class="text-xs text-slate-500 dark:text-emerald-100">
                  {{ job.pickup_postcode || '--' }} → {{ job.dropoff_postcode || '--' }}
                </p>
              </div>
              <div class="text-right">
                <div class="text-lg font-bold text-emerald-600 dark:text-emerald-300">
                  {{ formatDriverPayout(job) }}
                </div>
                <span class="badge bg-slate-200 text-slate-800 dark:bg-white/10 dark:text-emerald-100">{{ jobStatusLabel(job.status) }}</span>
              </div>
            </div>
            <p class="mt-2 text-xs text-slate-500 dark:text-emerald-100">
              Completed {{ formatDate(job.updated_at ?? job.created_at) }}
            </p>
          </RouterLink>
        </div>
      </section>
    </template>
  </div>
</template>
