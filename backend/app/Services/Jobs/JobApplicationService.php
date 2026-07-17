<?php

namespace App\Services\Jobs;

use App\Models\Job;
use App\Models\JobApplication;
use App\Models\MessageThread;
use App\Models\User;
use App\Notifications\JobStatusNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class JobApplicationService
{
    public function listForJob(Job $job)
    {
        return $job->applications()
            ->with(['driver:id,name,email'])
            ->orderByRaw("FIELD(status, 'pending', 'accepted', 'declined')")
            ->latest()
            ->get();
    }

    public function apply(Job $job, User $driver, ?string $message = null): JobApplication
    {
        if ($job->assigned_to_id) {
            abort(422, 'Job has already been assigned.');
        }

        $existingApplication = JobApplication::query()
            ->where('job_id', $job->id)
            ->where('driver_id', $driver->id)
            ->first();

        $this->ensureDailyApplicationLimit($driver, $existingApplication);

        $application = JobApplication::updateOrCreate(
            [
                'job_id' => $job->id,
                'driver_id' => $driver->id,
            ],
            [
                'message' => $message,
                'status' => 'pending',
                'responded_at' => null,
            ]
        );

        $this->notifyDealerOfApplication($job, $driver, $message);

        return $application->fresh();
    }

    public function updateStatus(Job $job, JobApplication $application, User $dealer, string $status): JobApplication
    {
        if ((int) $application->job_id !== (int) $job->id) {
            abort(404);
        }

        $application = DB::transaction(function () use ($status, $job, $application, $dealer) {
            if ($application->status !== 'pending') {
                abort(422, 'This application has already been processed.');
            }

            $application->update([
                'status' => $status,
                'responded_at' => now(),
            ]);

            if ($status === 'accepted') {
                $job->update([
                    'assigned_to_id' => $application->driver_id,
                    'status' => 'in_progress',
                ]);

                $job->applications()
                    ->where('id', '!=', $application->id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'declined',
                        'responded_at' => now(),
                    ]);

                $this->ensureConversationExists($job, $dealer->id, $application->driver_id);
            }

            return $application->fresh(['driver:id,name,email']);
        });

        $this->notifyDriverOfDecision($job, $application, $dealer);

        return $application->fresh(['driver:id,name,email']);
    }

    protected function ensureDailyApplicationLimit(User $driver, ?JobApplication $existingApplication): void
    {
        $planSlug = $driver->plan_slug ?? Str::slug((string) $driver->plan, '_');
        if ($existingApplication || $planSlug !== 'starter' || $driver->isAdmin()) {
            return;
        }

        $dailyLimit = config('jobs.plan_limits.starter.daily_applications', 0);
        if (!$dailyLimit) {
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

    protected function notifyDealerOfApplication(Job $job, User $driver, ?string $message): void
    {
        $job->loadMissing('postedBy:id,name');
        if (!$job->postedBy) {
            return;
        }

        Notification::send($job->postedBy, new JobStatusNotification($job->fresh(), 'driver_applied', [
            'driver' => [
                'id' => $driver->id,
                'name' => $driver->name,
            ],
            'message' => $message,
        ]));
    }

    protected function notifyDriverOfDecision(Job $job, JobApplication $application, User $dealer): void
    {
        $freshJob = $job->fresh(['postedBy:id,name', 'assignedTo:id,name']);

        if ($application->status === 'accepted') {
            if ($application->driver) {
                Notification::send($application->driver, new JobStatusNotification($freshJob, 'application_accepted', [
                    'dealer' => [
                        'id' => $dealer->id,
                        'name' => $dealer->name,
                    ],
                ]));
            }
            return;
        }

        if ($application->status === 'declined' && $application->driver) {
            Notification::send($application->driver, new JobStatusNotification($freshJob, 'application_declined', [
                'dealer' => [
                    'id' => $dealer->id,
                    'name' => $dealer->name,
                ],
            ]));
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
