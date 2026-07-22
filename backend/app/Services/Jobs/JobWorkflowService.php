<?php

namespace App\Services\Jobs;

use App\Events\JobStatusChanged;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\JobApplication;
use App\Models\JobInspectionPhoto;
use App\Models\User;
use App\Services\AwsS3Service;
use App\Services\Invoices\InvoiceFinalizer;
use App\Services\Payments\StripePaymentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class JobWorkflowService
{
    private const DEALER_ASSIGNMENT_CANCEL_WINDOW_MINUTES = 30;

    public function __construct(
        protected AwsS3Service $s3,
        protected InvoiceFinalizer $invoiceFinalizer,
        protected StripePaymentService $payments,
        protected JobCompletionReportPdfGenerator $completionReports,
    ) {}

    public function markCollected(Job $job): Job
    {
        if (! $job->delivery_proof_path) {
            abort(422, 'Upload the pre-delivery inspection photos before marking this run as collected.');
        }

        if ($job->completion_status !== 'inspection_approved') {
            abort(422, 'The dealer must approve the inspection photos before collection.');
        }

        if (! $job->last_tracked_at || $job->current_latitude === null || $job->current_longitude === null) {
            abort(422, 'Share live location before marking the vehicle as collected.');
        }

        $job->update(['status' => 'in_transit']);

        $this->notifyDealer($job->fresh(), 'driver_marked_collected');

        return $job->fresh();
    }

    public function markDelivered(Job $job): Job
    {
        if (! in_array(strtolower((string) $job->status), ['collected', 'in_transit'], true)) {
            abort(422, 'Mark the vehicle as collected before marking this run as delivered.');
        }

        if (! $job->last_tracked_at || $job->current_latitude === null || $job->current_longitude === null) {
            abort(422, 'Share live location before marking the vehicle as delivered.');
        }

        $this->ensureDealerPaymentHeld($job);

        // A delivered run is no longer an active tracking session. The last
        // coordinates remain available for the dealer, while the tracking
        // endpoint rejects any further updates for this job status.
        $job->update(['status' => 'delivered']);

        $this->notifyDealer($job->fresh(), 'driver_marked_delivered');

        return $job->fresh();
    }

    /**
     * @param  array<int, UploadedFile>  $proofs
     */
    public function uploadInspection(Job $job, User $driver, ?string $notes, array $proofs): Job
    {
        if ($job->finalized_invoice_id) {
            abort(422, 'This job has already been finalized.');
        }

        if (! in_array(strtolower((string) $job->status), ['accepted', 'in_progress'], true)) {
            abort(422, 'Inspection photos should be uploaded before collection starts.');
        }

        $paths = $this->storeProofFiles($job, $proofs, $driver);
        if (empty($paths)) {
            abort(422, 'Upload inspection photos before collection.');
        }

        $job->update([
            'completion_notes' => $notes ?? $job->completion_notes,
            'completion_status' => $job->completion_status === 'rejected' ? 'not_submitted' : $job->completion_status,
            'completion_rejected_at' => null,
            'delivery_proof_path' => $paths[0],
            'delivery_proof_disk' => config('invoices.proof_disk'),
        ]);

        $this->notifyDealer($job->fresh(), 'driver_uploaded_inspection', [
            'notes' => $notes,
        ]);

        return $job->fresh();
    }

    public function cancel(Job $job, User $user, ?string $reason = null): Job
    {
        if ($user->isAdmin() || $job->posted_by_id === $user->id) {
            return $this->cancelByDealer($job, $user, $reason);
        }

        if ($job->assigned_to_id !== $user->id) {
            abort(403, 'You cannot cancel this job.');
        }

        if (in_array(strtolower((string) $job->status), ['completed', 'delivered', 'closed'], true)) {
            abort(422, 'Completed jobs cannot be cancelled.');
        }

        if ($job->delivery_proof_path) {
            $this->deleteProofs($job);
        }

        $job->update([
            'status' => 'open',
            'assigned_to_id' => null,
            'cancellation_reason' => $reason,
            'completion_status' => 'not_submitted',
            'completion_submitted_at' => null,
            'completion_notes' => null,
            'completion_approved_at' => null,
            'completion_rejected_at' => null,
            'delivery_proof_path' => null,
            'delivery_proof_disk' => null,
        ]);

        JobApplication::query()
            ->where('job_id', $job->id)
            ->where('driver_id', $user->id)
            ->update([
                'status' => 'withdrawn',
                'responded_at' => now(),
            ]);

        $this->notifyDealer($job->fresh(), 'driver_cancelled_job', [
            'reason' => $reason,
        ]);

        return $job->fresh();
    }

    public function submitCompletion(Job $job, User $driver, ?string $notes, ?UploadedFile $proof = null): Job
    {
        if ($job->finalized_invoice_id) {
            abort(422, 'This job has already been finalized.');
        }

        $this->ensureDealerPaymentHeld($job);

        if (! in_array(strtolower((string) $job->status), ['delivered'], true)) {
            abort(422, 'Mark the run as delivered before submitting completion.');
        }

        if (! $job->delivery_proof_path && ! $proof) {
            abort(422, 'Upload the pre-delivery inspection photos before submitting completion.');
        }

        if ($job->completion_status !== 'inspection_approved') {
            abort(422, 'The dealer must approve the inspection photos before completion can be submitted.');
        }

        $updates = [
            'status' => 'completion_pending',
            'completion_status' => 'submitted',
            'completion_submitted_at' => now(),
            'completion_notes' => $notes,
        ];

        if ($proof) {
            $updates['delivery_proof_path'] = $this->storeProofFiles($job, [$proof], $driver)[0] ?? $job->delivery_proof_path;
            $updates['delivery_proof_disk'] = config('invoices.proof_disk');
        }

        $job->update($updates);

        $this->notifyDealer($job->fresh(), 'driver_submitted_completion', [
            'notes' => $notes,
        ]);

        return $job->fresh();
    }

    public function approveCompletion(Job $job, User $dealer): array
    {
        if ($job->completion_status !== 'submitted') {
            abort(422, 'Completion has not been submitted or was already handled.');
        }

        if (! $job->delivery_proof_path) {
            abort(422, 'Pre-delivery inspection photos are required before approval.');
        }

        if (! in_array(strtolower((string) $job->status), ['delivered', 'completion_pending', 'completed', 'closed'], true)) {
            abort(422, 'The run must be delivered before approval.');
        }

        $this->ensureDealerPaymentHeld($job);

        $job->loadMissing(['expenses']);
        $invoice = $this->invoiceFinalizer->finalize($job, $dealer);
        $job->refresh()->load(['postedBy', 'assignedTo', 'inspectionPhotos', 'incidents.reportedBy', 'expenses']);
        $this->completionReports->store($job);

        $this->notifyAssignedDriver($job->fresh(), 'completion_approved');

        return [
            'message' => 'Job completion approved and invoice generated.',
            'invoice' => $this->summariseInvoice($invoice),
        ];
    }

    public function approveCompletionAndReleasePayout(Job $job, User $dealer): array
    {
        // Approval and payout are one dealer action. If invoice approval already
        // succeeded but Stripe failed, a retry only releases the pending payout.
        if ($job->completion_status !== 'submitted' && strtolower((string) $job->status) === 'delivered') {
            // Delivery itself is the driver's completion confirmation. Keep the
            // legacy completion field in sync so invoice finalisation can run.
            $job->update([
                'completion_status' => 'submitted',
                'completion_submitted_at' => $job->completion_submitted_at ?? now(),
            ]);
            $job->refresh();
        }

        if ($job->completion_status === 'submitted') {
            $this->approveCompletion($job, $dealer);
            $job->refresh();
        } elseif ($job->completion_status !== 'approved') {
            abort(422, 'The driver must submit completion before approval and payout.');
        }

        $payout = $this->payments->releasePayout($job->fresh());

        return [
            'message' => 'Delivery approved and driver payout released.',
            'job' => $payout['job'],
            'invoice' => $this->summariseInvoice($job->fresh()->finalizedInvoice),
        ];
    }

    public function rejectCompletion(Job $job, ?string $reason = null): Job
    {
        if ($job->completion_status !== 'submitted') {
            abort(422, 'There is no submitted completion to reject.');
        }

        $job->update([
            'status' => 'in_progress',
            'completion_status' => 'rejected',
            'completion_rejected_at' => now(),
            'completion_notes' => $reason ?? $job->completion_notes,
        ]);

        $this->notifyAssignedDriver($job->fresh(), 'completion_rejected', [
            'reason' => $reason,
        ]);

        return $job->fresh();
    }

    public function approveInspection(Job $job): Job
    {
        if (! $job->delivery_proof_path) {
            abort(422, 'Inspection photos are required before approval.');
        }

        if ($job->finalized_invoice_id) {
            abort(422, 'This job has already been finalized.');
        }

        if (! in_array($job->completion_status, ['not_submitted', 'rejected', 'inspection_approved'], true)) {
            abort(422, 'This inspection cannot be reviewed at this stage.');
        }

        $job->update([
            'completion_status' => 'inspection_approved',
            'completion_rejected_at' => null,
        ]);

        $this->notifyAssignedDriver($job->fresh(), 'inspection_approved');

        return $job->fresh(['inspectionPhotos']);
    }

    public function requestInspectionChanges(Job $job, ?string $reason = null): Job
    {
        if (! $job->delivery_proof_path) {
            abort(422, 'There are no inspection photos to review.');
        }

        if ($job->finalized_invoice_id) {
            abort(422, 'This job has already been finalized.');
        }

        $this->deleteProofs($job);

        $job->update([
            'completion_status' => 'rejected',
            'completion_rejected_at' => now(),
            'completion_notes' => $reason ?? $job->completion_notes,
            'delivery_proof_path' => null,
            'delivery_proof_disk' => null,
        ]);

        $this->notifyAssignedDriver($job->fresh(), 'inspection_changes_requested', [
            'reason' => $reason,
        ]);

        return $job->fresh(['inspectionPhotos']);
    }

    public function dealerComplete(Job $job, User $dealer): array
    {
        if (! $job->assigned_to_id) {
            abort(422, 'Assign a driver before completing the job.');
        }

        if ($job->status === 'completed') {
            return [
                'job' => $job->fresh(),
                'invoice' => $job->finalizedInvoice,
            ];
        }

        if (! $job->delivery_proof_path) {
            abort(422, 'Pre-delivery inspection photos are required before completing the job.');
        }

        if (! in_array(strtolower((string) $job->status), ['delivered', 'completion_pending'], true)) {
            abort(422, 'The driver must mark the run delivered before completion can be approved.');
        }

        if ($job->completion_status !== 'submitted') {
            abort(422, 'The driver must submit completion before the dealer can approve it.');
        }

        $invoice = $this->invoiceFinalizer->finalize($job->fresh(), $dealer);
        $job->refresh()->load(['postedBy', 'assignedTo', 'inspectionPhotos', 'incidents.reportedBy', 'expenses']);
        $this->completionReports->store($job);

        $this->notifyAssignedDriver($job->fresh(), 'completion_approved');

        return [
            'job' => $job->fresh(),
            'invoice' => $this->summariseInvoice($invoice),
        ];
    }

    protected function cancelByDealer(Job $job, User $user, ?string $reason): Job
    {
        $assigned = $job->assignedTo;

        // The cancellation window is enforced here on the server; the client countdown is display-only.
        if (! $user->isAdmin() && $assigned && $job->completion_status === 'inspection_approved') {
            abort(422, 'This run cannot be cancelled after the inspection photos are approved.');
        }
        if (! $user->isAdmin() && $assigned && $job->assigned_at && $job->assigned_at->copy()->addMinutes(self::DEALER_ASSIGNMENT_CANCEL_WINDOW_MINUTES)->isPast()) {
            abort(422, 'The 30-minute cancellation window after driver assignment has expired.');
        }

        $job->update([
            'status' => 'cancelled',
            'assigned_to_id' => null,
            'cancellation_reason' => $reason,
        ]);

        if ($assigned) {
            JobStatusChanged::dispatch($job->fresh(), 'dealer_cancelled_job', [$assigned->id], [
                'reason' => $reason,
            ]);
        }

        return $job->fresh();
    }

    protected function deleteProofs(Job $job): void
    {
        $photos = $job->inspectionPhotos()->get();

        if ($photos->isEmpty() && ! $job->delivery_proof_path) {
            return;
        }

        foreach ($photos as $photo) {
            $storage = Storage::disk($photo->disk ?? config('invoices.proof_disk'));
            if ($storage->exists($photo->path)) {
                $storage->delete($photo->path);
            }
        }

        if ($photos->isEmpty() && $job->delivery_proof_path) {
            $disk = $job->delivery_proof_disk ?? config('invoices.proof_disk');
            $storage = Storage::disk($disk);
            if ($storage->exists($job->delivery_proof_path)) {
                $storage->delete($job->delivery_proof_path);
            }
        }

        $job->inspectionPhotos()->delete();
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, string>
     */
    protected function storeProofFiles(Job $job, array $files, ?User $uploadedBy = null): array
    {
        $proofDisk = config('invoices.proof_disk');
        $files = collect($files)->filter()->values();

        if ($job->delivery_proof_path) {
            $this->deleteProofs($job);
        }

        return $files
            ->map(function (UploadedFile $file, int $index) use ($job, $proofDisk, $uploadedBy) {
                $directory = $this->inspectionDirectory($job);
                $extension = $file->getClientOriginalExtension() ?: 'jpg';
                $filename = sprintf('%s-%02d-%s.%s', now()->format('YmdHis'), $index + 1, Str::ulid(), $extension);

                $path = $this->s3->uploadFile($file, $directory, $filename, $proofDisk);

                JobInspectionPhoto::create([
                    'job_id' => $job->id,
                    'uploaded_by_id' => $uploadedBy?->id,
                    'disk' => $proofDisk,
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName() ?: $filename,
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'sort_order' => $index + 1,
                ]);

                return $path;
            })
            ->all();
    }

    protected function inspectionDirectory(Job $job): string
    {
        $reference = Str::lower((string) ($job->title ?: sprintf('job-%d', $job->id)));
        $reference = preg_replace('/[^a-z0-9]+/', '', $reference) ?: sprintf('job%d', $job->id);

        return sprintf('%s/inspection', $reference);
    }

    protected function notifyDealer(Job $job, string $event, ?array $meta = null): void
    {
        $job->loadMissing(['postedBy:id,name']);
        if (! $job->postedBy) {
            return;
        }

        JobStatusChanged::dispatch($job, $event, [$job->postedBy->id], $meta);
    }

    protected function notifyAssignedDriver(Job $job, string $event, ?array $meta = null): void
    {
        $job->loadMissing(['assignedTo:id,name']);
        if (! $job->assignedTo) {
            return;
        }

        JobStatusChanged::dispatch($job, $event, [$job->assignedTo->id], $meta);
    }

    protected function ensureDealerPaymentHeld(Job $job): void
    {
        if (! in_array($job->payment_status, ['paid', 'payout_released'], true)) {
            abort(422, 'Dealer payment must be held before the driver can progress this job.');
        }
    }

    protected function summariseInvoice(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->status,
            'currency' => $invoice->currency,
            'total' => $invoice->total,
            'issued_at' => $invoice->issued_at,
            'finalized_at' => $invoice->finalized_at,
        ];
    }
}
