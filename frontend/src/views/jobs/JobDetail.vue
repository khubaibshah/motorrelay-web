<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from "vue";
import { RouterLink, useRoute } from "vue-router";
import {
  fetchJobApplications,
  submitJobCompletion,
  uploadJobInspection,
  downloadDeliveryProof,
  updateJobLocation,
  markJobCollected,
  markJobDelivered,
  applyForJob,
  requestJobLocationUpdate,
  cancelJob
} from "@/services/jobs";
import { createJobCheckout, releaseDriverPayout, syncJobPayment } from "@/services/payments";
import { createEchoClient } from "@/services/realtime";
import { useAuthStore } from "@/stores/auth";
import { useDriverStore } from "@/stores/driver";
import { useJobsStore } from "@/stores/jobs";
import { AppLauncher } from "@capacitor/app-launcher";
import { Capacitor } from "@capacitor/core";
import { Geolocation } from "@capacitor/geolocation";
import BackPillButton from "@/components/BackPillButton.vue";
import DriverChatModal from "@/components/jobs/DriverChatModal.vue";
import DriverModeOverlay from "@/components/jobs/DriverModeOverlay.vue";
import InspectionGalleryOverlay from "@/components/jobs/InspectionGalleryOverlay.vue";
import JobIncidentModal from "@/components/jobs/JobIncidentModal.vue";
import NavigationAppModal from "@/components/jobs/NavigationAppModal.vue";
import RecoveryConfirmationModal from "@/components/jobs/RecoveryConfirmationModal.vue";
import JobActionConfirmDialog from "@/components/jobs/JobActionConfirmDialog.vue";
import RunCompactProgress from "@/components/jobs/RunCompactProgress.vue";
import RunCompletionSummary from "@/components/jobs/RunCompletionSummary.vue";
import RunDetailHeader from "@/components/jobs/RunDetailHeader.vue";
import RunIncidentHistory from "@/components/jobs/RunIncidentHistory.vue";
import RunRouteSummary from "@/components/jobs/RunRouteSummary.vue";
import RunQuickActions from "@/components/jobs/RunQuickActions.vue";
import RunTrackingCard from "@/components/jobs/RunTrackingCard.vue";
import { useRunPayments } from "@/composables/jobs/useRunPayments";
import { useRunWorkflow } from "@/composables/jobs/useRunWorkflow";
import { useDriverModeState } from "@/composables/jobs/useDriverModeState";
import { useInspectionGallery } from "@/composables/jobs/useInspectionGallery";
import { useDriverChat } from "@/composables/jobs/useDriverChat";
import { useRunIncidents } from "@/composables/jobs/useRunIncidents";
import { formatStatusLabel } from "@/utils/statusLabels";

const route = useRoute();
const auth = useAuthStore();
const driverStore = useDriverStore();
const jobsStore = useJobsStore();

const job = ref(null);
const loading = ref(false);
const errorMessage = ref("");

const applications = ref([]);
const applicationsLoading = ref(false);
const applicationsError = ref("");
const applicationsSection = ref(null);

const completionForm = reactive({
  notes: "",
  proof: []
});
const completionError = ref("");
const completionSubmitting = ref(false);
const proofDownloading = ref(false);
const driverActionLoading = ref("");
const driverActionError = ref("");
const driverModeOpen = ref(false);
const jobRequestLoading = ref(false);
const jobRequestError = ref("");
const cancelDialogOpen = ref(false);
const cancelDialogNote = ref("");
const cancelSubmitting = ref(false);
const cancelError = ref("");

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
const {
  uploadedPhotos: driverModeUploadedPhotos,
  galleryOpen: inspectionGalleryOpen,
  galleryIndex: inspectionGalleryIndex,
  currentPhoto: currentInspectionGalleryPhoto,
  resetUploadedPhotos: resetDriverModeUploadedPhotos,
  appendFiles: appendInspectionFiles,
  removeFile: removeInspectionFile,
  openGallery: openInspectionGallery,
  closeGallery: closeInspectionGallery,
  previousPhoto: previousInspectionPhoto,
  nextPhoto: nextInspectionPhoto,
  onTouchStart: onInspectionGalleryTouchStart,
  onTouchEnd: onInspectionGalleryTouchEnd
} = useInspectionGallery({ completionForm });
const {
  open: driverChatOpen,
  loading: driverChatLoading,
  sending: driverChatSending,
  error: driverChatError,
  messages: driverChatMessages,
  body: driverChatBody,
  openChat: openDriverChatModal,
  sendChatMessage: sendDriverChatMessage
} = useDriverChat({ job });

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

function resetCompletionForm() {
  completionForm.notes = "";
  completionForm.proof = [];
  completionError.value = "";
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
const {
  incidentModalOpen,
  incidentSubmitting,
  incidentError,
  incidentForm,
  recoverySendingId,
  recoverySendError,
  recoveryCompletingId,
  recoveryCompleteError,
  recoveryConfirmation,
  openIncidentModal,
  closeIncidentModal,
  useCurrentIncidentLocation,
  handleIncidentSubmit,
  openRecoveryConfirmation,
  closeRecoveryConfirmation,
  confirmRecoveryAction
} = useRunIncidents({
  job,
  canReportIncident,
  canSendRecovery,
  canConfirmRecoveryCompleted,
  reloadJob: loadJob
});
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
const hasDeliveryProof = computed(() => Boolean(job.value?.delivery_proof_path || hasInspectionPhotos.value));
const canReviewInspection = computed(() => {
  if (!(currentRole.value === "admin" || isDealerForJob.value)) return false;
  if (!hasDeliveryProof.value) return false;
  if (job.value?.finalized_invoice_id) return false;
  return ['not_submitted', 'rejected', 'inspection_approved'].includes(String(completionStatus.value || '').toLowerCase());
});

const {
  driverModeDestinationLabel,
  driverModeNavigationLinks,
  driverModePickupShort,
  driverModeDropoffShort,
  driverModeRouteLabel,
  driverModeStatusLabel,
  driverModeMapSrc,
  driverModeTrackingLabel,
  driverModeTimelineItems,
  driverModePrimaryAction,
  driverModeShowSecondaryTracking,
  navigationDestination,
  navigationLinks,
  runQuickGoogleHref,
  runQuickWazeHref,
  shouldShowRunQuickActions
} = useDriverModeState({
  job,
  trackingState,
  lastTrackedDisplay,
  hasDeliveryProof,
  isAssignedDriver,
  canMarkCollected,
  canMarkDelivered: canMarkDeliveredFromDetail,
  canSubmitCompletion,
  canShareTracking,
  canReportIncident,
  canUploadInspection,
  canReviewInspection,
  hasInspectionPhotos,
  driverActionLoading,
  completionSubmitting,
  handlers: {
    markCollected: handleDriverCollected,
    markDelivered: handleDriverDelivered,
    submitCompletion: handleCompletionSubmit,
    shareLocation: shareLiveLocation
  }
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
  return canUploadInspection.value || canSubmitCompletion.value || completionStatus.value !== 'not_submitted' || hasDeliveryProof.value || invoiceFinalized.value;
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
const canCancelJob = computed(() => {
  if (!job.value || !(isDealerForJob.value || currentRole.value === "admin")) return false;
  return !['completed', 'delivered', 'closed', 'cancelled'].includes(String(job.value.status || '').toLowerCase());
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

async function loadJob({ force = true } = {}) {
  const jobId = route.params.id;
  if (!jobId) {
    job.value = null;
    return;
  }

  loading.value = true;
  errorMessage.value = "";
  try {
    const payload = await jobsStore.fetchDetail(jobId, { force });
    job.value = payload?.data ?? payload ?? null;
    trackingState.lastUpdate = job.value?.last_tracked_at ?? null;
  } catch (error) {
    console.error("Failed to load run", error);
    errorMessage.value = "We could not load this run.";
    job.value = null;
    trackingState.lastUpdate = null;
  } finally {
    loading.value = false;
  }

  if (!job.value) {
    applications.value = [];
    return;
  }

  await loadApplicationsIfNeeded();
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

async function handleRequestJob() {
  if (!job.value?.id || !canRequestJob.value || jobRequestLoading.value) return;

  jobRequestLoading.value = true;
  jobRequestError.value = "";

  try {
    const response = await applyForJob(job.value.id);
    driverStore.addPendingApplication(job.value, response?.application ?? response?.data ?? response);
    await loadJob();
  } catch (error) {
    console.error("Failed to request run", error);
    jobRequestError.value = error.response?.data?.message || "Unable to request this run right now.";
  } finally {
    jobRequestLoading.value = false;
  }
}

function openCancelDialog() {
  cancelDialogNote.value = "";
  cancelError.value = "";
  cancelDialogOpen.value = true;
}

function closeCancelDialog() {
  if (cancelSubmitting.value) return;
  cancelDialogOpen.value = false;
}

async function confirmCancelJob() {
  if (!job.value?.id || !canCancelJob.value) return;

  cancelSubmitting.value = true;
  cancelError.value = "";
  try {
    await cancelJob(job.value.id, cancelDialogNote.value.trim() ? { reason: cancelDialogNote.value.trim() } : {});
    cancelDialogOpen.value = false;
    await loadJob();
  } catch (error) {
    console.error("Failed to cancel run", error);
    cancelError.value = error.response?.data?.message || "Unable to cancel this run right now.";
  } finally {
    cancelSubmitting.value = false;
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
  await loadJob({ force: false });
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
});

watch(
  () => route.params.id,
  async () => {
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
        :can-cancel-job="canCancelJob"
        :cancel-loading="cancelSubmitting"
        @request-job="handleRequestJob"
        @start-driver-mode="driverModeOpen = true"
        @cancel-job="openCancelDialog"
      />

      <p
        v-if="jobRequestError"
        class="rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm font-semibold text-amber-800 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-100"
      >
        {{ jobRequestError }}
      </p>

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

      <RunTrackingCard
        v-if="shouldShowTrackingCard && isDriverDetailView"
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


    <InspectionGalleryOverlay
      :open="inspectionGalleryOpen"
      :photo="currentInspectionGalleryPhoto"
      :index="inspectionGalleryIndex"
      :total="driverModeUploadedPhotos.length"
      @close="closeInspectionGallery"
      @previous="previousInspectionPhoto"
      @next="nextInspectionPhoto"
      @remove="removeInspectionFile(inspectionGalleryIndex)"
      @touch-start="onInspectionGalleryTouchStart"
      @touch-end="onInspectionGalleryTouchEnd"
    />

    <JobIncidentModal
      :open="incidentModalOpen"
      :form="incidentForm"
      :error="incidentError"
      :submitting="incidentSubmitting"
      @close="closeIncidentModal"
      @submit="handleIncidentSubmit"
      @use-current-location="useCurrentIncidentLocation"
    />

    <DriverChatModal
      v-model:body="driverChatBody"
      :open="driverChatOpen"
      :job="job"
      :loading="driverChatLoading"
      :sending="driverChatSending"
      :error="driverChatError"
      :messages="driverChatMessages"
      :current-user-id="auth.user?.id"
      @close="driverChatOpen = false"
      @send="sendDriverChatMessage"
    />

    <RecoveryConfirmationModal
      :confirmation="recoveryConfirmation"
      :recovery-sending-id="recoverySendingId"
      :recovery-completing-id="recoveryCompletingId"
      @close="closeRecoveryConfirmation"
      @confirm="confirmRecoveryAction"
    />

    <NavigationAppModal
      :open="navigationModalOpen"
      :destination="navigationDestination"
      :links="navigationLinks"
      @close="closeNavigationModal"
    />

    <JobActionConfirmDialog
      :open="cancelDialogOpen"
      mode="cancel"
      message="Cancel this run? The assigned driver will be notified and live tracking will end. Add a reason so everyone has a clear record."
      :pending="cancelSubmitting"
      :note="cancelDialogNote"
      @close="closeCancelDialog"
      @confirm="confirmCancelJob"
      @update:note="cancelDialogNote = $event"
    />

    <p
      v-if="cancelError"
      class="fixed bottom-24 left-1/2 z-[60] w-[min(90vw,32rem)] -translate-x-1/2 rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm font-semibold text-rose-700 shadow-xl"
    >
      {{ cancelError }}
    </p>
  </div>
</template>
