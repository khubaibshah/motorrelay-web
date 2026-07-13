<script setup>
import { computed, onMounted } from 'vue';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();

onMounted(() => {
  if (!auth.user && auth.token) {
    auth.fetchMe().catch(() => null);
  }
});

const postedJobs = computed(() => {
  const list = Array.isArray(auth.postedJobs) ? auth.postedJobs : auth.jobs?.posted;
  return Array.isArray(list) ? [...list] : [];
});

const openJobs = computed(() =>
  postedJobs.value.filter((job) => {
    const status = String(job?.status ?? '').toLowerCase();
    return (status === 'open' || status === 'pending') && !job?.assigned_to_id;
  })
);

const paymentDueJobs = computed(() =>
  postedJobs.value.filter((job) => {
    return job?.assigned_to_id && !['paid', 'payout_released'].includes(String(job?.payment_status || 'unpaid'));
  })
);

const proofReviewJobs = computed(() =>
  postedJobs.value.filter((job) => {
    return String(job?.completion_status || '').toLowerCase() === 'submitted';
  })
);

const payoutReadyJobs = computed(() =>
  postedJobs.value.filter((job) => {
    return String(job?.payment_status || '').toLowerCase() === 'paid'
      && String(job?.completion_status || '').toLowerCase() === 'approved'
      && !job?.stripe_transfer_id;
  })
);

const completedJobs = computed(() => {
  const list = Array.isArray(auth.completedJobs) ? auth.completedJobs : auth.jobs?.completed;
  return Array.isArray(list) ? list : [];
});

const planLabel = computed(() => {
  return auth.planDisplayLabel || 'MotorRelay plan';
});

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
  <div class="mx-auto w-full max-w-6xl space-y-6">
      <section class="section-card">
        <header class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-700">Dealer workspace</p>
            <h1 class="mt-2 text-3xl font-black tracking-tight text-slate-950">Dealer dashboard</h1>
            <p class="mt-1 text-sm text-slate-600">
              Overview of your MotorRelay runs, driver activity, and paperwork.
            </p>
          </div>
          <div class="flex flex-wrap items-center gap-2 text-sm text-slate-600">
            <span class="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 font-bold text-emerald-700">
              {{ planLabel }}
            </span>
            <span class="rounded-full border border-slate-200 bg-white/70 px-3 py-1 font-semibold">
              {{ postedJobs.length }} posted
            </span>
            <span class="rounded-full border border-slate-200 bg-white/70 px-3 py-1 font-semibold">
              {{ completedJobs.length }} completed
            </span>
          </div>
        </header>

        <div class="mt-6 grid gap-4 sm:grid-cols-2">
          <div class="rounded-3xl border border-emerald-100 bg-emerald-50 p-5">
            <h2 class="text-xs font-bold uppercase tracking-wide text-emerald-700">Open marketplace runs</h2>
            <p class="mt-3 text-4xl font-black text-emerald-950">{{ openJobs.length }}</p>
            <p class="mt-1 text-sm text-emerald-800">Runs still waiting for driver applications.</p>
          </div>
          <div class="rounded-3xl border border-sky-100 bg-sky-50 p-5">
            <h2 class="text-xs font-bold uppercase tracking-wide text-sky-700">Need payment</h2>
            <p class="mt-3 text-4xl font-black text-sky-950">{{ paymentDueJobs.length }}</p>
            <p class="mt-1 text-sm text-sky-800">Assigned runs waiting for dealer payment.</p>
          </div>
          <div class="rounded-3xl border border-amber-100 bg-amber-50 p-5">
            <h2 class="text-xs font-bold uppercase tracking-wide text-amber-700">Proof to review</h2>
            <p class="mt-3 text-4xl font-black text-amber-950">{{ proofReviewJobs.length }}</p>
            <p class="mt-1 text-sm text-amber-800">Drivers have uploaded pre-delivery inspection photos.</p>
          </div>
          <div class="rounded-3xl border border-violet-100 bg-violet-50 p-5">
            <h2 class="text-xs font-bold uppercase tracking-wide text-violet-700">Payout ready</h2>
            <p class="mt-3 text-4xl font-black text-violet-950">{{ payoutReadyJobs.length }}</p>
            <p class="mt-1 text-sm text-violet-800">Approved runs ready to pay drivers.</p>
          </div>
        </div>
      </section>
  </div>
</template>
