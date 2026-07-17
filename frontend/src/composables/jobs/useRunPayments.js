import { computed } from 'vue';

export function useRunPayments({
  job,
  currentRole,
  isDealerForJob,
  paymentStatus,
  completionStatus,
  hasDeliveryProof
}) {
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
    if (paymentStatus.value === 'unpaid') return 'Take payment before this run is offered to drivers.';
    if (paymentStatus.value === 'checkout_pending') return 'Stripe checkout has started. Refresh after the dealer completes payment.';
    if (paymentStatus.value === 'payout_released') return 'The driver payout has been released for this completed run.';
    return 'Funds are held by MotorRelay until the inspection is approved.';
  });

  const paymentStatusBadgeClass = computed(() => {
    if (paymentStatus.value === 'paid') return 'bg-emerald-100 text-emerald-700';
    if (paymentStatus.value === 'payout_released') return 'bg-slate-900 text-white';
    if (paymentStatus.value === 'checkout_pending') return 'bg-amber-100 text-amber-700';
    return 'bg-white text-slate-800';
  });

  const paymentConfirmationText = computed(() => {
    if (paymentStatus.value === 'paid') return 'Payment confirmed. Funds are held until the inspection is approved.';
    if (paymentStatus.value === 'payout_released') return 'Payout released. This payment workflow is complete.';
    return '';
  });

  return {
    canManagePayment,
    canReleasePayout,
    canStartCheckout,
    dealerPaymentAmount,
    driverPayoutAmount,
    estimatedPlatformFee,
    headerDisplayAmount,
    headerDisplayLabel,
    jobBasePrice,
    paymentActionHelp,
    paymentCardDescription,
    paymentCardEyebrow,
    paymentCardTitle,
    paymentConfirmationText,
    paymentStatusBadgeClass,
    platformFeeAmount
  };
}
