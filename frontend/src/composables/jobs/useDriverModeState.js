import { computed } from "vue";
import { formatStatusLabel } from "@/utils/statusLabels";

function buildNavigationLinks(destination, googleLabel = "Open in Google Maps", wazeLabel = "Open in Waze") {
  if (!destination) return [];

  const encoded = encodeURIComponent(destination);

  return [
    {
      id: "google",
      label: googleLabel,
      href: `https://www.google.com/maps/dir/?api=1&destination=${encoded}&travelmode=driving`
    },
    {
      id: "waze",
      label: wazeLabel,
      href: `https://waze.com/ul?q=${encoded}&navigate=yes`
    }
  ];
}

export function useDriverModeState({
  job,
  trackingState,
  lastTrackedDisplay,
  hasDeliveryProof,
  isAssignedDriver,
  canMarkCollected,
  canMarkDelivered,
  canSubmitCompletion,
  canShareTracking,
  canReportIncident,
  canUploadInspection,
  canReviewInspection,
  hasInspectionPhotos,
  driverActionLoading,
  completionSubmitting,
  handlers
}) {
  const navigationDestination = computed(() => {
    if (!job.value) return "";
    return [job.value.dropoff_label, job.value.dropoff_postcode].filter(Boolean).join(", ");
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
    return buildNavigationLinks(driverModeNavigationDestination.value, "Google Maps", "Waze");
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
  const driverModeRouteLabel = computed(() => `${driverModePickupShort.value} to ${driverModeDropoffShort.value}`);
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
    const hasCollected = ["collected", "in_transit", "delivered", "completion_pending", "completed", "closed"].includes(status);
    const hasDelivered = ["delivered", "completion_pending", "completed", "closed"].includes(status);

    return [
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
        meta: hasCollected ? "Done" : "Next driving step",
        complete: hasCollected
      },
      {
        id: "delivered",
        label: "Vehicle delivered",
        meta: hasDelivered ? "Done" : "Not yet",
        complete: hasDelivered
      }
    ];
  });

  const driverModePrimaryAction = computed(() => {
    if (canMarkCollected.value) {
      return {
        id: "mark-collected",
        label: driverActionLoading.value === "collected" ? "Updating..." : "Mark collected",
        disabled: driverActionLoading.value === "collected",
        handler: handlers.markCollected
      };
    }

    if (canMarkDelivered.value) {
      return {
        id: "mark-delivered",
        label: driverActionLoading.value === "delivered" ? "Updating..." : "Mark delivered",
        disabled: driverActionLoading.value === "delivered",
        handler: handlers.markDelivered
      };
    }

    if (canSubmitCompletion.value) {
      return {
        id: "submit-completion",
        label: completionSubmitting.value ? "Submitting..." : "Submit completion",
        disabled: completionSubmitting.value,
        handler: handlers.submitCompletion
      };
    }

    if (canShareTracking.value && !trackingState.shared) {
      return {
        id: "share-location",
        label: trackingState.sending ? "Sharing location..." : "Share live location",
        disabled: trackingState.sending,
        handler: handlers.shareLocation
      };
    }

    return null;
  });

  const driverModeShowSecondaryTracking = computed(() => {
    return canShareTracking.value && !trackingState.shared && driverModePrimaryAction.value?.id !== "share-location";
  });

  const navigationLinks = computed(() => buildNavigationLinks(navigationDestination.value));

  const runQuickNavigationLinks = computed(() => {
    return isAssignedDriver.value ? driverModeNavigationLinks.value : navigationLinks.value;
  });

  const runQuickGoogleHref = computed(() => runQuickNavigationLinks.value.find((link) => link.id === "google")?.href || "");
  const runQuickWazeHref = computed(() => runQuickNavigationLinks.value.find((link) => link.id === "waze")?.href || "");

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

  return {
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
  };
}
