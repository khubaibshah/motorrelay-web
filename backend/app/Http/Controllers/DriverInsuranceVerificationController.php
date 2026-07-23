<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DriverVerification\DriverInsuranceVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DriverInsuranceVerificationController extends Controller
{
    public function __construct(private readonly DriverInsuranceVerificationService $service) {}

    public function status(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isDriver(), 403, 'Driver access required.');
        return response()->json(['verification' => $this->payload($request->user())]);
    }

    public function submit(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isDriver(), 403, 'Driver access required.');
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:160'],
            'policy_number' => ['required', 'string', 'max:120'],
            'expires_at' => ['required', 'date', 'after:today'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
        $driver = $this->service->submit($request->user(), $data, $request->file('document'));
        return response()->json(['message' => 'Insurance submitted for manual review.', 'verification' => $this->payload($driver)], 202);
    }

    public function verifyByAdmin(Request $request, User $driver): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Admin access required.');
        abort_unless($driver->isDriver(), 404);
        $data = $request->validate(['status' => ['required', 'in:verified,failed'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $updated = $data['status'] === 'verified'
            ? $this->service->markVerified($driver, $data)
            : $this->service->markFailed($driver, $data);
        return response()->json(['verification' => $this->payload($updated)]);
    }

    public function document(Request $request, User $driver): Response
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Admin access required.');
        abort_unless($driver->isDriver(), 404);

        $document = $this->service->openDocument($driver);
        abort_unless($document, 404, 'Insurance document not found.');

        $filename = addcslashes($document['filename'], "\\\"");
        $stream = $document['stream'];

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $document['mime_type'],
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function payload(User $driver): array
    {
        return [
            'status' => $driver->driver_insurance_status ?? 'not_started',
            'provider' => $driver->driver_insurance_provider,
            'expires_at' => optional($driver->driver_insurance_expires_at)->toDateString(),
            'submitted_at' => optional($driver->driver_insurance_submitted_at)->toIso8601String(),
            'verified_at' => optional($driver->driver_insurance_verified_at)->toIso8601String(),
            'last_checked_at' => optional($driver->driver_insurance_last_checked_at)->toIso8601String(),
        ];
    }
}
