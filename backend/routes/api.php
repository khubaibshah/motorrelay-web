<?php

use App\Http\Controllers\AccountChangeRequestController;
use App\Http\Controllers\AdminAccountChangeController;
use App\Http\Controllers\AdminPortalController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DriverDashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\JobApplicationController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\JobIncidentController;
use App\Http\Controllers\JobTrackingController;
use App\Http\Controllers\JobWorkflowController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PostcodeLookupController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\StripePaymentController;
use App\Http\Controllers\UserProfileController;
use App\Http\Controllers\VehicleLookupController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/stripe/webhook', [StripePaymentController::class, 'webhook']);

Route::get('/jobs/highlights', [JobController::class, 'highlights']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/profile', [UserProfileController::class, 'show']);
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);
    Route::delete('/notifications', [NotificationController::class, 'destroyAll']);
    Route::post('/push-subscriptions', [PushSubscriptionController::class, 'store']);
    Route::delete('/push-subscriptions', [PushSubscriptionController::class, 'destroy']);
    Route::get('/vehicles/registration/{registration}', [VehicleLookupController::class, 'show']);
    Route::get('/postcodes/places/{placeId}', [PostcodeLookupController::class, 'resolve']);
    Route::get('/postcodes/reverse', [PostcodeLookupController::class, 'reverse']);
    Route::get('/postcodes/{postcode}/coordinates', [PostcodeLookupController::class, 'coordinates']);
    Route::get('/postcodes/{postcode}/addresses', [PostcodeLookupController::class, 'show']);
    Route::get('/driver/overview', [DriverDashboardController::class, 'show']);
    Route::get('/account/change-requests', [AccountChangeRequestController::class, 'index']);
    Route::post('/account/change-requests', [AccountChangeRequestController::class, 'store']);

    Route::get('/jobs', [JobController::class, 'index']);
    Route::post('/jobs', [JobController::class, 'store']);
    Route::get('/jobs/{job}', [JobController::class, 'show']);
    Route::patch('/jobs/{job}', [JobController::class, 'update']);
    Route::delete('/jobs/{job}', [JobController::class, 'destroy']);

    Route::post('/jobs/{job}/invoice/send', [InvoiceController::class, 'sendFromJob']);
    Route::post('/jobs/{job}/payment/checkout', [StripePaymentController::class, 'createJobCheckout']);
    Route::post('/jobs/{job}/payment/sync', [StripePaymentController::class, 'syncJobPayment']);
    Route::post('/jobs/{job}/payment/release-payout', [StripePaymentController::class, 'releaseDriverPayout']);
    Route::post('/stripe/connect/onboard', [StripePaymentController::class, 'onboardDriver']);
    Route::post('/stripe/connect/disconnect', [StripePaymentController::class, 'disconnectDriver']);

    Route::get('/jobs/{job}/expenses', [ExpenseController::class, 'index']);
    Route::post('/jobs/{job}/expenses', [ExpenseController::class, 'store']);
    Route::patch('/jobs/{job}/expenses/{expense}', [ExpenseController::class, 'update']);
    Route::delete('/jobs/{job}/expenses/{expense}', [ExpenseController::class, 'destroy']);
    Route::post('/jobs/{job}/expenses/{expense}/decision', [ExpenseController::class, 'decide']);
    Route::get('/jobs/{job}/expenses/{expense}/receipt', [ExpenseController::class, 'receipt']);

    Route::get('/jobs/{job}/applications', [JobApplicationController::class, 'index']);
    Route::post('/jobs/{job}/applications', [JobApplicationController::class, 'store']);
    Route::patch('/jobs/{job}/applications/{application}', [JobApplicationController::class, 'update']);
    Route::delete('/jobs/{job}/applications/{application}', [JobApplicationController::class, 'destroy']);

    Route::post('/jobs/{job}/accept', [JobWorkflowController::class, 'accept']);
    Route::post('/jobs/{job}/collected', [JobWorkflowController::class, 'collected']);
    Route::post('/jobs/{job}/delivered', [JobWorkflowController::class, 'delivered']);
    Route::post('/jobs/{job}/cancel', [JobWorkflowController::class, 'cancel']);
    Route::post('/jobs/{job}/inspection', [JobWorkflowController::class, 'inspection']);
    Route::post('/jobs/{job}/inspection/approve', [JobWorkflowController::class, 'approveInspection']);
    Route::post('/jobs/{job}/inspection/request-changes', [JobWorkflowController::class, 'requestInspectionChanges']);
    Route::post('/jobs/{job}/complete', [JobWorkflowController::class, 'complete']);
    Route::post('/jobs/{job}/completion/approve', [JobWorkflowController::class, 'approveCompletion']);
    Route::post('/jobs/{job}/completion/reject', [JobWorkflowController::class, 'rejectCompletion']);
    Route::post('/jobs/{job}/dealer-complete', [JobWorkflowController::class, 'dealerComplete']);
    Route::post('/jobs/{job}/location-update', [JobTrackingController::class, 'store']);
    Route::post('/jobs/{job}/location-request', [JobTrackingController::class, 'requestUpdate']);
    Route::post('/jobs/{job}/incidents', [JobIncidentController::class, 'store']);
    Route::post('/jobs/{job}/incidents/{incident}/recovery-sent', [JobIncidentController::class, 'recoverySent']);
    Route::post('/jobs/{job}/incidents/{incident}/recovery-completed', [JobIncidentController::class, 'recoveryCompleted']);
    Route::get('/jobs/{job}/delivery-proof', [JobWorkflowController::class, 'deliveryProof']);
    Route::get('/jobs/{job}/inspection-photos/{photo}', [JobWorkflowController::class, 'inspectionPhoto']);

    Route::get('/messages', [MessageController::class, 'index']);
    Route::post('/messages', [MessageController::class, 'store']);
    Route::get('/messages/threads/{thread}', [MessageController::class, 'show']);
    Route::post('/messages/{message}/view', [MessageController::class, 'markAsViewed']);

    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
    Route::get('/invoices/{invoice}/download', [InvoiceController::class, 'download']);
    Route::get('/invoices/{invoice}/verify', [InvoiceController::class, 'verify']);

    Route::get('/admin/dashboard', [AdminPortalController::class, 'dashboard']);
    Route::get('/admin/account-change-requests', [AdminAccountChangeController::class, 'index']);
    Route::post('/admin/account-change-requests/{accountChangeRequest}/decision', [AdminAccountChangeController::class, 'decide']);
});
