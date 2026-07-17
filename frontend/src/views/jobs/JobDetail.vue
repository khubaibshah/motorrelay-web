<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from "vue";
import { RouterLink, useRoute } from "vue-router";
import {
  fetchJob,
  fetchJobApplications,
  updateJobApplication,
  fetchJobExpenses,
  createJobExpense,
  updateJobExpense,
  deleteJobExpense,
  reviewJobExpense,
  submitJobCompletion,
  uploadJobInspection,
  approveJobInspection,
  requestJobInspectionChanges,
  approveJobCompletion,
  rejectJobCompletion,
  downloadExpenseReceipt,
  downloadDeliveryProof,
  downloadInspectionPhoto,
  updateJobLocation,
  markJobCollected,
  markJobDelivered,
  markIncidentRecoverySent,
  markIncidentRecoveryCompleted,
  reportJobIncident,
  applyForJob,
  requestJobLocationUpdate
} from "@/services/jobs";
import { fetchThreadMessages, fetchThreads, sendMessage } from "@/services/messages";
import { createJobCheckout, releaseDriverPayout, syncJobPayment } from "@/services/payments";
import { createEchoClient } from "@/services/realtime";
import { useAuthStore } from "@/stores/auth";
import { AppLauncher } from "@capacitor/app-launcher";
import { Capacitor } from "@capacitor/core";
import { Geolocation } from "@capacitor/geolocation";
import BackPillButton from "@/components/BackPillButton.vue";
import DealerLiveTrackingCard from "@/components/jobs/DealerLiveTrackingCard.vue";
import DriverModeOverlay from "@/components/jobs/DriverModeOverlay.vue";
import RunCompactProgress from "@/components/jobs/RunCompactProgress.vue";
import RunCompletionSummary from "@/components/jobs/RunCompletionSummary.vue";
import RunDetailHeader from "@/components/jobs/RunDetailHeader.vue";
import RunExpensesCard from "@/components/jobs/RunExpensesCard.vue";
import RunIncidentHistory from "@/components/jobs/RunIncidentHistory.vue";
import RunPaymentCard from "@/components/jobs/RunPaymentCard.vue";
import RunRouteSummary from "@/components/jobs/RunRouteSummary.vue";
import RunQuickActions from "@/components/jobs/RunQuickActions.vue";
import RunTrackingCard from "@/components/jobs/RunTrackingCard.vue";
import { useRunPayments } from "@/composables/jobs/useRunPayments";
import { useRunWorkflow } from "@/composables/jobs/useRunWorkflow";
import { formatStatusLabel } from "@/utils/statusLabels";

const route = useRoute();
const auth = useAuthStore();

const job = ref(null);
const loading = ref(false);
const errorMessage = ref("");

const applications = ref([]);
const applicationsLoading = ref(false);
const applicationsError = ref("");
const applicationsSection = ref(null);

const expenses = ref([]);
const expensesSummary = ref({
  submitted_total: 0,
  approved_total: 0,
  rejected_total: 0
});
const expensesLoading = ref(false);
const expensesError = ref("");
const expenseForm = reactive({
  description: "",
  amount: "",
  vat_rate: "20",
  receipt: null
});
const expenseFormKey = ref(0);
const expenseFormError = ref("");
const expenseSubmitting = ref(false);
const editingExpenseId = ref(null);
const receiptDownloadingId = ref(null);

const completionForm = reactive({
  notes: "",
  proof: []
});
const completionFormKey = ref(0);
const completionError = ref("");
const completionSubmitting = ref(false);
const completionDecisionLoading = ref(false);
const proofDownloading = ref(false);
const inspectionReviewLoading = ref("");
const inspectionPhotoPreviews = ref({});
const inspectionPreviewLoading = ref(false);
const driverActionLoading = ref("");
const driverActionError = ref("");
const driverModeOpen = ref(false);
const driverModeUploadedPhotos = ref([]);
const inspectionGalleryOpen = ref(false);
const inspectionGalleryIndex = ref(0);
const inspectionGalleryTouchStartX = ref(null);
const driverChatOpen = ref(false);
const driverChatLoading = ref(false);
const driverChatSending = ref(false);
const driverChatError = ref("");
const driverChatThread = ref(null);
const driverChatMessages = ref([]);
const driverChatBody = ref("");
const jobRequestLoading = ref(false);
const jobRequestError = ref("");
const incidentModalOpen = ref(false);
const incidentSubmitting = ref(false);
const incidentError = ref("");
const recoverySendingId = ref(null);
const recoverySendError = ref("");
const recoveryCompletingId = ref(null);
const recoveryCompleteError = ref("");
const recoveryConfirmation = reactive({
  open: false,
  mode: "",
  incident: null
});
const incidentForm = reactive({
  type: "vehicle_breakdown",
  recovery_required: true,
  vehicle_safe: true,
  blocking_road: false,
  location_label: "",
  latitude: null,
  longitude: null,
  description: ""
});

const trackingState = reactive({
  sending: false,
  shared: false,
  requesting: false,
  error: "",
  requestError: "",
  requestNotice: "",
  locationBlocked: false,
  locationServicesOff: false,
  lastUpdate: null
});
const navigationModalOpen = ref(false);
const checkoutLoading = ref(false);
const payoutReleaseLoading = ref(false);
const paymentError = ref("");
const paymentNotice = ref("");
const realtimeReloadTimer = ref(null);
const liveTrackingChannel = ref(null);
const liveTrackingInterval = ref(null);

const priceFormatter = new Intl.NumberFormat("en-GB", {
  style: "currency",
  currency: "GBP",
  maximumFractionDigits: 0
});

const requiredInspectionShots = [
  "Front",
  "Rear",
  "Driver side",
  "Passenger side",
  "Interior",
  "Mileage"
];
const minInspectionPhotoCount = requiredInspectionShots.length;
const currentInspectionGalleryPhoto = computed(() => driverModeUploadedPhotos.value[inspectionGalleryIndex.value] ?? null);

function formatCurrency(value, currencyCode = "GBP") {
  try {
    return new Intl.NumberFormat("en-GB", {
      style: "currency",
      currency: currencyCode || "GBP",
      maximumFractionDigits: 2
    }).format(Number(value || 0));
  } catch {
    return `${currencyCode} ${Number(value || 0).toFixed(2)}`;
  }
}

function formatDateTime(value) {
  if (!value) return "--";
  try {
    return new Intl.DateTimeFormat("en-GB", {
      day: "numeric",
      month: "short",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit"
    }).format(new Date(value));
  } catch {
    return value;
  }
}

async function getCurrentPosition(options = {}) {
  if (Capacitor.isNativePlatform()) {
    const currentPermission = await Geolocation.checkPermissions();

    if (currentPermission.location !== "granted") {
      const requestedPermission = await Geolocation.requestPermissions({
        permissions: ["location"]
      });

      if (requestedPermission.location !== "granted") {
        throw createLocationPermissionError();
      }
    }

    return Geolocation.getCurrentPosition(options);
  }

  return new Promise((resolve, reject) => {
    if (!navigator.geolocation) {
      reject(new Error("Geolocation is not supported on this device."));
      return;
    }
    navigator.geolocation.getCurrentPosition(resolve, reject, options);
  });
}

async function sendLiveLocationUpdate({ silent = false } = {}) {
  if (!job.value) return;

  if (!silent) {
    trackingState.error = "";
    trackingState.requestNotice = "";
    trackingState.locationBlocked = false;
    trackingState.locationServicesOff = false;
    trackingState.sending = true;
  }

  try {
    const position = await getCurrentPosition({
      enableHighAccuracy: true,
      maximumAge: 30000,
      timeout: 15000
    });

    const coords = position.coords || {};
    const heading = Number(coords.heading);
    const speed = Number(coords.speed);
    const payload = {
      latitude: coords.latitude,
      longitude: coords.longitude,
      accuracy: coords.accuracy ?? undefined,
      heading: Number.isFinite(heading) && heading >= 0 && heading <= 360 ? heading : undefined,
      speed_kph: Number.isFinite(speed) && speed >= 0 ? Math.min(speed * 3.6, 300) : undefined,
      source: Capacitor.isNativePlatform() ? "ios" : "web"
    };

    if (payload.latitude === undefined || payload.longitude === undefined) {
      throw new Error("Unable to determine your current position.");
    }

    const response = await updateJobLocation(job.value.id, payload);
    if (response?.job) {
      job.value = {
        ...job.value,
        current_latitude: response.job.current_latitude,
        current_longitude: response.job.current_longitude,
        last_tracked_at: response.job.last_tracked_at
      };
      trackingState.lastUpdate = response.job.last_tracked_at;
    }
    trackingState.shared = true;
  } catch (error) {
    console.error("Failed to share live location", error);
    trackingState.locationServicesOff = isLocationServicesDisabledError(error);
    trackingState.locationBlocked = !trackingState.locationServicesOff && isLocationPermissionBlockedError(error);
    if (!silent) {
      trackingState.error =
        error?.response?.data?.message ||
        geolocationErrorMessage(error) ||
        "We could not determine your current location. Please try again.";
    } else if (trackingState.locationBlocked || trackingState.locationServicesOff) {
      stopLiveTrackingUpdates();
    }
  } finally {
    if (!silent) {
      trackingState.sending = false;
    }
  }
}

function startLiveTrackingUpdates() {
  if (typeof window === "undefined" || liveTrackingInterval.value || !canShareTracking.value) return;

  liveTrackingInterval.value = window.setInterval(() => {
    if (!canShareTracking.value || hasTrackingEnded.value) {
      stopLiveTrackingUpdates();
      return;
    }

    sendLiveLocationUpdate({ silent: true }).catch(() => null);
  }, 25000);
}

function stopLiveTrackingUpdates() {
  if (liveTrackingInterval.value && typeof window !== "undefined") {
    window.clearInterval(liveTrackingInterval.value);
  }
  liveTrackingInterval.value = null;
}

async function shareLiveLocation() {
  await sendLiveLocationUpdate();
  if (!trackingState.error && trackingState.shared) {
    startLiveTrackingUpdates();
  }
}

async function requestLocationUpdate() {
  if (!job.value?.id || !canRequestTracking.value) return;

  trackingState.requesting = true;
  trackingState.requestError = "";
  trackingState.requestNotice = "";

  try {
    const payload = await requestJobLocationUpdate(job.value.id);
    trackingState.requestNotice = payload?.message || "Location update requested. The driver has been notified.";
  } catch (error) {
    console.error("Failed to request location update", error);
    trackingState.requestError = error?.response?.data?.message || "Unable to request a location update right now.";
  } finally {
    trackingState.requesting = false;
  }
}

async function openLocationSettings() {
  trackingState.error = "";

  try {
    await AppLauncher.openUrl({ url: "app-settings:" });
  } catch (error) {
    console.error("Failed to open app settings", error);
    window.location.href = "app-settings:";
  }
}

function createLocationPermissionError() {
  const error = new Error("Location permission is blocked.");
  error.code = 1;
  return error;
}

function geolocationErrorMessage(error) {
  if (!error) return "";

  if (isLocationServicesDisabledError(error)) {
    return "Location Services are switched off on this iPhone. Turn them on in Settings > Privacy & Security > Location Services, then tap Share live location again.";
  }

  if (isLocationPermissionBlockedError(error)) {
    return "Location permission is blocked. Open MotorRelay settings, allow Location while using the app, then tap Share live location again.";
  }

  if (error.code === 2) {
    return "Your current location is unavailable right now. Check signal/location services and try again.";
  }

  if (error.code === 3) {
    return "Location lookup timed out. Try again somewhere with better GPS signal.";
  }

  return error.message || "";
}

function isLocationPermissionBlockedError(error) {
  const message = String(error?.message || error?.errorMessage || "").toLowerCase();
  return error?.code === 1 || message.includes("denied") || message.includes("permission");
}

function isLocationServicesDisabledError(error) {
  const message = String(error?.message || error?.errorMessage || "").toLowerCase();
  return error?.code === "OS-PLUG-GLOC-0007" || message.includes("location services are not enabled");
}

function closeNavigationModal() {
  navigationModalOpen.value = false;
}

async function openDriverChatModal() {
  if (!job.value?.id) return;

  driverChatOpen.value = true;
  driverChatError.value = "";
  driverChatLoading.value = true;

  try {
    const payload = await fetchThreads();
    const threads = Array.isArray(payload?.data) ? payload.data : [];
    const thread = threads.find((item) => Number(item.job_id) === Number(job.value.id)) ?? null;
    driverChatThread.value = thread;

    if (thread?.id) {
      const messagePayload = await fetchThreadMessages(thread.id);
      driverChatMessages.value = Array.isArray(messagePayload?.data) ? messagePayload.data : [];
    } else {
      driverChatMessages.value = [];
    }
  } catch (error) {
    console.error("Failed to open driver chat", error);
    driverChatError.value = "Unable to load chat right now.";
  } finally {
    driverChatLoading.value = false;
  }
}

async function sendDriverChatMessage() {
  if (!job.value?.id || !driverChatBody.value.trim() || driverChatSending.value) return;

  driverChatSending.value = true;
  driverChatError.value = "";

  try {
    const payload = driverChatThread.value?.id
      ? {
          thread_id: driverChatThread.value.id,
          body: driverChatBody.value.trim()
        }
      : {
          job_id: job.value.id,
          recipient_id: job.value.posted_by_id,
          subject: job.value.title || `Run #${job.value.id}`,
          body: driverChatBody.value.trim()
        };

    const response = await sendMessage(payload);
    if (response?.thread) {
      driverChatThread.value = response.thread;
    }
    if (response?.message) {
      driverChatMessages.value.push(response.message);
    }
    driverChatBody.value = "";
  } catch (error) {
    console.error("Failed to send driver chat message", error);
    driverChatError.value = error?.response?.data?.message || "Unable to send message right now.";
  } finally {
    driverChatSending.value = false;
  }
}

function resetExpenseForm() {
  expenseForm.description = "";
  expenseForm.amount = "";
  expenseForm.vat_rate = "20";
  expenseForm.receipt = null;
  expenseFormError.value = "";
  editingExpenseId.value = null;
  expenseFormKey.value += 1;
}

function resetCompletionForm() {
  completionForm.notes = "";
  completionForm.proof = [];
  completionError.value = "";
  completionFormKey.value += 1;
}

function resetDriverModeUploadedPhotos() {
  driverModeUploadedPhotos.value.forEach((photo) => {
    if (photo.previewUrl) {
      URL.revokeObjectURL(photo.previewUrl);
    }
  });
  driverModeUploadedPhotos.value = [];
}

function syncSelectedInspectionPreviews() {
  resetDriverModeUploadedPhotos();
  driverModeUploadedPhotos.value = completionForm.proof.map((file, index) => ({
    id: `${file.name}-${file.size}-${file.lastModified}-${index}`,
    name: file.name,
    previewUrl: file.type?.startsWith("image/") ? URL.createObjectURL(file) : "",
  }));
}

function appendInspectionFiles(files) {
  const incoming = Array.from(files ?? []).filter((file) => file?.type?.startsWith("image/"));
  if (!incoming.length) return;

  const existing = completionForm.proof.map((file) => `${file.name}-${file.size}-${file.lastModified}`);
  const nextFiles = [...completionForm.proof];

  incoming.forEach((file) => {
    const key = `${file.name}-${file.size}-${file.lastModified}`;
    if (!existing.includes(key) && nextFiles.length < 20) {
      nextFiles.push(file);
      existing.push(key);
    }
  });

  completionForm.proof = nextFiles;
  completionFormKey.value += 1;
  syncSelectedInspectionPreviews();
}

function removeInspectionFile(index) {
  completionForm.proof = completionForm.proof.filter((_, fileIndex) => fileIndex !== index);
  syncSelectedInspectionPreviews();

  if (inspectionGalleryIndex.value >= driverModeUploadedPhotos.value.length) {
    inspectionGalleryIndex.value = Math.max(driverModeUploadedPhotos.value.length - 1, 0);
  }
  if (!driverModeUploadedPhotos.value.length) {
    inspectionGalleryOpen.value = false;
  }
}

function openInspectionGallery(index = 0) {
  if (!driverModeUploadedPhotos.value.length) return;
  inspectionGalleryIndex.value = Math.min(Math.max(index, 0), driverModeUploadedPhotos.value.length - 1);
  inspectionGalleryOpen.value = true;
}

function closeInspectionGallery() {
  inspectionGalleryOpen.value = false;
  inspectionGalleryTouchStartX.value = null;
}

function previousInspectionPhoto() {
  if (!driverModeUploadedPhotos.value.length) return;
  inspectionGalleryIndex.value = inspectionGalleryIndex.value === 0
    ? driverModeUploadedPhotos.value.length - 1
    : inspectionGalleryIndex.value - 1;
}

function nextInspectionPhoto() {
  if (!driverModeUploadedPhotos.value.length) return;
  inspectionGalleryIndex.value = inspectionGalleryIndex.value === driverModeUploadedPhotos.value.length - 1
    ? 0
    : inspectionGalleryIndex.value + 1;
}

function onInspectionGalleryTouchStart(event) {
  inspectionGalleryTouchStartX.value = event.changedTouches?.[0]?.clientX ?? null;
}

function onInspectionGalleryTouchEnd(event) {
  if (inspectionGalleryTouchStartX.value === null) return;

  const endX = event.changedTouches?.[0]?.clientX ?? inspectionGalleryTouchStartX.value;
  const delta = endX - inspectionGalleryTouchStartX.value;
  inspectionGalleryTouchStartX.value = null;

  if (Math.abs(delta) < 40) return;
  if (delta < 0) {
    nextInspectionPhoto();
  } else {
    previousInspectionPhoto();
  }
}

function updateExpenseSummary(list) {
  const totals = {
    submitted_total: 0,
    approved_total: 0,
    rejected_total: 0
  };

  list.forEach((expense) => {
    const totalAmount = Number(expense?.total_amount ?? 0);
    if (expense.status === "submitted") {
      totals.submitted_total += totalAmount;
    } else if (expense.status === "approved") {
      totals.approved_total += totalAmount;
    } else if (expense.status === "rejected") {
      totals.rejected_total += totalAmount;
    }
  });

  expensesSummary.value = totals;
}

function syncExpensesFromJob() {
  if (!job.value) {
    expenses.value = [];
    updateExpenseSummary([]);
    return;
  }

  if (Array.isArray(job.value.expenses)) {
    expenses.value = job.value.expenses;
  } else {
    expenses.value = [];
  }

  if (job.value.expenses_summary) {
    expensesSummary.value = {
      submitted_total: Number(job.value.expenses_summary.submitted_total ?? 0),
      approved_total: Number(job.value.expenses_summary.approved_total ?? 0),
      rejected_total: Number(job.value.expenses_summary.rejected_total ?? 0)
    };
  } else {
    updateExpenseSummary(expenses.value);
  }
}

async function refreshExpenses() {
  if (!job.value || !canSeeExpenses.value) {
    expenses.value = [];
    updateExpenseSummary([]);
    return;
  }

  expensesLoading.value = true;
  expensesError.value = "";
  try {
    const payload = await fetchJobExpenses(job.value.id);
    const list = Array.isArray(payload?.data) ? payload.data : [];
    expenses.value = list;
    updateExpenseSummary(list);
  } catch (error) {
    console.error("Failed to load expenses", error);
    expensesError.value = "Unable to load expenses right now.";
    expenses.value = [];
    updateExpenseSummary([]);
  } finally {
    expensesLoading.value = false;
  }
}

const assignedDriver = computed(() => job.value?.assigned_to ?? null);
const currentRole = computed(() => auth.role ?? auth.user?.role ?? null);

const goLiveDate = computed(() => {
  if (!job.value?.goes_live_at) return null;
  const parsed = new Date(job.value.goes_live_at);
  return Number.isNaN(parsed.getTime()) ? null : parsed;
});

const isAwaitingGoLive = computed(() => {
  if (!goLiveDate.value) return false;
  return goLiveDate.value.getTime() > Date.now();
});

const goLiveFormatted = computed(() => {
  if (!goLiveDate.value) return "";
  return new Intl.DateTimeFormat("en-GB", {
    day: "numeric",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit"
  }).format(goLiveDate.value);
});


const basicAnalytics = computed(() => job.value?.basic_analytics ?? null);

const lastTrackedAt = computed(() => trackingState.lastUpdate ?? job.value?.last_tracked_at ?? null);

const lastTrackedDisplay = computed(() => (lastTrackedAt.value ? formatDateTime(lastTrackedAt.value) : ""));

const activeTrackingStatuses = new Set(["in_progress", "collected", "in_transit", "accepted"]);
const endedTrackingStatuses = new Set(["delivered", "completion_pending", "completed", "closed", "cancelled"]);
const isTrackingActive = computed(() => {
  if (!job.value?.assigned_to_id) return false;
  return activeTrackingStatuses.has(String(job.value.status || "").toLowerCase());
});
const hasTrackingEnded = computed(() => {
  if (!job.value?.assigned_to_id) return false;
  return endedTrackingStatuses.has(String(job.value.status || "").toLowerCase()) || Boolean(job.value.completed_at);
});
const canShareTracking = computed(() => {
  if (!job.value || !auth.user) return false;
  return job.value.assigned_to_id === auth.user.id && isTrackingActive.value;
});
const canRequestTracking = computed(() => {
  if (!job.value || !auth.user) return false;
  return (currentRole.value === "admin" || isDealerForJob.value) && isTrackingActive.value;
});
const shouldShowTrackingCard = computed(() => canShareTracking.value || canRequestTracking.value || hasTrackingEnded.value || Boolean(lastTrackedAt.value));
const canUseDriverMode = computed(() => {
  if (!Capacitor.isNativePlatform()) return false;
  return canShareTracking.value || canMarkCollected.value || canMarkDeliveredFromDetail.value || canReportIncident.value || canUploadInspection.value || canSubmitCompletion.value;
});

const liveTrackingLocation = computed(() => {
  const latitude = Number(job.value?.current_latitude);
  const longitude = Number(job.value?.current_longitude);

  if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
    return null;
  }

  return {
    lat: latitude,
    lng: longitude
  };
});

const shouldShowDealerLiveTracking = computed(() => {
  return (currentRole.value === "admin" || isDealerForJob.value) && Boolean(liveTrackingLocation.value);
});

const dealerLiveTrackingMapSrc = computed(() => {
  if (!liveTrackingLocation.value) return "";
  const { lat, lng } = liveTrackingLocation.value;
  return `https://maps.google.com/maps?q=${lat},${lng}&z=15&output=embed`;
});

const dealerLiveTrackingUpdatedLabel = computed(() => {
  if (!lastTrackedDisplay.value) return "Waiting for the driver to share location.";
  return `Updated ${lastTrackedDisplay.value}`;
});

const navigationDestination = computed(() => {
  if (!job.value) return "";
  const parts = [job.value.dropoff_label, job.value.dropoff_postcode].filter(Boolean);
  return parts.join(", ");
});

const driverModeDestinationLabel = computed(() => {
  if (!job.value) return "Destination";
  const status = String(job.value.status || "").toLowerCase();

  if (["accepted", "in_progress"].includes(status)) {
    return job.value.pickup_label || job.value.pickup_postcode || "Pickup";
  }

  return job.value.dropoff_label || job.value.dropoff_postcode || "Drop-off";
});

const driverModeNavigationDestination = computed(() => {
  if (!job.value) return "";
  const status = String(job.value.status || "").toLowerCase();
  const parts = ["accepted", "in_progress"].includes(status)
    ? [job.value.pickup_label, job.value.pickup_postcode]
    : [job.value.dropoff_label, job.value.dropoff_postcode];

  return parts.filter(Boolean).join(", ");
});

const driverModeNavigationLinks = computed(() => {
  const destination = driverModeNavigationDestination.value;
  if (!destination) return [];
  const encoded = encodeURIComponent(destination);
  return [
    {
      id: "google",
      label: "Google Maps",
      href: `https://www.google.com/maps/dir/?api=1&destination=${encoded}&travelmode=driving`
    },
    {
      id: "waze",
      label: "Waze",
      href: `https://waze.com/ul?q=${encoded}&navigate=yes`
    }
  ];
});

const driverModePickupLabel = computed(() => {
  if (!job.value) return "Pickup";
  return job.value.pickup_label || job.value.pickup_postcode || "Pickup";
});

const driverModeDropoffLabel = computed(() => {
  if (!job.value) return "Drop-off";
  return job.value.dropoff_label || job.value.dropoff_postcode || "Drop-off";
});

const driverModePickupShort = computed(() => job.value?.pickup_postcode || driverModePickupLabel.value);
const driverModeDropoffShort = computed(() => job.value?.dropoff_postcode || driverModeDropoffLabel.value);

const driverModeRouteLabel = computed(() => {
  return `${driverModePickupShort.value} to ${driverModeDropoffShort.value}`;
});

const driverModeStatusLabel = computed(() => formatStatusLabel(job.value?.status, "In progress"));

const driverModeMapSrc = computed(() => {
  if (!job.value) return "";

  const start = [
    job.value.current_latitude && job.value.current_longitude
      ? `${job.value.current_latitude},${job.value.current_longitude}`
      : "",
    driverModePickupLabel.value
  ].find(Boolean);
  const destination = driverModeNavigationDestination.value || driverModeDropoffLabel.value;

  if (!start && !destination) return "";

  const params = new URLSearchParams({
    output: "embed",
    saddr: start || driverModePickupLabel.value,
    daddr: destination,
    dirflg: "d"
  });

  return `https://www.google.com/maps?${params.toString()}`;
});

const driverModeTrackingLabel = computed(() => {
  if (trackingState.shared) return "Live location shared";
  if (lastTrackedDisplay.value) return `Last shared ${lastTrackedDisplay.value}`;
  return "Share live location when you start moving.";
});

const driverModeTimelineItems = computed(() => {
  const status = String(job.value?.status || "").toLowerCase();
  const items = [
    {
      id: "accepted",
      label: "Run accepted",
      meta: "Assigned to you",
      complete: Boolean(job.value?.assigned_to_id)
    },
    {
      id: "inspection",
      label: "Inspection photos",
      meta: hasDeliveryProof.value ? "Uploaded" : "Needed before collection",
      complete: hasDeliveryProof.value
    },
    {
      id: "collected",
      label: "Vehicle collected",
      meta: ["collected", "in_transit", "delivered", "completion_pending", "completed", "closed"].includes(status)
        ? "Done"
        : "Next driving step",
      complete: ["collected", "in_transit", "delivered", "completion_pending", "completed", "closed"].includes(status)
    },
    {
      id: "delivered",
      label: "Vehicle delivered",
      meta: ["delivered", "completion_pending", "completed", "closed"].includes(status) ? "Done" : "Not yet",
      complete: ["delivered", "completion_pending", "completed", "closed"].includes(status)
    }
  ];

  return items;
});

const driverModePrimaryAction = computed(() => {
  if (canMarkCollected.value) {
    return {
      label: driverActionLoading.value === "collected" ? "Updating..." : "Mark collected",
      disabled: driverActionLoading.value === "collected",
      handler: handleDriverCollected
    };
  }

  if (canMarkDeliveredFromDetail.value) {
    return {
      label: driverActionLoading.value === "delivered" ? "Updating..." : "Mark delivered",
      disabled: driverActionLoading.value === "delivered",
      handler: handleDriverDelivered
    };
  }

  if (canSubmitCompletion.value) {
    return {
      label: completionSubmitting.value ? "Submitting..." : "Submit completion",
      disabled: completionSubmitting.value,
      handler: handleCompletionSubmit
    };
  }

  if (canShareTracking.value && !trackingState.shared) {
    return {
      label: trackingState.sending ? "Sharing location..." : "Share live location",
      disabled: trackingState.sending,
      handler: shareLiveLocation
    };
  }

  return null;
});

const driverModeShowSecondaryTracking = computed(() => {
  return canShareTracking.value && !trackingState.shared && driverModePrimaryAction.value?.handler !== shareLiveLocation;
});

const navigationLinks = computed(() => {
  const destination = navigationDestination.value;
  if (!destination) return [];
  const encoded = encodeURIComponent(destination);
  return [
    {
      id: "google",
      label: "Open in Google Maps",
      href: `https://www.google.com/maps/dir/?api=1&destination=${encoded}&travelmode=driving`
    },
    {
      id: "waze",
      label: "Open in Waze",
      href: `https://waze.com/ul?q=${encoded}&navigate=yes`
    }
  ];
});

const runQuickNavigationLinks = computed(() => {
  return isAssignedDriver.value ? driverModeNavigationLinks.value : navigationLinks.value;
});

const runQuickGoogleHref = computed(() => runQuickNavigationLinks.value.find((link) => link.id === "google")?.href || "");
const runQuickWazeHref = computed(() => runQuickNavigationLinks.value.find((link) => link.id === "waze")?.href || "");
const runPhotosRoute = computed(() => ({
  name: "job-photos",
  params: {
    id: job.value?.id
  }
}));
const runIssueRoute = computed(() => ({
  name: "job-report-issue",
  params: {
    id: job.value?.id
  }
}));
const shouldShowRunQuickActions = computed(() => {
  if (!job.value?.id) return false;
  return Boolean(
    runQuickGoogleHref.value ||
      runQuickWazeHref.value ||
      canReportIncident.value ||
      hasInspectionPhotos.value ||
      canUploadInspection.value ||
      canReviewInspection.value
  );
});

const statusDescription = computed(() => {
  if (!job.value) return "";
  const status = String(job.value.status || "").toLowerCase();

  if (status === "open") {
    return "This run is open and awaiting driver applications.";
  }

  if (["pending", "accepted", "in_progress", "collected", "in_transit"].includes(status)) {
    if (assignedDriver.value) {
      return `This run is currently in progress with ${assignedDriver.value.name}.`;
    }
    return "This run is being prepared and will be assigned shortly.";
  }

  if (status === "completion_pending") {
    return "Completion has been submitted and is awaiting dealer approval.";
  }

  if (["delivered", "completed", "closed"].includes(status)) {
    if (assignedDriver.value) {
      return `This run was completed by ${assignedDriver.value.name}.`;
    }
    return "This run has been completed.";
  }

  if (status === "cancelled") {
    return "This run has been cancelled.";
  }

  return `This run status is ${job.value.status}.`;
});

const canReviewApplications = computed(() => {
  if (!job.value || !auth.user) return false;
  if (currentRole.value === "admin") return true;
  return job.value.posted_by_id === auth.user.id;
});
const showApplicationsAtTop = computed(() => canReviewApplications.value && !job.value?.assigned_to_id);

const isDealerForJob = computed(() => {
  if (!job.value || !auth.user) return false;
  return job.value.posted_by_id === auth.user.id;
});

const isAssignedDriver = computed(() => {
  if (!job.value || !auth.user) return false;
  return job.value.assigned_to_id === auth.user.id;
});
const isDriverDetailView = computed(() => currentRole.value === "driver");

const canSeeExpenses = computed(() => {
  if (!job.value || !auth.token) return false;
  if (currentRole.value === "admin") return true;
  return isDealerForJob.value || isAssignedDriver.value;
});

const canSubmitExpenses = computed(() => {
  if (!canSeeExpenses.value) return false;
  if (!isAssignedDriver.value) return false;
  return !job.value?.finalized_invoice_id;
});

const canReviewExpenses = computed(() => {
  if (!canSeeExpenses.value) return false;
  return currentRole.value === "admin" || isDealerForJob.value;
});
const shouldShowExpenses = computed(() => {
  return false;
});

const shouldShowGoLiveBanner = computed(
  () => isDealerForJob.value && isAwaitingGoLive.value
);
const completionStatus = computed(() => job.value?.completion_status ?? "not_submitted");
const paymentStatus = computed(() => job.value?.payment_status || 'unpaid');
const myApplication = computed(() => job.value?.my_application ?? null);
const completionStatusLabel = computed(() => formatStatusLabel(completionStatus.value));
const inspectionPhotos = computed(() => {
  const photos = job.value?.inspection_photos ?? job.value?.inspectionPhotos ?? [];
  return Array.isArray(photos) ? photos : [];
});
const hasInspectionPhotos = computed(() => inspectionPhotos.value.length > 0);

const canUploadInspection = computed(() => {
  if (!isAssignedDriver.value) return false;
  if (!['paid', 'payout_released'].includes(paymentStatus.value)) return false;
  if (completionStatus.value === 'inspection_approved') return false;
  if (job.value?.finalized_invoice_id) return false;
  return ['accepted', 'in_progress'].includes(String(job.value?.status || '').toLowerCase());
});
const canSubmitCompletion = computed(() => {
  if (!isAssignedDriver.value) return false;
  if (!['paid', 'payout_released'].includes(paymentStatus.value)) return false;
  if (!hasDeliveryProof.value) return false;
  if (completionStatus.value !== 'inspection_approved') return false;
  if (['submitted', 'approved'].includes(String(completionStatus.value || '').toLowerCase())) return false;
  if (String(job.value?.status || '').toLowerCase() !== 'delivered') return false;
  return !job.value?.finalized_invoice_id;
});
const canMarkCollected = computed(() => {
  if (!isAssignedDriver.value) return false;
  if (!['paid', 'payout_released'].includes(paymentStatus.value)) return false;
  if (!hasDeliveryProof.value) return false;
  if (completionStatus.value !== 'inspection_approved') return false;
  return ['accepted', 'in_progress'].includes(String(job.value?.status || '').toLowerCase());
});
const canMarkDeliveredFromDetail = computed(() => {
  if (!isAssignedDriver.value) return false;
  if (!['paid', 'payout_released'].includes(paymentStatus.value)) return false;
  return ['collected', 'in_transit'].includes(String(job.value?.status || '').toLowerCase());
});
const canReportIncident = computed(() => {
  if (!isAssignedDriver.value) return false;
  return ['accepted', 'in_progress', 'collected', 'in_transit'].includes(String(job.value?.status || '').toLowerCase());
});
const canSendRecovery = computed(() => currentRole.value === "admin" || isDealerForJob.value);
const canConfirmRecoveryCompleted = computed(() => currentRole.value === "admin" || isAssignedDriver.value);
const driverNextActionText = computed(() => {
  if (!isAssignedDriver.value) return '';
  if (paymentStatus.value === 'unpaid') return 'Waiting for the dealer to confirm this run is ready to start.';
  if (paymentStatus.value === 'checkout_pending') return 'Waiting for the dealer to finish confirming this run.';
  if (canUploadInspection.value) return 'Upload inspection photos before you collect the vehicle.';
  if (hasDeliveryProof.value && completionStatus.value !== 'inspection_approved' && ['accepted', 'in_progress'].includes(String(job.value?.status || '').toLowerCase())) {
    return 'Inspection photos are uploaded. Wait for the dealer to approve them before collection.';
  }
  if (canMarkCollected.value) return 'Collect the vehicle, then tap Mark collected.';
  if (canMarkDeliveredFromDetail.value) return 'Deliver the vehicle, then tap “Mark delivered”.';
  if (canSubmitCompletion.value) return 'Submit completion so the dealer can review and approve the run.';
  if (completionStatus.value === 'submitted') return 'Wait for the dealer to review your inspection photos.';
  if (completionStatus.value === 'approved' && paymentStatus.value !== 'payout_released') return 'Delivery is approved. Waiting for payout release.';
  if (paymentStatus.value === 'payout_released') return 'Payout has been released.';
  return '';
});
const showDriverNextAction = computed(() => Boolean(driverNextActionText.value));

const canApproveCompletion = computed(() => {
  if (!(currentRole.value === "admin" || isDealerForJob.value)) return false;
  if (!['paid', 'payout_released'].includes(paymentStatus.value)) return false;
  if (!['delivered', 'completion_pending', 'completed', 'closed'].includes(String(job.value?.status || '').toLowerCase())) return false;
  return completionStatus.value === "submitted";
});

const hasDeliveryProof = computed(() => Boolean(job.value?.delivery_proof_path || hasInspectionPhotos.value));
const canReviewInspection = computed(() => {
  if (!(currentRole.value === "admin" || isDealerForJob.value)) return false;
  if (!hasDeliveryProof.value) return false;
  if (job.value?.finalized_invoice_id) return false;
  return ['not_submitted', 'rejected', 'inspection_approved'].includes(String(completionStatus.value || '').toLowerCase());
});
const canApproveInspection = computed(() => canReviewInspection.value && completionStatus.value !== 'inspection_approved');
const canRequestInspectionChanges = computed(() => canReviewInspection.value);
const inspectionReviewTitle = computed(() => {
  if (!hasDeliveryProof.value) return 'Waiting for photos';
  if (completionStatus.value === 'inspection_approved') return 'Inspection approved';
  if (completionStatus.value === 'rejected') return 'More photos requested';
  return 'Inspection ready to review';
});
const inspectionReviewDescription = computed(() => {
  if (!hasDeliveryProof.value) return 'The driver must upload inspection photos before collection.';
  if (completionStatus.value === 'inspection_approved') return 'The driver can collect the vehicle and continue the run.';
  if (completionStatus.value === 'rejected') return 'The previous upload was rejected. The driver needs to upload a fresh set.';
  return 'Check the photos, then approve them or ask the driver for clearer images.';
});

const invoiceFinalized = computed(() => Boolean(job.value?.finalized_invoice_id));
const jobInvoiceLink = computed(() => ({
  name: 'invoices',
  query: {
    job: job.value?.id,
    invoice: job.value?.finalized_invoice_id
  }
}));
const shouldShowCompletionPanel = computed(() => {
  return canUploadInspection.value || canSubmitCompletion.value || canApproveCompletion.value || completionStatus.value !== 'not_submitted' || hasDeliveryProof.value || invoiceFinalized.value;
});
const {
  completedWorkflowCount,
  currentWorkflowStep,
  isCompletedJob,
  showRunProgress,
  workflowProgressPercent,
  workflowSteps
} = useRunWorkflow({
  job,
  currentRole,
  isAssignedDriver,
  isDealerForJob,
  paymentStatus,
  completionStatus,
  hasDeliveryProof,
  invoiceFinalized,
  assignedDriver,
  myApplication
});
const showCompactCompletionPanel = computed(() => shouldShowCompletionPanel.value && isCompletedJob.value);
const showFullCompletionPanel = computed(() => shouldShowCompletionPanel.value && !showCompactCompletionPanel.value && !isAssignedDriver.value);
const {
  canManagePayment,
  canReleasePayout,
  canStartCheckout,
  dealerPaymentAmount,
  driverPayoutAmount,
  headerDisplayAmount,
  paymentActionHelp,
  paymentCardEyebrow,
  paymentCardTitle,
  paymentConfirmationText,
  paymentStatusBadgeClass,
  platformFeeAmount
} = useRunPayments({
  job,
  currentRole,
  isDealerForJob,
  paymentStatus,
  completionStatus,
  hasDeliveryProof
});

const canRequestJob = computed(() => {
  if (!job.value || currentRole.value !== "driver") return false;
  if (job.value.assigned_to_id) return false;
  if (String(job.value.status || "").toLowerCase() !== "open") return false;
  return !myApplication.value;
});
const showDriverRequestPanel = computed(() => {
  if (!job.value || currentRole.value !== "driver") return false;
  if (job.value.assigned_to_id) return false;
  return String(job.value.status || "").toLowerCase() === "open";
});
const requestPanelTitle = computed(() => {
  const status = String(myApplication.value?.status || '').toLowerCase();

  if (status === 'accepted') return 'Request accepted';
  if (status === 'declined') return 'Request declined';
  if (status === 'pending') return 'Request sent';

  return 'Want this run?';
});
const requestPanelText = computed(() => {
  const status = String(myApplication.value?.status || '').toLowerCase();

  if (status === 'accepted') {
    return 'The dealer accepted your request. This run will appear in your current runs.';
  }

  if (status === 'declined') {
    return 'The dealer chose another driver for this run.';
  }

  if (status === 'pending') {
    return "Your request has been sent to the dealer. If they choose you, this run will move into your current runs.";
  }

  return "Request this run so the dealer can review you and assign the driver.";
});

function applicationBadgeClass(status) {
  const normalized = String(status || '').toLowerCase();

  if (normalized === 'accepted') return 'bg-emerald-600 text-white ring-emerald-600';
  if (normalized === 'declined') return 'bg-rose-50 text-rose-700 ring-rose-200';

  return 'bg-white text-emerald-700 ring-emerald-200';
}

async function loadJob() {
  const jobId = route.params.id;
  if (!jobId) {
    job.value = null;
    return;
  }

  loading.value = true;
  errorMessage.value = "";
  try {
    const payload = await fetchJob(jobId);
    job.value = payload?.data ?? payload ?? null;
    syncExpensesFromJob();
    trackingState.lastUpdate = job.value?.last_tracked_at ?? null;
    await loadInspectionPhotoPreviews();
  } catch (error) {
    console.error("Failed to load run", error);
    errorMessage.value = "We could not load this run.";
    job.value = null;
    expenses.value = [];
    updateExpenseSummary([]);
    trackingState.lastUpdate = null;
    clearInspectionPhotoPreviews();
  } finally {
    loading.value = false;
  }

  if (!job.value) {
    applications.value = [];
    return;
  }

  await loadApplicationsIfNeeded();

  if (canSeeExpenses.value) {
    await refreshExpenses();
  } else {
    expenses.value = [];
    updateExpenseSummary([]);
  }
}

function scheduleRealtimeJobReload() {
  if (typeof window === 'undefined') return;

  if (realtimeReloadTimer.value) {
    window.clearTimeout(realtimeReloadTimer.value);
  }

  realtimeReloadTimer.value = window.setTimeout(() => {
    realtimeReloadTimer.value = null;
    loadJob();
  }, 250);
}

function handleRealtimeJobEvent(event) {
  const incomingJobId = Number(event?.detail?.job_id || 0);
  const currentJobId = Number(route.params.id || job.value?.id || 0);

  if (!incomingJobId || !currentJobId || incomingJobId !== currentJobId) {
    return;
  }

  scheduleRealtimeJobReload();
}

function applyLiveLocationPayload(payload = {}) {
  const incomingJobId = Number(payload?.job_id || payload?.jobId || 0);
  const currentJobId = Number(route.params.id || job.value?.id || 0);

  if (!incomingJobId || !currentJobId || incomingJobId !== currentJobId || !job.value) {
    return;
  }

  const location = payload.location || {};
  const latitude = Number(location.lat ?? location.latitude);
  const longitude = Number(location.lng ?? location.longitude);
  const trackedAt = payload.last_tracked_at || location.recorded_at || new Date().toISOString();

  if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
    return;
  }

  job.value = {
    ...job.value,
    current_latitude: latitude,
    current_longitude: longitude,
    last_tracked_at: trackedAt
  };
  trackingState.lastUpdate = trackedAt;
}

function startLiveTrackingListener() {
  if (typeof window === "undefined" || liveTrackingChannel.value || !auth.user?.id) return;

  const echo = createEchoClient();
  if (!echo) return;

  liveTrackingChannel.value = echo.private(`App.Models.User.${auth.user.id}`);
  liveTrackingChannel.value.listen(".job.location.updated", applyLiveLocationPayload);
}

function stopLiveTrackingListener() {
  if (!liveTrackingChannel.value) return;

  liveTrackingChannel.value.stopListening?.(".job.location.updated");
  liveTrackingChannel.value = null;
}

function isImageInspectionPhoto(photo) {
  const mime = String(photo?.mime_type || '').toLowerCase();
  const name = String(photo?.original_name || photo?.path || '').toLowerCase();
  return mime.startsWith('image/') || /\.(jpg|jpeg|png|webp|gif|heic|heif)$/.test(name);
}

function clearInspectionPhotoPreviews() {
  Object.values(inspectionPhotoPreviews.value).forEach((url) => {
    if (url) URL.revokeObjectURL(url);
  });
  inspectionPhotoPreviews.value = {};
}

async function loadInspectionPhotoPreviews() {
  clearInspectionPhotoPreviews();

  if (!job.value?.id || !inspectionPhotos.value.length) {
    return;
  }

  inspectionPreviewLoading.value = true;
  const previews = {};

  try {
    await Promise.all(
      inspectionPhotos.value
        .filter((photo) => photo?.id && isImageInspectionPhoto(photo))
        .map(async (photo) => {
          const response = await downloadInspectionPhoto(job.value.id, photo.id);
          const contentType = response.headers?.["content-type"] || photo.mime_type || "image/jpeg";
          previews[photo.id] = URL.createObjectURL(new Blob([response.data], { type: contentType }));
        })
    );
    inspectionPhotoPreviews.value = previews;
  } catch (error) {
    console.error("Failed to load inspection photo previews", error);
  } finally {
    inspectionPreviewLoading.value = false;
  }
}

async function loadApplicationsIfNeeded() {
  if (!job.value || !canReviewApplications.value) {
    applications.value = [];
    return;
  }

  applicationsLoading.value = true;
  applicationsError.value = "";
  try {
    const payload = await fetchJobApplications(job.value.id);
    applications.value = Array.isArray(payload?.data) ? payload.data : [];
  } catch (error) {
    console.error("Failed to load applications", error);
    applicationsError.value = "Unable to load applications right now.";
    applications.value = [];
  } finally {
    applicationsLoading.value = false;
  }
}

function scrollToApplicationsSection() {
  if (route.query.section !== "applications") {
    return;
  }

  if (typeof window === "undefined") {
    return;
  }

  window.requestAnimationFrame(() => {
    applicationsSection.value?.scrollIntoView({
      behavior: "smooth",
      block: "start"
    });
  });
}

async function handleApplicationDecision(applicationId, status) {
  if (!job.value) return;
  try {
    await updateJobApplication(job.value.id, applicationId, { status });
    await loadJob();
    await loadApplicationsIfNeeded();
  } catch (error) {
    console.error("Failed to update application", error);
    alert(error.response?.data?.message || "Unable to update application. Please try again.");
  }
}

function onExpenseReceiptChange(event) {
  const [file] = event.target?.files ?? [];
  expenseForm.receipt = file ?? null;
}

function startEditingExpense(expense) {
  editingExpenseId.value = expense.id;
  expenseForm.description = expense.description ?? "";
  expenseForm.amount = expense.amount ?? "";
  expenseForm.vat_rate = expense.vat_rate ?? "20";
  expenseForm.receipt = null;
  expenseFormError.value = "";
  expenseFormKey.value += 1;
}

function cancelExpenseEdit() {
  resetExpenseForm();
}

async function handleExpenseSubmit() {
  if (!job.value) return;
  if (!expenseForm.description?.trim() || expenseForm.amount === "") {
    expenseFormError.value = "Description and amount are required.";
    return;
  }

  expenseSubmitting.value = true;
  expenseFormError.value = "";
  try {
    const payload = {
      description: expenseForm.description,
      amount: expenseForm.amount,
      vat_rate: expenseForm.vat_rate,
      receipt: expenseForm.receipt ?? undefined
    };

    if (editingExpenseId.value) {
      await updateJobExpense(job.value.id, editingExpenseId.value, payload);
    } else {
      await createJobExpense(job.value.id, payload);
    }

    resetExpenseForm();
    await refreshExpenses();
    await loadJob();
  } catch (error) {
    console.error("Failed to save expense", error);
    expenseFormError.value = error.response?.data?.message || "Unable to save expense.";
  } finally {
    expenseSubmitting.value = false;
  }
}

async function handleDeleteExpense(expense) {
  if (!job.value || !expense?.id) return;
  if (!window.confirm("Delete this expense?")) {
    return;
  }

  try {
    await deleteJobExpense(job.value.id, expense.id);
    await refreshExpenses();
    await loadJob();
  } catch (error) {
    console.error("Failed to delete expense", error);
    alert(error.response?.data?.message || "Unable to delete this expense.");
  }
}

async function handleReviewExpense(expense, decision) {
  if (!job.value || !expense?.id) return;
  const note = window.prompt("Add a note for the driver (optional)", "");
  if (note === null) {
    return;
  }

  try {
    await reviewJobExpense(job.value.id, expense.id, { decision, note });
    await refreshExpenses();
    await loadJob();
  } catch (error) {
    console.error("Failed to review expense", error);
    alert(error.response?.data?.message || "Unable to update this expense.");
  }
}

async function handleDownloadReceipt(expense) {
  if (!job.value || !expense?.id) return;

  receiptDownloadingId.value = expense.id;
  try {
    const response = await downloadExpenseReceipt(job.value.id, expense.id);
    const contentType = response.headers?.["content-type"] || "application/octet-stream";
    const extension = contentType.includes("pdf")
      ? "pdf"
      : contentType.includes("png")
      ? "png"
      : contentType.includes("jpeg") || contentType.includes("jpg")
      ? "jpg"
      : "bin";
    const blob = new Blob([response.data], { type: contentType });
    const url = window.URL.createObjectURL(blob);
    const safeName = (expense.description || "receipt").toString().replace(/[^a-z0-9]+/gi, "-").toLowerCase();
    const link = document.createElement("a");
    link.href = url;
    link.download = `${safeName || "receipt"}-${expense.id}.${extension}`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    window.URL.revokeObjectURL(url);
  } catch (error) {
    console.error("Failed to download receipt", error);
    alert("We could not download the receipt.");
  } finally {
    receiptDownloadingId.value = null;
  }
}

function safeDownloadName(value) {
  return (value || "")
    .toString()
    .trim()
    .replace(/[^a-z0-9]+/gi, "-")
    .replace(/^-+|-+$/g, "")
    .toLowerCase()
    .slice(0, 80);
}

function deliveryProofDownloadName() {
  const baseName = safeDownloadName(
    job.value?.registration ||
      job.value?.vehicle_registration ||
      job.value?.vehicle_reg ||
      job.value?.vrm ||
      job.value?.title ||
      job.value?.vehicle_make ||
      `job-${job.value?.id}`
  );

  return baseName || "inspection";
}

function onCompletionProofChange(event) {
  appendInspectionFiles(event.target?.files ?? []);
}

async function onDriverModeInspectionChange(event) {
  appendInspectionFiles(event.target?.files ?? []);
}

async function handleCompletionSubmit() {
  if (!job.value) return;
  if (canUploadInspection.value && completionForm.proof.length < minInspectionPhotoCount) {
    completionError.value = `Please upload at least ${minInspectionPhotoCount} inspection photos: ${requiredInspectionShots.join(", ")}.`;
    return;
  }

  completionSubmitting.value = true;
  completionError.value = "";
  try {
    if (canUploadInspection.value) {
      await uploadJobInspection(job.value.id, {
        notes: completionForm.notes,
        proofs: completionForm.proof
      });
    } else {
      await submitJobCompletion(job.value.id, {
        notes: completionForm.notes
      });
    }
    resetCompletionForm();
    resetDriverModeUploadedPhotos();
    await loadJob();
  } catch (error) {
    console.error("Failed to submit completion", error);
    completionError.value = error.response?.data?.message || "Unable to update this run.";
  } finally {
    completionSubmitting.value = false;
  }
}

async function handleApproveCompletion() {
  if (!job.value) return;
  completionDecisionLoading.value = true;
  try {
    await approveJobCompletion(job.value.id);
    await loadJob();
  } catch (error) {
    console.error("Failed to approve completion", error);
    alert(error.response?.data?.message || "Unable to approve completion.");
  } finally {
    completionDecisionLoading.value = false;
  }
}

async function handleRejectCompletion() {
  if (!job.value) return;
  const reason = window.prompt("Add a note for the driver (optional)", "");
  if (reason === null) {
    return;
  }

  completionDecisionLoading.value = true;
  try {
    await rejectJobCompletion(job.value.id, { reason });
    await loadJob();
  } catch (error) {
    console.error("Failed to reject completion", error);
    alert(error.response?.data?.message || "Unable to reject completion.");
  } finally {
    completionDecisionLoading.value = false;
  }
}

async function handleApproveInspection() {
  if (!job.value?.id || !canApproveInspection.value) return;

  inspectionReviewLoading.value = "approve";
  try {
    await approveJobInspection(job.value.id);
    await loadJob();
  } catch (error) {
    console.error("Failed to approve inspection", error);
    alert(error.response?.data?.message || "Unable to approve inspection photos.");
  } finally {
    inspectionReviewLoading.value = "";
  }
}

async function handleRequestInspectionChanges() {
  if (!job.value?.id || !canRequestInspectionChanges.value) return;
  const reason = window.prompt("Tell the driver what extra photos you need (optional)", "");
  if (reason === null) return;

  inspectionReviewLoading.value = "changes";
  try {
    await requestJobInspectionChanges(job.value.id, { reason });
    await loadJob();
  } catch (error) {
    console.error("Failed to request inspection changes", error);
    alert(error.response?.data?.message || "Unable to request more inspection photos.");
  } finally {
    inspectionReviewLoading.value = "";
  }
}

async function handleRequestJob() {
  if (!job.value?.id || !canRequestJob.value) return;

  jobRequestLoading.value = true;
  jobRequestError.value = "";

  try {
    await applyForJob(job.value.id);
    await loadJob();
  } catch (error) {
    console.error("Failed to request job", error);
    jobRequestError.value = error.response?.data?.message || "We could not send your request. Please try again.";
  } finally {
    jobRequestLoading.value = false;
  }
}

async function handleDriverCollected() {
  if (!job.value?.id || !canMarkCollected.value) return;

  driverActionLoading.value = "collected";
  driverActionError.value = "";

  try {
    await markJobCollected(job.value.id);
    await loadJob();
  } catch (error) {
    console.error("Failed to mark job collected", error);
    driverActionError.value = error.response?.data?.message || "Unable to mark this job as collected.";
  } finally {
    driverActionLoading.value = "";
  }
}

async function handleDriverDelivered() {
  if (!job.value?.id || !canMarkDeliveredFromDetail.value) return;

  driverActionLoading.value = "delivered";
  driverActionError.value = "";

  try {
    await markJobDelivered(job.value.id);
    await loadJob();
  } catch (error) {
    console.error("Failed to mark job delivered", error);
    driverActionError.value = error.response?.data?.message || "Unable to mark this job as delivered.";
  } finally {
    driverActionLoading.value = "";
  }
}

function openIncidentModal() {
  incidentError.value = "";
  incidentModalOpen.value = true;
  incidentForm.location_label = job.value?.current_latitude && job.value?.current_longitude ? "Current tracked location" : "";
  incidentForm.latitude = job.value?.current_latitude ?? null;
  incidentForm.longitude = job.value?.current_longitude ?? null;
}

function closeIncidentModal() {
  if (incidentSubmitting.value) return;
  incidentModalOpen.value = false;
}

function useCurrentIncidentLocation() {
  if (!navigator.geolocation) {
    incidentError.value = "Location is not available on this device.";
    return;
  }

  navigator.geolocation.getCurrentPosition(
    (position) => {
      incidentForm.latitude = position.coords.latitude;
      incidentForm.longitude = position.coords.longitude;
      incidentForm.location_label = "Driver current location";
      incidentError.value = "";
    },
    () => {
      incidentError.value = "Could not get your current location. You can still describe where you are.";
    },
    { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
  );
}

async function handleIncidentSubmit() {
  if (!job.value?.id || !canReportIncident.value) return;
  if (!incidentForm.description.trim()) {
    incidentError.value = "Add a short description so the dealer knows what happened.";
    return;
  }

  incidentSubmitting.value = true;
  incidentError.value = "";

  try {
    await reportJobIncident(job.value.id, {
      type: incidentForm.type,
      recovery_required: incidentForm.recovery_required,
      vehicle_safe: incidentForm.vehicle_safe,
      blocking_road: incidentForm.blocking_road,
      location_label: incidentForm.location_label,
      latitude: incidentForm.latitude,
      longitude: incidentForm.longitude,
      description: incidentForm.description
    });
    incidentModalOpen.value = false;
    incidentForm.description = "";
    await loadJob();
  } catch (error) {
    console.error("Failed to report incident", error);
    incidentError.value = error.response?.data?.message || "Unable to report this issue.";
  } finally {
    incidentSubmitting.value = false;
  }
}

function openRecoveryConfirmation(mode, incident) {
  recoverySendError.value = "";
  recoveryCompleteError.value = "";
  recoveryConfirmation.mode = mode;
  recoveryConfirmation.incident = incident;
  recoveryConfirmation.open = true;
}

function closeRecoveryConfirmation() {
  if (recoverySendingId.value || recoveryCompletingId.value) return;
  recoveryConfirmation.open = false;
  recoveryConfirmation.mode = "";
  recoveryConfirmation.incident = null;
}

async function confirmRecoveryAction() {
  const incident = recoveryConfirmation.incident;
  if (recoveryConfirmation.mode === "send") {
    await handleRecoverySent(incident);
    return;
  }

  if (recoveryConfirmation.mode === "complete") {
    await handleRecoveryCompleted(incident);
  }
}

async function handleRecoverySent(incident) {
  if (!job.value?.id || !incident?.id || !canSendRecovery.value) return;

  recoverySendingId.value = incident.id;
  recoverySendError.value = "";

  try {
    await markIncidentRecoverySent(job.value.id, incident.id);
    recoveryConfirmation.open = false;
    recoveryConfirmation.mode = "";
    recoveryConfirmation.incident = null;
    await loadJob();
  } catch (error) {
    console.error("Failed to mark recovery as sent", error);
    recoverySendError.value = error.response?.data?.message || "Unable to mark recovery as sent.";
  } finally {
    recoverySendingId.value = null;
  }
}

async function handleRecoveryCompleted(incident) {
  if (!job.value?.id || !incident?.id || !canConfirmRecoveryCompleted.value) return;

  recoveryCompletingId.value = incident.id;
  recoveryCompleteError.value = "";

  try {
    await markIncidentRecoveryCompleted(job.value.id, incident.id);
    recoveryConfirmation.open = false;
    recoveryConfirmation.mode = "";
    recoveryConfirmation.incident = null;
    await loadJob();
  } catch (error) {
    console.error("Failed to confirm recovery happened", error);
    recoveryCompleteError.value = error.response?.data?.message || "Unable to confirm recovery happened.";
  } finally {
    recoveryCompletingId.value = null;
  }
}

async function handleCheckout() {
  if (!job.value?.id) return;

  checkoutLoading.value = true;
  paymentError.value = "";

  try {
    const payload = await createJobCheckout(job.value.id);
    if (payload?.url) {
      window.location.href = payload.url;
      return;
    }
    throw new Error("Stripe did not return a checkout link.");
  } catch (error) {
    console.error("Failed to create Stripe checkout", error);
    paymentError.value = error.response?.data?.message || error.message || "Could not start payment.";
  } finally {
    checkoutLoading.value = false;
  }
}

async function handlePaymentSync(sessionId = null) {
  if (!job.value?.id) return;

  checkoutLoading.value = true;
  paymentError.value = "";
  paymentNotice.value = "";

  try {
    const payload = await syncJobPayment(job.value.id, sessionId);
    job.value = payload?.job ?? job.value;
    paymentNotice.value = payload?.payment_status === "paid"
      ? "Payment confirmed. Funds are now held by MotorRelay until delivery is approved."
      : "Payment is not confirmed yet. If the dealer finished checkout, try again in a moment.";
  } catch (error) {
    console.error("Failed to sync Stripe payment", error);
    paymentError.value = error.response?.data?.message || error.message || "Could not refresh payment status.";
  } finally {
    checkoutLoading.value = false;
  }
}

async function handleReleasePayout() {
  if (!job.value?.id) return;

  payoutReleaseLoading.value = true;
  paymentError.value = "";

  try {
    const payload = await releaseDriverPayout(job.value.id);
    job.value = payload?.job ?? job.value;
  } catch (error) {
    console.error("Failed to release driver payout", error);
    paymentError.value = error.response?.data?.message || error.message || "Could not release payout.";
  } finally {
    payoutReleaseLoading.value = false;
  }
}

async function handleDownloadProof() {
  if (!job.value) return;

  proofDownloading.value = true;
  try {
    const response = await downloadDeliveryProof(job.value.id);
    const contentType = response.headers?.["content-type"] || "application/octet-stream";
    const extension = contentType.includes("pdf")
      ? "pdf"
      : contentType.includes("png")
      ? "png"
      : contentType.includes("jpeg") || contentType.includes("jpg")
      ? "jpg"
      : "bin";
    const blob = new Blob([response.data], { type: contentType });
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = `${deliveryProofDownloadName()}.${extension}`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    window.URL.revokeObjectURL(url);
  } catch (error) {
    console.error("Failed to download inspection", error);
    alert("We could not download the inspection photos.");
  } finally {
    proofDownloading.value = false;
  }
}

onMounted(async () => {
  if (typeof window !== 'undefined') {
    window.addEventListener('motorrelay:job-event', handleRealtimeJobEvent);
  }

  if (!auth.user && auth.token) {
    await auth.fetchMe().catch(() => null);
  }
  startLiveTrackingListener();
  await loadJob();
  if (route.query.payment === "success") {
    await handlePaymentSync(route.query.session_id || null);
  } else if (route.query.payment === "cancelled") {
    paymentNotice.value = "Stripe checkout was cancelled. No payment was taken.";
  }
  scrollToApplicationsSection();
});

onBeforeUnmount(() => {
  if (typeof window !== 'undefined') {
    window.removeEventListener('motorrelay:job-event', handleRealtimeJobEvent);
    if (realtimeReloadTimer.value) {
      window.clearTimeout(realtimeReloadTimer.value);
      realtimeReloadTimer.value = null;
    }
  }

  stopLiveTrackingListener();
  stopLiveTrackingUpdates();
  clearInspectionPhotoPreviews();
});

watch(
  () => route.params.id,
  async () => {
    resetExpenseForm();
    resetCompletionForm();
    trackingState.shared = false;
    stopLiveTrackingUpdates();
    await loadJob();
    scrollToApplicationsSection();
  }
);

watch(
  () => route.query.section,
  () => {
    scrollToApplicationsSection();
  }
);

watch(
  () => job.value?.last_tracked_at,
  (value) => {
    if (value) {
      trackingState.lastUpdate = value;
    }
  }
);
</script>

<template>
  <div class="space-y-3">
    <div v-if="loading" class="rounded-2xl border bg-white p-4 text-sm text-slate-600">
      Loading run...
    </div>

    <div v-else-if="errorMessage" class="rounded-2xl border bg-white p-4 text-sm text-amber-600">
      {{ errorMessage }}
    </div>

    <div v-else-if="!job" class="rounded-2xl border bg-white p-4 text-sm text-slate-600">
      Run not found.
    </div>

    <div v-else class="space-y-3">
      <BackPillButton label="Runs" to="/jobs" />

      <RunDetailHeader
        :job="job"
        :display-amount="priceFormatter.format(headerDisplayAmount)"
        :is-driver-view="isDriverDetailView"
        :can-request-job="canRequestJob"
        :request-loading="jobRequestLoading"
        :show-driver-request-panel="showDriverRequestPanel"
        :my-application="myApplication"
        :can-use-driver-mode="canUseDriverMode"
        @request-job="handleRequestJob"
        @start-driver-mode="driverModeOpen = true"
      />

      <section
        v-if="shouldShowGoLiveBanner"
        class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800"
      >
        <div class="font-semibold text-amber-900 flex items-center justify-between gap-3">
          <span>Scheduled to go live soon</span>
          <RouterLink
            v-if="isDealerForJob"
            :to="{ name: 'job-edit', params: { id: job.id } }"
            class="inline-flex items-center gap-2 rounded-xl border border-amber-300 bg-white px-3 py-1 text-xs font-semibold text-amber-800 hover:bg-amber-100"
          >
            Edit run
            <span aria-hidden="true">→</span>
          </RouterLink>
        </div>
        <p class="mt-1">
          This run will become visible to drivers at
          <strong>{{ goLiveFormatted }}</strong>. You can still make changes for the next few minutes before it publishes.
        </p>
      </section>


      <RunRouteSummary :job="job" compact />

      <RunCompactProgress
        :current-label="currentWorkflowStep?.label || 'Complete'"
        :progress-percent="workflowProgressPercent"
        :completed-count="completedWorkflowCount"
        :total-count="workflowSteps.length"
        :photos-uploaded="hasDeliveryProof"
        :location-shared="Boolean(lastTrackedAt || trackingState.shared)"
        :status-label="formatStatusLabel(job.status)"
      />

      <RunQuickActions
        v-if="shouldShowRunQuickActions"
        :google-href="runQuickGoogleHref"
        :waze-href="runQuickWazeHref"
        :issue-to="runIssueRoute"
        :photos-to="runPhotosRoute"
        :show-issue="canReportIncident"
        :show-photos="canUploadInspection || hasInspectionPhotos || canReviewInspection"
      />

      <DealerLiveTrackingCard
        v-if="shouldShowDealerLiveTracking"
        :location="liveTrackingLocation"
        :map-src="dealerLiveTrackingMapSrc"
        :updated-label="dealerLiveTrackingUpdatedLabel"
      />

      <RunIncidentHistory
        v-if="job.incidents?.length"
        :incidents="job.incidents"
        :can-send-recovery="canSendRecovery"
        :can-confirm-recovery-completed="canConfirmRecoveryCompleted"
        :recovery-sending-id="recoverySendingId"
        :recovery-completing-id="recoveryCompletingId"
        :recovery-send-error="recoverySendError"
        :recovery-complete-error="recoveryCompleteError"
        @confirm-recovery="openRecoveryConfirmation"
      />

      <RunCompletionSummary
        v-if="showCompactCompletionPanel"
        :status-description="statusDescription"
        :completion-status-label="completionStatusLabel"
        :submitted-at="formatDateTime(job?.completion_submitted_at)"
        :approved-at="formatDateTime(job?.completion_approved_at)"
        :has-delivery-proof="hasDeliveryProof"
        :notes="job?.completion_notes"
        :proof-downloading="proofDownloading"
        :invoice-finalized="invoiceFinalized"
        :invoice-to="jobInvoiceLink"
        @download-proof="handleDownloadProof"
      />


      <section
        v-if="showApplicationsAtTop"
        ref="applicationsSection"
        id="run-applications"
        class="tile scroll-mt-28 border-emerald-200 bg-emerald-50/40 p-3 dark:border-emerald-400/30 dark:bg-emerald-400/10"
      >
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <p class="text-xs font-black uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Next step</p>
            <h2 class="mt-1 text-base font-black text-slate-950 dark:text-white">Review driver applications</h2>
          </div>
          <RouterLink
            :to="`/jobs/${job.id}/applications`"
            class="btn-primary inline-flex w-full justify-center px-4 py-2 text-sm sm:w-auto"
          >
            Applications {{ applicationsLoading ? '' : `(${applications.length})` }}
          </RouterLink>
        </div>
        <p v-if="applicationsError" class="mt-2 rounded-xl border border-amber-200 bg-amber-50 p-2 text-xs font-bold text-amber-700 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-100">
          {{ applicationsError }}
        </p>
      </section>

      <RunPaymentCard
        v-if="canManagePayment"
        :eyebrow="paymentCardEyebrow"
        :title="paymentCardTitle"
        :dealer-charge="priceFormatter.format(dealerPaymentAmount)"
        :driver-payout="priceFormatter.format(driverPayoutAmount)"
        :status="paymentStatus"
        :status-class="paymentStatusBadgeClass"
        :paid-text="job.paid_at ? `Paid ${formatDateTime(job.paid_at)}.` : ''"
        :payment-error="paymentError"
        :payment-notice="paymentNotice"
        :confirmation-text="paymentConfirmationText"
        :can-start-checkout="canStartCheckout"
        :can-release-payout="canReleasePayout"
        :checkout-loading="checkoutLoading"
        :payout-release-loading="payoutReleaseLoading"
        :action-help="paymentActionHelp"
        @checkout="handleCheckout"
        @sync-payment="handlePaymentSync()"
        @release-payout="handleReleasePayout"
      />




      <RunTrackingCard
        v-if="shouldShowTrackingCard && !isAssignedDriver"
        :has-tracking-ended="hasTrackingEnded"
        :can-share-tracking="canShareTracking"
        :can-request-tracking="canRequestTracking"
        :tracking-state="trackingState"
        :last-tracked-display="lastTrackedDisplay"
        @share-location="shareLiveLocation"
        @request-location="requestLocationUpdate"
        @open-navigation="navigationModalOpen = true"
        @open-settings="openLocationSettings"
      />


      <RunExpensesCard
        v-if="shouldShowExpenses"
        :expenses="expenses"
        :summary="expensesSummary"
        :loading="expensesLoading"
        :error="expensesError"
        :can-submit="canSubmitExpenses"
        :can-review="canReviewExpenses"
        :is-assigned-driver="isAssignedDriver"
        :form="expenseForm"
        :form-key="expenseFormKey"
        :form-error="expenseFormError"
        :submitting="expenseSubmitting"
        :editing-id="editingExpenseId"
        :receipt-downloading-id="receiptDownloadingId"
        @submit="handleExpenseSubmit"
        @receipt-change="onExpenseReceiptChange"
        @cancel-edit="cancelExpenseEdit"
        @download-receipt="handleDownloadReceipt"
        @edit="startEditingExpense"
        @delete="handleDeleteExpense"
        @review="handleReviewExpense"
      />


    </div>

    <DriverModeOverlay
      v-if="driverModeOpen && canUseDriverMode"
      :job="job"
      :route-label="driverModeRouteLabel"
      :status-label="driverModeStatusLabel"
      :next-action-text="driverNextActionText"
      :primary-action="driverModePrimaryAction"
      :show-secondary-tracking="driverModeShowSecondaryTracking"
      :tracking-state="trackingState"
      :completion-form="completionForm"
      :completion-error="completionError"
      :completion-submitting="completionSubmitting"
      :can-upload-inspection="canUploadInspection"
      :min-inspection-photo-count="minInspectionPhotoCount"
      :required-inspection-shots="requiredInspectionShots"
      :uploaded-photos="driverModeUploadedPhotos"
      :map-src="driverModeMapSrc"
      :pickup-short="driverModePickupShort"
      :dropoff-short="driverModeDropoffShort"
      :destination-label="driverModeDestinationLabel"
      :tracking-label="driverModeTrackingLabel"
      :completed-workflow-count="completedWorkflowCount"
      :workflow-total-count="workflowSteps.length"
      :timeline-items="driverModeTimelineItems"
      :driver-action-error="driverActionError"
      :navigation-links="driverModeNavigationLinks"
      :can-report-incident="canReportIncident"
      @close="driverModeOpen = false"
      @inspection-files="onDriverModeInspectionChange"
      @open-gallery="openInspectionGallery"
      @submit-photos="handleCompletionSubmit"
      @share-location="shareLiveLocation"
      @open-chat="openDriverChatModal"
      @report-issue="openIncidentModal"
    />


    <transition name="fade">
      <div
        v-if="inspectionGalleryOpen && currentInspectionGalleryPhoto"
        class="fixed inset-0 z-[130] flex flex-col bg-slate-950 text-white"
      >
        <header class="flex items-center justify-between gap-3 px-4 pb-3 pt-[calc(env(safe-area-inset-top)+1rem)]">
          <div>
            <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-300">Inspection gallery</p>
            <h3 class="mt-1 text-lg font-black">
              Photo {{ inspectionGalleryIndex + 1 }} of {{ driverModeUploadedPhotos.length }}
            </h3>
          </div>
          <button
            type="button"
            class="rounded-2xl border border-white/15 px-4 py-2 text-sm font-black text-white"
            @click="closeInspectionGallery"
          >
            Close
          </button>
        </header>

        <div
          class="relative flex min-h-0 flex-1 items-center justify-center px-3 pb-4"
          @touchstart.passive="onInspectionGalleryTouchStart"
          @touchend.passive="onInspectionGalleryTouchEnd"
        >
          <button
            v-if="driverModeUploadedPhotos.length > 1"
            type="button"
            class="absolute left-3 z-10 rounded-full bg-white/10 px-4 py-3 text-2xl font-black text-white backdrop-blur"
            @click="previousInspectionPhoto"
          >
            ‹
          </button>

          <img
            v-if="currentInspectionGalleryPhoto.previewUrl"
            :src="currentInspectionGalleryPhoto.previewUrl"
            :alt="currentInspectionGalleryPhoto.name"
            class="max-h-full max-w-full rounded-3xl object-contain shadow-2xl"
          >
          <div v-else class="rounded-3xl border border-white/10 bg-white/[0.06] p-8 text-center text-sm font-bold text-emerald-100">
            {{ currentInspectionGalleryPhoto.name }}
          </div>

          <button
            v-if="driverModeUploadedPhotos.length > 1"
            type="button"
            class="absolute right-3 z-10 rounded-full bg-white/10 px-4 py-3 text-2xl font-black text-white backdrop-blur"
            @click="nextInspectionPhoto"
          >
            ›
          </button>
        </div>

        <footer class="grid gap-2 px-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
          <p class="truncate text-center text-sm font-bold text-emerald-100">
            {{ currentInspectionGalleryPhoto.name }}
          </p>
          <button
            type="button"
            class="mx-auto rounded-2xl bg-white/10 px-4 py-2 text-xs font-black text-emerald-100"
            @click="removeInspectionFile(inspectionGalleryIndex)"
          >
            Remove this photo
          </button>
        </footer>
      </div>
    </transition>

    <transition name="fade">
      <div
        v-if="incidentModalOpen"
        class="fixed inset-0 z-[140] flex items-center justify-center bg-slate-900/70 px-4"
        @click.self="closeIncidentModal"
      >
        <form class="max-h-[90vh] w-full max-w-lg space-y-4 overflow-y-auto rounded-3xl bg-white p-5 shadow-2xl dark:bg-slate-950" @submit.prevent="handleIncidentSubmit">
          <header>
            <p class="text-xs font-black uppercase tracking-[0.18em] text-amber-600">Report issue</p>
            <h3 class="mt-1 text-xl font-black text-slate-950 dark:text-white">What happened?</h3>
            <p class="mt-1 text-sm text-slate-600 dark:text-emerald-100">
              This will notify the dealer and add the issue to the job chat.
            </p>
          </header>

          <label class="block">
            <span class="text-sm font-bold text-slate-700 dark:text-emerald-100">Issue type</span>
            <select v-model="incidentForm.type" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-900 dark:text-emerald-100">
              <option value="vehicle_breakdown">Vehicle breakdown</option>
              <option value="accident">Accident</option>
              <option value="access_issue">Cannot access vehicle</option>
              <option value="dealer_unavailable">Dealer/customer unavailable</option>
              <option value="wrong_address">Wrong address</option>
              <option value="other">Other issue</option>
            </select>
          </label>

          <div class="grid gap-2 sm:grid-cols-3">
            <label class="flex items-center gap-2 rounded-2xl border border-slate-200 p-3 text-sm font-semibold dark:border-white/10 dark:text-emerald-100">
              <input v-model="incidentForm.recovery_required" type="checkbox" class="h-4 w-4">
              Recovery needed
            </label>
            <label class="flex items-center gap-2 rounded-2xl border border-slate-200 p-3 text-sm font-semibold dark:border-white/10 dark:text-emerald-100">
              <input v-model="incidentForm.vehicle_safe" type="checkbox" class="h-4 w-4">
              Vehicle safe
            </label>
            <label class="flex items-center gap-2 rounded-2xl border border-slate-200 p-3 text-sm font-semibold dark:border-white/10 dark:text-emerald-100">
              <input v-model="incidentForm.blocking_road" type="checkbox" class="h-4 w-4">
              Blocking road
            </label>
          </div>

          <label class="block">
            <span class="text-sm font-bold text-slate-700 dark:text-emerald-100">Location</span>
            <div class="mt-2 flex gap-2">
              <input
                v-model="incidentForm.location_label"
                type="text"
                placeholder="e.g. hard shoulder near M65 J12"
                class="min-w-0 flex-1 rounded-2xl border border-slate-200 px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-900 dark:text-emerald-100"
              >
              <button type="button" class="btn-secondary px-4 py-2 text-sm" @click="useCurrentIncidentLocation">
                Use GPS
              </button>
            </div>
          </label>

          <label class="block">
            <span class="text-sm font-bold text-slate-700 dark:text-emerald-100">Details</span>
            <textarea
              v-model="incidentForm.description"
              rows="4"
              placeholder="Tell the dealer what happened and what help you need."
              class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-900 dark:text-emerald-100"
            ></textarea>
          </label>

          <p v-if="incidentError" class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-700">
            {{ incidentError }}
          </p>

          <div class="flex flex-wrap justify-end gap-2">
            <button type="button" class="btn-secondary px-4 py-2 text-sm" @click="closeIncidentModal">Cancel</button>
            <button type="submit" class="btn-primary px-4 py-2 text-sm" :disabled="incidentSubmitting">
              {{ incidentSubmitting ? 'Reporting...' : 'Report issue' }}
            </button>
          </div>
        </form>
      </div>
    </transition>

    <transition name="fade">
      <div
        v-if="driverChatOpen"
        class="fixed inset-0 z-[140] flex items-center justify-center bg-slate-900/70 px-4"
        @click.self="driverChatOpen = false"
      >
        <div class="flex max-h-[88vh] w-full max-w-lg flex-col rounded-3xl bg-white p-4 shadow-2xl dark:bg-slate-950">
          <header class="flex items-start justify-between gap-3 border-b border-slate-100 pb-3 dark:border-white/10">
            <div>
              <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-300">Driver chat</p>
              <h3 class="mt-1 text-xl font-black text-slate-950 dark:text-white">{{ job.title || `Run #${job.id}` }}</h3>
            </div>
            <button type="button" class="btn-secondary px-3 py-2 text-sm" @click="driverChatOpen = false">
              Close
            </button>
          </header>

          <div class="min-h-0 flex-1 overflow-y-auto py-3">
            <p v-if="driverChatLoading" class="rounded-2xl bg-slate-50 p-3 text-sm text-slate-600 dark:bg-white/[0.06] dark:text-emerald-100">
              Loading chat...
            </p>
            <p v-else-if="driverChatError" class="rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-700">
              {{ driverChatError }}
            </p>
            <div v-else-if="!driverChatMessages.length" class="rounded-2xl bg-slate-50 p-3 text-sm text-slate-600 dark:bg-white/[0.06] dark:text-emerald-100">
              No messages yet. Send a quick update to the dealer below.
            </div>
            <div v-else class="space-y-2">
              <article
                v-for="message in driverChatMessages"
                :key="message.id"
                class="rounded-2xl p-3 text-sm"
                :class="message.user?.id === auth.user?.id
                  ? 'ml-8 bg-emerald-600 text-white'
                  : 'mr-8 bg-slate-100 text-slate-800 dark:bg-white/[0.08] dark:text-emerald-100'"
              >
                <p class="text-[11px] font-black uppercase tracking-wide opacity-70">{{ message.user?.name || 'User' }}</p>
                <p class="mt-1 whitespace-pre-wrap">{{ message.body || 'Update sent.' }}</p>
              </article>
            </div>
          </div>

          <form class="border-t border-slate-100 pt-3 dark:border-white/10" @submit.prevent="sendDriverChatMessage">
            <textarea
              v-model="driverChatBody"
              rows="3"
              placeholder="Send the dealer a quick update..."
              class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-900 dark:text-emerald-100"
            ></textarea>
            <div class="mt-2 flex justify-end">
              <button type="submit" class="btn-primary px-4 py-2 text-sm" :disabled="driverChatSending || !driverChatBody.trim()">
                {{ driverChatSending ? 'Sending...' : 'Send message' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </transition>

    <transition name="fade">
      <div
        v-if="recoveryConfirmation.open"
        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 px-4"
        @click.self="closeRecoveryConfirmation"
      >
        <div class="w-full max-w-md space-y-4 rounded-3xl bg-white p-5 shadow-2xl dark:bg-slate-950">
          <header>
            <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-600 dark:text-emerald-300">
              {{ recoveryConfirmation.mode === 'send' ? 'Send recovery' : 'Confirm recovery' }}
            </p>
            <h3 class="mt-1 text-xl font-black text-slate-950 dark:text-white">
              {{ recoveryConfirmation.mode === 'send' ? 'Confirm recovery is being sent?' : 'Has recovery happened?' }}
            </h3>
            <p class="mt-2 text-sm text-slate-600 dark:text-emerald-100">
              <template v-if="recoveryConfirmation.mode === 'send'">
                This will tell the driver that recovery has been sent and add the update to the job chat.
              </template>
              <template v-else>
                This will tell the dealer recovery has happened and mark the reported issue as handled.
              </template>
            </p>
          </header>

          <div v-if="recoveryConfirmation.incident" class="rounded-2xl bg-slate-50 p-3 text-sm dark:bg-white/[0.06]">
            <p class="font-black capitalize text-slate-950 dark:text-white">
              {{ String(recoveryConfirmation.incident.type || '').replaceAll('_', ' ') }}
            </p>
            <p v-if="recoveryConfirmation.incident.description" class="mt-1 text-slate-600 dark:text-emerald-100">
              {{ recoveryConfirmation.incident.description }}
            </p>
          </div>

          <div class="flex flex-wrap justify-end gap-2">
            <button type="button" class="btn-secondary px-4 py-2 text-sm" @click="closeRecoveryConfirmation">
              Cancel
            </button>
            <button
              type="button"
              class="btn-primary px-4 py-2 text-sm"
              :disabled="Boolean(recoverySendingId || recoveryCompletingId)"
              @click="confirmRecoveryAction"
            >
              <template v-if="recoveryConfirmation.mode === 'send'">
                {{ recoverySendingId ? 'Sending...' : 'Send recovery' }}
              </template>
              <template v-else>
                {{ recoveryCompletingId ? 'Confirming...' : 'Yes, recovery happened' }}
              </template>
            </button>
          </div>
        </div>
      </div>
    </transition>

    <transition name="fade">
      <div
        v-if="navigationModalOpen"
        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 px-4"
        @click.self="closeNavigationModal"
      >
        <div class="w-full max-w-sm space-y-4 rounded-2xl bg-white p-6 shadow-2xl">
          <header class="space-y-1">
            <h3 class="text-lg font-semibold text-slate-900">Navigation</h3>
            <p class="text-sm text-slate-600">
              Choose an app to start directions to {{ navigationDestination || 'the drop-off location' }}.
            </p>
          </header>
          <div class="space-y-2">
            <a
              v-for="link in navigationLinks"
              :key="link.id"
              :href="link.href"
              target="_blank"
              rel="noopener"
              class="flex items-center justify-between rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
              @click="closeNavigationModal"
            >
              {{ link.label }}
              <span aria-hidden="true">↗</span>
            </a>
            <p v-if="!navigationLinks.length" class="text-xs text-slate-500">
              We could not determine a destination for this run yet.
            </p>
          </div>
          <button
            type="button"
            class="w-full rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
            @click="closeNavigationModal"
          >
            Close
          </button>
        </div>
      </div>
    </transition>
  </div>
</template>

<style scoped>
.route-car-animation {
  animation: route-car-drive 2.8s ease-in-out infinite;
}

@keyframes route-car-drive {
  0%,
  100% {
    transform: translateX(-46px);
  }
  50% {
    transform: translateX(46px);
  }
}

@media (max-width: 767px) {
  .route-car-animation {
    animation: route-car-drive-mobile 2.8s ease-in-out infinite;
  }

  @keyframes route-car-drive-mobile {
    0%,
    100% {
      transform: translateX(-70px);
    }
    50% {
      transform: translateX(70px);
    }
  }
}
</style>
