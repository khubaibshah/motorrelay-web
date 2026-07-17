import { reactive, ref } from "vue";
import {
  markIncidentRecoveryCompleted,
  markIncidentRecoverySent,
  reportJobIncident
} from "@/services/jobs";

export function useRunIncidents({
  job,
  canReportIncident,
  canSendRecovery,
  canConfirmRecoveryCompleted,
  reloadJob
}) {
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
      await reloadJob();
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
      await reloadJob();
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
      await reloadJob();
    } catch (error) {
      console.error("Failed to confirm recovery happened", error);
      recoveryCompleteError.value = error.response?.data?.message || "Unable to confirm recovery happened.";
    } finally {
      recoveryCompletingId.value = null;
    }
  }

  return {
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
  };
}
