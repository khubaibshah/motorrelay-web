<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DriverVerification\DriverLicenceVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverLicenceVerificationController extends Controller
{
    public function __construct(private readonly DriverLicenceVerificationService $service)
    {
    }

    public function status(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isDriver(), 403, 'Driver access required.');

        return response()->json(['verification' => $this->payload($request->user())]);
    }

    public function submit(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isDriver(), 403, 'Driver access required.');

        $data = $request->validate([
            'licence_number' => ['required', 'string', 'min:5', 'max:32', 'regex:/^[A-Za-z0-9\s]+$/'],
            'check_code' => ['required', 'string', 'min:4', 'max:32'],
        ]);

        $driver = $this->service->submitManualCheck($request->user(), $data['licence_number'], $data['check_code']);

        return response()->json([
            'message' => 'Licence check submitted for manual review.',
            'verification' => $this->payload($driver),
        ], 202);
    }

    public function verifyByAdmin(Request $request, User $driver): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Admin access required.');
        abort_unless($driver->isDriver(), 404);

        $data = $request->validate([
            'status' => ['sometimes', 'in:verified,failed'],
            'points' => ['nullable', 'integer', 'min:0'],
            'disqualifications' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $updated = ($data['status'] ?? 'verified') === 'failed'
            ? $this->service->markFailed($driver, $data)
            : $this->service->markVerified($driver, $data);

        return response()->json(['verification' => $this->payload($updated)]);
    }

    private function payload(User $driver): array
    {
        return [
            'status' => $driver->driver_dvla_check_status ?? 'not_started',
            'submitted_at' => optional($driver->driver_dvla_check_submitted_at)->toIso8601String(),
            'verified_at' => optional($driver->driver_dvla_verified_at)->toIso8601String(),
            'expires_at' => optional($driver->driver_dvla_expires_at)->toIso8601String(),
            'last_checked_at' => optional($driver->driver_dvla_last_checked_at)->toIso8601String(),
            'result' => $driver->driver_dvla_check_result,
        ];
    }
}
