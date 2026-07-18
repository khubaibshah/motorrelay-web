<?php

namespace Tests\Feature;

use App\Events\JobStatusChanged;
use App\Models\Job;
use App\Models\JobApplication;
use App\Models\User;
use App\Services\Jobs\JobApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class JobLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_application_is_stored_and_dispatches_domain_event(): void
    {
        Event::fake([JobStatusChanged::class]);

        $dealer = User::factory()->create(['role' => 'dealer']);
        $driver = User::factory()->create(['role' => 'driver']);
        $job = Job::factory()->create(['posted_by_id' => $dealer->id, 'payment_status' => 'paid']);

        $application = app(JobApplicationService::class)->apply($job, $driver, 'Available tomorrow.');

        $this->assertDatabaseHas('job_applications', [
            'id' => $application->id,
            'job_id' => $job->id,
            'driver_id' => $driver->id,
            'status' => 'pending',
        ]);

        Event::assertDispatched(JobStatusChanged::class, fn (JobStatusChanged $event) =>
            $event->job->id === $job->id
            && $event->event === 'driver_applied'
            && $event->recipientIds === [$dealer->id]
        );
    }

    public function test_dealer_accepting_application_assigns_driver_and_dispatches_event(): void
    {
        Event::fake([JobStatusChanged::class]);

        $dealer = User::factory()->create(['role' => 'dealer']);
        $driver = User::factory()->create(['role' => 'driver']);
        $job = Job::factory()->create(['posted_by_id' => $dealer->id, 'payment_status' => 'paid']);
        $application = JobApplication::create([
            'job_id' => $job->id,
            'driver_id' => $driver->id,
            'status' => 'pending',
        ]);

        $updated = app(JobApplicationService::class)->updateStatus($job, $application, $dealer, 'accepted');

        $this->assertSame('accepted', $updated->status);
        $this->assertDatabaseHas('jobs', [
            'id' => $job->id,
            'assigned_to_id' => $driver->id,
            'status' => 'in_progress',
        ]);

        Event::assertDispatched(JobStatusChanged::class, fn (JobStatusChanged $event) =>
            $event->job->id === $job->id
            && $event->event === 'application_accepted'
            && $event->recipientIds === [$driver->id]
        );

        Event::assertDispatched(JobStatusChanged::class, fn (JobStatusChanged $event) =>
            $event->job->id === $job->id
            && $event->event === 'driver_assigned'
            && $event->recipientIds === [$dealer->id]
            && $event->meta['driver']['id'] === $driver->id
        );
    }

    public function test_only_posting_dealer_can_view_job_applications(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer']);
        $otherDealer = User::factory()->create(['role' => 'dealer']);
        $job = Job::factory()->create(['posted_by_id' => $dealer->id]);

        $this->actingAs($otherDealer)->getJson("/api/jobs/{$job->id}/applications")
            ->assertForbidden();

        $this->actingAs($dealer)->getJson("/api/jobs/{$job->id}/applications")
            ->assertOk()
            ->assertJsonPath('data', []);
    }
}
