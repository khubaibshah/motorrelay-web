<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import BackPillButton from '@/components/BackPillButton.vue';
import {
  approveJobInspection,
  downloadInspectionPhoto,
  fetchJob,
  requestJobInspectionChanges,
  uploadJobInspection
} from '@/services/jobs';
import { useAuthStore } from '@/stores/auth';

const route = useRoute();
const auth = useAuthStore();

const job = ref(null);
const loading = ref(false);
const errorMessage = ref('');
const photoPreviews = ref({});
const previewLoading = ref(false);
const selectedPhotoIndex = ref(0);
const actionLoading = ref('');

const form = reactive({
  notes: '',
  files: [],
  submitting: false,
  error: ''
});

const minimumPhotoCount = 6;
const requiredShots = ['Front', 'Rear', 'Left side', 'Right side', 'Interior', 'Mileage'];

const jobId = computed(() => route.params.id);
const photos = computed(() => {
  const payload = job.value?.inspection_photos ?? job.value?.inspectionPhotos ?? [];
  return Array.isArray(payload) ? payload : [];
});
const selectedPhoto = computed(() => photos.value[selectedPhotoIndex.value] ?? null);
const completionStatus = computed(() => job.value?.completion_status ?? 'not_submitted');
const isAssignedDriver = computed(() => Boolean(job.value && auth.user && job.value.assigned_to_id === auth.user.id));
const isDealerForJob = computed(() => Boolean(job.value && auth.user && job.value.posted_by_id === auth.user.id));
const canUploadPhotos = computed(() => {
  if (!isAssignedDriver.value) return false;
  if (job.value?.finalized_invoice_id) return false;
  return ['accepted', 'in_progress'].includes(String(job.value?.status || '').toLowerCase()) && completionStatus.value !== 'inspection_approved';
});
const canReviewPhotos = computed(() => {
  if (!isDealerForJob.value && auth.user?.role !== 'admin') return false;
  return photos.value.length > 0 && completionStatus.value !== 'inspection_approved';
});

function isImagePhoto(photo) {
  const mime = String(photo?.mime_type || '').toLowerCase();
  const name = String(photo?.original_name || photo?.path || '').toLowerCase();
  return mime.startsWith('image/') || /\.(jpg|jpeg|png|webp|gif|heic|heif)$/.test(name);
}

async function loadPhotoPreviews() {
  Object.values(photoPreviews.value).forEach((url) => URL.revokeObjectURL(url));
  photoPreviews.value = {};

  if (!job.value?.id || !photos.value.length) return;

  previewLoading.value = true;
  const previews = {};

  try {
    await Promise.all(
      photos.value
        .filter((photo) => photo?.id && isImagePhoto(photo))
        .map(async (photo) => {
          const response = await downloadInspectionPhoto(job.value.id, photo.id);
          const contentType = response.headers?.['content-type'] || photo.mime_type || 'image/jpeg';
          previews[photo.id] = URL.createObjectURL(new Blob([response.data], { type: contentType }));
        })
    );
    photoPreviews.value = previews;
  } catch (error) {
    console.error('Failed to load inspection photos', error);
  } finally {
    previewLoading.value = false;
  }
}

async function loadPage() {
  loading.value = true;
  errorMessage.value = '';

  try {
    const payload = await fetchJob(jobId.value);
    job.value = payload?.data ?? payload ?? null;
    selectedPhotoIndex.value = 0;
    await loadPhotoPreviews();
  } catch (error) {
    console.error('Failed to load run photos', error);
    errorMessage.value = error?.response?.data?.message || 'Unable to load photos right now.';
  } finally {
    loading.value = false;
  }
}

function handleFiles(event) {
  form.error = '';
  form.files = Array.from(event.target?.files ?? []);
  event.target.value = '';
}

async function submitPhotos() {
  if (!job.value?.id || form.submitting) return;
  if (form.files.length < minimumPhotoCount) {
    form.error = `Please upload at least ${minimumPhotoCount} photos: ${requiredShots.join(', ')}.`;
    return;
  }

  form.submitting = true;
  form.error = '';

  try {
    await uploadJobInspection(job.value.id, {
      notes: form.notes,
      proofs: form.files
    });
    form.notes = '';
    form.files = [];
    await loadPage();
  } catch (error) {
    console.error('Failed to upload inspection photos', error);
    form.error = error?.response?.data?.message || 'Unable to upload photos.';
  } finally {
    form.submitting = false;
  }
}

async function approvePhotos() {
  if (!job.value?.id || actionLoading.value) return;
  actionLoading.value = 'approve';

  try {
    await approveJobInspection(job.value.id);
    await loadPage();
  } catch (error) {
    console.error('Failed to approve photos', error);
    errorMessage.value = error?.response?.data?.message || 'Unable to approve photos.';
  } finally {
    actionLoading.value = '';
  }
}

async function requestChanges() {
  if (!job.value?.id || actionLoading.value) return;
  actionLoading.value = 'changes';

  try {
    await requestJobInspectionChanges(job.value.id, { note: 'Please upload clearer inspection photos.' });
    await loadPage();
  } catch (error) {
    console.error('Failed to request photo changes', error);
    errorMessage.value = error?.response?.data?.message || 'Unable to request changes.';
  } finally {
    actionLoading.value = '';
  }
}

onMounted(loadPage);
</script>

<template>
  <div class="space-y-3">
    <BackPillButton label="Run" :to="`/jobs/${jobId}`" />

    <section class="tile p-3">
      <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-300">Inspection photos</p>
      <div class="mt-1 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 class="text-2xl font-black text-slate-950 dark:text-white">{{ job?.title || `Run #${jobId}` }}</h1>
          <p class="text-sm font-semibold text-slate-600 dark:text-emerald-100">{{ photos.length }} files uploaded</p>
        </div>
        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-black text-slate-700 dark:bg-white/10 dark:text-emerald-100">
          {{ completionStatus.replaceAll('_', ' ') }}
        </span>
      </div>
    </section>

    <p v-if="loading" class="tile p-4 text-sm text-slate-600 dark:text-emerald-100">Loading photos...</p>
    <p v-else-if="errorMessage" class="rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm font-bold text-amber-700">{{ errorMessage }}</p>

    <section v-if="canUploadPhotos" class="tile space-y-3 p-3">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
          <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-300">Upload</p>
          <h2 class="text-lg font-black text-slate-950 dark:text-white">Pre-inspection set</h2>
        </div>
        <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-black text-emerald-700 dark:bg-emerald-400 dark:text-slate-950">
          {{ form.files.length }}/{{ minimumPhotoCount }}
        </span>
      </div>

      <div class="grid grid-cols-3 gap-2">
        <span
          v-for="(shot, index) in requiredShots"
          :key="shot"
          class="rounded-xl px-2 py-2 text-xs font-black"
          :class="form.files.length > index ? 'bg-emerald-600 text-white dark:bg-emerald-400 dark:text-slate-950' : 'bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-emerald-100'"
        >
          {{ shot }}
        </span>
      </div>

      <input type="file" accept="image/*" multiple class="w-full text-sm" @change="handleFiles">
      <textarea
        v-model="form.notes"
        rows="2"
        placeholder="Notes about condition, damage, mileage..."
        class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-950 dark:text-emerald-100"
      />
      <p v-if="form.error" class="text-sm font-bold text-amber-600">{{ form.error }}</p>
      <button type="button" class="btn-primary w-full px-4 py-3 text-sm" :disabled="form.submitting" @click="submitPhotos">
        {{ form.submitting ? 'Uploading...' : 'Upload photos' }}
      </button>
    </section>

    <section v-if="photos.length" class="tile space-y-3 p-3">
      <div class="flex items-center justify-between gap-3">
        <div>
          <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-300">Gallery</p>
          <h2 class="text-lg font-black text-slate-950 dark:text-white">Review photos</h2>
        </div>
        <span class="text-xs font-black text-slate-500 dark:text-emerald-100">{{ selectedPhotoIndex + 1 }} / {{ photos.length }}</span>
      </div>

      <div class="overflow-hidden rounded-3xl border border-slate-200 bg-slate-100 dark:border-white/10 dark:bg-white/[0.06]">
        <img
          v-if="selectedPhoto && photoPreviews[selectedPhoto.id]"
          :src="photoPreviews[selectedPhoto.id]"
          :alt="selectedPhoto.original_name || 'Inspection photo'"
          class="h-80 w-full object-cover"
        >
        <div v-else class="flex h-80 items-center justify-center p-6 text-center text-sm font-bold text-slate-500 dark:text-emerald-100">
          {{ previewLoading ? 'Loading preview...' : 'Preview unavailable' }}
        </div>
      </div>

      <div class="flex gap-2 overflow-x-auto pb-1">
        <button
          v-for="(photo, index) in photos"
          :key="photo.id || index"
          type="button"
          class="h-16 w-16 shrink-0 overflow-hidden rounded-2xl border"
          :class="selectedPhotoIndex === index ? 'border-emerald-500 ring-2 ring-emerald-200' : 'border-slate-200 dark:border-white/10'"
          @click="selectedPhotoIndex = index"
        >
          <img v-if="photoPreviews[photo.id]" :src="photoPreviews[photo.id]" class="h-full w-full object-cover" alt="">
          <span v-else class="flex h-full w-full items-center justify-center bg-slate-100 text-xs dark:bg-white/10">File</span>
        </button>
      </div>

      <div v-if="canReviewPhotos" class="grid grid-cols-2 gap-2">
        <button type="button" class="btn-secondary px-4 py-2 text-sm" :disabled="Boolean(actionLoading)" @click="requestChanges">
          {{ actionLoading === 'changes' ? 'Sending...' : 'Request changes' }}
        </button>
        <button type="button" class="btn-primary px-4 py-2 text-sm" :disabled="Boolean(actionLoading)" @click="approvePhotos">
          {{ actionLoading === 'approve' ? 'Approving...' : 'Approve photos' }}
        </button>
      </div>
    </section>

    <section v-else-if="!loading" class="tile border-dashed p-4 text-sm font-semibold text-slate-600 dark:text-emerald-100">
      No inspection photos have been uploaded yet.
    </section>
  </div>
</template>
