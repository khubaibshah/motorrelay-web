<script setup>
import { computed, onMounted, ref, reactive } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { Capacitor } from '@capacitor/core';
import { Geolocation } from '@capacitor/geolocation';
import { fetchJobs, applyForJob, cancelJob, markJobDelivered, sendJobInvoice } from '@/services/jobs';
import api from '@/services/api';
import { useAuthStore } from '@/stores/auth';
import { formatStatusLabel } from '@/utils/statusLabels';
import DriverRunMarketplace from '@/components/jobs/DriverRunMarketplace.vue';
import DealerRunsOverview from '@/components/jobs/DealerRunsOverview.vue';
import ActiveRunsSection from '@/components/jobs/ActiveRunsSection.vue';
import JobActionConfirmDialog from '@/components/jobs/JobActionConfirmDialog.vue';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();

const availableJobs = ref([]);
const activeJobs = ref([]);
const completedJobs = ref([]);
const availableLoading = ref(false);
const activeLoading = ref(false);
const completedLoading = ref(false);
const errorMessage = ref('');
const activeErrorMessage = ref('');
const completedErrorMessage = ref('');
const successMessage = ref('');
const appliedJobIds = ref(new Set());
const driverLocationQuery = ref(typeof route.query.location === 'string' ? route.query.location : '');
const driverLocationFocused = ref(false);
const driverLocationAutocomplete = ref([]);
const driverLocationAutocompleteLoading = ref(false);
const driverRadius = ref(route.query.radius ? (route.query.radius === 'all' ? 'all' : Number(route.query.radius)) : 'all');
const driverLocation = reactive({
  latitude: null,
  longitude: null,
  enabled: false,
  source: '',
  label: '',
  postcode: '',
  loading: false,
  error: ''
});
const driverMarketplaceNearbyActive = ref(false);
const actionState = reactive({
  id: null,
  type: null
});
const confirmDialog = reactive({
  open: false,
  job: null,
  mode: null,
  message: '',
  pending: false,
  note: ''
});

const priceFormatter = new Intl.NumberFormat('en-GB', {
  style: 'currency',
  currency: 'GBP',
  maximumFractionDigits: 0
});
const defaultNearbyRadiusMiles = 25;
let driverLocationAutocompleteTimer = null;

function driverPayoutForJob(job) {
  const storedPayout = Number(job?.driver_payout_amount || 0);
  if (storedPayout > 0) return storedPayout;

  const price = Number(job?.price || 0);
  const storedFee = Number(job?.platform_fee_amount || 0);
  const platformFee = storedFee > 0 ? storedFee : Math.round(price * 0.1 * 100) / 100;

  return Math.max(price - platformFee, 0);
}

function visibleAmountForJob(job) {
  return isDriver.value ? driverPayoutForJob(job) : Number(job?.price ?? 0);
}

async function loadJobs() {
  successMessage.value = '';
  availableLoading.value = true;
  if (showActiveSection.value) {
    activeLoading.value = true;
  } else {
    activeLoading.value = false;
    activeJobs.value = [];
  }
  if (showCompletedSection.value) {
    completedLoading.value = true;
  } else {
    completedLoading.value = false;
    completedJobs.value = [];
  }
  errorMessage.value = '';
  activeErrorMessage.value = '';
  completedErrorMessage.value = '';
  try {
    const availableParams = { scope: 'available' };
    if (isDriver.value && driverHasLocation.value && driverRadius.value !== 'all') {
        availableParams.marketplace = 'nearby';
        availableParams.latitude = driverLocation.latitude;
        availableParams.longitude = driverLocation.longitude;
        availableParams.nearby_radius_miles = activeRadiusMiles.value;
        if (driverLocation.postcode) {
          availableParams.nearby_postcode = driverLocation.postcode;
        }
    } else if (isDriver.value) {
      availableParams.marketplace = 'all';
    }
    const payload = await fetchJobs(availableParams);
    driverMarketplaceNearbyActive.value = Boolean(payload?.marketplace?.nearby_active);
    const rawJobs = Array.isArray(payload?.data) ? payload.data : Array.isArray(payload?.jobs) ? payload.jobs : [];
    const openJobs = rawJobs.filter((job) => String(job.status || '').toLowerCase() === 'open');
    availableJobs.value = openJobs;

    if (isDriver.value) {
      const appliedIds = availableJobs.value
        .filter((job) => job.my_application && job.my_application.status !== 'declined')
        .map((job) => job.id);
      appliedJobIds.value = new Set(appliedIds);
    }
  } catch (error) {
    console.error('Failed to load jobs', error);
    errorMessage.value = 'Unable to load runs right now.';
    availableJobs.value = [];
  } finally {
    availableLoading.value = false;
  }

  if (showActiveSection.value) {
    try {
      const payload = await fetchJobs({ scope: 'current' });
      const rawJobs = Array.isArray(payload?.data) ? payload.data : Array.isArray(payload?.jobs) ? payload.jobs : [];
      activeJobs.value = rawJobs;
    } catch (error) {
    console.error('Failed to load active runs', error);
      activeErrorMessage.value = 'We could not load active runs right now.';
      activeJobs.value = [];
    } finally {
      activeLoading.value = false;
    }
  }

  if (!showCompletedSection.value) {
    return;
  }

  try {
    const payload = await fetchJobs({ scope: 'completed' });
    const rawJobs = Array.isArray(payload?.data) ? payload.data : Array.isArray(payload?.jobs) ? payload.jobs : [];
    completedJobs.value = rawJobs;
  } catch (error) {
    console.error('Failed to load completed runs', error);
    completedErrorMessage.value = 'We could not load completed runs right now.';
    completedJobs.value = [];
  } finally {
    completedLoading.value = false;
  }
}

async function handleApply(job) {
  if (appliedJobIds.value.has(job.id)) return;

  try {
    await applyForJob(job.id);
    appliedJobIds.value = new Set([...appliedJobIds.value, job.id]);
  } catch (error) {
    console.error('Run application failed', error);
    alert(error.response?.data?.message || 'We could not submit your application. Please try again.');
  }
}

function openJob(job) {
  router.push({ name: 'job-detail', params: { id: job.id } });
}

async function submitDriverSearch() {
  driverRunsTab.value = 'available';
  driverLocationFocused.value = false;

  if (driverRadius.value === 'all') {
    clearDriverLocation();
  } else if (isDriver.value && !driverHasLocation.value) {
    await resolveDriverLocationQuery();
  }

  await router.replace({
    name: 'jobs',
    query: driverMarketplaceQuery()
  });
  await loadJobs();
}

async function clearDriverSearch() {
  driverLocationQuery.value = '';
  driverRadius.value = 'all';
  driverLocationAutocomplete.value = [];
  clearDriverLocation();
  driverRunsTab.value = 'available';
  await router.replace({
    name: 'jobs',
    query: driverMarketplaceQuery()
  });
  await loadJobs();
}

async function useDriverCurrentLocation() {
  driverRunsTab.value = 'available';
  driverLocationFocused.value = false;
  driverLocationAutocomplete.value = [];
  driverLocation.loading = true;
  driverLocation.error = '';

  try {
    const position = await getDriverCurrentPosition({
      enableHighAccuracy: true,
      timeout: 12000,
      maximumAge: 60000
    });

    const latitude = Number(position?.coords?.latitude);
    const longitude = Number(position?.coords?.longitude);

    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
      throw new Error('Your phone did not return a usable location.');
    }

    const { data } = await api.get('/postcodes/reverse', {
      params: { latitude, longitude }
    });
    const result = data?.data ?? {};
    const searchLabel = result.outward_code || result.postcode || result.locality || result.label || 'Current location';

    driverLocation.latitude = latitude;
    driverLocation.longitude = longitude;
    driverLocation.enabled = true;
    driverLocation.source = 'gps';
    driverLocation.label = result.locality || result.label || 'Current location';
    driverLocation.postcode = result.outward_code || result.postcode || '';
    driverLocationQuery.value = searchLabel;
    driverRadius.value = driverRadius.value === 'all' ? defaultNearbyRadiusMiles : driverRadius.value;

    await router.replace({
      name: 'jobs',
      query: driverMarketplaceQuery()
    });
    await loadJobs();
  } catch (error) {
    console.warn('Driver current location unavailable', error);
    clearDriverLocation();
    driverLocation.error = currentLocationErrorMessage(error);
  } finally {
    driverLocation.loading = false;
  }
}

async function getDriverCurrentPosition(options = {}) {
  if (Capacitor.isNativePlatform()) {
    const currentPermission = await Geolocation.checkPermissions();

    if (currentPermission.location !== 'granted') {
      const requestedPermission = await Geolocation.requestPermissions({
        permissions: ['location']
      });

      if (requestedPermission.location !== 'granted') {
        throw new Error('Location permission was not granted.');
      }
    }

    return Geolocation.getCurrentPosition(options);
  }

  if (!navigator.geolocation) {
    throw new Error('Geolocation is not supported on this device.');
  }

  return new Promise((resolve, reject) => {
    navigator.geolocation.getCurrentPosition(resolve, reject, options);
  });
}

function currentLocationErrorMessage(error) {
  const message = String(error?.message || '').toLowerCase();

  if (message.includes('permission') || error?.code === 1) {
    return 'Location permission is needed to find runs near you.';
  }

  if (message.includes('location services') || error?.code === 'OS-PLUG-GLOC-0007') {
    return 'Location services are off. Turn them on for MotorRelay and try again.';
  }

  return error?.response?.data?.message || error?.message || 'We could not use your current location.';
}

function driverMarketplaceQuery() {
  return {
    ...(driverLocationQuery.value.trim() ? { location: driverLocationQuery.value.trim() } : {}),
    ...(driverRadius.value !== 'all' ? { radius: driverRadius.value } : {})
  };
}

async function chooseDriverLocationSuggestion(suggestion) {
  driverLocationFocused.value = false;
  driverLocationQuery.value = suggestion.label || suggestion.value || '';

  if (suggestion.placeId) {
    await useDriverPlace(suggestion.placeId, suggestion.label);
    return;
  }

  await useDriverPostcode(suggestion.value || suggestion.label || '');
}

function handleDriverLocationInput() {
  driverLocationFocused.value = true;
  clearDriverLocation();

  if (driverLocationAutocompleteTimer) {
    window.clearTimeout(driverLocationAutocompleteTimer);
  }

  driverLocationAutocompleteTimer = window.setTimeout(loadDriverLocationAutocomplete, 250);
}

async function loadDriverLocationAutocomplete() {
  const query = driverLocationQuery.value.trim();

  if (query.length < 2) {
    driverLocationAutocomplete.value = [];
    return;
  }

  driverLocationAutocompleteLoading.value = true;

  try {
    const { data } = await api.get(`/postcodes/${encodeURIComponent(query)}/addresses`);
    const addresses = Array.isArray(data?.data?.addresses) ? data.data.addresses : [];
    driverLocationAutocomplete.value = addresses.slice(0, 8).map((item) => ({
      label: item.label,
      sublabel: item.secondary,
      placeId: item.id,
      icon: 'place'
    }));
  } catch (error) {
    console.warn('Location autocomplete failed', error);
    driverLocationAutocomplete.value = [];
  } finally {
    driverLocationAutocompleteLoading.value = false;
  }
}

async function resolveDriverLocationQuery() {
  const postcode = driverLocationQuery.value.trim();

  if (!postcode) {
    clearDriverLocation();
    return;
  }

  await useDriverPostcode(postcode);
}

async function useDriverPostcode(postcode) {
  const normalisedPostcode = extractDriverLocationPostcode(postcode) || postcode.trim();

  if (!normalisedPostcode) {
    clearDriverLocation();
    return;
  }

  driverLocation.loading = true;
  driverLocation.error = '';

  try {
    const { data } = await api.get(`/postcodes/${encodeURIComponent(normalisedPostcode)}/coordinates`);
    const result = data?.data ?? {};

    if (!Number.isFinite(Number(result.latitude)) || !Number.isFinite(Number(result.longitude))) {
      throw new Error('No coordinates were returned for that postcode.');
    }

    driverLocation.latitude = Number(result.latitude);
    driverLocation.longitude = Number(result.longitude);
    driverLocation.enabled = true;
    driverLocation.source = 'postcode';
    driverLocation.label = result.label || result.postcode || normalisedPostcode;
    driverLocation.postcode = result.outward_code || result.postcode || normalisedPostcode;
    driverLocationQuery.value = result.outward_code || result.postcode || normalisedPostcode;

    await router.replace({
      name: 'jobs',
      query: driverMarketplaceQuery()
    });
    await loadJobs();
  } catch (error) {
    console.warn('Driver postcode location unavailable', error);
    clearDriverLocation();
    driverLocation.error = error.response?.data?.message || error.message || 'We could not use that postcode.';
  } finally {
    driverLocation.loading = false;
  }
}

function extractDriverLocationPostcode(value) {
  const input = String(value || '').toUpperCase();
  const fullPostcode = input.match(/\b[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}\b/);
  if (fullPostcode?.[0]) {
    return fullPostcode[0].replace(/\s+/, ' ').trim();
  }

  const outwardPostcode = input.match(/\b[A-Z]{1,2}\d[A-Z\d]?\b/);
  return outwardPostcode?.[0]?.trim() || '';
}

async function useDriverPlace(placeId, fallbackLabel = '') {
  if (!placeId) return;

  driverLocation.loading = true;
  driverLocation.error = '';

  try {
    const { data } = await api.get(`/postcodes/places/${encodeURIComponent(placeId)}`);
    const result = data?.data ?? {};

    if (!Number.isFinite(Number(result.latitude)) || !Number.isFinite(Number(result.longitude))) {
      throw new Error('No coordinates were returned for that place.');
    }

    driverLocation.latitude = Number(result.latitude);
    driverLocation.longitude = Number(result.longitude);
    driverLocation.enabled = true;
    driverLocation.source = 'place';
    driverLocation.label = result.label || fallbackLabel;
    driverLocation.postcode = result.postcode || outwardPostcode(fallbackLabel);
    driverLocationQuery.value = result.label || fallbackLabel;
    driverLocationAutocomplete.value = [];

    await router.replace({
      name: 'jobs',
      query: driverMarketplaceQuery()
    });
    await loadJobs();
  } catch (error) {
    console.warn('Driver place location unavailable', error);
    clearDriverLocation();
    driverLocation.error = error.response?.data?.message || error.message || 'We could not use that location.';
  } finally {
    driverLocation.loading = false;
  }
}

function outwardPostcode(value) {
  const postcode = String(value || '').trim().toUpperCase();
  if (!postcode) return '';
  if (postcode.includes(' ')) return postcode.split(' ')[0];
  return postcode.length > 3 ? postcode.slice(0, -3) : postcode;
}

function clearDriverLocation() {
  driverLocation.latitude = null;
  driverLocation.longitude = null;
  driverLocation.enabled = false;
  driverLocation.source = '';
  driverLocation.label = '';
  driverLocation.postcode = '';
  driverMarketplaceNearbyActive.value = false;
}

function jobIsAwaitingLive(job) {
  if (!job?.goes_live_at) return false;
  const date = new Date(job.goes_live_at);
  return !Number.isNaN(date.getTime()) && date.getTime() > Date.now();
}

function formatGoLive(job) {
  if (!jobIsAwaitingLive(job)) return "";
  return new Intl.DateTimeFormat("en-GB", {
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit"
  }).format(new Date(job.goes_live_at));
}

const driverHasLocation = computed(() => Number.isFinite(Number(driverLocation.latitude)) && Number.isFinite(Number(driverLocation.longitude)));
const activeRadiusMiles = computed(() => driverRadius.value === 'all' ? null : Number(driverRadius.value || defaultNearbyRadiusMiles));
const driverMarketplaceLabel = computed(() => {
  if (driverRadius.value === 'all') return 'Showing all open runs';
  if (driverLocation.loading) return 'Finding nearby runs...';
  if (driverHasLocation.value && driverMarketplaceNearbyActive.value) {
    const place = driverLocation.source === 'gps'
      ? 'you'
      : (driverLocation.label || driverLocation.postcode || driverLocationQuery.value);
    return `Showing runs within ${activeRadiusMiles.value} miles of ${place}`;
  }
  if (driverLocation.error) return driverLocation.error;
  return 'Enter a location to search nearby runs, or choose All jobs.';
});
const visibleJobs = computed(() => {
  let jobs = availableJobs.value ?? [];

  if (isDriver.value && driverHasLocation.value && driverRadius.value !== 'all') {
    jobs = jobs.filter((job) => {
      const distance = Number(job?.driver_distance_mi);
      return (Number.isFinite(distance) && distance <= activeRadiusMiles.value) || isPickupPostcodeAreaMatch(job);
    });
  }

  return jobs;
});

function isPickupPostcodeAreaMatch(job) {
  const outward = (driverLocation.postcode || driverLocationQuery.value || '').trim().toUpperCase();
  const pickup = String(job?.pickup_postcode || '').trim().toUpperCase();

  return Boolean(outward && pickup.startsWith(outward));
}

const isDriver = computed(() => auth.role === 'driver');
const isDealer = computed(() => auth.role === 'dealer');
const isAdmin = computed(() => auth.role === 'admin');
const driverRunsTab = ref('available');
const showActiveSection = computed(() => isAdmin.value);
const showCompletedSection = computed(() => isDealer.value);
const selectedDriverJobs = computed(() => {
  return visibleJobs.value;
});
const selectedDriverLoading = computed(() => {
  return availableLoading.value;
});
const selectedDriverError = computed(() => {
  return errorMessage.value;
});
const selectedDriverEmptyMessage = computed(() => {
  return availableEmptyMessage.value;
});
const driverHomeLocation = computed(() => {
  const postcode = auth.user?.postcode || auth.user?.profile?.postcode || '';

  if (!postcode) return null;

  return {
    label: 'Home',
    sublabel: postcode,
    value: postcode,
    icon: 'home'
  };
});
const baseDriverLocationSuggestions = computed(() => [
  ...(driverHomeLocation.value ? [driverHomeLocation.value] : [])
]);
const driverLocationSuggestions = computed(() => {
  if (driverLocationQuery.value.trim().length >= 2) {
    return driverLocationAutocomplete.value;
  }

  return baseDriverLocationSuggestions.value;
});
const dealerPipelineJobs = computed(() => {
  const byId = new Map();
  [...availableJobs.value, ...activeJobs.value, ...completedJobs.value].forEach((job) => {
    if (job?.id) byId.set(job.id, job);
  });

  return [...byId.values()].sort((a, b) => {
    const aTime = new Date(a?.created_at ?? a?.pickup_ready_at ?? 0).getTime();
    const bTime = new Date(b?.created_at ?? b?.pickup_ready_at ?? 0).getTime();
    return bTime - aTime;
  });
});
const dealerJobsProgress = computed(() => {
  if (!isDealer.value) return [];

  const byId = new Map();
  [...(Array.isArray(auth.postedJobs) ? auth.postedJobs : []), ...(Array.isArray(auth.completedJobs) ? auth.completedJobs : [])].forEach((job) => {
    if (job?.id) byId.set(job.id, job);
  });

  return [...byId.values()].sort((a, b) => {
    const aTime = new Date(a?.updated_at ?? a?.created_at ?? 0).getTime();
    const bTime = new Date(b?.updated_at ?? b?.created_at ?? 0).getTime();
    return bTime - aTime;
  });
});
const dealerJobsSearch = ref('');
const dealerJobsStatusFilter = ref('all');
const dealerJobsPaymentFilter = ref('all');
const showAllDealerRuns = ref(false);
const filteredDealerJobs = computed(() => {
  const query = dealerJobsSearch.value.trim().toLowerCase();
  return dealerJobsProgress.value.filter((job) => {
    const status = String(job?.status || '').toLowerCase();
    const payment = String(job?.payment_status || 'unpaid').toLowerCase();

    if (dealerJobsStatusFilter.value !== 'all' && status !== dealerJobsStatusFilter.value) {
      return false;
    }

    if (dealerJobsPaymentFilter.value !== 'all' && payment !== dealerJobsPaymentFilter.value) {
      return false;
    }

    if (!query) return true;

    const haystack = [
      job?.title,
      job?.pickup_postcode,
      job?.dropoff_postcode,
      job?.pickup_label,
      job?.dropoff_label,
      job?.status,
      job?.payment_status,
      dealerCurrentStage(job),
      dealerMovingTo(job),
      paymentLabel(job),
      job?.assigned_to?.name,
      job?.driver_name
    ]
      .filter(Boolean)
      .join(' ')
      .toLowerCase();

    return haystack.includes(query);
  });
});
const dealerPreviewJobs = computed(() => dealerJobsProgress.value.slice(0, 2));
const displayedDealerJobs = computed(() => (showAllDealerRuns.value ? filteredDealerJobs.value : dealerPreviewJobs.value));
const dealerRunStats = computed(() => {
  const jobs = dealerJobsProgress.value;
  const openStatuses = ['open', 'pending'];
  const inProgressStatuses = ['accepted', 'in_progress', 'collected', 'in_transit', 'completion_pending', 'delivered'];

  return [
    {
      label: 'Open',
      value: jobs.filter((job) => openStatuses.includes(String(job?.status || '').toLowerCase())).length
    },
    {
      label: 'Active',
      value: jobs.filter((job) => inProgressStatuses.includes(String(job?.status || '').toLowerCase())).length
    },
    {
      label: 'Needs payment',
      value: jobs.filter((job) => ['unpaid', 'checkout_pending'].includes(String(job?.payment_status || 'unpaid').toLowerCase())).length
    }
  ];
});
const mainJobs = computed(() => {
  if (isDealer.value) return [...availableJobs.value, ...activeJobs.value];
  return activeJobs.value;
});
const pageIntro = computed(() => {
  if (isDriver.value) {
    return {
      eyebrow: 'Driver runs',
      title: 'Find and manage runs',
      text: 'Request open runs, wait for dealer assignment, then complete delivery and proof.'
    };
  }

  if (isDealer.value) {
    return {
      eyebrow: 'Dealer runs',
      title: 'Runs command centre',
      text: 'Post runs, pay upfront, choose drivers, review inspection photos, and release payout.'
    };
  }

  return {
    eyebrow: 'Marketplace',
    title: 'Runs',
    text: 'Review, request, assign, and complete MotorRelay runs.'
  };
});
const processSteps = computed(() => {
  if (isDriver.value) {
    return ['Request run', 'Dealer assigns driver', 'Upload inspection photos', 'Deliver vehicle'];
  }

  if (isDealer.value) {
    return ['Post run', 'Pay upfront', 'Choose driver', 'Approve inspection', 'Release payout'];
  }

  return ['Create or request', 'Assign driver', 'Track delivery', 'Close paperwork'];
});
const activeEmptyMessage = computed(() => {
  if (isDriver.value) return 'No assigned runs yet. Browse available runs below and request one when you are ready.';
  if (isDealer.value) return 'No dealer runs yet. Create a run to start receiving driver requests.';
  return 'No active runs right now.';
});
const availableEmptyMessage = computed(() => {
  if (isDriver.value && driverHasLocation.value && driverRadius.value !== 'all') {
    return `No runs within ${activeRadiusMiles.value} miles right now. Increase the radius or choose All jobs.`;
  }
  if (isDriver.value) return 'No open runs right now. Check back later or ask a dealer to post a run.';
  if (isDealer.value) return 'No open runs visible. Create a run to start receiving driver requests.';
  return 'No runs here yet.';
});

function hasApplied(jobId) {
  return appliedJobIds.value.has(jobId);
}

function dealerNextAction(job) {
  const status = String(job?.status || '').toLowerCase();
  const paymentStatus = String(job?.payment_status || 'unpaid').toLowerCase();
  const completionStatus = String(job?.completion_status || 'not_submitted').toLowerCase();

  if (paymentStatus === 'unpaid') return 'Take payment';
  if (paymentStatus === 'checkout_pending') return 'Refresh payment';
  if (!job?.assigned_to_id) return 'Review driver requests';
  if (completionStatus === 'submitted') return 'Approve completion';
  if (paymentStatus === 'paid' && completionStatus === 'approved' && !job?.stripe_transfer_id) return 'Release driver payout';
  if (paymentStatus === 'payout_released') return 'Paid out';
  if (['in_progress', 'accepted', 'collected', 'in_transit'].includes(status)) return 'Track delivery';
  return 'View run';
}

function dealerCurrentStage(job) {
  const status = String(job?.status || '').toLowerCase();
  const paymentStatus = String(job?.payment_status || 'unpaid').toLowerCase();
  const completionStatus = String(job?.completion_status || 'not_submitted').toLowerCase();

  if (paymentStatus === 'payout_released') return 'Payout released';
  if (completionStatus === 'approved' || status === 'completed') return 'Approved';
  if (completionStatus === 'submitted') return 'Completion submitted';
  if (['delivered', 'completion_pending'].includes(status)) return 'Delivered';
  if (paymentStatus === 'paid') return 'Payment held';
  if (paymentStatus === 'checkout_pending') return 'Payment pending';
  if (!['paid', 'payout_released'].includes(paymentStatus)) return 'Awaiting payment';
  if (job?.assigned_to_id) return 'Driver assigned';
  return 'Open for requests';
}

function dealerMovingTo(job) {
  const status = String(job?.status || '').toLowerCase();
  const paymentStatus = String(job?.payment_status || 'unpaid').toLowerCase();
  const completionStatus = String(job?.completion_status || 'not_submitted').toLowerCase();

  if (paymentStatus === 'payout_released') return 'Complete';
  if (completionStatus === 'approved') return job?.stripe_transfer_id ? 'Complete' : 'Release payout';
  if (completionStatus === 'submitted') return 'Approve completion';
  if (paymentStatus === 'unpaid') return 'Take payment';
  if (paymentStatus === 'checkout_pending') return 'Confirm payment';
  if (!job?.assigned_to_id) return 'Choose driver';
  if (['accepted', 'in_progress'].includes(status) && !job?.delivery_proof_path) return 'Upload inspection';
  return 'Track delivery';
}

function paymentLabel(job) {
  return formatStatusLabel(job?.payment_status, 'Unpaid');
}

function statusClass(job) {
  const status = String(job?.status || '').toLowerCase();
  if (status === 'open') return 'bg-emerald-100 text-emerald-700';
  if (['in_progress', 'accepted', 'collected', 'in_transit'].includes(status)) return 'bg-sky-100 text-sky-700';
  if (['completion_pending', 'delivered'].includes(status)) return 'bg-amber-100 text-amber-700';
  if (['completed', 'closed'].includes(status)) return 'bg-slate-900 text-white';
  return 'bg-slate-100 text-slate-700';
}

function paymentClass(job) {
  const status = String(job?.payment_status || 'unpaid').toLowerCase();
  if (status === 'paid') return 'bg-emerald-100 text-emerald-700';
  if (status === 'payout_released') return 'bg-slate-900 text-white';
  if (status === 'checkout_pending') return 'bg-amber-100 text-amber-700';
  return 'bg-rose-100 text-rose-700';
}

function canEditDealerJob(job) {
  if (!isDealer.value) return false;
  const status = String(job?.status || '').toLowerCase();
  return !job?.assigned_to_id && ['open', 'pending'].includes(status);
}

function isActionPending(jobId, type) {
  return actionState.id === jobId && actionState.type === type;
}

async function handleCancelJob(job) {
  openConfirmDialog(job, 'cancel');
}

async function handleMarkDelivered(job) {
  openConfirmDialog(job, 'deliver');
}

function openConfirmDialog(job, mode) {
  confirmDialog.job = job;
  confirmDialog.mode = mode;
  confirmDialog.open = true;
  confirmDialog.pending = false;

  if (mode === 'deliver') {
    confirmDialog.message = 'Mark this run as delivered? The dealer will be notified.';
  } else if (mode === 'invoice') {
    confirmDialog.message = 'Send the invoice for this run to the dealer? They will be able to download it immediately.';
  } else {
      confirmDialog.message = isDriver.value
      ? 'Cancel this run? It will return to the board for other drivers. Add an optional note below.'
      : 'Cancel this run? This will withdraw the run from the assigned driver and close it. Add an optional note below.';
  }

  confirmDialog.note = '';
}

async function confirmAction() {
  if (!confirmDialog.job || !confirmDialog.mode) return;

  confirmDialog.pending = true;
  actionState.id = confirmDialog.job.id;
  actionState.type = confirmDialog.mode;

  try {
    let message = '';
    if (confirmDialog.mode === 'deliver') {
      await markJobDelivered(confirmDialog.job.id);
      message = 'Run marked as delivered.';
    } else if (confirmDialog.mode === 'invoice') {
      await sendJobInvoice(confirmDialog.job.id);
      message = 'Invoice sent to the dealer.';
    } else {
      const payload = confirmDialog.note ? { reason: confirmDialog.note } : {};
      await cancelJob(confirmDialog.job.id, payload);
      message = 'Run cancelled.';
    }
    await loadJobs();
    successMessage.value = message;
    closeConfirmDialog();
  } catch (error) {
    console.error('Run action failed', error);
    alert(error.response?.data?.message || 'We could not complete this action. Please try again.');
    confirmDialog.pending = false;
  } finally {
    actionState.id = null;
    actionState.type = null;
  }
}

function closeConfirmDialog() {
  confirmDialog.open = false;
  confirmDialog.job = null;
  confirmDialog.mode = null;
  confirmDialog.message = '';
  confirmDialog.note = '';
  confirmDialog.pending = false;
}

function formatTransportType(value) {
  const normalized = (value || '').toString().toLowerCase();
  if (normalized === 'trailer') {
    return 'Trailer';
  }
  if (normalized === 'drive_away') {
    return 'Drive-away';
  }
  if (!value) {
    return '--';
  }
  return value;
}

function formatDriverDistance(job) {
  const distance = Number(job?.driver_distance_mi);

  if (!Number.isFinite(distance)) {
    return null;
  }

  if (distance < 1) {
    return 'Pickup under 1 mi away';
  }

  return `Pickup ${distance.toFixed(distance >= 10 ? 0 : 1)} mi away`;
}

onMounted(async () => {
  if ((!auth.user || auth.role === 'dealer') && auth.token) {
    await auth.fetchMe().catch(() => null);
  }

  if (auth.role === 'driver' && driverLocationQuery.value.trim()) {
    await resolveDriverLocationQuery();
    await loadJobs();
    return;
  }

  await loadJobs();
});
</script>

<template>
  <div class="space-y-5">
    <div
      v-if="!isDealer && !isDriver"
      class="section-card overflow-hidden"
    >
      <div class="flex flex-col justify-between gap-4 md:flex-row md:items-center">
        <div>
          <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700">{{ pageIntro.eyebrow }}</p>
          <h1 class="mt-2 text-2xl font-black tracking-tight text-slate-950 sm:text-3xl">{{ pageIntro.title }}</h1>
          <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-600">{{ pageIntro.text }}</p>
        </div>

        <div class="grid w-full gap-2 sm:flex sm:w-auto sm:flex-wrap sm:items-center">
          <RouterLink
            v-if="auth.isDealer || auth.role === 'admin'"
            to="/jobs/new"
            class="btn-primary w-full sm:w-auto"
          >
            Create run
          </RouterLink>
        </div>
      </div>
    </div>

    <DealerRunsOverview
      v-if="isDealer"
      v-model:show-all="showAllDealerRuns"
      v-model:search="dealerJobsSearch"
      v-model:status-filter="dealerJobsStatusFilter"
      v-model:payment-filter="dealerJobsPaymentFilter"
      :jobs="displayedDealerJobs"
      :stats="dealerRunStats"
      :loading="activeLoading"
      :total-jobs="dealerJobsProgress.length"
      @open-job="openJob"
    />
    <div class="flex flex-col gap-4">
    <p v-if="errorMessage" class="text-sm text-amber-600">{{ errorMessage }}</p>


    <section v-if="isAdmin" class="section-card">
      <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <h2 class="text-lg font-semibold text-slate-900">Simple workflow</h2>
          <p class="text-sm text-slate-600">These are the main steps for your role.</p>
        </div>
        <ol class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
          <li
            v-for="step in processSteps"
            :key="step"
            class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-bold text-slate-700"
          >
            {{ step }}
          </li>
        </ol>
      </div>
    </section>

    <p
      v-if="successMessage"
      class="rounded-2xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700"
    >
      {{ successMessage }}
    </p>

    <DriverRunMarketplace
      v-if="isDriver"
      v-model:query="driverLocationQuery"
      v-model:radius="driverRadius"
      v-model:focused="driverLocationFocused"
      :jobs="selectedDriverJobs"
      :suggestions="driverLocationSuggestions"
      :suggestions-loading="driverLocationAutocompleteLoading"
      :loading="selectedDriverLoading"
      :error="selectedDriverError"
      :empty-message="selectedDriverEmptyMessage"
      :marketplace-label="driverMarketplaceLabel"
      :applied-job-ids="appliedJobIds"
      :location-loading="driverLocation.loading"
      @search="submitDriverSearch"
      @input="handleDriverLocationInput"
      @clear="clearDriverSearch"
      @choose-suggestion="chooseDriverLocationSuggestion"
      @use-current-location="useDriverCurrentLocation"
      @open-job="openJob"
      @apply="handleApply"
    />

    <div v-if="availableLoading && !activeLoading && !isDriver" class="rounded-2xl border bg-white p-4 text-sm text-slate-600">
      Loading runs&hellip;
    </div>

    <ActiveRunsSection
      v-if="showActiveSection && !isDriver"
      :jobs="mainJobs"
      :loading="activeLoading"
      :error="activeErrorMessage"
      :empty-message="activeEmptyMessage"
      :action-state="actionState"
      @open-job="openJob"
      @cancel-job="handleCancelJob"
      @mark-delivered="handleMarkDelivered"
    />

    <section v-if="isAdmin" class="section-card order-2 space-y-4">
      <header class="flex items-center justify-between gap-3" v-if="isDriver">
        <h2 class="text-xl font-black tracking-tight text-slate-950">Available runs</h2>
        <span class="text-xs font-semibold text-slate-500">
          {{ visibleJobs.length }} listed
        </span>
      </header>

      <div v-if="availableLoading && !visibleJobs.length" class="rounded-2xl border bg-white p-4 text-sm text-slate-600">
        Loading runs...
      </div>

      <div v-else-if="!visibleJobs.length" class="rounded-2xl border bg-white p-4 text-sm text-slate-600">
        {{ availableEmptyMessage }}
      </div>

      <div v-else class="space-y-4">
        <button
          v-for="job in visibleJobs"
          :key="job.id"
          type="button"
          class="w-full cursor-pointer rounded-3xl border border-slate-200 bg-white p-4 text-left shadow-sm transition hover:-translate-y-1 hover:border-emerald-200 hover:shadow-xl sm:p-5"
          @click="openJob(job)"
        >
          <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
              <p v-if="isDriver" class="text-xs font-black uppercase tracking-wide text-slate-500">Driver payout</p>
              <div class="text-2xl font-black text-slate-950">
                {{ priceFormatter.format(visibleAmountForJob(job)) }}
              </div>
              <p class="text-sm text-slate-600">
                {{ job.company || 'Customer' }} - {{ job.vehicle_make || 'Vehicle' }}
              </p>
              <p class="text-xs text-slate-500">
                {{ job.pickup_postcode || '--' }} to {{ job.dropoff_postcode || '--' }}
              </p>
              <p class="text-xs text-slate-500">
                Transport: {{ formatTransportType(job.transport_type) }}
              </p>
            </div>
            <span
              class="badge bg-slate-100 text-slate-800"
            >
              {{ formatStatusLabel(job.status) }}
            </span>
          </div>

          <div class="mt-3 grid gap-2 sm:flex sm:flex-wrap">
            <button
              v-if="isDriver"
              type="button"
              class="btn-primary w-full px-4 py-2 text-sm disabled:opacity-60 sm:w-auto"
              :disabled="hasApplied(job.id)"
              @click.stop="handleApply(job)"
            >
              <span v-if="hasApplied(job.id)">Application sent</span>
            <span v-else>Request this run</span>
            </button>
          </div>
        </button>
      </div>
    </section>
    </div>

  </div>

  <JobActionConfirmDialog
    v-model:note="confirmDialog.note"
    :open="confirmDialog.open"
    :mode="confirmDialog.mode"
    :message="confirmDialog.message"
    :pending="confirmDialog.pending"
    @close="closeConfirmDialog"
    @confirm="confirmAction"
  />
</template>
