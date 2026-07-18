<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
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
