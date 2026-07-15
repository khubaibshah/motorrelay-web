<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Job;
use App\Models\JobApplication;
use App\Notifications\JobStatusNotification;
use App\Services\Invoices\InvoiceFinalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class JobWorkflowController extends Controller
{
    public function accept(Request $request, Job $job): JsonResponse
    {
        $user = $request->user();

        if (!$user || (!$user->isDriver() && !$user->isAdmin())) {
            abort(403, 'Only drivers can apply for jobs.');
        }

        if ($job->assigned_to_id) {
            abort(422, 'Job has already been assigned.');
        }

        $application = JobApplication::updateOrCreate(
            [
                'job_id' => $job->id,
                'driver_id' => $user->id,
            ],
            [
                'status' => 'pending',
                'message' => $request->filled('message')
                    ? $request->string('message')->toString()
                    : null,
                'responded_at' => null,
            ]
        );

        $job->loadMissing('postedBy:id,name');
        if ($job->postedBy) {
            Notification::send($job->postedBy, new JobStatusNotification($job->fresh(), 'driver_applied', [
                'driver' => [
                    'id' => $user->id,
                    'name' => $user->name,
                ],
                'message' => $request->filled('message') ? $request->string('message')->toString() : null,
            ]));
        }

        return response()->json([
            'message' => 'Application submitted. Waiting for dealer approval.',
            'application' => $application,
        ], 202);
    }

    public function collected(Request $request, Job $job): JsonResponse
    {
        $user = $request->user();
        if (!$user || (!$user->isAdmin() && $job->assigned_to_id !== $user->id)) {
            abort(403, 'You cannot update this job.');
        }

        $this->ensureDealerPaymentHeld($job);

        if (!$job->delivery_proof_path) {
            abort(422, 'Upload the pre-delivery inspection photos before marking this run as collected.');
        }

        $job->update([
            'status' => 'collected'
        ]);

        $this->notifyDealer($job->fresh(), 'driver_marked_collected');

        return response()->json($job);
    }

    public function delivered(Request $request, Job $job): JsonResponse
    {
        $user = $request->user();
        if (!$user || (!$user->isAdmin() && $job->assigned_to_id !== $user->id)) {
            abort(403, 'You cannot update this job.');
        }

        $this->ensureDealerPaymentHeld($job);

        $job->update([
            'status' => 'delivered'
        ]);

        $this->notifyDealer($job->fresh(), 'driver_marked_delivered');

        return response()->json($job);
    }

    public function inspection(Request $request, Job $job): JsonResponse
    {
        $user = $request->user();
        if (!$user || (!$user->isAdmin() && $job->assigned_to_id !== $user->id)) {
            abort(403, 'Only the assigned driver can upload inspection photos.');
        }

        if ($job->finalized_invoice_id) {
            abort(422, 'This job has already been finalized.');
        }

        $this->ensureDealerPaymentHeld($job);

        if (!in_array(strtolower((string) $job->status), ['accepted', 'in_progress'], true)) {
            abort(422, 'Inspection photos should be uploaded before collection starts.');
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:' . config('invoices.proof_max_size_kb')],
            'proofs' => ['nullable', 'array', 'min:1'],
            'proofs.*' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:' . config('invoices.proof_max_size_kb')],
        ]);

        $paths = $this->storeProofFiles($request, $job);
        if (empty($paths)) {
            abort(422, 'Upload inspection photos before collection.');
        }

        $job->update([
            'completion_notes' => $validated['notes'] ?? $job->completion_notes,
            'completion_status' => $job->completion_status === 'rejected' ? 'not_submitted' : $job->completion_status,
            'completion_rejected_at' => null,
            'delivery_proof_path' => $paths[0],
            'delivery_proof_disk' => config('invoices.proof_disk'),
        ]);

        $this->notifyDealer($job->fresh(), 'driver_uploaded_inspection', [
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json($job->fresh());
    }

    public function cancel(Request $request, Job $job): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($user->isAdmin() || $job->posted_by_id === $user->id) {
            $assigned = $job->assignedTo;

            $job->update([
                'status' => 'cancelled',
                'assigned_to_id' => null
            ]);

            if ($assigned) {
                Notification::send($assigned, new JobStatusNotification($job, 'dealer_cancelled_job', [
                    'reason' => $validated['reason'] ?? null,
                ]));
            }

            return response()->json($job);
        }

        if ($job->assigned_to_id !== $user->id) {
            abort(403, 'You cannot cancel this job.');
        }

        if (in_array(strtolower((string) $job->status), ['completed', 'delivered', 'closed'], true)) {
            abort(422, 'Completed jobs cannot be cancelled.');
        }

        if ($job->delivery_proof_path) {
            $this->deleteProof($job);
        }

        $job->update([
            'status' => 'open',
            'assigned_to_id' => null,
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
            'reason' => $validated['reason'] ?? null,
        ]);

        return response()->json($job);
    }

    public function complete(Request $request, Job $job): JsonResponse
    {
        $user = $request->user();
        if (!$user || (!$user->isAdmin() && $job->assigned_to_id !== $user->id)) {
            abort(403, 'Only the assigned driver can submit completion.');
        }

        if ($job->finalized_invoice_id) {
            abort(422, 'This job has already been finalized.');
        }

        $this->ensureDealerPaymentHeld($job);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:' . config('invoices.proof_max_size_kb')],
        ]);

        if (!in_array(strtolower((string) $job->status), ['delivered'], true)) {
            abort(422, 'Mark the run as delivered before submitting completion.');
        }

        if (!$job->delivery_proof_path && !$request->hasFile('proof')) {
            abort(422, 'Upload the pre-delivery inspection photos before submitting completion.');
        }

        $updates = [
            'status' => 'completion_pending',
            'completion_status' => 'submitted',
            'completion_submitted_at' => now(),
            'completion_notes' => $validated['notes'] ?? null,
        ];

        if ($request->hasFile('proof')) {
            $updates['delivery_proof_path'] = $this->storeProofFile($request, $job);
            $updates['delivery_proof_disk'] = config('invoices.proof_disk');
        }

        $job->update($updates);

        $this->notifyDealer($job->fresh(), 'driver_submitted_completion', [
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json($job->fresh());
    }

    public function approveCompletion(Request $request, Job $job, InvoiceFinalizer $finalizer): JsonResponse
    {
        $user = $request->user();
        if (!$user || (!$user->isAdmin() && $job->posted_by_id !== $user->id)) {
            abort(403, 'Only the posting dealer can approve completion.');
        }

        if ($job->completion_status !== 'submitted') {
            abort(422, 'Completion has not been submitted or was already handled.');
        }

        if (!$job->delivery_proof_path) {
            abort(422, 'Pre-delivery inspection photos are required before approval.');
        }

        if (!in_array(strtolower((string) $job->status), ['delivered', 'completion_pending', 'completed', 'closed'], true)) {
            abort(422, 'The run must be delivered before approval.');
        }

        $this->ensureDealerPaymentHeld($job);

        $job->loadMissing(['expenses']);
        $invoice = $finalizer->finalize($job, $user);

        if ($job->assignedTo) {
            Notification::send($job->assignedTo, new JobStatusNotification($job->fresh(), 'completion_approved'));
        }

        return response()->json([
            'message' => 'Job completion approved and invoice generated.',
            'invoice' => $this->summariseInvoice($invoice),
        ]);
    }

    public function rejectCompletion(Request $request, Job $job): JsonResponse
    {
        $user = $request->user();
        if (!$user || (!$user->isAdmin() && $job->posted_by_id !== $user->id)) {
            abort(403, 'Only the posting dealer can reject completion.');
        }

        if ($job->completion_status !== 'submitted') {
            abort(422, 'There is no submitted completion to reject.');
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $job->update([
            'status' => 'in_progress',
            'completion_status' => 'rejected',
            'completion_rejected_at' => now(),
            'completion_notes' => $validated['reason'] ?? $job->completion_notes,
        ]);

        if ($job->assignedTo) {
            Notification::send($job->assignedTo, new JobStatusNotification($job->fresh(), 'completion_rejected', [
                'reason' => $validated['reason'] ?? null,
            ]));
        }

        return response()->json($job->fresh());
    }

    public function dealerComplete(Request $request, Job $job, InvoiceFinalizer $finalizer): JsonResponse
    {
        $user = $request->user();

        if (!$user || (!$user->isAdmin() && !$user->isDealer()) || $job->posted_by_id !== $user->id) {
            abort(403, 'Only the posting dealer can mark this job as completed.');
        }

        if (!$job->assigned_to_id) {
            abort(422, 'Assign a driver before completing the job.');
        }

        if ($job->status === 'completed') {
            return response()->json([
                'job' => $job->fresh(),
                'invoice' => $job->finalizedInvoice,
            ]);
        }

        if (!$job->delivery_proof_path) {
            abort(422, 'Pre-delivery inspection photos are required before completing the job.');
        }

        if (!in_array(strtolower((string) $job->status), ['delivered', 'completion_pending'], true)) {
            abort(422, 'The driver must mark the run delivered before completion can be approved.');
        }

        if ($job->completion_status !== 'submitted') {
            abort(422, 'The driver must submit completion before the dealer can approve it.');
        }

        $invoice = $finalizer->finalize($job->fresh(), $user);

        if ($job->assignedTo) {
            Notification::send($job->assignedTo, new JobStatusNotification($job->fresh(), 'completion_approved'));
        }

        return response()->json([
            'job' => $job->fresh(),
            'invoice' => $this->summariseInvoice($invoice),
        ]);
    }

    public function deliveryProof(Request $request, Job $job)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        if (!$user->isAdmin() && $job->posted_by_id !== $user->id && $job->assigned_to_id !== $user->id) {
            abort(403, 'You cannot view this proof.');
        }

        if (!$job->delivery_proof_path) {
            abort(404, 'No pre-delivery inspection photos uploaded.');
        }

        $disk = $job->delivery_proof_disk ?? config('invoices.proof_disk');
        $storage = Storage::disk($disk);
        if (!$storage->exists($job->delivery_proof_path)) {
            abort(404, 'Proof file not found.');
        }

        $extension = pathinfo($job->delivery_proof_path, PATHINFO_EXTENSION) ?: 'pdf';
        $filename = sprintf('job-%d-inspection.%s', $job->id, $extension);

        return $storage->response($job->delivery_proof_path, $filename, [], 'inline');
    }

    protected function deleteProof(Job $job): void
    {
        if (!$job->delivery_proof_path) {
            return;
        }

        $disk = $job->delivery_proof_disk ?? config('invoices.proof_disk');
        $storage = Storage::disk($disk);
        if ($storage->exists($job->delivery_proof_path)) {
            $storage->delete($job->delivery_proof_path);
        }
    }

    protected function storeProofFile(Request $request, Job $job): string
    {
        $paths = $this->storeProofFiles($request, $job);

        return $paths[0];
    }

    protected function storeProofFiles(Request $request, Job $job): array
    {
        $proofDisk = config('invoices.proof_disk');
        $files = collect($request->file('proofs', []));

        if ($request->hasFile('proof')) {
            $files->prepend($request->file('proof'));
        }

        if ($job->delivery_proof_path) {
            $this->deleteProof($job);
        }

        return $files
            ->filter()
            ->values()
            ->map(function ($file, int $index) use ($job, $proofDisk) {
                $directory = $this->inspectionDirectory($job);
                $extension = $file->getClientOriginalExtension() ?: 'jpg';
                $filename = sprintf('%s-%02d-%s.%s', now()->format('YmdHis'), $index + 1, Str::ulid(), $extension);

                return $file->storeAs($directory, $filename, $proofDisk);
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
        if (!$job->postedBy) {
            return;
        }

        Notification::send($job->postedBy, new JobStatusNotification($job, $event, $meta));
    }

    protected function ensureDealerPaymentHeld(Job $job): void
    {
        if (!in_array($job->payment_status, ['paid', 'payout_released'], true)) {
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
