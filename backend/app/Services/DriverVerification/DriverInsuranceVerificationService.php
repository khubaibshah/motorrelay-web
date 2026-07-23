<?php

namespace App\Services\DriverVerification;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DriverInsuranceVerificationService
{
    public function __construct(private readonly DriverInsuranceVerifier $verifier) {}

    public function submit(User $driver, array $data, ?UploadedFile $document): User
    {
        if (! $driver->isDriver()) {
            throw ValidationException::withMessages(['driver' => 'Only driver accounts can submit insurance.']);
        }

        $result = $this->verifier->verify($data);
        $disk = config('filesystems.default', 'public');
        $path = $document?->store('driver-insurance', $disk);

        return DB::transaction(function () use ($driver, $data, $result, $path): User {
            $driver->forceFill([
                'driver_insurance_status' => $result['status'] ?? 'pending',
                'driver_insurance_provider' => trim($data['provider']),
                'driver_insurance_policy_number' => trim($data['policy_number']),
                'driver_insurance_expires_at' => $data['expires_at'],
                'driver_insurance_document_path' => $path ?: $driver->driver_insurance_document_path,
                'driver_insurance_submitted_at' => now(),
                'driver_insurance_last_checked_at' => now(),
                'driver_insurance_verified_at' => null,
                'driver_insurance_check_result' => $result,
            ])->save();

            return $driver->refresh();
        });
    }

    public function markVerified(User $driver, array $result = []): User
    {
        $driver->forceFill([
            'driver_insurance_status' => 'verified',
            'driver_insurance_verified_at' => now(),
            'driver_insurance_last_checked_at' => now(),
            'driver_insurance_check_result' => $result,
        ])->save();

        return $driver->refresh();
    }

    public function markFailed(User $driver, array $result = []): User
    {
        $driver->forceFill([
            'driver_insurance_status' => 'failed',
            'driver_insurance_last_checked_at' => now(),
            'driver_insurance_check_result' => $result,
        ])->save();

        return $driver->refresh();
    }
}
