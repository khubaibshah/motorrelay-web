import { computed } from 'vue';

const deliveredStatuses = new Set(['delivered', 'completion_pending', 'completed', 'closed']);
const activeStatuses = ['accepted', 'in_progress', 'collected', 'in_transit', 'delivered', 'completion_pending', 'completed', 'closed'];

export function useRunWorkflow({
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
}) {
  const isCompletedJob = computed(() => ['completed', 'closed'].includes(String(job.value?.status || '').toLowerCase()));

  const workflowSteps = computed(() => {
    if (!job.value) return [];

    const status = String(job.value.status || '').toLowerCase();
    const isAssigned = Boolean(job.value.assigned_to_id);
    const paymentComplete = ['paid', 'payout_released'].includes(paymentStatus.value);
    // Inspection approval is a separate gate. It must not mark the vehicle as
    // delivered; delivery is complete only after the workflow status changes.
    const deliveryComplete = deliveredStatuses.has(status);
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
        label: 'Vehicle collected',
        help: 'The driver confirms collection after the inspection is approved.',
        complete: ['collected', 'in_transit', 'delivered', 'completion_pending', 'completed', 'closed'].includes(status)
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
    return activeStatuses.includes(String(job.value.status || '').toLowerCase());
  });

  return {
    completedWorkflowCount,
    currentWorkflowStep,
    isCompletedJob,
    showRunProgress,
    workflowProgressPercent,
    workflowSteps
  };
}
