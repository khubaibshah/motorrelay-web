<script setup>
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import BackPillButton from '@/components/BackPillButton.vue';
import { fetchJob, fetchJobApplications, updateJobApplication } from '@/services/jobs';
import { formatStatusLabel } from '@/utils/statusLabels';

const route = useRoute();

const job = ref(null);
const applications = ref([]);
const loading = ref(false);
const errorMessage = ref('');
const actionLoadingId = ref(null);

const jobId = computed(() => route.params.id);

const pendingApplications = computed(() => applications.value.filter((application) => application.status === 'pending'));
const processedApplications = computed(() => applications.value.filter((application) => application.status !== 'pending'));

const routeLabel = computed(() => {
  if (!job.value) return '';
  const pickup = job.value.pickup_postcode || job.value.pickup_label || 'Pickup';
  const dropoff = job.value.dropoff_postcode || job.value.dropoff_label || 'Drop-off';
  return `${pickup} to ${dropoff}`;
});

const priceLabel = computed(() => {
  try {
    return new Intl.NumberFormat('en-GB', {
      style: 'currency',
      currency: 'GBP',
      maximumFractionDigits: 0
    }).format(Number(job.value?.price || 0));
  } catch {
    return `£${Number(job.value?.price || 0).toFixed(0)}`;
  }
});

function applicationBadgeClass(status) {
  const normalized = String(status || '').toLowerCase();

  if (normalized === 'accepted') return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-400 dark:text-slate-950';
  if (normalized === 'declined') return 'bg-slate-200 text-slate-700 dark:bg-white/10 dark:text-emerald-100';

  return 'bg-amber-100 text-amber-700 dark:bg-amber-300 dark:text-slate-950';
}

function formatAppliedAt(value) {
  if (!value) return 'Applied recently';

  try {
    return `Applied ${new Intl.DateTimeFormat('en-GB', {
      day: 'numeric',
      month: 'short',
      hour: '2-digit',
      minute: '2-digit'
    }).format(new Date(value))}`;
  } catch {
    return `Applied ${value}`;
  }
}

async function loadApplicationsPage() {
  loading.value = true;
  errorMessage.value = '';

  try {
    const [jobPayload, applicationsPayload] = await Promise.all([
      fetchJob(jobId.value),
      fetchJobApplications(jobId.value)
    ]);

    job.value = jobPayload?.data ?? jobPayload ?? null;
    applications.value = Array.isArray(applicationsPayload?.data) ? applicationsPayload.data : [];
  } catch (error) {
    console.error('Failed to load run applications', error);
    errorMessage.value = error?.response?.data?.message || 'Unable to load applications right now.';
  } finally {
    loading.value = false;
  }
}

async function decideApplication(application, status) {
  if (!application?.id || actionLoadingId.value) return;

  actionLoadingId.value = application.id;
  errorMessage.value = '';

  try {
    await updateJobApplication(jobId.value, application.id, { status });
    await loadApplicationsPage();
  } catch (error) {
    console.error('Failed to update application', error);
    errorMessage.value = error?.response?.data?.message || 'Unable to update this application.';
  } finally {
    actionLoadingId.value = null;
  }
}

onMounted(loadApplicationsPage);
</script>

<template>
  <div class="space-y-4">
    <BackPillButton label="Run details" :to="`/jobs/${jobId}`" />

    <section class="tile p-4">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
          <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-300">Driver applications</p>
          <h1 class="mt-1 text-2xl font-black text-slate-950 dark:text-white">{{ job?.title || `Run #${jobId}` }}</h1>
          <p class="mt-1 text-sm font-semibold text-slate-600 dark:text-emerald-100">{{ routeLabel }}</p>
        </div>
        <div class="text-right">
          <p class="text-2xl font-black text-emerald-600 dark:text-emerald-300">{{ priceLabel }}</p>
          <p class="mt-1 text-xs font-black uppercase tracking-wide text-slate-500 dark:text-emerald-100">
            {{ applications.length }} total
          </p>
        </div>
      </div>
    </section>

    <p v-if="errorMessage" class="rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm font-bold text-amber-700 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-100">
      {{ errorMessage }}
    </p>

    <section class="tile space-y-3 p-4">
      <div class="flex items-center justify-between gap-3">
        <div>
          <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-300">Pending</p>
          <h2 class="mt-0.5 text-lg font-black text-slate-950 dark:text-white">Choose a driver</h2>
        </div>
        <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-black text-emerald-700 dark:bg-emerald-400 dark:text-slate-950">
          {{ pendingApplications.length }} pending
        </span>
      </div>

      <div v-if="loading" class="rounded-2xl border bg-white p-4 text-sm text-slate-600 dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100">
        Loading applications...
      </div>

      <div v-else-if="!applications.length" class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-600 dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100">
        No drivers have applied for this run yet.
      </div>

      <div v-else-if="!pendingApplications.length" class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-600 dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100">
        No pending applications left.
      </div>

      <div v-else class="space-y-3">
        <article
          v-for="application in pendingApplications"
          :key="application.id"
          class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-white/[0.06]"
        >
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p class="text-base font-black text-slate-950 dark:text-white">{{ application.driver?.name || 'Driver' }}</p>
              <p class="text-xs font-semibold text-slate-500 dark:text-emerald-100">{{ formatAppliedAt(application.created_at) }}</p>
            </div>
            <span class="badge" :class="applicationBadgeClass(application.status)">
              {{ formatStatusLabel(application.status, 'Pending') }}
            </span>
          </div>

          <p v-if="application.message" class="mt-3 rounded-xl bg-slate-50 p-3 text-sm text-slate-600 dark:bg-white/[0.05] dark:text-emerald-100">
            {{ application.message }}
          </p>

          <div class="mt-3 grid grid-cols-2 gap-2">
            <button
              type="button"
              class="btn-secondary px-4 py-2 text-sm disabled:opacity-60"
              :disabled="actionLoadingId === application.id"
              @click="decideApplication(application, 'declined')"
            >
              Decline
            </button>
            <button
              type="button"
              class="btn-primary px-4 py-2 text-sm disabled:opacity-60"
              :disabled="actionLoadingId === application.id"
              @click="decideApplication(application, 'accepted')"
            >
              {{ actionLoadingId === application.id ? 'Updating...' : 'Accept' }}
            </button>
          </div>
        </article>
      </div>
    </section>

    <section v-if="processedApplications.length" class="tile space-y-3 p-4">
      <p class="text-xs font-black uppercase tracking-[0.18em] text-slate-500 dark:text-emerald-100">History</p>
      <article
        v-for="application in processedApplications"
        :key="`processed-${application.id}`"
        class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-3 dark:border-white/10 dark:bg-white/[0.06]"
      >
        <div>
          <p class="font-black text-slate-950 dark:text-white">{{ application.driver?.name || 'Driver' }}</p>
          <p class="text-xs font-semibold text-slate-500 dark:text-emerald-100">{{ formatAppliedAt(application.created_at) }}</p>
        </div>
        <span class="badge" :class="applicationBadgeClass(application.status)">
          {{ formatStatusLabel(application.status) }}
        </span>
      </article>
    </section>

    <RouterLink
      v-if="job"
      :to="`/jobs/${job.id}`"
      class="btn-secondary inline-flex w-full justify-center px-4 py-3 text-sm"
    >
      Back to run
    </RouterLink>
  </div>
</template>
