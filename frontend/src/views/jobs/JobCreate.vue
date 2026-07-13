<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '@/services/api';
import { createJobCheckout } from '@/services/payments';
import { useAuthStore } from '@/stores/auth';

const props = defineProps({
  id: {
    type: [String, Number],
    default: null
  }
});

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const defaultFormState = {
  title: '',
  pickup_postcode: '',
  pickup_label: '',
  dropoff_postcode: '',
  dropoff_label: '',
  vehicle_make: '',
  price: '',
  transport_type: 'drive_away',
  pickup_date: '',
  pickup_time: '',
  delivery_date: '',
  delivery_time: '',
  is_urgent: false,
  urgent_fee_ack: false
};

const form = reactive({ ...defaultFormState });

const submitting = ref(false);
const errorMessage = ref('');
const validationMessage = ref('');
const loading = ref(false);
const loadError = ref('');
const vehicleLookupLoading = ref(false);
const vehicleLookupError = ref('');
const verifiedVehicle = ref(null);
const currentStep = ref(0);
const validationState = reactive({
  vehicle: false,
  pickup_postcode: false,
  pickup_label: false,
  dropoff_postcode: false,
  dropoff_label: false,
  transport_type: false,
  pickup_date: false,
  pickup_time: false,
  delivery_date: false,
  delivery_time: false,
  price: false,
  urgent_fee_ack: false
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

const timeSlotValues = Array.from({ length: 96 }, (_, index) => {
  const totalMinutes = index * 15;
  const hours = String(Math.floor(totalMinutes / 60)).padStart(2, '0');
  const minutes = String(totalMinutes % 60).padStart(2, '0');
  return `${hours}:${minutes}`;
});

const wizardStepKeys = ['vehicle', 'route', 'movement', 'payment'];

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

const selectedTransport = computed(() => {
  return transportOptions.find((option) => option.value === form.transport_type) ?? transportOptions[0];
});

function buildTimeOptions(selectedValue) {
  if (selectedValue && !timeSlotValues.includes(selectedValue)) {
    return [selectedValue, ...timeSlotValues];
  }
  return timeSlotValues;
}

const pickupTimeOptions = computed(() => buildTimeOptions(form.pickup_time));
const deliveryTimeOptions = computed(() => buildTimeOptions(form.delivery_time));

const wizardSteps = [
  { key: 'vehicle', label: 'Vehicle' },
  { key: 'route', label: 'Route' },
  { key: 'movement', label: 'Movement' },
  { key: 'payment', label: 'Payment' }
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

const paidPlans = ['gold_driver', 'dealer_pro'];
const planSlug = computed(() => (auth.planSlug || auth.user?.plan_slug || '').toLowerCase());
const hasPaidPlan = computed(() => paidPlans.includes(planSlug.value));
const requiresUrgentAcknowledgement = computed(() => form.is_urgent && !hasPaidPlan.value);
const jobPrice = computed(() => Number(form.price || 0));
const platformCommissionRate = 0.1;
const estimatedPlatformFee = computed(() => Math.max(jobPrice.value * platformCommissionRate, 0));
const estimatedUrgentFee = computed(() => (requiresUrgentAcknowledgement.value ? 25 : 0));
const estimatedDealerTotal = computed(() => jobPrice.value + estimatedUrgentFee.value);
const estimatedDriverPayout = computed(() => Math.max(jobPrice.value - estimatedPlatformFee.value, 0));
const moneyFormatter = new Intl.NumberFormat('en-GB', {
  style: 'currency',
  currency: 'GBP',
  maximumFractionDigits: 2
});
const urgentHelperText = computed(() => {
  if (!form.is_urgent) {
    return 'Optional. Use this when the job needs driver attention quickly.';
  }
  return hasPaidPlan.value
    ? 'Urgent boost is included with your subscription.'
    : 'Urgent boost adds an extra charge on Starter before the job is posted.';
});

function formatMoney(value) {
  return moneyFormatter.format(Number(value || 0));
}

function normaliseRegistration(value) {
  return (value || '').toString().replace(/[^a-z0-9]/gi, '').toUpperCase();
}

function normalisePostcode(value) {
  return (value || '').toString().replace(/\s+/g, ' ').trim().toUpperCase();
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
  () => form.is_urgent,
  (next) => {
    if (!next) {
      form.urgent_fee_ack = false;
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
  () => [form.pickup_date, form.pickup_time, form.delivery_date, form.delivery_time],
  ([pickupDate, pickupTime, deliveryDate, deliveryTime]) => {
    if (pickupDate) validationState.pickup_date = false;
    if (pickupTime) validationState.pickup_time = false;
    if (deliveryDate) validationState.delivery_date = false;
    if (deliveryTime) validationState.delivery_time = false;
  }
);

watch(
  () => form.price,
  (next) => {
    if (Number(next) > 0) {
      validationState.price = false;
    }
  }
);

watch(
  () => form.urgent_fee_ack,
  (next) => {
    if (next) {
      validationState.urgent_fee_ack = false;
    }
  }
);

const starterUsageInfo = computed(() => {
  if (planSlug.value !== 'starter') return null;

  const jobLimit = auth.planLimits?.monthly_job_posts ?? null;
  const jobUsed = auth.usage?.job_posts_this_month ?? 0;
  const urgentLimit = auth.planLimits?.urgent_boost_per_month ?? null;
  const urgentUsed = auth.usage?.urgent_boosts_used ?? 0;

  return {
    jobLimit,
    jobUsed,
    jobRemaining: jobLimit != null ? Math.max(jobLimit - jobUsed, 0) : null,
    urgentLimit,
    urgentUsed,
    urgentRemaining: urgentLimit != null ? Math.max(urgentLimit - urgentUsed, 0) : null
  };
});

const canUseUrgentBoost = computed(() => {
  if (!starterUsageInfo.value) return true;
  if (starterUsageInfo.value.urgentRemaining === null) return true;
  return starterUsageInfo.value.urgentRemaining > 0;
});

watch(canUseUrgentBoost, (allowed) => {
  if (!allowed) {
    form.is_urgent = false;
    form.urgent_fee_ack = false;
  }
});

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
    if (!form.pickup_date) {
      setValidationError('pickup_date', 'Please choose a pickup date.');
    }
    if (!form.pickup_time) {
      setValidationError('pickup_time', 'Please choose a pickup time.');
    }
    if (!form.delivery_date) {
      setValidationError('delivery_date', 'Please choose a delivery date.');
    }
    if (!form.delivery_time) {
      setValidationError('delivery_time', 'Please choose a delivery time.');
    }

    if (
      validationState.transport_type ||
      validationState.pickup_date ||
      validationState.pickup_time ||
      validationState.delivery_date ||
      validationState.delivery_time
    ) {
      throw new Error('Please complete the highlighted fields before continuing.');
    }

    const pickupComparable = buildComparison(form.pickup_date, form.pickup_time);
    const deliveryComparable = buildComparison(form.delivery_date, form.delivery_time);

    if (pickupComparable && deliveryComparable && deliveryComparable < pickupComparable) {
      throw new Error('Delivery due time must be after the pickup ready time.');
    }
  }

  if (currentStep.value === 3) {
    if (!form.price || Number(form.price) <= 0) {
      setValidationError('price', 'Please enter a dealer charge.');
      throw new Error('Please complete the highlighted field before continuing.');
    }

    if (requiresUrgentAcknowledgement.value && !form.urgent_fee_ack) {
      setValidationError('urgent_fee_ack', 'Please acknowledge the urgent boost fee.');
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


function buildDateTime(dateValue, timeValue) {
  if (!dateValue) {
    return null;
  }
  const timePart = timeValue ? `${timeValue}` : '00:00';
  return `${dateValue} ${timePart}`;
}

function buildComparison(dateValue, timeValue) {
  if (!dateValue) {
    return null;
  }
  const safeTime = timeValue ? `${timeValue}:00` : '00:00:00';
  return new Date(`${dateValue}T${safeTime}`);
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

    if (requiresUrgentAcknowledgement.value && !form.urgent_fee_ack) {
      throw new Error('Please acknowledge the urgent boost fee before continuing.');
    }

    const pickupComparable = buildComparison(form.pickup_date, form.pickup_time);
    const deliveryComparable = buildComparison(form.delivery_date, form.delivery_time);

    if (pickupComparable && deliveryComparable && deliveryComparable < pickupComparable) {
      throw new Error('Delivery due time must be after the pickup ready time.');
    }

    const payload = {
      title: form.title,
      pickup_postcode: form.pickup_postcode,
      pickup_label: form.pickup_label,
      dropoff_postcode: form.dropoff_postcode,
      dropoff_label: form.dropoff_label,
      price: Number(form.price || 0),
      transport_type: form.transport_type,
      pickup_ready_at: buildDateTime(form.pickup_date, form.pickup_time),
      delivery_due_at: buildDateTime(form.delivery_date, form.delivery_time),
      is_urgent: form.is_urgent,
      urgent_accept_fee: requiresUrgentAcknowledgement.value ? form.urgent_fee_ack : false
    };

    if (isEdit.value) {
      await api.patch(`/jobs/${jobId.value}`, payload);
      await auth.fetchMe().catch(() => null);
      router.push({ name: 'job-detail', params: { id: jobId.value } });
    } else {
      const { data: createdJob } = await api.post('/jobs', payload);
      await auth.fetchMe().catch(() => null);
      const checkout = await createJobCheckout(createdJob.id);
      if (!checkout?.url) {
        throw new Error('Job was created, but Stripe did not return a checkout link.');
      }
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
  Object.assign(form, { ...defaultFormState });
  verifiedVehicle.value = null;
  vehicleLookupError.value = '';
  currentStep.value = 0;
  clearValidationState();
  hydrateSelectedAddress('pickup', '', '');
  hydrateSelectedAddress('dropoff', '', '');
}

function splitDateTime(value) {
  if (!value) {
    return { date: '', time: '' };
  }
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return { date: '', time: '' };
  }
  const pad = (num) => String(num).padStart(2, '0');
  const datePart = `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
  const timePart = `${pad(date.getHours())}:${pad(date.getMinutes())}`;
  return { date: datePart, time: timePart };
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
      pickup_date: pickup.date,
      pickup_time: pickup.time,
      delivery_date: dropoff.date,
      delivery_time: dropoff.time,
      is_urgent: Boolean(job.is_urgent),
      urgent_fee_ack: Boolean(job.is_urgent)
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
  <div class="mx-auto max-w-6xl space-y-5">
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

          <template v-if="currentStep === 0">
            <section class="space-y-5 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
              <header>
                <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700">Vehicle</p>
                <h2 class="mt-1 text-xl font-black text-slate-950">What is being moved?</h2>
              </header>

              <div class="flex items-end gap-3">
                <label class="block min-w-0 flex-1">
                  <span class="text-sm font-bold text-slate-700">Licence plate</span>
                  <input
                    v-model="form.title"
                    type="text"
                    required
                    placeholder="e.g. AB12 CDE"
                    :readonly="isEdit || Boolean(verifiedVehicle)"
                    @blur="!isEdit && !verifiedVehicle && lookupVehicle()"
                    class="mt-2 w-full rounded-2xl border px-4 py-3 text-sm"
                    :class="[
                      verifiedVehicle ? 'bg-slate-100 font-black text-slate-700' : '',
                      validationState.vehicle && !verifiedVehicle ? 'border-rose-400 bg-rose-50 ring-2 ring-rose-200' : ''
                    ]"
                  />
                </label>

                <button
                  v-if="!isEdit && !verifiedVehicle"
                  type="button"
                  class="btn-secondary shrink-0 px-5"
                  :disabled="vehicleLookupLoading || !form.title"
                  @click="lookupVehicle"
                >
                  <span v-if="vehicleLookupLoading">Checking...</span>
                  <span v-else>Check plate</span>
                </button>
                <button
                  v-else-if="!isEdit"
                  type="button"
                  class="btn-secondary shrink-0 px-5"
                  :disabled="vehicleLookupLoading"
                  @click="changeVehicle"
                >
                  Change plate
                </button>
              </div>

              <div
                v-if="verifiedVehicle"
                class="grid gap-3 rounded-3xl border border-emerald-200 bg-emerald-50/70 p-4 sm:grid-cols-3"
              >
                <div>
                  <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">Verified plate</p>
                  <p class="mt-1 text-lg font-black text-slate-950">{{ verifiedVehicle.registration }}</p>
                </div>
                <div>
                  <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">Vehicle</p>
                  <p class="mt-1 text-lg font-black text-slate-950">{{ form.vehicle_make || '--' }}</p>
                </div>
                <div>
                  <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">Details</p>
                  <p class="mt-1 text-lg font-black text-slate-950">{{ verifiedVehicle.vehicle_type || '--' }}</p>
                </div>
              </div>

                  <p
                    v-else
                    class="hidden rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-600 md:block"
                    :class="validationState.vehicle ? 'border-rose-400 bg-rose-50 text-rose-700' : ''"
                  >
                    Enter the registration plate and MotorRelay will pull the vehicle details automatically. Dealers cannot type vehicle details manually.
                  </p>

              <div class="flex justify-end">
                <button type="button" class="btn-primary px-5 py-3" :disabled="vehicleLookupLoading" @click="goNext">
                  Next
                </button>
              </div>
            </section>
          </template>

          <template v-else-if="currentStep === 1">
            <section class="space-y-5 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
              <header>
                <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700">Route</p>
                <h2 class="mt-1 text-xl font-black text-slate-950">Pickup and drop-off</h2>
                <p class="mt-1 text-sm text-slate-600">
                  Enter each postcode, choose the exact address, then MotorRelay locks it into the job.
                </p>
              </header>

              <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_120px_minmax(0,1fr)] md:items-start">
                <div class="space-y-3">
                  <div class="flex items-end gap-3">
                    <label class="block min-w-0 flex-1">
                      <span class="text-sm font-bold text-slate-700">Pickup postcode</span>
                      <input
                        v-model="form.pickup_postcode"
                        type="text"
                        required
                        :readonly="Boolean(form.pickup_label)"
                        placeholder="e.g. M1 2AB"
                        class="mt-2 w-full rounded-2xl border px-4 py-3 text-sm"
                        :class="[
                          form.pickup_label ? 'bg-slate-100 font-black text-slate-700' : '',
                          validationState.pickup_postcode && !form.pickup_label ? 'border-rose-400 bg-rose-50 ring-2 ring-rose-200' : ''
                        ]"
                      />
                    </label>
                    <button
                      v-if="!form.pickup_label"
                      type="button"
                      class="btn-secondary shrink-0 px-5"
                      :disabled="addressLookup.pickup.loading || !form.pickup_postcode"
                      @click="lookupAddresses('pickup')"
                    >
                      <span v-if="addressLookup.pickup.loading">Finding...</span>
                      <span v-else>Find address</span>
                    </button>
                    <button
                      v-else
                      type="button"
                      class="btn-secondary shrink-0 px-5"
                      @click="changeAddress('pickup')"
                    >
                      Change
                    </button>
                  </div>

                  <p v-if="addressLookup.pickup.error" class="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                    {{ addressLookup.pickup.error }}
                  </p>
                  <button
                    v-if="addressLookup.pickup.error && !form.pickup_label"
                    type="button"
                    class="btn-secondary w-full"
                    @click="usePostcodeOnly('pickup')"
                  >
                    Use pickup postcode only for testing
                  </button>

                  <label v-if="addressLookup.pickup.addresses.length && !form.pickup_label" class="block">
                    <span class="text-sm font-bold text-slate-700">Select pickup address</span>
                    <select
                      class="mt-2 w-full rounded-2xl border px-4 py-3 text-sm"
                      @change="selectAddress('pickup', $event.target.value)"
                    >
                      <option value="">Choose the exact pickup address</option>
                      <option
                        v-for="address in addressLookup.pickup.addresses"
                        :key="address.id"
                        :value="address.id"
                      >
                        {{ address.label }}{{ address.secondary ? ` â€” ${address.secondary}` : '' }}
                      </option>
                    </select>
                  </label>

                  <div
                    v-if="form.pickup_label"
                    class="rounded-3xl border border-emerald-200 bg-emerald-50/70 p-4"
                    :class="validationState.pickup_label ? 'border-rose-400 bg-rose-50 text-rose-700' : ''"
                  >
                    <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">Pickup address locked</p>
                    <p class="mt-1 text-base font-black text-slate-950">{{ form.pickup_label }}</p>
                    <p class="mt-1 text-sm text-slate-600">{{ form.pickup_postcode }}</p>
                  </div>
                </div>

                <div class="hidden pt-10 md:flex md:items-center md:justify-center">
                  <div class="relative h-10 w-full">
                    <div class="absolute left-0 right-0 top-1/2 h-1 -translate-y-1/2 rounded-full bg-gradient-to-r from-emerald-300 to-sky-300"></div>
                    <div class="absolute left-0 top-1/2 h-4 w-4 -translate-y-1/2 rounded-full bg-emerald-500 ring-4 ring-emerald-100"></div>
                    <div class="absolute right-0 top-1/2 h-4 w-4 -translate-y-1/2 rounded-full bg-sky-500 ring-4 ring-sky-100"></div>
                    <div class="absolute left-1/2 top-1/2 flex h-11 w-11 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-2xl bg-slate-950 text-lg shadow-xl">
                      🚗
                    </div>
                  </div>
                </div>

                <div class="space-y-3">
                  <div class="flex items-end gap-3">
                    <label class="block min-w-0 flex-1">
                      <span class="text-sm font-bold text-slate-700">Drop-off postcode</span>
                      <input
                        v-model="form.dropoff_postcode"
                        type="text"
                        required
                        :readonly="Boolean(form.dropoff_label)"
                        placeholder="e.g. LS1 4XY"
                        class="mt-2 w-full rounded-2xl border px-4 py-3 text-sm"
                        :class="[
                          form.dropoff_label ? 'bg-slate-100 font-black text-slate-700' : '',
                          validationState.dropoff_postcode && !form.dropoff_label ? 'border-rose-400 bg-rose-50 ring-2 ring-rose-200' : ''
                        ]"
                      />
                    </label>
                    <button
                      v-if="!form.dropoff_label"
                      type="button"
                      class="btn-secondary shrink-0 px-5"
                      :disabled="addressLookup.dropoff.loading || !form.dropoff_postcode"
                      @click="lookupAddresses('dropoff')"
                    >
                      <span v-if="addressLookup.dropoff.loading">Finding...</span>
                      <span v-else>Find address</span>
                    </button>
                    <button
                      v-else
                      type="button"
                      class="btn-secondary shrink-0 px-5"
                      @click="changeAddress('dropoff')"
                    >
                      Change
                    </button>
                  </div>

                  <p v-if="addressLookup.dropoff.error" class="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                    {{ addressLookup.dropoff.error }}
                  </p>
                  <button
                    v-if="addressLookup.dropoff.error && !form.dropoff_label"
                    type="button"
                    class="btn-secondary w-full"
                    @click="usePostcodeOnly('dropoff')"
                  >
                    Use drop-off postcode only for testing
                  </button>

                  <label v-if="addressLookup.dropoff.addresses.length && !form.dropoff_label" class="block">
                    <span class="text-sm font-bold text-slate-700">Select drop-off address</span>
                    <select
                      class="mt-2 w-full rounded-2xl border px-4 py-3 text-sm"
                      @change="selectAddress('dropoff', $event.target.value)"
                    >
                      <option value="">Choose the exact drop-off address</option>
                      <option
                        v-for="address in addressLookup.dropoff.addresses"
                        :key="address.id"
                        :value="address.id"
                      >
                        {{ address.label }}{{ address.secondary ? ` â€” ${address.secondary}` : '' }}
                      </option>
                    </select>
                  </label>

                  <div
                    v-if="form.dropoff_label"
                    class="rounded-3xl border border-sky-200 bg-sky-50/70 p-4"
                    :class="validationState.dropoff_label ? 'border-rose-400 bg-rose-50 text-rose-700' : ''"
                  >
                    <p class="text-xs font-bold uppercase tracking-wide text-sky-700">Drop-off address locked</p>
                    <p class="mt-1 text-base font-black text-slate-950">{{ form.dropoff_label }}</p>
                    <p class="mt-1 text-sm text-slate-600">{{ form.dropoff_postcode }}</p>
                  </div>
                </div>
              </div>

              <div class="flex items-center justify-between gap-3">
                <button type="button" class="btn-secondary px-5" @click="goBack">Back</button>
                <button type="button" class="btn-primary px-5 py-3" :disabled="addressLookup.pickup.loading || addressLookup.dropoff.loading" @click="goNext">
                  Next
                </button>
              </div>
            </section>
          </template>

            <template v-else-if="currentStep === 2">
              <section class="space-y-4 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm md:p-5">
                <header class="space-y-1">
                  <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700">Movement</p>
                  <h2 class="text-xl font-black text-slate-950">Transport and timing</h2>
                  <p class="text-sm text-slate-600">
                    Choose how the vehicle moves, then add the timings the driver needs to see.
                  </p>
                </header>

                <div class="grid gap-3 rounded-3xl border border-slate-200 bg-slate-50 p-3 md:grid-cols-[1.2fr_1fr] md:items-center">
                  <div class="min-w-0">
                    <p class="text-xs font-black uppercase tracking-wide text-slate-500">Selected transport</p>
                    <p class="mt-1 text-base font-black text-slate-950">{{ selectedTransport.label }}</p>
                  </div>
                  <p class="min-w-0 text-sm leading-6 text-slate-600 md:text-right">
                    {{ selectedTransport.helper }}
                  </p>
                </div>

                <div>
                  <p class="text-sm font-bold text-slate-700">Transport type</p>
                  <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <button
                      v-for="option in transportOptions"
                      :key="option.value"
                      type="button"
                      :class="[
                        'min-w-0 rounded-2xl border p-3 text-left transition hover:-translate-y-0.5 hover:shadow-md',
                        form.transport_type === option.value
                          ? 'border-emerald-300 bg-emerald-50 text-emerald-900 ring-1 ring-emerald-200'
                          : 'border-slate-200 bg-white text-slate-700 hover:border-emerald-200'
                      ]"
                      @click="selectTransport(option.value)"
                    >
                      <span class="block font-black">{{ option.label }}</span>
                      <span class="mt-1 block text-xs leading-5 text-slate-500">{{ option.helper }}</span>
                    </button>
                  </div>
                </div>

                <div class="grid gap-3 md:grid-cols-2">
                  <div
                    class="min-w-0 rounded-3xl border border-slate-200 bg-slate-50 p-3"
                    :class="validationState.pickup_date || validationState.pickup_time ? 'border-rose-400 bg-rose-50' : ''"
                  >
                    <p class="text-xs font-black uppercase tracking-wide text-slate-500">Pickup ready</p>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                      <label class="block min-w-0">
                        <span class="mb-1 block text-[11px] font-bold uppercase tracking-[0.16em] text-slate-500">Date</span>
                        <input
                          v-model="form.pickup_date"
                          type="date"
                          class="block w-full max-w-full min-w-0 box-border rounded-2xl border border-slate-200 bg-white px-3 py-2.5 text-sm shadow-sm outline-none transition focus:border-emerald-300 focus:ring-2 focus:ring-emerald-200"
                          :class="validationState.pickup_date ? 'border-rose-400 bg-rose-50 ring-2 ring-rose-200' : ''"
                        />
                      </label>
                      <label class="block min-w-0">
                        <span class="mb-1 block text-[11px] font-bold uppercase tracking-[0.16em] text-slate-500">Time</span>
                        <select
                          v-model="form.pickup_time"
                          class="block w-full max-w-full min-w-0 box-border rounded-2xl border border-slate-200 bg-white px-3 py-2.5 text-sm shadow-sm outline-none transition focus:border-emerald-300 focus:ring-2 focus:ring-emerald-200"
                          :class="validationState.pickup_time ? 'border-rose-400 bg-rose-50 ring-2 ring-rose-200' : ''"
                        >
                          <option value="">Select time</option>
                          <option v-for="slot in pickupTimeOptions" :key="slot" :value="slot">
                            {{ slot }}
                          </option>
                        </select>
                      </label>
                    </div>
                  </div>

                  <div
                    class="min-w-0 rounded-3xl border border-slate-200 bg-slate-50 p-3"
                    :class="validationState.delivery_date || validationState.delivery_time ? 'border-rose-400 bg-rose-50' : ''"
                  >
                    <p class="text-xs font-black uppercase tracking-wide text-slate-500">Delivery due</p>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                      <label class="block min-w-0">
                        <span class="mb-1 block text-[11px] font-bold uppercase tracking-[0.16em] text-slate-500">Date</span>
                        <input
                          v-model="form.delivery_date"
                          type="date"
                          class="block w-full max-w-full min-w-0 box-border rounded-2xl border border-slate-200 bg-white px-3 py-2.5 text-sm shadow-sm outline-none transition focus:border-emerald-300 focus:ring-2 focus:ring-emerald-200"
                          :class="validationState.delivery_date ? 'border-rose-400 bg-rose-50 ring-2 ring-rose-200' : ''"
                        />
                      </label>
                      <label class="block min-w-0">
                        <span class="mb-1 block text-[11px] font-bold uppercase tracking-[0.16em] text-slate-500">Time</span>
                        <select
                          v-model="form.delivery_time"
                          class="block w-full max-w-full min-w-0 box-border rounded-2xl border border-slate-200 bg-white px-3 py-2.5 text-sm shadow-sm outline-none transition focus:border-emerald-300 focus:ring-2 focus:ring-emerald-200"
                          :class="validationState.delivery_time ? 'border-rose-400 bg-rose-50 ring-2 ring-rose-200' : ''"
                        >
                          <option value="">Select time</option>
                          <option v-for="slot in deliveryTimeOptions" :key="slot" :value="slot">
                            {{ slot }}
                          </option>
                        </select>
                      </label>
                    </div>
                  </div>
                </div>

                <div class="flex items-center justify-between gap-3">
                <button type="button" class="btn-secondary px-5" @click="goBack">Back</button>
                <button type="button" class="btn-primary px-5 py-3" @click="goNext">
                  Next
                </button>
              </div>
            </section>
          </template>

          <template v-else>
            <section class="space-y-5 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
              <header>
                <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700">Payment</p>
                <h2 class="mt-1 text-xl font-black text-slate-950">Price breakdown</h2>
                <p class="mt-1 text-sm text-slate-600">
                  The dealer pays on creation. MotorRelay releases the driver payout after delivery proof is approved.
                </p>
              </header>

              <label class="block">
                <span class="text-sm font-bold text-slate-700">Dealer charge (GBP)</span>
                <input
                  v-model="form.price"
                  type="number"
                  min="0"
                  required
                  placeholder="e.g. 120"
                  class="mt-2 w-full rounded-2xl border px-4 py-3 text-sm"
                  :class="validationState.price ? 'border-rose-400 bg-rose-50 ring-2 ring-rose-200' : ''"
                />
              </label>

              <dl class="grid gap-3">
                <div class="rounded-2xl bg-slate-50 p-4">
                  <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Dealer charge</dt>
                  <dd class="mt-1 text-xl font-black text-slate-950">{{ formatMoney(jobPrice) }}</dd>
                </div>
                <div class="rounded-2xl bg-slate-950 p-4 text-white">
                  <dt class="text-xs font-bold uppercase tracking-wide text-slate-400">Driver receives</dt>
                  <dd class="mt-1 text-2xl font-black">{{ formatMoney(estimatedDriverPayout) }}</dd>
                </div>
              </dl>

              <section
                class="rounded-3xl border border-emerald-200 bg-emerald-50/70 p-5"
                :class="validationState.urgent_fee_ack ? 'border-rose-400 bg-rose-50' : ''"
              >
                <label class="flex items-start gap-3">
                  <input
                    v-model="form.is_urgent"
                    :disabled="!canUseUrgentBoost"
                    type="checkbox"
                    class="mt-1 h-4 w-4 rounded border-emerald-300 text-emerald-600 focus:ring-emerald-500"
                  />
                  <span>
                    <span class="text-sm font-black text-emerald-900">Add urgent boost</span>
                    <p class="mt-1 text-xs leading-5 text-emerald-800">{{ urgentHelperText }}</p>
                  </span>
                </label>

                <div
                  v-if="requiresUrgentAcknowledgement"
                  class="mt-4 rounded-2xl border border-amber-300 bg-amber-50 p-3 text-xs text-amber-800"
                >
                  <p>This boost adds an extra charge for Starter plans.</p>
                  <label class="mt-3 flex items-start gap-2">
                    <input
                      v-model="form.urgent_fee_ack"
                      type="checkbox"
                      class="mt-0.5 h-4 w-4 rounded border-amber-300 text-amber-700 focus:ring-amber-500"
                    />
                    <span>I understand an extra boost fee will be added to this job.</span>
                  </label>
                </div>
                <p v-if="starterUsageInfo && starterUsageInfo.urgentRemaining === 0" class="mt-3 text-xs text-amber-700">
                  Starter urgent boost quota reached for this month.
                </p>
              </section>

              <div class="flex items-center justify-between gap-3">
                <button type="button" class="btn-secondary px-5" @click="goBack">Back</button>
                <button
                  type="submit"
                  class="btn-primary px-5 py-3"
                  :disabled="submitting || (requiresUrgentAcknowledgement && !form.urgent_fee_ack)"
                >
                  <span v-if="submitting">{{ isEdit ? 'Saving...' : 'Opening checkout...' }}</span>
                  <span v-else>{{ isEdit ? 'Save changes' : 'Create and pay' }}</span>
                </button>
              </div>
            </section>
          </template>
        </div>
      </Transition>
    </form>
  </div>
</template>
