<?php

namespace App\Services\DriverVerification;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DriverLicenceVerificationService
{
    public function __construct(private readonly DriverLicenceVerifier $verifier)
    {
    }

    public function submitManualCheck(User $driver, string $licenceNumber, string $checkCode): User
    {
        $licenceNumber = strtoupper(trim($licenceNumber));
        $checkCode = strtoupper(trim($checkCode));

        if ($driver->role !== 'driver') {
            throw ValidationException::withMessages(['driver' => 'Only driver accounts can submit a licence check.']);
        }

        $result = $this->verifier->verify($licenceNumber, $checkCode);

        return DB::transaction(function () use ($driver, $licenceNumber, $checkCode, $result): User {
            $driver->forceFill([
                'driver_licence_number' => $licenceNumber,
                'driver_dvla_check_status' => $result['status'] ?? 'pending',
                'driver_dvla_check_code_hash' => hash('sha256', $checkCode),
                'driver_dvla_check_submitted_at' => now(),
                // GOV.UK check codes are valid for 21 days.
                'driver_dvla_expires_at' => now()->addDays(21),
                'driver_dvla_last_checked_at' => now(),
                'driver_dvla_check_result' => $this->safeResult($result),
                'driver_dvla_verified_at' => null,
            ])->save();

            return $driver->refresh();
        });
    }

    public function markVerified(User $driver, array $result = []): User
    {
        return DB::transaction(function () use ($driver, $result): User {
            $driver->forceFill([
                'driver_dvla_check_status' => 'verified',
                'driver_dvla_verified_at' => now(),
                'driver_dvla_last_checked_at' => now(),
                'driver_dvla_check_result' => $this->safeResult($result),
            ])->save();

            return $driver->refresh();
        });
    }

    public function markFailed(User $driver, array $result = []): User
    {
        $driver->forceFill([
            'driver_dvla_check_status' => 'failed',
            'driver_dvla_last_checked_at' => now(),
            'driver_dvla_check_result' => $this->safeResult($result),
        ])->save();

        return $driver->refresh();
    }

    private function safeResult(array $result): array
    {
        return collect($result)->only([
            'status',
            'provider',
            'licence_last_four',
            'entitlements',
            'points',
            'disqualifications',
            'checked_at',
            'notes',
        ])->all();
    }
}
