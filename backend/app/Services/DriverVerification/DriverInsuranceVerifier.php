<?php

namespace App\Services\DriverVerification;

interface DriverInsuranceVerifier
{
    public function verify(array $data): array;
}
