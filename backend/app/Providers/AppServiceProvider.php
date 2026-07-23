<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use App\Services\DriverVerification\DriverLicenceVerifier;
use App\Services\DriverVerification\ManualDriverLicenceVerifier;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Keep the verification workflow provider-agnostic. Replace this binding
        // with a DVLA API implementation when accreditation is complete.
        $this->app->bind(DriverLicenceVerifier::class, ManualDriverLicenceVerifier::class);

        // Runtime containers may not include ignored Laravel storage folders.
        // Create the folders before any Blade/Markdown view is resolved.
        foreach ([
            storage_path('framework/cache'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
        ] as $directory) {
            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
        }
    }

    public function boot(): void
    {
        // Surface accidental lazy loads during development and tests so new
        // endpoints do not quietly introduce N+1 queries.
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
            Log::warning('Lazy-loaded Eloquent relation detected', [
                'model' => $model::class,
                'model_id' => $model->getKey(),
                'relation' => $relation,
                'request_id' => request()?->header('X-Request-Id'),
            ]);
        });

        Model::preventLazyLoading(! app()->isProduction());

        Queue::failing(function (JobFailed $event): void {
            Log::error('Queue job failed', [
                'connection' => $event->connectionName,
                'job' => $event->job->resolveName(),
                'job_id' => $event->job->getJobId(),
                'exception' => $event->exception->getMessage(),
                'exception_class' => $event->exception::class,
            ]);
        });
    }
}
