<?php

namespace App\Services\DriverVerification;

class ManualDriverLicenceVerifier implements DriverLicenceVerifier
{
    public function verify(string $licenceNumber, string $checkCode): array
    {
        return [
            'status' => 'pending',
            'provider' => 'manual_dvla_portal',
            'licence_last_four' => substr(preg_replace('/\s+/', '', $licenceNumber), -4),
            'submitted_at' => now()->toIso8601String(),
        ];
    }
}
