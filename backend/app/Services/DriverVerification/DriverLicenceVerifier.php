<?php

namespace App\Services\DriverVerification;

interface DriverLicenceVerifier
{
    /**
     * Verify a licence with the configured provider.
     * The manual provider deliberately returns a pending result until an operator
     * checks the GOV.UK portal. A DVLA API provider can replace it later.
     */
    public function verify(string $licenceNumber, string $checkCode): array;
}
