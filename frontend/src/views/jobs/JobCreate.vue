<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '@/services/api';
import { createJobCheckout } from '@/services/payments';
import { useAuthStore } from '@/stores/auth';
import { useJobCreateDraftStore } from '@/stores/jobCreateDraft';
import JobCreateVehicleStep from '@/components/jobs/JobCreateVehicleStep.vue';
import JobCreateRouteStep from '@/components/jobs/JobCreateRouteStep.vue';
import JobCreateMovementStep from '@/components/jobs/JobCreateMovementStep.vue';
import JobCreatePaymentStep from '@/components/jobs/JobCreatePaymentStep.vue';
import JobCreateReviewStep from '@/components/jobs/JobCreateReviewStep.vue';

const props = defineProps({
  id: {
    type: [String, Number],
    default: null
  }
});

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const jobDraft = useJobCreateDraftStore();
const form = jobDraft.form;

const submitting = ref(false);
const errorMessage = ref('');
const validationMessage = ref('');
const loading = ref(false);
const loadError = ref('');
const vehicleLookupLoading = ref(false);
const vehicleLookupError = ref('');
const verifiedVehicle = computed({
  get: () => jobDraft.verifiedVehicle,
  set: (value) => {
    jobDraft.verifiedVehicle = value;
  }
});
const currentStep = computed({
  get: () => jobDraft.currentStep,
  set: (value) => {
    jobDraft.setStep(value);
  }
});
const validationState = reactive({
  vehicle: false,
  pickup_postcode: false,
  pickup_label: false,
  dropoff_postcode: false,
  dropoff_label: false,
  transport_type: false,
  pickup_at: false,
  delivery_at: false,
  price: false,
});
const addressLookup = reactive({
  pickup: {
    loading: false,
    error: '',
    postcode: '',
    addresses: [],
    selected: null
  },
  dropoff: {
    loading: false,
    error: '',
    postcode: '',
    addresses: [],
    selected: null
  }
});

const transportOptions = [
  {
    value: 'drive_away',
    label: 'Drive-away',
    helper: 'The driver will drive the vehicle from pickup to drop-off.'
  },
  {
    value: 'trailer',
    label: 'Trailer',
    helper: 'The vehicle should be moved on a trailer or transporter.'
  }
];

const wizardStepKeys = ['vehicle', 'route', 'movement', 'payment', 'review'];

function normaliseStepKey(value) {
  return (value || '').toString().trim().toLowerCase();
}

function stepIndexFromKey(value) {
  const stepKey = normaliseStepKey(value);
  const index = wizardStepKeys.indexOf(stepKey);
  return index >= 0 ? index : 0;
}

function clearValidationState() {
  Object.keys(validationState).forEach((key) => {
    validationState[key] = false;
  });
  validationMessage.value = '';
}

function setValidationError(key, message) {
  if (Object.prototype.hasOwnProperty.call(validationState, key)) {
    validationState[key] = true;
  }
  if (message && !validationMessage.value) {
    validationMessage.value = message;
  }
}

function hasValidationError(key) {
  return Boolean(validationState[key]);
}

function syncWizardStepQuery(stepIndex, mode = 'push') {
  const stepKey = wizardStepKeys[stepIndex] ?? wizardStepKeys[0];
  if (normaliseStepKey(route.query.step) === stepKey) {
    return;
  }

  const navigation = mode === 'replace' ? router.replace : router.push;
  navigation({
    query: {
      ...route.query,
      step: stepKey
    }
  }).catch(() => null);
}

const wizardSteps = [
  { key: 'vehicle', label: 'Vehicle' },
  { key: 'route', label: 'Route' },
  { key: 'movement', label: 'Movement' },
  { key: 'payment', label: 'Payment' },
  { key: 'review', label: 'Review' }
];

const currentWizardStep = computed(() => wizardSteps[currentStep.value] ?? wizardSteps[0]);
const wizardProgress = computed(() => ((currentStep.value + 1) / wizardSteps.length) * 100);
const isFirstStep = computed(() => currentStep.value === 0);
const isLastStep = computed(() => currentStep.value === wizardSteps.length - 1);
const bannerMessage = computed(() => validationMessage.value || errorMessage.value || vehicleLookupError.value);

const jobId = computed(() => {
  if (props.id === null || props.id === undefined || props.id === '') {
    return null;
  }
  return String(props.id);
});

const isEdit = computed(() => Boolean(jobId.value));

const jobPrice = computed(() => Number(normalisePrice(form.price) || 0));
const platformCommissionRate = 0.1;
const estimatedPlatformFee = computed(() => Math.max(jobPrice.value * platformCommissionRate, 0));
const estimatedDriverPayout = computed(() => Math.max(jobPrice.value - estimatedPlatformFee.value, 0));
const moneyFormatter = new Intl.NumberFormat('en-GB', {
  style: 'currency',
  currency: 'GBP',
  maximumFractionDigits: 2
});
function formatMoney(value) {
  return moneyFormatter.format(Number(value || 0));
}

function formatShortDateTime(value) {
  if (!value) return '--';

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '--';

  return new Intl.DateTimeFormat('en-GB', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  }).format(date);
}

const reviewSections = computed(() => [
  {
    key: 'vehicle',
    label: 'Vehicle',
    value: [verifiedVehicle.value?.registration || form.title || '--', form.vehicle_make || ''].filter(Boolean).join(' • '),
    step: 0
  },
  {
    key: 'route',
    label: 'Route',
    value: `${form.pickup_label || form.pickup_postcode || '--'} → ${form.dropoff_label || form.dropoff_postcode || '--'}`,
    step: 1
  },
  {
    key: 'movement',
    label: 'Movement',
    value: `${transportOptions.find((option) => option.value === form.transport_type)?.label || 'Drive-away'} • ${formatShortDateTime(form.pickup_at)} → ${formatShortDateTime(form.delivery_at)}`,
    step: 2
  }
]);

function normaliseRegistration(value) {
  return (value || '').toString().replace(/[^a-z0-9]/gi, '').toUpperCase();
}

function normalisePostcode(value) {
  return (value || '').toString().replace(/\s+/g, ' ').trim().toUpperCase();
}

function normalisePrice(value) {
  return (value || '').toString().replace(/,/g, '').trim();
}

function addressState(type) {
  return addressLookup[type];
}

async function lookupAddresses(type) {
  const state = addressState(type);
  const postcodeField = `${type}_postcode`;
  const labelField = `${type}_label`;
  const postcode = normalisePostcode(form[postcodeField]);

  state.error = '';
  state.addresses = [];
  state.selected = null;
  form[labelField] = '';
  validationState[postcodeField] = false;
  validationState[labelField] = false;

  if (!postcode) {
    state.error = 'Enter a postcode first.';
    return;
  }

  form[postcodeField] = postcode;
  state.loading = true;

  try {
    const { data } = await api.get(`/postcodes/${encodeURIComponent(postcode)}/addresses`);
    const result = data?.data ?? data ?? {};
    state.postcode = result.postcode || postcode;
    state.addresses = Array.isArray(result.addresses) ? result.addresses : [];

    if (!state.addresses.length) {
      throw new Error('No addresses were returned for this postcode.');
    }
  } catch (error) {
    console.error('Postcode lookup failed', error);
    state.error = error.response?.data?.message || error.message || 'Could not find addresses for this postcode.';
  } finally {
    state.loading = false;
  }
}

async function selectAddress(type, selectedId) {
  const state = addressState(type);
  const address = state.addresses.find((item) => item.id === selectedId);
  if (!address) return;

  state.loading = true;
  state.error = '';

  try {
    const { data } = await api.get(`/postcodes/places/${encodeURIComponent(selectedId)}`);
    const result = data?.data ?? data ?? {};
    const label = result.label || address.label;

    state.selected = {
      ...address,
      ...result,
      label
    };
    state.postcode = result.postcode || state.postcode || form[`${type}_postcode`];
    form[`${type}_postcode`] = state.postcode || form[`${type}_postcode`];
    form[`${type}_label`] = label;
    validationState[`${type}_postcode`] = false;
    validationState[`${type}_label`] = false;
  } catch (error) {
    console.error('Address resolution failed', error);
    state.error = error.response?.data?.message || error.message || 'Could not resolve this address.';
  } finally {
    state.loading = false;
  }
}

function changeAddress(type) {
  const state = addressState(type);
  state.error = '';
  state.addresses = [];
  state.selected = null;
  state.postcode = '';
  form[`${type}_label`] = '';
  validationState[`${type}_postcode`] = false;
  validationState[`${type}_label`] = false;
}

function usePostcodeOnly(type) {
  const state = addressState(type);
  const postcodeField = `${type}_postcode`;
  const postcode = normalisePostcode(form[postcodeField]);

  if (!postcode) {
    state.error = 'Enter a postcode first.';
    return;
  }

  form[postcodeField] = postcode;
  form[`${type}_label`] = postcode;
  state.error = '';
  state.addresses = [];
  state.selected = { id: 'postcode-only', label: postcode };
  validationState[postcodeField] = false;
  validationState[`${type}_label`] = false;
}

function hydrateSelectedAddress(type, label, postcode) {
  const state = addressState(type);
  state.error = '';
  state.addresses = [];
  state.postcode = normalisePostcode(postcode);
  state.selected = label ? { id: 'saved', label } : null;
  validationState[`${type}_postcode`] = false;
  validationState[`${type}_label`] = false;
}

async function lookupVehicle() {
  const registration = normaliseRegistration(form.title);
  vehicleLookupError.value = '';

  if (!registration) {
    vehicleLookupError.value = 'Enter a registration plate first.';
    return;
  }

  if (verifiedVehicle.value?.registration === registration) {
    return;
  }

  verifiedVehicle.value = null;
  form.vehicle_make = '';

  form.title = registration;
  vehicleLookupLoading.value = true;

  try {
    const { data } = await api.get(`/vehicles/registration/${encodeURIComponent(registration)}`);
    const vehicle = data?.data ?? data ?? null;
    if (!vehicle?.registration) {
      throw new Error('No vehicle details were returned for this registration.');
    }

    verifiedVehicle.value = vehicle;
    form.title = vehicle.registration;
    form.vehicle_make = vehicle.display_name || [vehicle.make, vehicle.model].filter(Boolean).join(' ') || '';
    validationState.vehicle = false;
    validationMessage.value = '';
    errorMessage.value = '';
  } catch (error) {
    console.error('Vehicle lookup failed', error);
    vehicleLookupError.value = error.response?.data?.message || error.message || 'Could not verify this registration.';
  } finally {
    vehicleLookupLoading.value = false;
  }
}

function selectTransport(value) {
  form.transport_type = value;
}

function changeVehicle() {
  if (isEdit.value || vehicleLookupLoading.value) return;

  verifiedVehicle.value = null;
  vehicleLookupError.value = '';
  validationMessage.value = '';
  errorMessage.value = '';
  form.title = '';
  form.vehicle_make = '';
  validationState.vehicle = false;
}

watch(
  () => route.query.step,
  (nextStep) => {
    const nextIndex = stepIndexFromKey(nextStep);
    if (nextIndex !== currentStep.value) {
      currentStep.value = nextIndex;
    }
  },
  { immediate: true }
);

watch(
  currentStep,
  (nextStep) => {
    syncWizardStepQuery(nextStep, 'push');
  },
  { immediate: true }
);

watch(
  () => form.title,
  (next) => {
    const registration = normaliseRegistration(next);
    const verifiedRegistration = verifiedVehicle.value?.registration || '';

    if (verifiedRegistration && registration !== verifiedRegistration) {
      verifiedVehicle.value = null;
      form.vehicle_make = '';
    }
  }
);

watch(
  () => form.transport_type,
  (next) => {
    if (next) {
      validationState.transport_type = false;
    }
  }
);

watch(
  () => [form.pickup_postcode, form.pickup_label],
  ([pickupPostcode, pickupLabel]) => {
    if (pickupPostcode) {
      validationState.pickup_postcode = false;
    }
    if (pickupLabel) {
      validationState.pickup_label = false;
    }
  }
);

watch(
  () => [form.dropoff_postcode, form.dropoff_label],
  ([dropoffPostcode, dropoffLabel]) => {
    if (dropoffPostcode) {
      validationState.dropoff_postcode = false;
    }
    if (dropoffLabel) {
      validationState.dropoff_label = false;
    }
  }
);

watch(
  () => [form.pickup_at, form.delivery_at],
  ([pickupAt, deliveryAt]) => {
    if (pickupAt) validationState.pickup_at = false;
    if (deliveryAt) validationState.delivery_at = false;
  }
);

watch(
  () => form.price,
  (next) => {
      if (Number(normalisePrice(next) || 0) > 0) {
        validationState.price = false;
      }
  }
);

function setStep(stepIndex) {
  if (stepIndex < 0 || stepIndex >= wizardSteps.length) {
    return;
  }

  errorMessage.value = '';
  validationMessage.value = '';
  currentStep.value = stepIndex;
}

async function validateCurrentStep() {
  errorMessage.value = '';
  clearValidationState();

  if (currentStep.value === 0) {
    if (!verifiedVehicle.value && !isEdit.value) {
      await lookupVehicle();
    }

    if (!verifiedVehicle.value && !isEdit.value) {
      setValidationError('vehicle', 'Please verify the registration plate before continuing.');
      throw new Error('Please complete the highlighted field before continuing.');
    }
  }

  if (currentStep.value === 1) {
    if (!form.pickup_label) {
      setValidationError('pickup_postcode', 'Please complete the pickup postcode.');
      setValidationError('pickup_label', 'Please choose the exact pickup address.');
      throw new Error('Please complete the highlighted fields before continuing.');
    }

    if (!form.dropoff_label) {
      setValidationError('dropoff_postcode', 'Please complete the drop-off postcode.');
      setValidationError('dropoff_label', 'Please choose the exact drop-off address.');
      throw new Error('Please complete the highlighted fields before continuing.');
    }
  }

    if (currentStep.value === 2) {
      if (!form.transport_type) {
        setValidationError('transport_type', 'Please choose a transport type.');
      }
      if (!form.pickup_at) {
        setValidationError('pickup_at', 'Please choose a pickup date and time.');
      }
      if (!form.delivery_at) {
        setValidationError('delivery_at', 'Please choose a delivery date and time.');
      }

      if (
        validationState.transport_type ||
        validationState.pickup_at ||
        validationState.delivery_at
      ) {
        throw new Error('Please complete the highlighted fields before continuing.');
      }

      validateMovementTimings();
    }

  if (currentStep.value === 3) {
    if (!form.price || Number(normalisePrice(form.price)) <= 0) {
      setValidationError('price', 'Please enter a dealer charge.');
      throw new Error('Please complete the highlighted field before continuing.');
    }

    }
  }

async function handleWizardSubmit() {
  if (isLastStep.value) {
    await submit();
    return;
  }

  await goNext();
}

async function goNext() {
  try {
    await validateCurrentStep();
    if (!isLastStep.value) {
      currentStep.value += 1;
      errorMessage.value = '';
      validationMessage.value = '';
    }
  } catch (error) {
    errorMessage.value = error.message || 'Please complete this step.';
  }
}

function goBack() {
  errorMessage.value = '';
  validationMessage.value = '';
  if (!isFirstStep.value) {
    currentStep.value -= 1;
  }
}


function buildDateTime(dateTimeValue) {
  if (!dateTimeValue) {
    return null;
  }
  return dateTimeValue;
}

function buildComparison(dateTimeValue) {
  if (!dateTimeValue) {
    return null;
  }
  const [datePart, timePart = '00:00'] = `${dateTimeValue}`.split('T');
  const [year, month, day] = datePart.split('-').map(Number);
  const [hours, minutes] = timePart.split(':').map(Number);

  if (!year || !month || !day) {
    return null;
  }

  return new Date(year, month - 1, day, hours || 0, minutes || 0, 0, 0);
}

function validateMovementTimings() {
  const pickupComparable = buildComparison(form.pickup_at);
  const deliveryComparable = buildComparison(form.delivery_at);
  const now = new Date();

  if (deliveryComparable && deliveryComparable <= now) {
    validationMessage.value = 'Delivery due must be in the future.';
    setValidationError('delivery_at', 'Delivery due must be in the future.');
    throw new Error('Delivery due must be in the future.');
  }

  if (pickupComparable && deliveryComparable && deliveryComparable.getTime() === pickupComparable.getTime()) {
    validationMessage.value = 'Pickup and delivery cannot be the same date and time.';
    setValidationError('pickup_at', 'Pickup and delivery cannot be the same date and time.');
    setValidationError('delivery_at', 'Delivery due cannot match pickup exactly.');
    throw new Error('Pickup and delivery cannot be the same date and time.');
  }

  if (pickupComparable && deliveryComparable && deliveryComparable < pickupComparable) {
    validationMessage.value = 'Delivery due must be after pickup ready time.';
    setValidationError('pickup_at', 'Delivery due must be after pickup ready time.');
    setValidationError('delivery_at', 'Delivery due must be after pickup ready time.');
    throw new Error('Delivery due time must be after the pickup ready time.');
  }
}

async function submit() {
  if (!auth.token) {
    errorMessage.value = 'You need to log in as a dealer to create jobs.';
    return;
  }
  if (isEdit.value && auth.role !== 'dealer') {
    errorMessage.value = 'Only dealers can edit jobs.';
    return;
  }

  submitting.value = true;
  errorMessage.value = '';

  try {
    await validateCurrentStep();

    if (!verifiedVehicle.value && !isEdit.value) {
      throw new Error('Verify the registration plate before creating this job.');
    }

    if (!form.pickup_label) {
      throw new Error('Find and select the exact pickup address.');
    }

    if (!form.dropoff_label) {
      throw new Error('Find and select the exact drop-off address.');
    }

      validateMovementTimings();

      const payload = {
        title: form.title,
        pickup_postcode: form.pickup_postcode,
        pickup_label: form.pickup_label,
        dropoff_postcode: form.dropoff_postcode,
        dropoff_label: form.dropoff_label,
        price: Number(normalisePrice(form.price) || 0),
        transport_type: form.transport_type,
        pickup_ready_at: buildDateTime(form.pickup_at),
        delivery_due_at: buildDateTime(form.delivery_at),
      };

    if (isEdit.value) {
      await api.patch(`/jobs/${jobId.value}`, payload);
      await auth.fetchMe().catch(() => null);
      jobDraft.clearDraft();
      router.push({ name: 'job-detail', params: { id: jobId.value } });
    } else {
      const { data: createdJob } = await api.post('/jobs', payload);
      await auth.fetchMe().catch(() => null);
      const checkout = await createJobCheckout(createdJob.id);
      if (!checkout?.url) {
        throw new Error('Job was created, but Stripe did not return a checkout link.');
      }
      jobDraft.clearDraft();
      window.location.href = checkout.url;
    }
  } catch (error) {
    console.error('Failed to create job', error);
    errorMessage.value =
      error.response?.data?.message ||
      error.message ||
      'Could not save job. Please check the form.';
  } finally {
    submitting.value = false;
  }
}

function resetForm() {
  jobDraft.clearDraft();
  vehicleLookupError.value = '';
  currentStep.value = 0;
  clearValidationState();
  hydrateSelectedAddress('pickup', '', '');
  hydrateSelectedAddress('dropoff', '', '');
}

function splitDateTime(value) {
  if (!value) {
    return '';
  }
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return '';
  }
  const pad = (num) => String(num).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

async function loadJobForEditing() {
  if (!isEdit.value) {
    resetForm();
    return;
  }

  loading.value = true;
  loadError.value = '';

  try {
    const { data } = await api.get(`/jobs/${jobId.value}`);
    const job = data?.data ?? data ?? null;
    if (!job) {
      throw new Error('Job not found.');
    }

    const pickup = splitDateTime(job.pickup_ready_at);
    const dropoff = splitDateTime(job.delivery_due_at);

    Object.assign(form, {
      title: job.title || '',
      pickup_postcode: job.pickup_postcode || '',
      pickup_label: job.pickup_label || job.pickup_postcode || '',
      dropoff_postcode: job.dropoff_postcode || '',
      dropoff_label: job.dropoff_label || job.dropoff_postcode || '',
      vehicle_make: job.vehicle_make || '',
      price: job.price != null ? String(job.price) : '',
      transport_type: job.transport_type || 'drive_away',
      pickup_at: pickup,
      delivery_at: dropoff,
    });
    verifiedVehicle.value = {
      registration: normaliseRegistration(job.title || ''),
      display_name: job.vehicle_make || '',
      vehicle_type: job.vehicle_type || null,
    };
    hydrateSelectedAddress('pickup', form.pickup_label, form.pickup_postcode);
    hydrateSelectedAddress('dropoff', form.dropoff_label, form.dropoff_postcode);
    clearValidationState();
    currentStep.value = 0;
  } catch (error) {
    console.error('Failed to load job for editing', error);
    loadError.value =
      error.response?.data?.message ||
      error.message ||
      'We could not load this job for editing.';
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  if (!auth.user && auth.token) {
    auth.fetchMe().catch(() => null);
  }
  if (isEdit.value) {
    loadJobForEditing();
  }
});

watch(
  () => jobId.value,
  (next, prev) => {
    if (next !== prev) {
      if (next) {
        loadJobForEditing();
      } else {
        resetForm();
      }
    }
  }
);
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-5 overflow-x-hidden">
    <div v-if="loading" class="section-card text-sm text-slate-600">
      Loading job details...
    </div>

    <p v-else-if="loadError" class="rounded-3xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-700">
      {{ loadError }}
    </p>

    <form v-else class="section-card space-y-6" @submit.prevent="handleWizardSubmit">
      <header class="space-y-4 border-b border-slate-200 pb-5">
          <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="space-y-2">
              <div>
                <h1 class="text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">
                  {{ isEdit ? 'Edit job' : 'Create a new job' }}
                </h1>
              </div>
            </div>
          </div>

        <div class="space-y-2">
          <div class="h-2 overflow-hidden rounded-full bg-slate-100">
            <div
              class="h-full rounded-full bg-gradient-to-r from-emerald-500 to-sky-500 transition-all duration-300"
              :style="{ width: `${wizardProgress}%` }"
            ></div>
          </div>
          <div class="flex flex-wrap gap-3 pt-2 sm:gap-4">
            <button
              v-for="(step, index) in wizardSteps"
              :key="step.key"
              type="button"
              class="rounded-full px-4 py-1.5 text-xs font-black uppercase tracking-[0.16em] transition"
              :disabled="index > currentStep"
              @click="setStep(index)"
              :class="
                index === currentStep
                  ? 'bg-emerald-100 text-emerald-900 shadow-sm ring-1 ring-emerald-200'
                  : index < currentStep
                    ? 'bg-slate-200 text-slate-700 hover:bg-slate-300'
                    : 'bg-slate-100 text-slate-400'
              "
            >
              {{ step.label }}
            </button>
          </div>
        </div>
      </header>

      <Transition
        mode="out-in"
        enter-active-class="transition duration-300 ease-out"
        enter-from-class="opacity-0 translate-x-6"
        enter-to-class="opacity-100 translate-x-0"
        leave-active-class="transition duration-200 ease-in"
        leave-from-class="opacity-100 translate-x-0"
        leave-to-class="opacity-0 -translate-x-6"
      >
        <div :key="currentStep" class="relative space-y-5">
          <p v-if="bannerMessage" class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-700">
            {{ bannerMessage }}
          </p>

          <JobCreateVehicleStep
            v-if="currentStep === 0"
            :form="form"
            :verified-vehicle="verifiedVehicle"
            :validation-state="validationState"
            :is-edit="isEdit"
            :vehicle-lookup-loading="vehicleLookupLoading"
            @lookup-vehicle="lookupVehicle"
            @change-vehicle="changeVehicle"
            @next="goNext"
          />

          <JobCreateRouteStep
            v-else-if="currentStep === 1"
            :form="form"
            :address-lookup="addressLookup"
            :validation-state="validationState"
            @lookup-addresses="lookupAddresses"
            @select-address="selectAddress"
            @change-address="changeAddress"
            @use-postcode-only="usePostcodeOnly"
            @back="goBack"
            @next="goNext"
          />

          <JobCreateMovementStep
            v-else-if="currentStep === 2"
            :form="form"
            :transport-options="transportOptions"
            :validation-state="validationState"
            @select-transport="selectTransport"
            @back="goBack"
            @next="goNext"
          />

          <JobCreatePaymentStep
            v-else-if="currentStep === 3"
            :form="form"
            :validation-state="validationState"
            :job-price="jobPrice"
            :estimated-driver-payout="estimatedDriverPayout"
            :submitting="submitting"
            :is-edit="isEdit"
            :format-money="formatMoney"
            @back="goBack"
            @next="goNext"
          />

          <JobCreateReviewStep
            v-else
            :form="form"
            :review-sections="reviewSections"
            :job-price="jobPrice"
            :estimated-driver-payout="estimatedDriverPayout"
            :submitting="submitting"
            :is-edit="isEdit"
            :format-money="formatMoney"
            @back="goBack"
            @go-to-step="setStep"
            @submit="handleWizardSubmit"
          />
        </div>
      </Transition>
    </form>
  </div>
</template>
