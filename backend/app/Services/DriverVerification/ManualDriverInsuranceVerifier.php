<?php

namespace App\Services\DriverVerification;

class ManualDriverInsuranceVerifier implements DriverInsuranceVerifier
{
    public function verify(array $data): array
    {
        return ['status' => 'pending', 'provider' => 'manual', 'checked_at' => now()->toIso8601String()];
    }
}
