<script setup>
import { computed, onMounted, ref, reactive } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { fetchJobs, applyForJob, cancelJob, markJobDelivered, sendJobInvoice } from '@/services/jobs';
import api from '@/services/api';
import { useAuthStore } from '@/stores/auth';
import { formatStatusLabel } from '@/utils/statusLabels';
import { Geolocation } from '@capacitor/geolocation';
import { Capacitor } from '@capacitor/core';

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
const driverRadius = ref(route.query.radius === 'all' ? 'all' : Number(route.query.radius || 25));
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
const driverLocationPermissionBlocked = ref(false);
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
  } else if (isDriver.value && isCurrentLocationQuery(driverLocationQuery.value)) {
    await useDriverLocation();
    return;
  } else if (isDriver.value) {
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
  driverRadius.value = defaultNearbyRadiusMiles;
  driverLocationAutocomplete.value = [];
  clearDriverLocation();
  driverRunsTab.value = 'available';
  await router.replace({
    name: 'jobs',
    query: driverMarketplaceQuery()
  });
  await loadJobs();
}

function driverMarketplaceQuery() {
  return {
    ...(driverLocationQuery.value.trim() ? { location: driverLocationQuery.value.trim() } : {}),
    ...(driverRadius.value !== defaultNearbyRadiusMiles ? { radius: driverRadius.value } : {})
  };
}

async function useDriverLocation() {
  driverLocationQuery.value = 'Current Location';
  driverLocationFocused.value = false;
  driverLocationAutocomplete.value = [];
  if (driverRadius.value === 'all') {
    driverRadius.value = defaultNearbyRadiusMiles;
  }

  await ensureDriverLocation({ force: true });

  if (!driverHasLocation.value) {
    return;
  }

  await router.replace({
    name: 'jobs',
    query: driverMarketplaceQuery()
  });
  await loadJobs();
}

async function chooseDriverLocationSuggestion(suggestion) {
  if (suggestion.current) {
    await useDriverLocation();
    return;
  }

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

  if (isCurrentLocationQuery(postcode)) {
    await useDriverLocation();
    return;
  }

  await useDriverPostcode(postcode);
}

function isCurrentLocationQuery(value) {
  return ['current location', 'current', 'my location', 'near me'].includes(String(value || '').trim().toLowerCase());
}

async function useDriverPostcode(postcode) {
  const normalisedPostcode = postcode.trim();

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
  driverLocationPermissionBlocked.value = false;
}

async function getDriverCurrentPosition(options = {}) {
  if (Capacitor.isNativePlatform()) {
    driverLocationPermissionBlocked.value = false;
    const currentPermission = await Geolocation.checkPermissions();

    if (!locationPermissionGranted(currentPermission)) {
      const requestedPermission = await Geolocation.requestPermissions({
        permissions: ['location']
      });

      if (!locationPermissionGranted(requestedPermission)) {
        driverLocationPermissionBlocked.value = true;
        throw new Error('Location permission is blocked. Allow location for MotorRelay in iPhone Settings, then try again.');
      }
    }

    return Geolocation.getCurrentPosition(options);
  }

  if (!window.isSecureContext) {
    throw new Error('Location only works on a secure website. Open MotorRelay using the https:// address and try again.');
  }

  if (navigator.permissions?.query) {
    try {
      const permission = await navigator.permissions.query({ name: 'geolocation' });

      if (permission.state === 'denied') {
        driverLocationPermissionBlocked.value = true;
        throw new Error('Location permission is blocked for this website. Allow location for MotorRelay in your browser settings, then try again.');
      }
    } catch (error) {
      if (driverLocationPermissionBlocked.value) {
        throw error;
      }
    }
  }

  return new Promise((resolve, reject) => {
    if (!navigator.geolocation) {
      reject(new Error('Location is not supported in this browser.'));
      return;
    }

    navigator.geolocation.getCurrentPosition(resolve, (error) => {
      if (error?.code === 1) {
        driverLocationPermissionBlocked.value = true;
        reject(new Error('Location permission was denied. Allow location for MotorRelay in your browser settings, then try again.'));
        return;
      }

      if (error?.code === 2) {
        reject(new Error('Your current location is unavailable. Check Location Services and try again.'));
        return;
      }

      if (error?.code === 3) {
        reject(new Error('Finding your location took too long. Try again or search by postcode.'));
        return;
      }

      reject(error);
    }, options);
  });
}

function locationPermissionGranted(permission) {
  return ['granted', 'limited'].includes(permission?.location)
    || ['granted', 'limited'].includes(permission?.coarseLocation);
}

async function ensureDriverLocation({ force = false } = {}) {
  if (driverLocation.loading) {
    return;
  }

  if (!force && !driverLocation.enabled) {
    return;
  }

  if (driverHasLocation.value) {
    return;
  }

  driverLocation.loading = true;
  driverLocation.error = '';

  try {
    const position = await getDriverCurrentPosition({
      enableHighAccuracy: true,
      timeout: 15000,
      maximumAge: 60000
    });

    driverLocation.latitude = position.coords.latitude;
    driverLocation.longitude = position.coords.longitude;
    driverLocation.enabled = true;
    driverLocation.source = 'gps';
    driverLocation.label = 'your phone location';
    driverLocation.postcode = '';
    driverLocationPermissionBlocked.value = false;
  } catch (error) {
    console.warn('Driver marketplace location unavailable', error);
    const wasPermissionBlocked = driverLocationPermissionBlocked.value;
    clearDriverLocation();
    driverLocationPermissionBlocked.value = wasPermissionBlocked;
    driverLocationQuery.value = 'Current Location';
    driverLocation.error = error.message || 'Location services are off. Enable location or search by postcode.';
  } finally {
    driverLocation.loading = false;
  }
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
      return (Number.isFinite(distance) && distance <= activeRadiusMiles.value) || isPostcodeAreaMatch(job);
    });
  }

  return jobs;
});

function isPostcodeAreaMatch(job) {
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
  { label: 'Current Location', value: 'current', icon: 'target', current: true },
  ...(driverHomeLocation.value ? [driverHomeLocation.value] : [])
]);
const driverLocationSuggestions = computed(() => {
  const baseSuggestions = baseDriverLocationSuggestions.value;

  if (driverLocationQuery.value.trim().length >= 2) {
    return [
      baseSuggestions[0],
      ...driverLocationAutocomplete.value,
    ];
  }

  return baseSuggestions;
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
    return 'Under 1 mi away';
  }

  return `${distance.toFixed(distance >= 10 ? 0 : 1)} mi away`;
}

function formatDate(value) {
  if (!value) return '--';
  try {
    return new Intl.DateTimeFormat('en-GB', {
      day: 'numeric',
      month: 'short',
      year: 'numeric'
    }).format(new Date(value));
  } catch {
    return value;
  }
}

function formatShortDate(value) {
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

function resolveInvoiceLink(job) {
  if (!job) return null;
  return (
    job.invoice_download_url ||
    job.invoice_url ||
    job.invoice ||
    job.invoice_pdf ||
    job.invoice_link ||
    null
  );
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

    <section v-if="isDealer" class="section-card space-y-4 dark:border-white/10 dark:bg-slate-950 dark:text-white">
      <header class="space-y-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-300">Dealer operations</p>
          <RouterLink
            to="/jobs/new"
            class="btn-primary w-full px-4 py-2 text-sm sm:w-auto"
          >
            Create run
          </RouterLink>
        </div>

        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <h2 class="text-xl font-black text-slate-950 dark:text-emerald-300">Your runs</h2>
            <p class="mt-2 text-sm text-slate-600 dark:text-emerald-100">
              Keep an eye on your posted jobs, then expand the table when you need the full view.
            </p>
          </div>
          <div class="grid w-full grid-cols-3 gap-2 lg:w-auto">
            <div
              v-for="stat in dealerRunStats"
              :key="stat.label"
              class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-center dark:border-white/10 dark:bg-white/[0.06]"
            >
              <p class="text-base font-black text-slate-950 dark:text-emerald-300">{{ stat.value }}</p>
              <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500 dark:text-emerald-100">{{ stat.label }}</p>
            </div>
          </div>
        </div>
      </header>

      <div v-if="showAllDealerRuns" class="grid w-full gap-3 sm:grid-cols-3">
          <div class="flex flex-col gap-2">
            <label for="dealer-jobs-search" class="text-xs font-black uppercase tracking-[0.16em] text-slate-500 dark:text-emerald-100">
              Search
            </label>
            <input
              id="dealer-jobs-search"
              v-model="dealerJobsSearch"
              type="search"
              placeholder="Title, route, driver..."
              class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100"
            >
          </div>

          <div class="flex flex-col gap-2">
            <label for="dealer-jobs-status" class="text-xs font-black uppercase tracking-[0.16em] text-slate-500 dark:text-emerald-100">
              Status
            </label>
            <select
              id="dealer-jobs-status"
              v-model="dealerJobsStatusFilter"
              class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100"
            >
              <option value="all">All statuses</option>
              <option value="open">Open</option>
              <option value="pending">Pending</option>
              <option value="in_progress">In progress</option>
              <option value="accepted">Accepted</option>
              <option value="collected">Collected</option>
              <option value="in_transit">In transit</option>
              <option value="completion_pending">Completion pending</option>
              <option value="delivered">Delivered</option>
              <option value="completed">Completed</option>
              <option value="closed">Closed</option>
            </select>
          </div>

          <div class="flex flex-col gap-2">
            <label for="dealer-jobs-payment" class="text-xs font-black uppercase tracking-[0.16em] text-slate-500 dark:text-emerald-100">
              Payment
            </label>
            <select
              id="dealer-jobs-payment"
              v-model="dealerJobsPaymentFilter"
              class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100"
            >
              <option value="all">All payments</option>
              <option value="unpaid">Unpaid</option>
              <option value="checkout_pending">Checkout pending</option>
              <option value="paid">Paid</option>
              <option value="payout_released">Payout released</option>
            </select>
          </div>
      </div>

      <div v-if="activeLoading" class="rounded-2xl border bg-white p-4 text-sm text-slate-600 dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100">
        Loading your runs...
      </div>

      <div v-else-if="!displayedDealerJobs.length" class="space-y-3 rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-5 text-sm text-slate-600 dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100">
        <p>{{ showAllDealerRuns ? 'No runs match your search.' : 'No runs yet. Create a run to start receiving driver requests.' }}</p>
        <button
          v-if="showAllDealerRuns"
          type="button"
          class="text-xs font-bold text-emerald-700 hover:text-emerald-800"
          @click="showAllDealerRuns = false"
        >
          Show preview
        </button>
      </div>

      <div v-else class="space-y-4">
        <div class="space-y-3 md:hidden">
          <article
            v-for="job in displayedDealerJobs"
            :key="`mobile-job-${job.id}`"
            class="cursor-pointer rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-white/10 dark:bg-white/[0.06]"
            role="button"
            tabindex="0"
            @click="openJob(job)"
            @keydown.enter="openJob(job)"
            @keydown.space.prevent="openJob(job)"
          >
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="text-base font-black text-slate-950 dark:text-white">{{ job.title || `Run #${job.id}` }}</p>
                <p class="mt-1 text-xs text-slate-500 dark:text-emerald-100">
                  {{ job.pickup_label || job.pickup_postcode || '--' }} to {{ job.dropoff_label || job.dropoff_postcode || '--' }}
                </p>
              </div>
              <span class="badge bg-emerald-100 text-emerald-700">{{ formatStatusLabel(job.status) }}</span>
            </div>

            <div class="mt-3 flex items-center justify-between gap-3">
              <span class="text-xs font-semibold text-slate-500 dark:text-emerald-100">
                {{ formatShortDate(job.updated_at || job.created_at) }}
              </span>
              <a
                v-if="resolveInvoiceLink(job)"
                :href="resolveInvoiceLink(job)"
                target="_blank"
                rel="noreferrer"
                class="inline-flex rounded-full border border-emerald-200 px-3 py-1.5 text-xs font-bold text-emerald-700"
                @click.stop
              >
                Invoice
              </a>
            </div>
          </article>
        </div>

        <div class="hidden overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm md:block dark:border-white/10 dark:bg-white/[0.06]">
        <div :class="showAllDealerRuns ? 'max-h-[34rem] overflow-auto' : ''">
          <table class="min-w-full divide-y divide-slate-200 text-left dark:divide-white/10">
            <thead class="sticky top-0 z-10 bg-slate-50 dark:bg-slate-950">
              <tr class="text-[11px] font-black uppercase tracking-[0.16em] text-slate-500 dark:text-emerald-100">
                <th class="px-4 py-3">Run</th>
                <th class="px-4 py-3">Now</th>
                <th class="px-4 py-3">Payment</th>
                <th class="px-4 py-3">Updated</th>
                <th class="px-4 py-3 text-right">Open</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-white/10">
              <tr
                v-for="job in displayedDealerJobs"
                :key="job.id"
                class="cursor-pointer transition hover:bg-slate-50 dark:hover:bg-white/[0.08]"
                @click="openJob(job)"
              >
                <td class="px-4 py-4 align-top">
                <p class="font-black text-slate-950 dark:text-white">{{ job.title || `Run #${job.id}` }}</p>
                  <p class="mt-1 text-xs text-slate-500 dark:text-emerald-100">
                    {{ job.pickup_label || job.pickup_postcode || '--' }} to {{ job.dropoff_label || job.dropoff_postcode || '--' }}
                  </p>
                  <p class="mt-1 text-xs text-slate-500 dark:text-emerald-100">{{ job.assigned_to?.name || job.driver_name || 'Not assigned yet' }}</p>
                </td>
                <td class="px-4 py-4 align-top">
                  <span class="badge bg-emerald-100 text-emerald-700">{{ formatStatusLabel(job.status) }}</span>
                </td>
                <td class="px-4 py-4 align-top">
                  <span class="badge bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-emerald-100">{{ paymentLabel(job) }}</span>
                </td>
                <td class="px-4 py-4 align-top text-sm text-slate-600 dark:text-emerald-100">
                  {{ formatShortDate(job.updated_at || job.created_at) }}
                </td>
                <td class="px-4 py-4 align-top text-right">
                  <RouterLink
                    :to="`/jobs/${job.id}`"
                    class="inline-flex rounded-full border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-700 hover:border-emerald-200 hover:text-emerald-700 dark:border-white/10 dark:text-emerald-100 dark:hover:border-emerald-300 dark:hover:text-emerald-300"
                    @click.stop
                  >
                    Open
                  </RouterLink>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
          <p v-if="!showAllDealerRuns && dealerJobsProgress.length > displayedDealerJobs.length" class="text-xs text-slate-500 dark:text-emerald-100">
            Showing {{ displayedDealerJobs.length }} of {{ dealerJobsProgress.length }} runs.
          </p>
          <button
            v-if="showAllDealerRuns || dealerJobsProgress.length > displayedDealerJobs.length"
            type="button"
            class="btn-secondary w-full px-4 py-2 text-sm sm:w-auto"
            @click="showAllDealerRuns = !showAllDealerRuns"
          >
            {{ showAllDealerRuns ? 'Show less' : 'View all runs' }}
          </button>
        </div>
      </div>
    </section>
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

    <section v-if="isDriver" class="section-card order-1 space-y-3 p-4 dark:border-white/10 dark:bg-slate-950 sm:p-5">
      <header class="space-y-3">
        <div class="flex items-start justify-between gap-3">
          <div>
            <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-300">Run marketplace</p>
            <h2 class="mt-1 text-lg font-black tracking-tight text-slate-950 dark:text-emerald-300">Available runs</h2>
          </div>
          <span class="w-fit rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600 dark:bg-white/10 dark:text-emerald-100">
            {{ selectedDriverJobs.length }} shown
          </span>
        </div>

        <form class="grid gap-2 rounded-3xl border-2 border-emerald-700/70 bg-white p-3 shadow-sm transition focus-within:border-emerald-500 focus-within:ring-4 focus-within:ring-emerald-100 dark:border-emerald-300/40 dark:bg-white/[0.04] dark:focus-within:ring-emerald-300/10 sm:grid-cols-[minmax(0,1fr)_auto_auto]" @submit.prevent="submitDriverSearch">
          <label class="flex min-w-0 items-center gap-3">
            <span class="text-lg text-slate-700 dark:text-emerald-200">●</span>
            <input
              v-model="driverLocationQuery"
              type="text"
              inputmode="search"
              autocomplete="off"
              placeholder="City, town, or postcode"
              class="min-w-0 flex-1 border-0 bg-transparent py-2 text-sm font-black uppercase text-slate-900 outline-none placeholder:normal-case placeholder:font-semibold placeholder:text-slate-400 dark:text-emerald-100 dark:placeholder:text-emerald-100/40"
              @focus="driverLocationFocused = true"
              @input="handleDriverLocationInput"
            >
            <button
              v-if="driverLocationQuery"
              type="button"
              class="rounded-full px-2 py-1 text-xs font-black text-slate-500 hover:bg-slate-100 hover:text-emerald-700 dark:text-emerald-100 dark:hover:bg-white/10"
              @click="clearDriverSearch"
            >
              Clear
            </button>
          </label>
          <select
            v-model="driverRadius"
            class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-black text-slate-900 outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100 dark:border-white/10 dark:bg-slate-950 dark:text-emerald-100"
            @change="submitDriverSearch"
          >
            <option :value="25">25 mi</option>
            <option :value="50">50 mi</option>
            <option :value="100">100 mi</option>
            <option value="all">All jobs</option>
          </select>
          <button type="submit" class="btn-primary min-h-0 px-4 py-2 text-sm">
            Search
          </button>
        </form>

        <div v-if="driverLocationFocused" class="rounded-3xl border border-slate-200 bg-white p-2 shadow-sm dark:border-white/10 dark:bg-white/[0.04]">
          <p v-if="driverLocationAutocompleteLoading" class="px-3 py-2 text-xs font-bold text-slate-500 dark:text-emerald-100">
            Finding places...
          </p>
          <button
            v-for="suggestion in driverLocationSuggestions"
            :key="`${suggestion.label}-${suggestion.value}`"
            type="button"
            class="flex w-full items-center gap-3 rounded-2xl px-3 py-2 text-left transition hover:bg-emerald-50 dark:hover:bg-emerald-300/10"
            @click="chooseDriverLocationSuggestion(suggestion)"
          >
            <span class="grid size-8 place-items-center rounded-full bg-slate-100 text-sm text-slate-700 dark:bg-slate-900 dark:text-emerald-100">
              {{ suggestion.icon === 'target' ? '⌾' : suggestion.icon === 'home' ? '⌂' : '⌖' }}
            </span>
            <span class="min-w-0">
              <span class="block truncate text-sm font-black" :class="suggestion.current ? 'text-emerald-700 dark:text-emerald-300' : 'text-slate-900 dark:text-white'">
                {{ suggestion.current && driverLocation.loading ? 'Asking for location...' : suggestion.label }}
              </span>
              <span v-if="suggestion.sublabel" class="block truncate text-xs font-semibold text-slate-500 dark:text-emerald-100">
                {{ suggestion.sublabel }}
              </span>
            </span>
          </button>
          <p v-if="!driverLocationAutocompleteLoading && driverLocationQuery.trim().length >= 2 && !driverLocationSuggestions.length" class="px-3 py-2 text-xs font-bold text-slate-500 dark:text-emerald-100">
            No places found. Try a postcode like BB9 or a nearby town.
          </p>
        </div>

        <p class="px-1 text-xs font-bold text-slate-500 dark:text-emerald-100">
          {{ driverMarketplaceLabel }}
        </p>
        <div
          v-if="driverLocationPermissionBlocked"
          class="rounded-2xl border border-amber-200 bg-amber-50 p-3 text-xs font-bold text-amber-800 dark:border-amber-300/30 dark:bg-amber-300/10 dark:text-amber-100"
        >
          Location permission is blocked. Open iPhone Settings, allow MotorRelay location access, or search by postcode instead.
        </div>
      </header>

      <p v-if="selectedDriverError" class="rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-700 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-200">
        {{ selectedDriverError }}
      </p>

      <div v-if="selectedDriverLoading && !selectedDriverJobs.length" class="rounded-2xl border bg-white p-4 text-sm text-slate-600 dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100">
        Loading runs...
      </div>

      <div v-else-if="!selectedDriverJobs.length" class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-600 dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100">
        {{ selectedDriverEmptyMessage }}
      </div>

      <div v-else class="space-y-2">
        <article
          v-for="job in selectedDriverJobs"
          :key="`${driverRunsTab}-${job.id}`"
          class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-200 hover:shadow-xl dark:border-white/10 dark:bg-white/[0.06] dark:hover:bg-white/[0.09]"
        >
          <div class="flex items-start justify-between gap-3">
            <button type="button" class="min-w-0 flex-1 text-left" @click="openJob(job)">
              <p class="truncate text-base font-black text-slate-950 dark:text-white">
                {{ job.pickup_postcode || job.pickup_label || '--' }} to {{ job.dropoff_postcode || job.dropoff_label || '--' }}
              </p>
              <p class="mt-1 truncate text-xs font-semibold text-slate-600 dark:text-emerald-100">
                {{ job.company || 'Customer' }} · {{ job.vehicle_make || 'Vehicle' }} · {{ formatTransportType(job.transport_type) }}
              </p>
              <p v-if="formatDriverDistance(job)" class="mt-1 text-xs font-black text-emerald-700 dark:text-emerald-300">
                {{ formatDriverDistance(job) }}
              </p>
            </button>

            <div class="shrink-0 text-right">
              <p class="text-lg font-black text-emerald-600 dark:text-emerald-300">
                {{ priceFormatter.format(visibleAmountForJob(job)) }}
              </p>
              <span class="mt-1 inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-black uppercase tracking-wide text-slate-600 dark:bg-white/10 dark:text-emerald-100">
                {{ formatStatusLabel(job.status) }}
              </span>
            </div>
          </div>

          <div class="mt-3 flex gap-2">
            <button
              v-if="driverRunsTab === 'available'"
              type="button"
              class="btn-primary min-h-0 flex-1 px-3 py-2 text-xs disabled:opacity-60"
              :disabled="hasApplied(job.id)"
              @click.stop="handleApply(job)"
            >
              <span v-if="hasApplied(job.id)">Application sent</span>
              <span v-else>Request this run</span>
            </button>
            <button
              type="button"
              class="btn-secondary min-h-0 flex-1 px-3 py-2 text-xs"
              @click="openJob(job)"
            >
              View details
            </button>
            <button
              v-if="driverRunsTab === 'active'"
              type="button"
              class="btn-primary w-full px-3 py-2 text-xs disabled:opacity-60 sm:w-auto"
              :disabled="isActionPending(job.id, 'deliver')"
              @click="handleMarkDelivered(job)"
            >
              <span v-if="isActionPending(job.id, 'deliver')">Updating...</span>
              <span v-else>Mark as delivered</span>
            </button>
            <button
              v-if="driverRunsTab === 'active'"
              type="button"
              class="btn-secondary w-full px-4 py-2 text-sm disabled:opacity-60 sm:w-auto"
              :disabled="isActionPending(job.id, 'cancel')"
              @click="handleCancelJob(job)"
            >
              <span v-if="isActionPending(job.id, 'cancel')">Cancelling...</span>
              <span v-else>Cancel run</span>
            </button>
          </div>
        </article>
      </div>
    </section>

    <div v-if="availableLoading && !activeLoading && !isDriver" class="rounded-2xl border bg-white p-4 text-sm text-slate-600">
      Loading runs&hellip;
    </div>

    <section v-if="showActiveSection && !isDriver" class="section-card order-1 space-y-4">
      <header class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <h2 class="text-lg font-black text-slate-950">
          {{ isDriver ? 'Your active runs' : 'Active runs' }}
        </h2>
        <span v-if="isDealer" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">
          {{ mainJobs.length }} active
        </span>
        <RouterLink v-if="isDriver" to="/driver" class="text-xs font-semibold text-emerald-600 hover:underline">
          Driver dashboard
        </RouterLink>
      </header>

      <p v-if="activeErrorMessage" class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-700">
        {{ activeErrorMessage }}
      </p>

      <div v-if="activeLoading" class="rounded-2xl border bg-white p-4 text-sm text-slate-600">
        Loading your active runs...
      </div>
      <div v-else-if="!mainJobs.length" class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
        {{ activeEmptyMessage }}
      </div>
      <div v-else class="space-y-4">
        <article
          v-for="job in mainJobs"
          :key="`active-${job.id}`"
          class="rounded-3xl border p-4 transition hover:-translate-y-0.5 hover:shadow-xl sm:p-5"
          :class="isDealer ? 'border-slate-200 bg-white shadow-sm' : 'border-slate-200 bg-slate-50/80 hover:bg-white'"
        >
          <div v-if="isDealer" class="space-y-4">
            <div class="flex flex-wrap items-center gap-2">
              <span class="badge" :class="statusClass(job)">{{ formatStatusLabel(job.status) }}</span>
              <span class="badge" :class="paymentClass(job)">{{ paymentLabel(job) }}</span>
            </div>

            <div>
              <p class="text-lg font-black text-slate-950">
              {{ job.title || `Run #${job.id}` }}
              </p>
              <p class="mt-1 text-sm text-slate-600">
                {{ job.pickup_label || job.pickup_postcode || '--' }} → {{ job.dropoff_label || job.dropoff_postcode || '--' }}
              </p>
              <p class="mt-1 text-xs text-slate-500">
                Updated {{ formatShortDate(job.updated_at || job.created_at) }}
              </p>
            </div>

            <div class="flex flex-wrap gap-2">
              <button
                type="button"
                class="btn-primary w-full px-4 py-2 text-sm sm:w-auto"
                @click="openJob(job)"
              >
                Manage run
              </button>
              <RouterLink
                v-if="canEditDealerJob(job)"
                :to="`/jobs/${job.id}/edit`"
                class="btn-secondary w-full px-4 py-2 text-sm sm:w-auto"
              >
                Edit
              </RouterLink>
              <button
                type="button"
                class="btn-secondary w-full px-4 py-2 text-sm disabled:opacity-60 sm:w-auto"
                :disabled="isActionPending(job.id, 'cancel')"
                @click="handleCancelJob(job)"
              >
                <span v-if="isActionPending(job.id, 'cancel')">Cancelling...</span>
                <span v-else>Cancel</span>
              </button>
            </div>
          </div>

          <div v-else class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0 flex-1">
              <div class="mb-3 flex flex-wrap gap-2">
                <span class="badge" :class="statusClass(job)">{{ formatStatusLabel(job.status) }}</span>
                <span class="badge" :class="paymentClass(job)">{{ paymentLabel(job) }}</span>
                <span class="badge bg-slate-100 text-slate-700">{{ formatTransportType(job.transport_type) }}</span>
              </div>

              <p class="text-xl font-black text-slate-950">
                {{ job.title || `Run #${job.id}` }}
              </p>

              <div class="mt-3 grid gap-3 text-sm sm:grid-cols-3">
                <div class="rounded-2xl bg-slate-50 p-3">
                  <p class="text-xs font-black uppercase tracking-wide text-slate-500">Route</p>
                  <p class="mt-1 font-semibold text-slate-800">{{ job.pickup_postcode || '--' }} to {{ job.dropoff_postcode || '--' }}</p>
                </div>
                <div class="rounded-2xl bg-slate-50 p-3">
                  <p class="text-xs font-black uppercase tracking-wide text-slate-500">Driver</p>
                  <p class="mt-1 font-semibold text-slate-800">
                    <template v-if="isDriver">
                      {{ job.posted_by?.name || 'Dealer' }}
                    </template>
                    <template v-else>
                      {{ job.assigned_to?.name || 'Not assigned yet' }}
                    </template>
                  </p>
                </div>
                <div class="rounded-2xl bg-slate-50 p-3">
                  <p class="text-xs font-black uppercase tracking-wide text-slate-500">Next action</p>
                  <p class="mt-1 font-semibold text-emerald-700">{{ formatStatusLabel(job.status) }}</p>
                </div>
              </div>
            </div>

            <div class="rounded-3xl bg-slate-950 p-4 text-white lg:min-w-[180px] lg:text-right">
              <p class="text-xs font-black uppercase tracking-wide text-slate-400">Driver payout</p>
              <div class="mt-1 text-3xl font-black">
                {{ priceFormatter.format(visibleAmountForJob(job)) }}
              </div>
              <span class="badge mt-3 bg-emerald-100 text-emerald-700">{{ formatStatusLabel(job.status) }}</span>
            </div>
          </div>

          <div v-if="!isDealer" class="mt-4 grid gap-2 sm:flex sm:flex-wrap">
            <button
              type="button"
              class="btn-primary w-full px-4 py-2 text-sm sm:w-auto"
              @click="openJob(job)"
            >
              View details
            </button>
            <RouterLink
              v-if="canEditDealerJob(job)"
              :to="`/jobs/${job.id}/edit`"
              class="btn-secondary w-full px-4 py-2 text-sm sm:w-auto"
            >
              Edit run
            </RouterLink>
            <button
              type="button"
              class="btn-secondary w-full px-4 py-2 text-sm disabled:opacity-60 sm:w-auto"
              :disabled="isActionPending(job.id, 'cancel')"
              @click="handleCancelJob(job)"
            >
              <span v-if="isActionPending(job.id, 'cancel')">Cancelling...</span>
              <span v-else>Cancel run</span>
            </button>
            <button
              v-if="isDriver"
              type="button"
              class="btn-primary w-full px-3 py-2 text-xs disabled:opacity-60 sm:w-auto"
              :disabled="isActionPending(job.id, 'deliver')"
              @click="handleMarkDelivered(job)"
            >
              <span v-if="isActionPending(job.id, 'deliver')">Updating...</span>
              <span v-else>Mark as delivered</span>
            </button>
          </div>
        </article>
      </div>
    </section>

    <section v-if="false" id="completed-jobs" class="section-card order-3 space-y-4">
      <header class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 class="text-xl font-black tracking-tight text-slate-950">Completed runs</h2>
          <p class="text-xs text-slate-500">Finished runs and delivery history live here, not in your profile.</p>
        </div>
        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">
          {{ completedJobs.length }} completed
        </span>
      </header>

      <div v-if="completedLoading" class="rounded-2xl border bg-white p-4 text-sm text-slate-600">
        Loading completed runs...
      </div>

      <div v-else-if="completedErrorMessage" class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-700">
        {{ completedErrorMessage }}
      </div>

      <div v-else-if="!completedJobs.length" class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
        You have no completed runs yet.
      </div>

      <div v-else class="space-y-3">
        <RouterLink
          v-for="job in completedJobs"
          :key="job.id"
          :to="`/jobs/${job.id}`"
          class="block rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-200 hover:shadow-lg"
        >
          <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
              <p class="text-xl font-black tracking-tight text-slate-950">{{ job.title || `Run #${job.id}` }}</p>
              <p class="mt-1 text-xs text-slate-500">
                Completed {{ formatDate(job.completed_at || job.updated_at || job.created_at) }}
              </p>
              <p class="mt-2 text-sm text-slate-600">
                {{ job.pickup_postcode || '--' }} ? {{ job.dropoff_postcode || '--' }}
              </p>
            </div>
            <div class="flex items-center gap-2 sm:flex-col sm:items-end">
              <span class="text-lg font-black text-slate-950">
                {{ priceFormatter.format(driverPayoutForJob(job)) }}
              </span>
              <span class="badge bg-slate-900 text-white">{{ formatStatusLabel(job.status) }}</span>
            </div>
          </div>
        </RouterLink>
      </div>
    </section>

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

  <div
    v-if="confirmDialog.open"
    class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4"
  >
    <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
      <h3 class="text-lg font-semibold text-slate-900">
        {{
          confirmDialog.mode === 'deliver'
            ? 'Mark run as delivered'
            : confirmDialog.mode === 'invoice'
            ? 'Send invoice'
            : 'Cancel run'
        }}
      </h3>
      <p class="mt-3 text-sm text-slate-600">
        {{ confirmDialog.message }}
      </p>

      <div v-if="confirmDialog.mode === 'cancel'" class="mt-4 space-y-2">
        <label class="text-xs font-semibold uppercase tracking-wide text-slate-500">
          Optional note
        </label>
        <textarea
          v-model="confirmDialog.note"
          rows="3"
          class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200"
          placeholder="Let them know why you're cancelling"
        ></textarea>
      </div>

      <div class="mt-6 flex justify-end gap-2">
        <button
          type="button"
          class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-60"
          :disabled="confirmDialog.pending"
          @click="closeConfirmDialog"
        >
          Close
        </button>
        <button
          type="button"
          class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
          :disabled="confirmDialog.pending"
          @click="confirmAction"
        >
          <span v-if="confirmDialog.pending">Working...</span>
          <span v-else>Confirm</span>
        </button>
      </div>
    </div>
  </div>
</template>
