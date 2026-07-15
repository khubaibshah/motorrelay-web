<script setup>
import { computed, onMounted, reactive, ref, watch } from "vue";
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
  approveJobCompletion,
  rejectJobCompletion,
  downloadExpenseReceipt,
  downloadDeliveryProof,
  updateJobLocation,
  markJobCollected,
  markJobDelivered,
  markIncidentRecoverySent,
  markIncidentRecoveryCompleted,
  reportJobIncident,
  applyForJob,
  requestJobLocationUpdate
} from "@/services/jobs";
import { createJobCheckout, releaseDriverPayout, syncJobPayment } from "@/services/payments";
import { useAuthStore } from "@/stores/auth";
import { AppLauncher } from "@capacitor/app-launcher";
import { Capacitor } from "@capacitor/core";
import { Geolocation } from "@capacitor/geolocation";
import RunRouteSummary from "@/components/jobs/RunRouteSummary.vue";
import { formatStatusLabel } from "@/utils/statusLabels";

const route = useRoute();
const auth = useAuthStore();

const job = ref(null);
const loading = ref(false);
const errorMessage = ref("");

const applications = ref([]);
const applicationsLoading = ref(false);
const applicationsError = ref("");

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
  proof: null
});
const completionFormKey = ref(0);
const completionError = ref("");
const completionSubmitting = ref(false);
const completionDecisionLoading = ref(false);
const proofDownloading = ref(false);
const driverActionLoading = ref("");
const driverActionError = ref("");
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

const priceFormatter = new Intl.NumberFormat("en-GB", {
  style: "currency",
  currency: "GBP",
  maximumFractionDigits: 0
});

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

async function shareLiveLocation() {
  if (!job.value) return;
  trackingState.error = "";
  trackingState.requestNotice = "";
  trackingState.locationBlocked = false;
  trackingState.locationServicesOff = false;
  trackingState.sending = true;
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
    trackingState.error =
      error?.response?.data?.message ||
      geolocationErrorMessage(error) ||
      "We could not determine your current location. Please try again.";
  } finally {
    trackingState.sending = false;
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
  completionForm.proof = null;
  completionError.value = "";
  completionFormKey.value += 1;
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

const navigationDestination = computed(() => {
  if (!job.value) return "";
  const parts = [job.value.dropoff_label, job.value.dropoff_postcode].filter(Boolean);
  return parts.join(", ");
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

const canUploadInspection = computed(() => {
  if (!isAssignedDriver.value) return false;
  if (!['paid', 'payout_released'].includes(paymentStatus.value)) return false;
  if (hasDeliveryProof.value) return false;
  if (job.value?.finalized_invoice_id) return false;
  return ['accepted', 'in_progress'].includes(String(job.value?.status || '').toLowerCase());
});
const canSubmitCompletion = computed(() => {
  if (!isAssignedDriver.value) return false;
  if (!['paid', 'payout_released'].includes(paymentStatus.value)) return false;
  if (!hasDeliveryProof.value) return false;
  if (['submitted', 'approved'].includes(String(completionStatus.value || '').toLowerCase())) return false;
  if (String(job.value?.status || '').toLowerCase() !== 'delivered') return false;
  return !job.value?.finalized_invoice_id;
});
const canMarkCollected = computed(() => {
  if (!isAssignedDriver.value) return false;
  if (!['paid', 'payout_released'].includes(paymentStatus.value)) return false;
  if (!hasDeliveryProof.value) return false;
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

const hasDeliveryProof = computed(() => Boolean(job.value?.delivery_proof_path));

const invoiceFinalized = computed(() => Boolean(job.value?.finalized_invoice_id));
const jobInvoiceLink = computed(() => ({
  name: 'invoices',
  query: {
    job: job.value?.id,
    invoice: job.value?.finalized_invoice_id
  }
}));
const isCompletedJob = computed(() => ['completed', 'closed'].includes(String(job.value?.status || '').toLowerCase()));
const shouldShowCompletionPanel = computed(() => {
  return canUploadInspection.value || canSubmitCompletion.value || canApproveCompletion.value || completionStatus.value !== 'not_submitted' || hasDeliveryProof.value || invoiceFinalized.value;
});
const showCompactCompletionPanel = computed(() => shouldShowCompletionPanel.value && isCompletedJob.value);
const showFullCompletionPanel = computed(() => shouldShowCompletionPanel.value && !showCompactCompletionPanel.value);
const workflowSteps = computed(() => {
  if (!job.value) return [];
  const status = String(job.value.status || '').toLowerCase();
  const deliveredStatuses = new Set(['delivered', 'completion_pending', 'completed', 'closed']);
  const isAssigned = Boolean(job.value.assigned_to_id);
  const paymentComplete = ['paid', 'payout_released'].includes(paymentStatus.value);
  const deliveryComplete = deliveredStatuses.has(status) || completionStatus.value !== 'not_submitted';
  const proofComplete = hasDeliveryProof.value || ['submitted', 'approved'].includes(completionStatus.value);
  const approvalComplete = invoiceFinalized.value || completionStatus.value === 'approved';
  const payoutComplete = paymentStatus.value === 'payout_released';

  if (isAssignedDriver.value) {
    return [
      {
      label: 'Run accepted',
        help: 'You have been assigned to this vehicle movement.',
        complete: isAssigned
      },
      {
        label: 'Upload inspection photos',
        help: 'Photograph the vehicle as soon as you arrive so the dealer and driver both have a record.',
        complete: proofComplete
      },
      {
        label: 'Collect vehicle',
        help: 'Collect the vehicle from the pickup location after the inspection is uploaded.',
        complete: ['collected', 'in_transit', 'delivered', 'completion_pending', 'completed', 'closed'].includes(status)
      },
      {
        label: 'Deliver vehicle',
        help: 'Mark the vehicle as delivered when it reaches the drop-off.',
        complete: deliveryComplete
      },
      {
        label: 'Dealer approval',
        help: 'The dealer checks your inspection photos and approves completion.',
        complete: approvalComplete
      },
      {
        label: 'Payout released',
        help: 'MotorRelay releases your payout after approval.',
        complete: payoutComplete
      }
    ];
  }

  if (!isDealerForJob.value && currentRole.value !== 'admin') {
    return [
      {
        label: 'Run posted',
        help: 'This run is available for driver requests.',
        complete: true
      },
      {
        label: myApplication.value ? 'Request sent' : 'Request run',
        help: myApplication.value ? 'Your request has been sent to the dealer.' : 'Send a request so the dealer can review you.',
        complete: Boolean(myApplication.value)
      },
      {
        label: 'Await dealer',
        help: 'The dealer chooses which driver to assign.',
        complete: isAssigned
      }
    ];
  }

  return [
    {
    label: 'Run posted',
      help: 'The dealer created this vehicle movement.',
      complete: true
    },
    {
      label: 'Dealer payment held',
      help: 'The dealer pays MotorRelay before payout can be released.',
      complete: paymentComplete
    },
    {
      label: 'Driver assigned',
      help: assignedDriver.value ? `${assignedDriver.value.name} is assigned.` : 'The dealer still needs to choose a driver.',
      complete: isAssigned
    },
    {
      label: 'Inspection uploaded',
      help: 'The driver uploads pre-delivery inspection photos for dealer review.',
      complete: proofComplete
    },
    {
      label: 'Vehicle delivered',
      help: 'The assigned driver marks the vehicle as delivered.',
      complete: deliveryComplete
    },
    {
      label: 'Approved and invoiced',
      help: 'The dealer approves completion and the invoice becomes available.',
      complete: approvalComplete
    },
    {
      label: 'Driver paid out',
      help: 'MotorRelay releases the driver payout after approval.',
      complete: payoutComplete
    }
  ];
});
const completedWorkflowCount = computed(() => workflowSteps.value.filter((step) => step.complete).length);
const workflowProgressPercent = computed(() => {
  if (!workflowSteps.value.length) return 0;
  if (workflowSteps.value.length === 1) return workflowSteps.value[0].complete ? 100 : 0;
  return Math.round((completedWorkflowCount.value / workflowSteps.value.length) * 100);
});
const currentWorkflowStep = computed(() => {
  return workflowSteps.value.find((step) => !step.complete) ?? workflowSteps.value[workflowSteps.value.length - 1] ?? null;
});
const showRunProgress = computed(() => {
  if (!job.value?.assigned_to_id) return false;
  if (isCompletedJob.value) return false;
  return ['accepted', 'in_progress', 'collected', 'in_transit', 'delivered', 'completion_pending', 'completed', 'closed'].includes(
    String(job.value.status || '').toLowerCase()
  );
});
const paymentStatus = computed(() => job.value?.payment_status || 'unpaid');
const canManagePayment = computed(() => Boolean(job.value) && (isDealerForJob.value || currentRole.value === 'admin'));
const jobBasePrice = computed(() => Number(job.value?.price || 0));
const estimatedPlatformFee = computed(() => Math.round(jobBasePrice.value * 0.1 * 100) / 100);
const platformFeeAmount = computed(() => {
  const stored = Number(job.value?.platform_fee_amount || 0);
  return stored > 0 ? stored : estimatedPlatformFee.value;
});
const driverPayoutAmount = computed(() => {
  const stored = Number(job.value?.driver_payout_amount || 0);
  return stored > 0 ? stored : Math.max(jobBasePrice.value - platformFeeAmount.value, 0);
});
const dealerPaymentAmount = computed(() => jobBasePrice.value);
const headerDisplayAmount = computed(() => (currentRole.value === 'driver' ? driverPayoutAmount.value : jobBasePrice.value));
const headerDisplayLabel = computed(() => (currentRole.value === 'driver' ? 'Driver payout' : 'Run value'));
const canStartCheckout = computed(() => {
  if (!job.value || !(isDealerForJob.value || currentRole.value === 'admin')) return false;
  return !['checkout_pending', 'paid', 'payout_released'].includes(paymentStatus.value);
});
const canReleasePayout = computed(() => {
  if (!job.value || !(isDealerForJob.value || currentRole.value === 'admin')) return false;
  if (paymentStatus.value !== 'paid') return false;
  if (!hasDeliveryProof.value) return false;
  return completionStatus.value === 'approved' && !job.value.stripe_transfer_id;
});
const paymentActionHelp = computed(() => {
  if (paymentStatus.value === 'unpaid') return 'Take dealer payment now so this run is funded before drivers start.';
  if (paymentStatus.value === 'checkout_pending') return 'Checkout has started. If the dealer paid, use refresh or wait for Stripe to confirm.';
  if (paymentStatus.value === 'paid' && completionStatus.value !== 'approved') return 'Payment is held. Payout unlocks only after the inspection is approved.';
  if (paymentStatus.value === 'paid' && completionStatus.value === 'approved') return 'Inspection is approved. You can now release the driver payout.';
  if (paymentStatus.value === 'payout_released') return 'Driver payout has been released.';
  return 'Take payment, choose a driver, review inspection photos, then release payout.';
});
const paymentCardEyebrow = computed(() => {
  if (paymentStatus.value === 'unpaid') return 'Next step';
  if (paymentStatus.value === 'checkout_pending') return 'Payment pending';
  if (paymentStatus.value === 'payout_released') return 'Payout complete';
  return 'Payment secured';
});
const paymentCardTitle = computed(() => {
  if (paymentStatus.value === 'unpaid') return 'Pay upfront';
  if (paymentStatus.value === 'checkout_pending') return 'Finish payment';
  if (paymentStatus.value === 'payout_released') return 'Driver paid';
  return 'Dealer payment held';
});
const paymentCardDescription = computed(() => {
  if (paymentStatus.value === 'unpaid') {
    return 'Take payment before this run is offered to drivers.';
  }
  if (paymentStatus.value === 'checkout_pending') {
    return 'Stripe checkout has started. Refresh after the dealer completes payment.';
  }
  if (paymentStatus.value === 'payout_released') {
    return 'The driver payout has been released for this completed run.';
  }
  return 'Funds are held by MotorRelay until the inspection is approved.';
});
const paymentStatusBadgeClass = computed(() => {
  if (paymentStatus.value === 'paid') return 'bg-emerald-100 text-emerald-700';
  if (paymentStatus.value === 'payout_released') return 'bg-slate-900 text-white';
  if (paymentStatus.value === 'checkout_pending') return 'bg-amber-100 text-amber-700';
  return 'bg-white text-slate-800';
});
const paymentConfirmationText = computed(() => {
  if (paymentStatus.value === 'paid') {
    return 'Payment confirmed. Funds are held until the inspection is approved.';
  }
  if (paymentStatus.value === 'payout_released') {
    return 'Payout released. This payment workflow is complete.';
  }
  return '';
});

const myApplication = computed(() => job.value?.my_application ?? null);
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
  } catch (error) {
    console.error("Failed to load run", error);
    errorMessage.value = "We could not load this run.";
    job.value = null;
    expenses.value = [];
    updateExpenseSummary([]);
    trackingState.lastUpdate = null;
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
  const [file] = event.target?.files ?? [];
  completionForm.proof = file ?? null;
}

async function handleCompletionSubmit() {
  if (!job.value) return;
  if (canUploadInspection.value && !completionForm.proof) {
    completionError.value = "Please upload inspection photos before collection.";
    return;
  }

  completionSubmitting.value = true;
  completionError.value = "";
  try {
    if (canUploadInspection.value) {
      await uploadJobInspection(job.value.id, {
        notes: completionForm.notes,
        proof: completionForm.proof
      });
    } else {
      await submitJobCompletion(job.value.id, {
        notes: completionForm.notes
      });
    }
    resetCompletionForm();
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
  if (!auth.user && auth.token) {
    await auth.fetchMe().catch(() => null);
  }
  await loadJob();
  if (route.query.payment === "success") {
    await handlePaymentSync(route.query.session_id || null);
  } else if (route.query.payment === "cancelled") {
    paymentNotice.value = "Stripe checkout was cancelled. No payment was taken.";
  }
});

watch(
  () => route.params.id,
  () => {
    resetExpenseForm();
    resetCompletionForm();
    trackingState.shared = false;
    loadJob();
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
  <div class="space-y-4">
    <div v-if="loading" class="rounded-2xl border bg-white p-4 text-sm text-slate-600">
      Loading run...
    </div>

    <div v-else-if="errorMessage" class="rounded-2xl border bg-white p-4 text-sm text-amber-600">
      {{ errorMessage }}
    </div>

    <div v-else-if="!job" class="rounded-2xl border bg-white p-4 text-sm text-slate-600">
      Run not found.
    </div>

    <div v-else class="space-y-4">
      <header class="tile space-y-4 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0 flex-1">
            <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-300">Run details</p>
            <h1 class="mt-1 break-words text-2xl font-black text-slate-950 dark:text-white">
              {{ job.title || `Run #${job.id}` }}
            </h1>
            <p class="mt-1 text-sm text-slate-600 dark:text-emerald-100">
              {{ job.company || 'Customer' }} · {{ job.vehicle_make || 'Vehicle' }}
            </p>
          </div>
          <span class="badge bg-slate-100 text-slate-800 dark:bg-white/10 dark:text-emerald-100">
            {{ formatStatusLabel(job.status) }}
          </span>
        </div>

        <div class="flex flex-col gap-3 border-t border-slate-100 pt-3 dark:border-white/10 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p class="text-xs font-black uppercase tracking-wide text-slate-500 dark:text-emerald-100">{{ headerDisplayLabel }}</p>
            <p class="mt-1 text-3xl font-black text-emerald-600 dark:text-emerald-300">
              {{ priceFormatter.format(headerDisplayAmount) }}
            </p>
          </div>
          <RouterLink to="/jobs" class="btn-secondary w-full px-4 py-2 text-sm sm:w-auto">
            Back to runs
          </RouterLink>
        </div>
      </header>

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

      <section
        v-if="showDriverRequestPanel"
        class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 shadow-sm"
      >
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p class="text-xs font-black uppercase tracking-wide text-emerald-700">Driver request</p>
            <div class="mt-1 flex flex-wrap items-center gap-2">
              <h2 class="text-xl font-black text-slate-950">{{ requestPanelTitle }}</h2>
              <span
                v-if="myApplication"
                class="inline-flex w-fit rounded-full px-3 py-1 text-xs font-bold ring-1"
                :class="applicationBadgeClass(myApplication.status)"
              >
                {{ formatStatusLabel(myApplication.status, 'Pending') }}
              </span>
            </div>
            <p class="mt-1 text-sm text-emerald-900">{{ requestPanelText }}</p>
            <p v-if="myApplication?.message" class="mt-2 text-xs text-emerald-800">
              Your note: {{ myApplication.message }}
            </p>
          </div>
          <button
            v-if="canRequestJob"
            type="button"
            class="btn-primary w-full px-5 py-3 text-sm sm:w-auto"
            :disabled="jobRequestLoading"
            @click="handleRequestJob"
          >
            <span v-if="jobRequestLoading">Sending request...</span>
            <span v-else>Request this run</span>
          </button>
          <span
            v-else
            class="inline-flex w-fit rounded-full bg-white px-3 py-1 text-xs font-bold text-emerald-700 ring-1 ring-emerald-200"
          >
            Request sent
          </span>
        </div>
        <p v-if="jobRequestError" class="mt-3 rounded-xl border border-rose-200 bg-white p-3 text-sm text-rose-700">
          {{ jobRequestError }}
        </p>
      </section>

      <RunRouteSummary :job="job" />

      <section v-if="job.incidents?.length" class="tile space-y-3 border-amber-200 bg-amber-50/50 p-4 dark:border-amber-400/30 dark:bg-amber-400/10">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <p class="text-xs font-black uppercase tracking-wide text-amber-700 dark:text-amber-200">Reported issues</p>
            <h2 class="mt-1 text-lg font-black text-slate-950 dark:text-white">Incident history</h2>
          </div>
          <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-800 dark:bg-amber-300 dark:text-slate-950">
            {{ job.incidents.length }} logged
          </span>
        </div>
        <p v-if="recoverySendError" class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm font-bold text-rose-700 dark:border-rose-400/30 dark:bg-rose-400/10 dark:text-rose-100">
          {{ recoverySendError }}
        </p>
        <p v-if="recoveryCompleteError" class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm font-bold text-rose-700 dark:border-rose-400/30 dark:bg-rose-400/10 dark:text-rose-100">
          {{ recoveryCompleteError }}
        </p>
        <div class="space-y-2">
          <article
            v-for="incident in job.incidents"
            :key="incident.id"
            class="rounded-2xl border border-amber-200 bg-white p-3 text-sm dark:border-amber-400/20 dark:bg-white/[0.06]"
          >
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div>
                <p class="font-black capitalize text-slate-950 dark:text-white">{{ String(incident.type || '').replaceAll('_', ' ') }}</p>
                <p class="mt-1 text-xs text-slate-600 dark:text-emerald-100">
                  Reported by {{ incident.reported_by?.name || 'driver' }} · {{ formatDateTime(incident.created_at) }}
                </p>
              </div>
              <div v-if="incident.recovery_required" class="flex flex-wrap items-center justify-end gap-2">
                <span
                  class="rounded-full px-2.5 py-1 text-xs font-bold"
                  :class="incident.recovery_completed_at
                    ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-950'
                    : incident.recovery_sent_at
                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-300 dark:text-slate-950'
                    : 'bg-rose-100 text-rose-700 dark:bg-rose-400/20 dark:text-rose-100'"
                >
                  {{ incident.recovery_completed_at ? 'Recovery happened' : incident.recovery_sent_at ? 'Recovery sent' : 'Recovery requested' }}
                </span>
                <button
                  v-if="canSendRecovery && !incident.recovery_sent_at"
                  type="button"
                  class="btn-primary w-full px-4 py-2 text-sm sm:w-auto"
                  :disabled="recoverySendingId === incident.id"
                  @click="openRecoveryConfirmation('send', incident)"
                >
                  {{ recoverySendingId === incident.id ? 'Sending...' : 'Send recovery' }}
                </button>
                <button
                  v-if="canConfirmRecoveryCompleted && incident.recovery_sent_at && !incident.recovery_completed_at"
                  type="button"
                  class="btn-primary w-full px-4 py-2 text-sm sm:w-auto"
                  :disabled="recoveryCompletingId === incident.id"
                  @click="openRecoveryConfirmation('complete', incident)"
                >
                  {{ recoveryCompletingId === incident.id ? 'Confirming...' : 'Recovery happened' }}
                </button>
              </div>
            </div>
            <p v-if="incident.description" class="mt-2 text-slate-700 dark:text-emerald-100">{{ incident.description }}</p>
            <p v-if="incident.location_label" class="mt-1 text-xs text-slate-500 dark:text-emerald-100">Location: {{ incident.location_label }}</p>
            <p v-if="incident.recovery_sent_at" class="mt-2 text-xs font-bold text-emerald-700 dark:text-emerald-200">
              Recovery confirmed by {{ incident.recovery_sent_by?.name || 'dealer' }} · {{ formatDateTime(incident.recovery_sent_at) }}
            </p>
            <p v-if="incident.recovery_completed_at" class="mt-1 text-xs font-bold text-slate-700 dark:text-emerald-100">
              Recovery happened confirmed by {{ incident.recovery_completed_by?.name || 'driver' }} · {{ formatDateTime(incident.recovery_completed_at) }}
            </p>
          </article>
        </div>
      </section>

      <section v-if="showCompactCompletionPanel" class="tile space-y-3 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <p class="text-xs font-black uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Completion</p>
            <h2 class="mt-1 text-lg font-black text-slate-950 dark:text-white">Run completed</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-emerald-100">
              {{ statusDescription }}
            </p>
          </div>
          <span class="badge bg-emerald-100 text-emerald-700 dark:bg-emerald-400 dark:text-slate-950">
            {{ completionStatus }}
          </span>
        </div>

        <div class="grid gap-2 text-xs sm:grid-cols-3">
          <div class="rounded-2xl bg-slate-50 p-3 dark:bg-white/[0.06]">
            <span class="font-black uppercase tracking-wide text-slate-500 dark:text-emerald-100">Submitted</span>
            <p class="mt-1 text-sm font-bold text-slate-950 dark:text-white">{{ formatDateTime(job?.completion_submitted_at) }}</p>
          </div>
          <div class="rounded-2xl bg-slate-50 p-3 dark:bg-white/[0.06]">
            <span class="font-black uppercase tracking-wide text-slate-500 dark:text-emerald-100">Approved</span>
            <p class="mt-1 text-sm font-bold text-slate-950 dark:text-white">{{ formatDateTime(job?.completion_approved_at) }}</p>
          </div>
          <div class="rounded-2xl bg-slate-50 p-3 dark:bg-white/[0.06]">
            <span class="font-black uppercase tracking-wide text-slate-500 dark:text-emerald-100">Inspection</span>
            <p class="mt-1 text-sm font-bold text-slate-950 dark:text-white">{{ hasDeliveryProof ? 'Uploaded' : 'Not uploaded' }}</p>
          </div>
        </div>

        <p v-if="job?.completion_notes" class="rounded-2xl bg-slate-50 p-3 text-sm text-slate-600 dark:bg-white/[0.06] dark:text-emerald-100">
          {{ job.completion_notes }}
        </p>

        <div class="grid gap-2 sm:flex sm:flex-wrap">
          <button
            v-if="hasDeliveryProof"
            type="button"
            class="btn-secondary w-full px-4 py-2 text-sm sm:w-auto"
            :disabled="proofDownloading"
            @click="handleDownloadProof"
          >
            <span v-if="proofDownloading">Downloading...</span>
            <span v-else>Download inspection</span>
          </button>
          <RouterLink
            v-if="invoiceFinalized"
            :to="jobInvoiceLink"
            class="btn-secondary w-full px-4 py-2 text-center text-sm sm:w-auto"
          >
            View this invoice
          </RouterLink>
        </div>
      </section>

      <section v-if="showRunProgress" class="tile space-y-4 p-4">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 class="text-sm font-black uppercase tracking-wide text-slate-500 dark:text-emerald-100">Run progress</h2>
            <p class="mt-1 text-xs text-slate-500 dark:text-emerald-100">
              Next: {{ currentWorkflowStep?.label || 'Complete' }}
            </p>
          </div>
          <span class="badge bg-emerald-100 text-emerald-700">
            {{ completedWorkflowCount }} / {{ workflowSteps.length }} done
          </span>
        </div>

        <div class="relative pt-1">
          <div class="h-3 overflow-hidden rounded-full bg-slate-100">
            <div
              class="h-full rounded-full bg-gradient-to-r from-emerald-500 to-sky-500 transition-all duration-500"
              :style="{ width: `${workflowProgressPercent}%` }"
            ></div>
          </div>
        </div>

        <ol class="hidden gap-2 text-xs sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
          <li
            v-for="step in workflowSteps"
            :key="`compact-${step.label}`"
            class="flex items-center gap-2 rounded-xl px-2 py-1.5"
            :class="step.complete ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-50 text-slate-500'"
          >
            <span
              class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[10px] font-black"
              :class="step.complete ? 'bg-emerald-600 text-white' : 'bg-white text-slate-400 ring-1 ring-slate-200'"
            >
              {{ step.complete ? '✓' : '•' }}
            </span>
            <span class="font-bold">{{ step.label }}</span>
          </li>
        </ol>

        <div
          v-if="showDriverNextAction"
          class="flex flex-col gap-3 rounded-2xl border border-emerald-200 bg-emerald-50/70 p-3 dark:border-emerald-400/30 dark:bg-emerald-400/10 sm:flex-row sm:items-center sm:justify-between"
        >
          <div>
            <p class="text-xs font-black uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Next driver action</p>
            <p class="mt-1 text-sm font-bold text-slate-950 dark:text-white">{{ currentWorkflowStep?.label || 'Complete' }}</p>
            <p class="mt-1 text-xs text-slate-600 dark:text-emerald-100">{{ driverNextActionText }}</p>
          </div>
          <div class="grid gap-2 sm:flex sm:flex-wrap">
            <button
              v-if="canMarkCollected"
              type="button"
              class="btn-primary w-full sm:w-auto"
              :disabled="driverActionLoading === 'collected'"
              @click="handleDriverCollected"
            >
              <span v-if="driverActionLoading === 'collected'">Updating...</span>
              <span v-else>Mark collected</span>
            </button>
            <button
              v-if="canMarkDeliveredFromDetail"
              type="button"
              class="btn-primary w-full sm:w-auto"
              :disabled="driverActionLoading === 'delivered'"
              @click="handleDriverDelivered"
            >
              <span v-if="driverActionLoading === 'delivered'">Updating...</span>
              <span v-else>Mark delivered</span>
            </button>
            <button
              v-if="canReportIncident"
              type="button"
              class="btn-secondary w-full border-amber-200 px-4 py-2 text-sm text-amber-700 hover:border-amber-300 hover:text-amber-800 sm:w-auto dark:border-amber-400/30 dark:text-amber-200"
              @click="openIncidentModal"
            >
              Report issue
            </button>
          </div>
        </div>

        <p v-if="driverActionError" class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-700">
          {{ driverActionError }}
        </p>
      </section>

      <section v-if="showApplicationsAtTop" class="tile space-y-4 border-emerald-200 bg-emerald-50/40 p-4">
        <header class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p class="text-xs font-black uppercase tracking-wide text-emerald-700">Next step</p>
            <h2 class="mt-1 text-lg font-black text-slate-950">Choose a driver</h2>
            <p class="text-sm text-slate-600">
              Payment is taken upfront. Review applications and assign one driver after funding is confirmed.
            </p>
          </div>
          <span class="badge bg-white text-slate-800">{{ applications.length }} total</span>
        </header>

        <div v-if="applicationsLoading" class="rounded-xl border bg-white p-4 text-sm text-slate-600">
          Loading applications...
        </div>

        <div
          v-else-if="applicationsError"
          class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-700"
        >
          {{ applicationsError }}
        </div>

        <div v-else-if="!applications.length" class="rounded-xl border border-dashed border-slate-200 bg-white p-4 text-sm text-slate-600">
          No driver applications yet. This section will update when drivers request the run.
        </div>

        <div v-else class="space-y-3">
          <article
            v-for="application in applications"
            :key="application.id"
            class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"
          >
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p class="text-base font-black text-slate-950">
                  {{ application.driver?.name || 'Driver' }}
                </p>
                <p class="text-xs text-slate-500">
                  Applied {{ new Date(application.created_at).toLocaleString() }}
                </p>
              </div>
              <span
                class="badge"
                :class="{
                  'bg-emerald-100 text-emerald-700': application.status === 'accepted',
                  'bg-amber-100 text-amber-700': application.status === 'pending',
                  'bg-slate-200 text-slate-700': application.status === 'declined'
                }"
              >
                {{ formatStatusLabel(application.status, 'Pending') }}
              </span>
            </div>

            <p v-if="application.message" class="mt-3 rounded-xl bg-slate-50 p-3 text-sm text-slate-600">
              "{{ application.message }}"
            </p>

            <div class="mt-4 flex flex-wrap gap-2">
              <button
                type="button"
                class="btn-secondary px-4 py-2 text-sm disabled:opacity-60"
                :disabled="application.status !== 'pending'"
                @click="handleApplicationDecision(application.id, 'declined')"
              >
                Decline
              </button>
              <button
                type="button"
                class="btn-primary px-4 py-2 text-sm disabled:opacity-60"
                :disabled="application.status !== 'pending'"
                @click="handleApplicationDecision(application.id, 'accepted')"
              >
                Accept and assign
              </button>
            </div>
          </article>
        </div>
      </section>

      <section
        v-if="canManagePayment"
        class="tile space-y-4 border-sky-200 bg-sky-50/40 p-4"
      >
        <header class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p class="text-xs font-black uppercase tracking-wide text-sky-700">{{ paymentCardEyebrow }}</p>
            <h2 class="mt-1 text-lg font-black text-slate-950">{{ paymentCardTitle }}</h2>
            <p class="text-sm text-slate-600">
              {{ paymentCardDescription }}
            </p>
          </div>
          <span class="badge uppercase" :class="paymentStatusBadgeClass">{{ paymentStatus }}</span>
        </header>

        <dl class="grid gap-3 text-sm sm:grid-cols-3">
          <div class="rounded-2xl border border-slate-200 bg-white p-3">
            <dt class="text-xs font-semibold uppercase text-slate-500">Dealer charge</dt>
            <dd class="mt-1 font-black text-slate-900">{{ priceFormatter.format(dealerPaymentAmount) }}</dd>
          </div>
          <div class="rounded-2xl border border-slate-200 bg-white p-3">
            <dt class="text-xs font-semibold uppercase text-slate-500">Platform fee</dt>
            <dd class="mt-1 font-black text-emerald-700">{{ priceFormatter.format(platformFeeAmount) }}</dd>
          </div>
          <div class="rounded-2xl border border-slate-200 bg-white p-3">
            <dt class="text-xs font-semibold uppercase text-slate-500">Driver payout</dt>
            <dd class="mt-1 font-black text-slate-900">{{ priceFormatter.format(driverPayoutAmount) }}</dd>
          </div>
        </dl>

        <p class="text-xs text-slate-500">
          <span v-if="job.paid_at">Paid {{ formatDateTime(job.paid_at) }}.</span>
          <span v-else>Not paid yet. Payment should be completed before a driver starts.</span>
        </p>

        <p v-if="paymentError" class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700">
          {{ paymentError }}
        </p>
        <p v-if="paymentNotice" class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-700">
          {{ paymentNotice }}
        </p>
        <p v-else-if="paymentConfirmationText" class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-700">
          {{ paymentConfirmationText }}
        </p>

        <div class="grid gap-2 sm:flex sm:flex-wrap">
          <button
            v-if="canStartCheckout"
            type="button"
            class="btn-primary w-full sm:w-auto"
            :disabled="checkoutLoading"
            @click="handleCheckout"
          >
            <span v-if="checkoutLoading">Opening checkout...</span>
            <span v-else>Pay for this run</span>
          </button>
          <button
            v-if="paymentStatus === 'checkout_pending'"
            type="button"
            class="btn-secondary w-full sm:w-auto"
            :disabled="checkoutLoading"
            @click="handlePaymentSync()"
          >
            <span v-if="checkoutLoading">Checking payment...</span>
            <span v-else>Refresh payment status</span>
          </button>
          <button
            v-if="canReleasePayout"
            type="button"
            class="btn-primary w-full sm:w-auto"
            :disabled="payoutReleaseLoading"
            @click="handleReleasePayout"
          >
            <span v-if="payoutReleaseLoading">Releasing payout...</span>
            <span v-else>Release driver payout</span>
          </button>
          <p
            class="w-full text-xs text-slate-500"
          >
            {{ paymentActionHelp }}
          </p>
        </div>
      </section>

      <section v-if="false" class="tile space-y-4 p-4">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Run progress</h2>
            <p class="mt-1 text-xs text-slate-500">
              Next: {{ currentWorkflowStep?.label || 'Complete' }}
            </p>
          </div>
          <span class="badge bg-emerald-100 text-emerald-700">
            {{ completedWorkflowCount }} / {{ workflowSteps.length }} done
          </span>
        </div>

        <div class="relative pt-1">
          <div class="h-3 overflow-hidden rounded-full bg-slate-100">
            <div
              class="h-full rounded-full bg-gradient-to-r from-emerald-500 to-sky-500 transition-all duration-500"
              :style="{ width: `${workflowProgressPercent}%` }"
            ></div>
          </div>
        </div>

        <ol class="hidden">
          <li
            v-for="step in workflowSteps"
            :key="step.label"
            class="rounded-2xl border p-3"
            :class="step.complete ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-slate-200 bg-slate-50 text-slate-600'"
          >
            <div class="flex items-center gap-2">
              <span
                class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-black"
                :class="step.complete ? 'bg-emerald-600 text-white' : 'bg-white text-slate-500'"
              >
                {{ step.complete ? '✓' : '•' }}
              </span>
              <h3 class="text-sm font-black">{{ step.label }}</h3>
            </div>
            <p class="mt-2 text-xs leading-5">{{ step.help }}</p>
          </li>
        </ol>

        <ol class="grid gap-2 text-xs sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
          <li
            v-for="step in workflowSteps"
            :key="`compact-${step.label}`"
            class="flex items-center gap-2 rounded-xl px-2 py-1.5"
            :class="step.complete ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-50 text-slate-500'"
          >
            <span
              class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[10px] font-black"
              :class="step.complete ? 'bg-emerald-600 text-white' : 'bg-white text-slate-400 ring-1 ring-slate-200'"
            >
              {{ step.complete ? '✓' : '•' }}
            </span>
            <span class="font-bold">{{ step.label }}</span>
          </li>
        </ol>
      </section>

      <section v-if="false" class="tile p-4">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Status</h2>

        <p class="mt-2 text-sm text-slate-600">
          {{ statusDescription }}
        </p>

        <div class="mt-4 space-y-2">
          <label class="text-xs font-semibold uppercase tracking-wide text-slate-500" for="job-driver-select">
            Assigned driver
          </label>
          <select
            id="job-driver-select"
            class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-700"
            disabled
          >
            <option v-if="assignedDriver" :value="assignedDriver.id">
              {{ assignedDriver.name }} ({{ assignedDriver.email }})
            </option>
            <option v-else value="unassigned">
              No driver assigned
            </option>
          </select>
        </div>

        <p
          v-if="(isDealerForJob || currentRole === 'admin') && !job.assigned_to_id"
          class="mt-4 rounded-xl border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-700"
        >
          Payment should be completed before the driver starts. Review applications below after funding is confirmed.
        </p>

      </section>

      <section
        v-if="false"
        class="tile space-y-4 p-4"
      >
        <header class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Payment</h2>
            <p class="text-xs text-slate-500">
              Take dealer payment now. MotorRelay holds it until the inspection is approved.
            </p>
          </div>
          <span class="badge bg-slate-100 text-slate-800 uppercase">{{ paymentStatus }}</span>
        </header>

        <dl class="grid gap-3 text-sm sm:grid-cols-3">
          <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
            <dt class="text-xs font-semibold uppercase text-slate-500">Dealer charge</dt>
            <dd class="mt-1 font-black text-slate-900">{{ priceFormatter.format(dealerPaymentAmount) }}</dd>
          </div>
          <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
            <dt class="text-xs font-semibold uppercase text-slate-500">Platform fee</dt>
            <dd class="mt-1 font-black text-emerald-700">{{ priceFormatter.format(platformFeeAmount) }}</dd>
          </div>
          <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
            <dt class="text-xs font-semibold uppercase text-slate-500">Driver payout</dt>
            <dd class="mt-1 font-black text-slate-900">{{ priceFormatter.format(driverPayoutAmount) }}</dd>
          </div>
        </dl>

        <p class="text-xs text-slate-500">
          <span v-if="job.paid_at">Paid {{ formatDateTime(job.paid_at) }}.</span>
          <span v-else>Not paid yet. Payment should be completed before a driver starts.</span>
        </p>

        <p v-if="paymentError" class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700">
          {{ paymentError }}
        </p>
        <p v-if="paymentNotice" class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-700">
          {{ paymentNotice }}
        </p>

        <div class="grid gap-2 sm:flex sm:flex-wrap">
          <button
            v-if="canStartCheckout"
            type="button"
            class="btn-primary w-full sm:w-auto"
            :disabled="checkoutLoading"
            @click="handleCheckout"
          >
            <span v-if="checkoutLoading">Opening checkout...</span>
            <span v-else>Pay for this run</span>
          </button>
          <button
            v-if="paymentStatus === 'checkout_pending'"
            type="button"
            class="btn-secondary w-full sm:w-auto"
            :disabled="checkoutLoading"
            @click="handlePaymentSync()"
          >
            <span v-if="checkoutLoading">Checking payment...</span>
            <span v-else>Refresh payment status</span>
          </button>
          <button
            v-if="canReleasePayout"
            type="button"
            class="btn-primary w-full sm:w-auto"
            :disabled="payoutReleaseLoading"
            @click="handleReleasePayout"
          >
            <span v-if="payoutReleaseLoading">Releasing payout...</span>
            <span v-else>Release driver payout</span>
          </button>
          <p
            v-if="!canStartCheckout && !canReleasePayout"
            class="text-xs text-slate-500"
          >
            {{ paymentActionHelp }}
          </p>
          <p
            v-else
            class="w-full text-xs text-slate-500"
          >
            {{ paymentActionHelp }}
          </p>
        </div>
      </section>

      <section v-if="shouldShowTrackingCard" class="tile space-y-3 p-4">
        <header class="space-y-1">
          <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Live tracking</h2>
          <p v-if="hasTrackingEnded" class="text-xs text-slate-500">
            Live tracking has ended for this run. The last shared location is kept on the job record.
          </p>
          <p v-else-if="canShareTracking" class="text-xs text-slate-500">
            Share your current position with the dealer while this run is active.
          </p>
          <p v-else class="text-xs text-slate-500">
            Request a live location update from the assigned driver while the run is active.
          </p>
        </header>
        <div v-if="!hasTrackingEnded" class="flex flex-wrap gap-3">
          <button
            v-if="canShareTracking"
            type="button"
            class="rounded-xl px-4 py-2 text-sm font-semibold shadow disabled:cursor-not-allowed"
            :class="trackingState.shared
              ? 'bg-slate-200 text-slate-600'
              : 'bg-emerald-600 text-white hover:bg-emerald-700 disabled:bg-emerald-300'"
            :disabled="trackingState.sending || trackingState.shared"
            @click="shareLiveLocation"
          >
            <span v-if="trackingState.sending">Sharing location…</span>
            <span v-else-if="trackingState.shared">Live location shared</span>
            <span v-else>Share live location</span>
          </button>
          <button
            v-if="canRequestTracking"
            type="button"
            class="btn-primary w-full px-4 py-2 text-sm sm:w-auto"
            :disabled="trackingState.requesting"
            @click="requestLocationUpdate"
          >
            <span v-if="trackingState.requesting">Requesting...</span>
            <span v-else>Request location update</span>
          </button>
          <button
            v-if="canShareTracking"
            type="button"
            class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
            @click="navigationModalOpen = true"
          >
            Open navigation apps
          </button>
        </div>
        <p v-if="trackingState.error" class="text-xs text-rose-600">
          {{ trackingState.error }}
        </p>
        <p v-if="trackingState.requestError" class="text-xs text-rose-600">
          {{ trackingState.requestError }}
        </p>
        <p v-if="trackingState.requestNotice" class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs font-semibold text-emerald-800">
          {{ trackingState.requestNotice }}
        </p>
        <p v-if="trackingState.locationServicesOff" class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs font-semibold text-amber-800">
          On iPhone, open Settings → Privacy & Security → Location Services, turn Location Services on, then return to MotorRelay.
        </p>
        <button
          v-if="trackingState.locationBlocked"
          type="button"
          class="btn-secondary w-full px-4 py-2 text-sm sm:w-auto"
          @click="openLocationSettings"
        >
          Open MotorRelay settings
        </button>
        <p v-if="lastTrackedDisplay" class="text-xs text-slate-500">
          Last shared: {{ lastTrackedDisplay }}
        </p>
        <p v-else-if="hasTrackingEnded" class="text-xs text-slate-500">
          No live location was shared before this run ended.
        </p>
      </section>

      <section v-if="basicAnalytics" class="tile space-y-4 p-4">
        <header class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Basic analytics</h2>
            <p class="text-xs text-slate-500">Snapshot of views generated while this run is live.</p>
          </div>
          <span class="rounded-full bg-emerald-100 px-3 py-0.5 text-xs font-semibold uppercase tracking-wide text-emerald-700">
            {{ basicAnalytics.views_last_7_days }} this week
          </span>
        </header>
        <div class="grid gap-4 sm:grid-cols-3">
          <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Views today</p>
            <p class="text-2xl font-bold text-slate-900">{{ basicAnalytics.views_today }}</p>
          </div>
          <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Last 7 days</p>
            <p class="text-2xl font-bold text-slate-900">{{ basicAnalytics.views_last_7_days }}</p>
          </div>
          <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Daily log</p>
            <ul class="mt-1 space-y-1 text-xs text-slate-500">
              <li v-for="day in basicAnalytics.daily" :key="day.date">
                {{ new Date(day.date).toLocaleDateString() }} - {{ day.views }} views
              </li>
            </ul>
          </div>
        </div>
      </section>

      <section v-if="shouldShowExpenses" class="tile space-y-4 p-4">
        <header class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
          <div>
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Expenses</h2>
            <p class="text-xs text-slate-500">
              Track expense submissions and approvals for this run.
            </p>
          </div>
          <div class="flex flex-wrap gap-3 text-xs text-slate-500">
            <span>
              Submitted:
              <span class="font-semibold text-slate-800">{{ formatCurrency(expensesSummary.submitted_total) }}</span>
            </span>
            <span>
              Approved:
              <span class="font-semibold text-emerald-700">{{ formatCurrency(expensesSummary.approved_total) }}</span>
            </span>
            <span>
              Rejected:
              <span class="font-semibold text-slate-800">{{ formatCurrency(expensesSummary.rejected_total) }}</span>
            </span>
          </div>
        </header>

        <p v-if="expensesError" class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-700">
          {{ expensesError }}
        </p>

        <form
          v-if="canSubmitExpenses"
          class="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 md:grid-cols-4"
          @submit.prevent="handleExpenseSubmit"
        >
          <div class="md:col-span-2">
            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500">Description</label>
            <input
              v-model="expenseForm.description"
              type="text"
              required
              class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
              placeholder="Taxi from auction"
            />
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500">Amount</label>
            <input
              v-model="expenseForm.amount"
              type="number"
              min="0"
              step="0.01"
              required
              class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
              placeholder="32.00"
            />
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500">VAT %</label>
            <input
              v-model="expenseForm.vat_rate"
              type="number"
              min="0"
              step="0.5"
              class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
            />
          </div>
          <div class="md:col-span-2">
            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500">Receipt</label>
            <input
              :key="expenseFormKey"
              type="file"
              accept=".jpg,.jpeg,.png,.pdf"
              class="mt-1 w-full text-sm text-slate-600"
              @change="onExpenseReceiptChange"
            />
            <p class="mt-1 text-xs text-slate-500">Images or PDF up to 5 MB.</p>
          </div>
          <div class="md:col-span-2 flex items-end justify-end gap-2">
            <button
              v-if="editingExpenseId"
              type="button"
              class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100"
              @click="cancelExpenseEdit"
            >
              Cancel
            </button>
            <button
              type="submit"
              class="rounded-xl bg-emerald-600 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
              :disabled="expenseSubmitting"
            >
              <span v-if="expenseSubmitting">{{ editingExpenseId ? 'Updating...' : 'Saving...' }}</span>
              <span v-else>{{ editingExpenseId ? 'Update expense' : 'Add expense' }}</span>
            </button>
          </div>
          <p v-if="expenseFormError" class="md:col-span-4 text-xs text-amber-700">{{ expenseFormError }}</p>
        </form>

        <div v-if="expensesLoading" class="rounded-xl border bg-slate-50 p-4 text-sm text-slate-600">
          Loading expenses...
        </div>

        <div
          v-else-if="!expenses.length"
          class="rounded-xl border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-600"
        >
          No expenses submitted yet.
        </div>

        <div v-else class="space-y-3">
          <article
            v-for="expense in expenses"
            :key="expense.id"
            class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"
          >
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p class="text-sm font-semibold text-slate-900">
                  {{ expense.description }}
                </p>
                <p class="text-xs text-slate-500">
                  Submitted {{ formatDateTime(expense.submitted_at) }}
                  <span v-if="expense.driver?.name"> - {{ expense.driver.name }}</span>
                </p>
              </div>
              <span
                class="badge"
                :class="{
                  'bg-emerald-100 text-emerald-700': expense.status === 'approved',
                  'bg-amber-100 text-amber-700': expense.status === 'submitted',
                  'bg-rose-100 text-rose-700': expense.status === 'rejected'
                }"
              >
                {{ formatStatusLabel(expense.status, 'Submitted') }}
              </span>
            </div>

            <dl class="mt-3 grid gap-2 text-xs text-slate-600 sm:grid-cols-3">
              <div>
                <dt class="uppercase tracking-wide">Net</dt>
                <dd class="font-semibold text-slate-900">{{ formatCurrency(expense.amount) }}</dd>
              </div>
              <div>
                <dt class="uppercase tracking-wide">VAT ({{ expense.vat_rate }}%)</dt>
                <dd class="font-semibold text-slate-900">{{ formatCurrency(expense.vat_amount) }}</dd>
              </div>
              <div>
                <dt class="uppercase tracking-wide">Total</dt>
                <dd class="font-semibold text-slate-900">{{ formatCurrency(expense.total_amount) }}</dd>
              </div>
            </dl>

            <p v-if="expense.review_note" class="mt-2 rounded-xl bg-slate-50 p-3 text-xs text-slate-600">
              Dealer note: {{ expense.review_note }}
            </p>

            <div class="mt-3 flex flex-wrap gap-2">
              <button
                v-if="expense.receipt_path"
                type="button"
                class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-60"
                :disabled="receiptDownloadingId === expense.id"
                @click="handleDownloadReceipt(expense)"
              >
                <span v-if="receiptDownloadingId === expense.id">Downloading...</span>
                <span v-else>Receipt</span>
              </button>

              <button
                v-if="isAssignedDriver && expense.is_editable"
                type="button"
                class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                @click="startEditingExpense(expense)"
              >
                Edit
              </button>
              <button
                v-if="isAssignedDriver && expense.is_editable"
                type="button"
                class="rounded-xl border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50"
                @click="handleDeleteExpense(expense)"
              >
                Delete
              </button>

              <button
                v-if="canReviewExpenses && expense.status === 'submitted'"
                type="button"
                class="rounded-xl border border-emerald-200 px-3 py-2 text-xs font-semibold text-emerald-700 hover:bg-emerald-50"
                @click="handleReviewExpense(expense, 'approved')"
              >
                Approve
              </button>
              <button
                v-if="canReviewExpenses && expense.status === 'submitted'"
                type="button"
                class="rounded-xl border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50"
                @click="handleReviewExpense(expense, 'rejected')"
              >
                Reject
              </button>
            </div>
          </article>
        </div>
      </section>

      <section
        v-if="showFullCompletionPanel"
        class="tile space-y-4 p-4"
      >
        <header class="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
          <div>
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Pre-delivery inspection</h2>
            <p class="text-xs text-slate-500">
              Drivers upload inspection photos as soon as they arrive. Dealers review them later as the run progresses.
            </p>
          </div>
          <span class="badge bg-slate-100 text-slate-800 uppercase">{{ completionStatus }}</span>
        </header>

        <div class="grid gap-3 text-xs text-slate-600 sm:grid-cols-2">
          <div>
            <span class="font-semibold text-slate-500 uppercase tracking-wide">Submitted</span>
            <p class="text-sm font-semibold text-slate-900">
              {{ formatDateTime(job?.completion_submitted_at) }}
            </p>
          </div>
          <div>
            <span class="font-semibold text-slate-500 uppercase tracking-wide">Approved</span>
            <p class="text-sm font-semibold text-slate-900">
              {{ formatDateTime(job?.completion_approved_at) }}
            </p>
          </div>
          <div>
            <span class="font-semibold text-slate-500 uppercase tracking-wide">Rejected</span>
            <p class="text-sm font-semibold text-slate-900">
              {{ formatDateTime(job?.completion_rejected_at) }}
            </p>
          </div>
          <div>
            <span class="font-semibold text-slate-500 uppercase tracking-wide">Notes</span>
            <p class="text-sm text-slate-900">
              {{ job?.completion_notes || '--' }}
            </p>
          </div>
        </div>

        <p v-if="completionError" class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-700">
          {{ completionError }}
        </p>

        <form
          v-if="canUploadInspection || canSubmitCompletion"
          class="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 md:grid-cols-2"
          @submit.prevent="handleCompletionSubmit"
        >
          <div class="md:col-span-2">
            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500">
              {{ canUploadInspection ? 'Inspection notes' : 'Completion notes' }}
            </label>
            <textarea
              v-model="completionForm.notes"
              rows="2"
              class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
              :placeholder="canUploadInspection ? 'Photo notes, vehicle condition, or collection details' : 'Delivered at 17:45, keys left with reception'"
            ></textarea>
          </div>
          <div v-if="canUploadInspection" class="md:col-span-2">
            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500">Inspection photos</label>
            <input
              :key="completionFormKey"
              type="file"
              accept=".jpg,.jpeg,.png,.pdf"
              class="mt-1 w-full text-sm text-slate-600"
              multiple
              required
              @change="onCompletionProofChange"
            />
            <p class="mt-1 text-xs text-slate-500">Upload inspection images or a signed PDF before collecting the vehicle.</p>
          </div>
          <div v-else class="md:col-span-2 rounded-2xl border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-800">
            Inspection is uploaded. Submit completion after delivery so the dealer can approve and generate the invoice.
          </div>
          <div class="md:col-span-2 flex justify-end">
            <button
              type="submit"
              class="rounded-xl bg-emerald-600 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
              :disabled="completionSubmitting"
            >
              <span v-if="completionSubmitting">{{ canUploadInspection ? 'Uploading...' : 'Submitting...' }}</span>
              <span v-else>{{ canUploadInspection ? 'Upload inspection' : 'Submit completion' }}</span>
            </button>
          </div>
        </form>

        <div
          v-if="hasDeliveryProof"
          class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-xs text-slate-600"
        >
          <div>
            <span class="font-semibold text-slate-500 uppercase tracking-wide">Inspection photos</span>
            <p class="text-sm text-slate-900">
              {{ completionStatus === 'submitted' ? `Completion submitted ${formatDateTime(job?.completion_submitted_at)}` : 'Uploaded before collection' }}
            </p>
          </div>
          <button
            type="button"
            class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-60"
          :disabled="proofDownloading"
          @click="handleDownloadProof"
        >
          <span v-if="proofDownloading">Downloading...</span>
          <span v-else>Download inspection</span>
        </button>
      </div>

        <div v-if="canApproveCompletion" class="flex flex-wrap gap-2">
          <button
            type="button"
            class="rounded-xl bg-emerald-600 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
            :disabled="completionDecisionLoading"
            @click="handleApproveCompletion"
          >
            <span v-if="completionDecisionLoading">Processing...</span>
            <span v-else>Approve completion</span>
          </button>
          <button
            type="button"
            class="rounded-xl border border-rose-200 px-4 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-60"
            :disabled="completionDecisionLoading"
            @click="handleRejectCompletion"
          >
            Request changes
          </button>
        </div>

        <div
          v-if="invoiceFinalized"
          class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-xs text-emerald-700"
        >
          Invoice finalised.
          <RouterLink :to="jobInvoiceLink" class="font-semibold text-emerald-800 underline">View this invoice</RouterLink>
        </div>
      </section>

      <section v-if="false" class="tile space-y-4 p-4">
        <header class="flex items-center justify-between">
          <div>
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Driver applications</h2>
            <p class="text-xs text-slate-500">
              Review pending applications and choose a driver. The selected driver will receive messaging access.
            </p>
          </div>
          <span class="badge bg-slate-100 text-slate-800">{{ applications.length }} total</span>
        </header>

        <div v-if="applicationsLoading" class="rounded-xl border bg-slate-50 p-4 text-sm text-slate-600">
          Loading applications...
        </div>

        <div
          v-else-if="applicationsError"
          class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-700"
        >
          {{ applicationsError }}
        </div>

        <div v-else-if="!applications.length" class="rounded-xl border bg-slate-50 p-4 text-sm text-slate-600">
          No driver applications yet.
        </div>

        <div v-else class="space-y-3">
          <article
            v-for="application in applications"
            :key="application.id"
            class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"
          >
            <div class="flex flex-wrap items-center justify-between gap-3">
              <div>
                <p class="text-sm font-semibold text-slate-900">
                  {{ application.driver?.name || 'Driver' }}
                </p>
                <p class="text-xs text-slate-500">
                  Applied {{ new Date(application.created_at).toLocaleString() }}
                </p>
              </div>
              <span
                class="badge"
                :class="{
                  'bg-emerald-100 text-emerald-700': application.status === 'accepted',
                  'bg-amber-100 text-amber-700': application.status === 'pending',
                  'bg-slate-200 text-slate-700': application.status === 'declined'
                }"
              >
                {{ formatStatusLabel(application.status, 'Pending') }}
              </span>
            </div>

            <p v-if="application.message" class="mt-2 rounded-xl bg-slate-50 p-3 text-sm text-slate-600">
              "{{ application.message }}"
            </p>

            <div class="mt-3 flex flex-wrap gap-2">
              <button
                type="button"
                class="rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-60"
                :disabled="application.status !== 'pending'"
                @click="handleApplicationDecision(application.id, 'declined')"
              >
                Decline
              </button>
              <button
                type="button"
                class="rounded-xl bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
                :disabled="application.status !== 'pending'"
                @click="handleApplicationDecision(application.id, 'accepted')"
              >
                Accept and assign
              </button>
            </div>
          </article>
        </div>
      </section>
    </div>

    <transition name="fade">
      <div
        v-if="incidentModalOpen"
        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 px-4"
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
