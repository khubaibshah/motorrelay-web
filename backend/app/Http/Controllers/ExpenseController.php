<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Job;
use App\Services\Expenses\ExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ExpenseController extends Controller
{
    public function __construct(protected ExpenseService $expenses)
    {
    }

    public function index(Request $request, Job $job): JsonResponse
    {
        $this->assertCanView($request->user(), $job);
        return response()->json(['data' => $this->expenses->list($job)]);
    }

    public function store(Request $request, Job $job): JsonResponse
    {
        $user = $request->user();
        $this->assertDriverCanMutate($user, $job);
        $data = $request->validate($this->rules());
        return response()->json($this->expenses->transform($this->expenses->create($job, $user, $data, $request)), 201);
    }

    public function update(Request $request, Job $job, Expense $expense): JsonResponse
    {
        $user = $request->user();
        $this->assertDriverCanMutate($user, $job, $expense);
        $this->assertExpenseBelongsToJob($expense, $job);
        $data = $request->validate($this->rules(true));
        return response()->json($this->expenses->transform($this->expenses->update($job, $expense, $data, $request)));
    }

    public function destroy(Request $request, Job $job, Expense $expense): JsonResponse
    {
        $this->assertDriverCanMutate($request->user(), $job, $expense);
        $this->assertExpenseBelongsToJob($expense, $job);
        $this->expenses->delete($expense);
        return response()->noContent();
    }

    public function decide(Request $request, Job $job, Expense $expense): JsonResponse
    {
        $user = $request->user();
        $this->assertDealerCanReview($user, $job);
        $this->assertExpenseBelongsToJob($expense, $job);
        if ($expense->status !== 'submitted') abort(422, 'Only submitted expenses can be reviewed.');
        $data = $request->validate(['decision' => ['required', 'in:approved,rejected'], 'note' => ['nullable', 'string', 'max:2000']]);
        $updated = $this->expenses->decide($expense, $user, $data['decision'], $data['note'] ?? null);
        return response()->json($this->expenses->transform($updated));
    }

    public function receipt(Request $request, Job $job, Expense $expense)
    {
        $this->assertCanView($request->user(), $job);
        $this->assertExpenseBelongsToJob($expense, $job);
        if (! $expense->receipt_path) abort(404);
        return $this->expenses->receiptResponse($expense);
    }

    private function rules(bool $partial = false): array
    {
        return [
            'description' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'amount' => [$partial ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'receipt' => [$partial ? 'nullable' : 'sometimes', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:'.config('invoices.receipt_max_size_kb')],
        ];
    }

    private function assertExpenseBelongsToJob(Expense $expense, Job $job): void
    {
        if ($expense->job_id !== $job->id) abort(404);
    }

    private function assertCanView($user, Job $job): void
    {
        if (! $user) abort(401);
        if ($user->isAdmin() || $job->posted_by_id === $user->id || $job->assigned_to_id === $user->id) return;
        abort(403, 'You cannot access expenses for this job.');
    }

    private function assertDriverCanMutate($user, Job $job, ?Expense $expense = null): void
    {
        $this->assertCanView($user, $job);
        if (! $user->isDriver() && ! $user->isAdmin()) abort(403, 'Only drivers can create expenses.');
        if ($user->isDriver() && $job->assigned_to_id !== $user->id) abort(403, 'You are not assigned to this job.');
        if ($job->finalized_invoice_id) abort(422, 'Expenses cannot be changed after the invoice has been finalized.');

        $planSlug = $user->plan_slug ?? Str::slug((string) $user->plan, '_');
        if (! $expense && $planSlug === 'starter' && ! $user->isAdmin()) {
            $limit = config('jobs.plan_limits.starter.max_expenses_per_job', 0);
            if ($limit && $job->expenses()->where('driver_id', $user->id)->count() >= $limit) {
                abort(422, sprintf('Starter plan allows up to %d expense uploads per job. Upgrade to track additional receipts.', $limit));
            }
        }
        if ($expense && $expense->driver_id !== $user->id && ! $user->isAdmin()) abort(403, 'You cannot edit this expense.');
        if ($expense && ! $expense->is_editable) abort(422, 'This expense is locked and cannot be modified.');
    }

    private function assertDealerCanReview($user, Job $job): void
    {
        if (! $user) abort(401);
        if ($user->isAdmin() || ($user->isDealer() && $job->posted_by_id === $user->id)) return;
        abort(403, 'Only the posting dealer can review expenses for this job.');
    }
}
