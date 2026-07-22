<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Models\JobInspectionPhoto;
use App\Services\Jobs\JobApplicationService;
use App\Services\Jobs\JobService;
use App\Services\Jobs\JobWorkflowService;
use App\Services\Jobs\JobCompletionReportPdfGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class JobWorkflowController extends Controller
{
    private const MIN_INSPECTION_PHOTOS = 6;

    public function __construct(
        protected JobApplicationService $applications,
        protected JobWorkflowService $workflow,
        protected JobService $jobs,
        protected JobCompletionReportPdfGenerator $completionReports,
    ) {}

    public function accept(Request $request, Job $job): JsonResponse
    {
        $user = $request->user();

        if (! $user || (! $user->isDriver() && ! $user->isAdmin())) {
            abort(403, 'Only drivers can apply for jobs.');
        }

        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $application = $this->applications->apply($job, $user, $validated['message'] ?? null);

        return response()->json([
            'message' => 'Application submitted. Waiting for dealer approval.',
            'application' => $application,
        ], 202);
    }

    public function collected(Request $request, Job $job): JsonResponse
    {
        $this->authorizeAssignedDriver($request, $job, 'You cannot update this job.');

        return response()->json($this->workflow->markCollected($job));
    }

    public function delivered(Request $request, Job $job): JsonResponse
    {
        $this->authorizeAssignedDriver($request, $job, 'You cannot update this job.');

        return response()->json($this->workflow->markDelivered($job));
    }

    public function inspection(Request $request, Job $job): JsonResponse
    {
        $user = $this->authorizeAssignedDriver($request, $job, 'Only the assigned driver can upload inspection photos.');

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'proofs' => ['required', 'array', 'min:'.self::MIN_INSPECTION_PHOTOS, 'max:20'],
            'proofs.*' => ['file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:'.config('invoices.proof_max_size_kb')],
        ]);

        return response()->json($this->workflow->uploadInspection(
            $job,
            $user,
            $validated['notes'] ?? null,
            $request->file('proofs', [])
        ));
    }

    public function cancel(Request $request, Job $job): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        return response()->json($this->workflow->cancel($job, $user, $validated['reason']));
    }

    public function complete(Request $request, Job $job): JsonResponse
    {
        $user = $this->authorizeAssignedDriver($request, $job, 'Only the assigned driver can submit completion.');

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:'.config('invoices.proof_max_size_kb')],
        ]);

        return response()->json($this->workflow->submitCompletion(
            $job,
            $user,
            $validated['notes'] ?? null,
            $request->file('proof')
        ));
    }

    public function approveCompletion(Request $request, Job $job): JsonResponse
    {
        $user = $this->authorizePostingDealer($request, $job, 'Only the posting dealer can approve completion.');

        return response()->json($this->workflow->approveCompletion($job, $user));
    }

    public function approveCompletionAndReleasePayout(Request $request, Job $job): JsonResponse
    {
        $user = $this->authorizePostingDealer($request, $job, 'Only the posting dealer can approve delivery and release payout.');

        try {
            return response()->json($this->workflow->approveCompletionAndReleasePayout($job, $user));
        } catch (Throwable $exception) {
            // Keep the user-facing response safe while preserving enough state
            // in Laravel's log to diagnose payout and invoice failures quickly.
            Log::error('Delivery approval and payout release failed.', [
                'job_id' => $job->id,
                'user_id' => $user->id,
                'job_status' => $job->status,
                'payment_status' => $job->payment_status,
                'completion_status' => $job->completion_status,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            throw $exception;
        }
    }

    public function rejectCompletion(Request $request, Job $job): JsonResponse
    {
        $this->authorizePostingDealer($request, $job, 'Only the posting dealer can reject completion.');

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json($this->workflow->rejectCompletion($job, $validated['reason'] ?? null));
    }

    public function approveInspection(Request $request, Job $job): JsonResponse
    {
        $this->authorizePostingDealer($request, $job, 'Only the posting dealer can approve inspection photos.');

        return response()->json($this->workflow->approveInspection($job));
    }

    public function requestInspectionChanges(Request $request, Job $job): JsonResponse
    {
        $this->authorizePostingDealer($request, $job, 'Only the posting dealer can request more inspection photos.');

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json($this->workflow->requestInspectionChanges($job, $validated['reason'] ?? null));
    }

    public function dealerComplete(Request $request, Job $job): JsonResponse
    {
        $user = $this->authorizePostingDealer($request, $job, 'Only the posting dealer can mark this job as completed.', true);

        return response()->json($this->workflow->dealerComplete($job, $user));
    }

    public function deliveryProof(Request $request, Job $job)
    {
        $this->authorizeProofViewer($request, $job);

        if (! $job->delivery_proof_path) {
            abort(404, 'No pre-delivery inspection photos uploaded.');
        }

        $disk = $job->delivery_proof_disk ?? config('invoices.proof_disk');
        $storage = Storage::disk($disk);
        if (! $storage->exists($job->delivery_proof_path)) {
            abort(404, 'Proof file not found.');
        }

        $extension = pathinfo($job->delivery_proof_path, PATHINFO_EXTENSION) ?: 'pdf';
        $filename = sprintf('job-%d-inspection.%s', $job->id, $extension);

        return $storage->response($job->delivery_proof_path, $filename, [], 'inline');
    }

    public function auctionAssessmentReport(Request $request, Job $job)
    {
        $this->authorizeProofViewer($request, $job);
        [$storage, $path, $originalName] = $this->jobs->auctionAssessmentReport($job);

        return $storage->response($path, $originalName ?: sprintf('job-%d-auction-assessment', $job->id), [], 'inline');
    }

    public function inspectionPhoto(Request $request, Job $job, JobInspectionPhoto $photo)
    {
        $this->authorizeProofViewer($request, $job);

        if ((int) $photo->job_id !== (int) $job->id) {
            abort(404);
        }

        $storage = Storage::disk($photo->disk ?? config('invoices.proof_disk'));
        if (! $storage->exists($photo->path)) {
            abort(404, 'Inspection photo not found.');
        }

        $filename = $photo->original_name ?: sprintf('job-%d-inspection-%d', $job->id, $photo->id);

        return $storage->response($photo->path, $filename, [], 'inline');
    }

    public function completionReport(Request $request, Job $job)
    {
        $this->authorizeProofViewer($request, $job);

        if (! in_array($job->status, ['completed', 'closed'], true) || $job->completion_status !== 'approved') {
            abort(422, 'The completion report is available after the run is completed.');
        }

        $job->load([
            'postedBy',
            'assignedTo',
            'inspectionPhotos',
            'incidents.reportedBy',
            'expenses',
        ]);

        $disk = config('invoices.completion_report_disk');
        $path = sprintf('jobs/%d/reports/completion-report.pdf', $job->id);
        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            $this->completionReports->store($job);
        }

        $filename = sprintf('motorrelay-job-%d-completion-report.pdf', $job->id);

        return response($storage->get($path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    protected function authorizeAssignedDriver(Request $request, Job $job, string $message)
    {
        $user = $request->user();

        if (! $user || (! $user->isAdmin() && $job->assigned_to_id !== $user->id)) {
            abort(403, $message);
        }

        return $user;
    }

    protected function authorizePostingDealer(Request $request, Job $job, string $message, bool $dealerRoleRequired = false)
    {
        $user = $request->user();

        if (! $user || (! $user->isAdmin() && $job->posted_by_id !== $user->id)) {
            abort(403, $message);
        }

        if ($dealerRoleRequired && ! $user->isAdmin() && ! $user->isDealer()) {
            abort(403, $message);
        }

        return $user;
    }

    protected function authorizeProofViewer(Request $request, Job $job): void
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        if (! $user->isAdmin() && $job->posted_by_id !== $user->id && $job->assigned_to_id !== $user->id) {
            abort(403, 'You cannot view this proof.');
        }
    }
}
