<?php

namespace App\Services\Expenses;

use App\Models\Expense;
use App\Models\Job;
use App\Models\User;
use App\Notifications\ExpenseReviewedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExpenseService
{
    public function list(Job $job): array
    {
        return $job->expenses()->with(['driver:id,name'])->orderByDesc('created_at')->get()
            ->map(fn (Expense $expense) => $this->transform($expense))->all();
    }

    public function create(Job $job, User $driver, array $data, Request $request): Expense
    {
        $receipt = $this->storeReceipt($request, $job);
        $vatRate = $data['vat_rate'] ?? config('invoices.default_vat_rate');

        return DB::transaction(function () use ($job, $driver, $data, $receipt, $vatRate) {
            return Expense::create([
                'job_id' => $job->id,
                'driver_id' => $driver->id,
                'description' => $data['description'],
                'amount' => $data['amount'],
                'vat_rate' => $vatRate,
                'receipt_path' => $receipt['path'] ?? null,
                'receipt_disk' => $receipt['disk'] ?? null,
                'status' => 'submitted',
                'submitted_at' => now(),
            ])->fresh(['driver:id,name']);
        });
    }

    public function update(Job $job, Expense $expense, array $data, Request $request): Expense
    {
        $receipt = $this->storeReceipt($request, $job, $expense);
        $updatedVatRate = $data['vat_rate'] ?? $expense->vat_rate;

        return DB::transaction(function () use ($expense, $data, $receipt, $updatedVatRate) {
            $expense->fill(array_filter([
                'description' => $data['description'] ?? null,
                'amount' => $data['amount'] ?? null,
                'vat_rate' => $updatedVatRate,
                'receipt_path' => $receipt['path'] ?? null,
                'receipt_disk' => $receipt['disk'] ?? null,
            ], fn ($value) => $value !== null));
            $expense->save();
            return $expense->fresh(['driver:id,name', 'reviewer:id,name']);
        });
    }

    public function delete(Expense $expense): void
    {
        $this->deleteReceipt($expense);
        $expense->delete();
    }

    public function decide(Expense $expense, User $reviewer, string $decision, ?string $note): Expense
    {
        return DB::transaction(function () use ($expense, $reviewer, $decision, $note) {
            $expense->update([
                'status' => $decision,
                'review_note' => $note,
                'reviewed_by_id' => $reviewer->id,
                'reviewed_at' => now(),
                'locked_at' => now(),
            ]);
            $fresh = $expense->fresh(['driver:id,name,email', 'reviewer:id,name']);
            DB::afterCommit(function () use ($fresh) {
                if ($fresh->driver) Notification::send($fresh->driver, new ExpenseReviewedNotification($fresh));
            });
            return $fresh;
        });
    }

    public function receiptResponse(Expense $expense)
    {
        $disk = $expense->receipt_disk ?? config('invoices.receipt_disk');
        $adapter = Storage::disk($disk);
        if (! $adapter->exists($expense->receipt_path)) abort(404);
        $extension = pathinfo($expense->receipt_path, PATHINFO_EXTENSION) ?: 'pdf';
        $filename = Str::of($expense->description)->slug('-')."-receipt.{$extension}";
        return $adapter->response($expense->receipt_path, (string) $filename, [], 'inline');
    }

    public function transform(Expense $expense): array
    {
        return [
            'id' => $expense->id, 'job_id' => $expense->job_id, 'driver_id' => $expense->driver_id,
            'description' => $expense->description, 'amount' => $expense->amount, 'vat_rate' => $expense->vat_rate,
            'vat_amount' => $expense->vat_amount, 'total_amount' => $expense->total_amount, 'status' => $expense->status,
            'receipt_path' => $expense->receipt_path, 'receipt_disk' => $expense->receipt_disk,
            'submitted_at' => $expense->submitted_at, 'reviewed_at' => $expense->reviewed_at,
            'locked_at' => $expense->locked_at, 'review_note' => $expense->review_note,
            'driver' => $expense->driver ? ['id' => $expense->driver->id, 'name' => $expense->driver->name] : null,
            'reviewer' => $expense->reviewer ? ['id' => $expense->reviewer->id, 'name' => $expense->reviewer->name] : null,
            'is_editable' => $expense->is_editable,
        ];
    }

    private function storeReceipt(Request $request, Job $job, ?Expense $expense = null): array
    {
        if (! $request->hasFile('receipt')) return [];
        $file = $request->file('receipt');
        $disk = config('invoices.receipt_disk');
        $directory = sprintf('jobs/%d/expenses', $job->id);
        $extension = $file->getClientOriginalExtension() ?: 'pdf';
        $filename = sprintf('%s-%s.%s', now()->format('YmdHis'), Str::ulid(), $extension);
        $path = $file->storeAs($directory, $filename, $disk);
        if ($expense) $this->deleteReceipt($expense);
        return ['disk' => $disk, 'path' => $path];
    }

    private function deleteReceipt(Expense $expense): void
    {
        if (! $expense->receipt_path) return;
        $disk = $expense->receipt_disk ?? config('invoices.receipt_disk');
        $adapter = Storage::disk($disk);
        if ($adapter->exists($expense->receipt_path)) $adapter->delete($expense->receipt_path);
    }
}
