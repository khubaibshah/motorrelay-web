<?php

namespace App\Services\Jobs;

use App\Events\JobStatusChanged;
use App\Models\Job;
use App\Models\JobApplication;
use App\Models\MessageThread;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class JobApplicationService
{
    public function listForJob(Job $job)
    {
        return $job->applications()
            ->with(['driver:id,name,email'])
            ->orderByRaw(sprintf(
                "CASE status WHEN '%s' THEN 0 WHEN '%s' THEN 1 WHEN '%s' THEN 2 WHEN '%s' THEN 3 ELSE 4 END",
                JobApplication::STATUS_PENDING,
                JobApplication::STATUS_ACCEPTED,
                JobApplication::STATUS_DECLINED,
                JobApplication::STATUS_WITHDRAWN,
            ))
            ->latest()
            ->get();
    }

    public function apply(Job $job, User $driver, ?string $message = null): JobApplication
    {
        if ($driver->isDriver() && (! $driver->stripe_account_id || ! $driver->stripe_payouts_enabled)) {
            abort(422, 'Connect and complete your Stripe payout account before requesting a run.');
        }

        if ($job->assigned_to_id) {
            abort(422, 'Job has already been assigned.');
        }

        $existingApplication = JobApplication::query()
            ->where('job_id', $job->id)
            ->where('driver_id', $driver->id)
            ->first();

        // The mobile request action can be tapped more than once while the
        // first response is still in flight. Keep a pending application
        // idempotent so that one request creates one notification.
        if ($existingApplication?->isPending()) {
            return $existingApplication->fresh();
        }

        $this->ensureDailyApplicationLimit($driver, $existingApplication);

        $application = JobApplication::updateOrCreate(
            [
                'job_id' => $job->id,
                'driver_id' => $driver->id,
            ],
            [
                'message' => $message,
                'status' => JobApplication::STATUS_PENDING,
                'responded_at' => null,
            ]
        );

        $job->loadMissing('postedBy:id,name');
        if ($job->postedBy) {
            JobStatusChanged::dispatch($job->fresh(), 'driver_applied', [$job->postedBy->id], [
                'driver' => ['id' => $driver->id, 'name' => $driver->name],
                'message' => $message,
            ]);
        }

        return $application->fresh();
    }

    public function updateStatus(Job $job, JobApplication $application, User $dealer, string $status): JobApplication
    {
        if ((int) $application->job_id !== (int) $job->id) {
            abort(404);
        }

        $application = DB::transaction(function () use ($status, $job, $application, $dealer) {
            if (! $application->isPending()) {
                abort(422, 'This application has already been processed.');
            }

            $application->update([
                'status' => $status,
                'responded_at' => now(),
            ]);

            if ($status === JobApplication::STATUS_ACCEPTED) {
                $job->update([
                    'assigned_to_id' => $application->driver_id,
                    'assigned_at' => now(),
                    'status' => 'in_progress',
                ]);

                $job->applications()
                    ->where('id', '!=', $application->id)
                    ->where('status', JobApplication::STATUS_PENDING)
                    ->update([
                        'status' => 'declined',
                        'responded_at' => now(),
                    ]);

                $this->ensureConversationExists($job, $dealer->id, $application->driver_id);
            }

            return $application->fresh(['driver:id,name,email']);
        });

        if ($application->driver) {
            $updatedJob = $job->fresh(['postedBy:id,name', 'assignedTo:id,name']);
            $event = $application->status === JobApplication::STATUS_ACCEPTED
                ? 'application_accepted'
                : 'application_declined';

            JobStatusChanged::dispatch($updatedJob, $event, [$application->driver->id], [
                'dealer' => ['id' => $dealer->id, 'name' => $dealer->name],
            ]);

            // The dealer is also viewing this run while choosing a driver. Send
            // a separate event so their run detail, applications step and chat
            // access update immediately without pretending they applied.
            if ($application->status === JobApplication::STATUS_ACCEPTED) {
                JobStatusChanged::dispatch($updatedJob, 'driver_assigned', [$dealer->id], [
                    'driver' => ['id' => $application->driver->id, 'name' => $application->driver->name],
                ]);
            }
        }

        return $application->fresh(['driver:id,name,email']);
    }

    public function withdraw(Job $job, JobApplication $application, User $driver): JobApplication
    {
        if ((int) $application->job_id !== (int) $job->id || (int) $application->driver_id !== (int) $driver->id) {
            abort(404);
        }

        if (! $application->isPending()) {
            abort(422, 'Only pending applications can be withdrawn.');
        }

        $application->update([
            'status' => JobApplication::STATUS_WITHDRAWN,
            'responded_at' => now(),
        ]);

        $job->loadMissing('postedBy:id,name');
        if ($job->postedBy) {
            JobStatusChanged::dispatch($job->fresh(['postedBy:id,name']), 'driver_withdrew_application', [$job->postedBy->id], [
                'driver' => ['id' => $driver->id, 'name' => $driver->name],
            ]);
        }

        return $application->fresh();
    }

    protected function ensureDailyApplicationLimit(User $driver, ?JobApplication $existingApplication): void
    {
        $planSlug = $driver->plan_slug ?? Str::slug((string) $driver->plan, '_');
        if ($existingApplication || $planSlug !== 'starter' || $driver->isAdmin()) {
            return;
        }

        $dailyLimit = config('jobs.plan_limits.starter.daily_applications', 0);
        if (! $dailyLimit) {
            return;
        }

        $applicationsToday = JobApplication::where('driver_id', $driver->id)
            ->where('created_at', '>=', Carbon::today())
            ->count();

        if ($applicationsToday >= $dailyLimit) {
            abort(422, sprintf(
                'Starter plan allows up to %d applications per day. Please try again tomorrow or upgrade your plan.',
                $dailyLimit
            ));
        }
    }

    protected function ensureConversationExists(Job $job, int $dealerId, int $driverId): void
    {
        $thread = MessageThread::query()
            ->where('job_id', $job->id)
            ->whereHas('participants', fn ($query) => $query->where('user_id', $dealerId))
            ->whereHas('participants', fn ($query) => $query->where('user_id', $driverId))
            ->first();

        if ($thread) {
            return;
        }

        $thread = MessageThread::create([
            'job_id' => $job->id,
            'subject' => $job->title ?: sprintf('Run #%s', $job->id),
        ]);

        $thread->participants()->attach([$dealerId, $driverId]);
    }
}
