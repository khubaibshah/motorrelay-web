<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import BackPillButton from '@/components/BackPillButton.vue';
import { fetchJob, reportJobIncident } from '@/services/jobs';

const route = useRoute();
const router = useRouter();

const job = ref(null);
const loading = ref(false);
const errorMessage = ref('');
const submitting = ref(false);

const form = reactive({
  type: 'vehicle_breakdown',
  recovery_required: false,
  vehicle_safe: true,
  blocking_road: false,
  location_label: '',
  description: '',
  attachments: []
});

const jobId = computed(() => route.params.id);

async function loadJobForIssue() {
  loading.value = true;
  errorMessage.value = '';

  try {
    const payload = await fetchJob(jobId.value);
    job.value = payload?.data ?? payload ?? null;
  } catch (error) {
    console.error('Failed to load run for issue report', error);
    errorMessage.value = error?.response?.data?.message || 'Unable to load this run right now.';
  } finally {
    loading.value = false;
  }
}

async function submitIssue() {
  if (!job.value?.id || submitting.value) return;

  submitting.value = true;
  errorMessage.value = '';

  try {
    await reportJobIncident(job.value.id, {
      type: form.type,
      recovery_required: form.recovery_required,
      vehicle_safe: form.vehicle_safe,
      blocking_road: form.blocking_road,
      location_label: form.location_label,
      description: form.description,
      attachments: form.attachments
    });
    await router.push(`/jobs/${job.value.id}`);
  } catch (error) {
    console.error('Failed to report issue', error);
    errorMessage.value = error?.response?.data?.message || 'Unable to report this issue.';
  } finally {
    submitting.value = false;
  }
}

function handleAttachmentChange(event) {
  form.attachments = Array.from(event.target?.files ?? []);
  event.target.value = '';
}

onMounted(loadJobForIssue);
</script>

<template>
  <div class="space-y-3">
    <BackPillButton label="Run" :to="`/jobs/${jobId}`" />

    <section class="tile p-3">
      <p class="text-xs font-black uppercase tracking-[0.18em] text-amber-600">Report issue</p>
      <h1 class="mt-1 text-2xl font-black text-slate-950 dark:text-white">{{ job?.title || `Run #${jobId}` }}</h1>
      <p class="mt-1 text-sm font-semibold text-slate-600 dark:text-emerald-100">
        Tell the dealer what happened. Recovery requests are highlighted immediately.
      </p>
    </section>

    <p v-if="loading" class="tile p-4 text-sm text-slate-600 dark:text-emerald-100">Loading run...</p>

    <form v-else class="tile space-y-4 p-3" @submit.prevent="submitIssue">
      <label class="block">
        <span class="text-sm font-black text-slate-700 dark:text-emerald-100">Issue type</span>
        <select
          v-model="form.type"
          class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-950 dark:text-emerald-100"
        >
          <option value="vehicle_breakdown">Vehicle breakdown</option>
          <option value="accident">Accident</option>
          <option value="access_issue">Cannot access vehicle</option>
          <option value="dealer_unavailable">Dealer/customer unavailable</option>
          <option value="wrong_address">Wrong address</option>
          <option value="other">Other issue</option>
        </select>
      </label>

      <div class="grid gap-2">
        <label class="flex items-center gap-3 rounded-2xl border border-slate-200 p-3 text-sm font-black dark:border-white/10 dark:text-emerald-100">
          <input v-model="form.recovery_required" type="checkbox" class="h-4 w-4">
          Recovery needed
        </label>
        <label class="flex items-center gap-3 rounded-2xl border border-slate-200 p-3 text-sm font-black dark:border-white/10 dark:text-emerald-100">
          <input v-model="form.vehicle_safe" type="checkbox" class="h-4 w-4">
          Vehicle is safe
        </label>
        <label class="flex items-center gap-3 rounded-2xl border border-slate-200 p-3 text-sm font-black dark:border-white/10 dark:text-emerald-100">
          <input v-model="form.blocking_road" type="checkbox" class="h-4 w-4">
          Blocking the road
        </label>
      </div>

      <label class="block">
        <span class="text-sm font-black text-slate-700 dark:text-emerald-100">Location</span>
        <input
          v-model="form.location_label"
          type="text"
          placeholder="e.g. M65 J12 hard shoulder"
          class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-950 dark:text-emerald-100"
        >
      </label>

      <label class="block">
        <span class="text-sm font-black text-slate-700 dark:text-emerald-100">Details</span>
        <textarea
          v-model="form.description"
          rows="5"
          placeholder="Explain what happened and what help you need."
          class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-950 dark:text-emerald-100"
        />
      </label>

      <div class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-2 dark:border-white/10 dark:bg-white/[0.06]">
        <label class="inline-flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-2xl bg-white text-slate-700 shadow-sm ring-1 ring-slate-200 dark:bg-white/10 dark:text-emerald-100 dark:ring-white/10" aria-label="Attach issue images">
          <input type="file" accept="image/*" multiple class="hidden" @change="handleAttachmentChange">
          <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="m21.44 11.05-8.49 8.49a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.19 9.19a2 2 0 1 1-2.83-2.83l8.49-8.49" />
          </svg>
        </label>
        <div class="min-w-0 flex-1">
          <p class="text-sm font-black text-slate-900 dark:text-white">Attach images</p>
          <p class="truncate text-xs font-semibold text-slate-500 dark:text-emerald-100">
            <span v-if="form.attachments.length">{{ form.attachments.length }} image(s) ready</span>
            <span v-else>Optional photos of damage, location, or warning lights</span>
          </p>
        </div>
      </div>

      <p v-if="errorMessage" class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm font-bold text-amber-700">
        {{ errorMessage }}
      </p>

      <button type="submit" class="btn-primary w-full px-4 py-3 text-sm" :disabled="submitting">
        {{ submitting ? 'Reporting...' : 'Report issue' }}
      </button>
    </form>
  </div>
</template>
