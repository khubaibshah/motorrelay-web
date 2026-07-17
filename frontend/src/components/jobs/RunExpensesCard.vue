<script setup>
import { formatStatusLabel } from "@/utils/statusLabels";

defineProps({
  expenses: {
    type: Array,
    default: () => []
  },
  summary: {
    type: Object,
    required: true
  },
  loading: {
    type: Boolean,
    default: false
  },
  error: {
    type: String,
    default: ""
  },
  canSubmit: {
    type: Boolean,
    default: false
  },
  canReview: {
    type: Boolean,
    default: false
  },
  isAssignedDriver: {
    type: Boolean,
    default: false
  },
  form: {
    type: Object,
    required: true
  },
  formKey: {
    type: Number,
    required: true
  },
  formError: {
    type: String,
    default: ""
  },
  submitting: {
    type: Boolean,
    default: false
  },
  editingId: {
    type: [Number, String, null],
    default: null
  },
  receiptDownloadingId: {
    type: [Number, String, null],
    default: null
  }
});

defineEmits([
  "submit",
  "receipt-change",
  "cancel-edit",
  "download-receipt",
  "edit",
  "delete",
  "review"
]);

const currencyFormatter = new Intl.NumberFormat("en-GB", {
  style: "currency",
  currency: "GBP"
});

function formatCurrency(value) {
  return currencyFormatter.format(Number(value || 0));
}

function formatDateTime(value) {
  if (!value) return "Not recorded";

  return new Intl.DateTimeFormat("en-GB", {
    dateStyle: "medium",
    timeStyle: "short"
  }).format(new Date(value));
}
</script>

<template>
  <section class="tile space-y-4 p-4">
    <header class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
      <div>
        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Expenses</h2>
        <p class="text-xs text-slate-500">Track expense submissions and approvals for this run.</p>
      </div>
      <div class="flex flex-wrap gap-3 text-xs text-slate-500">
        <span>Submitted: <span class="font-semibold text-slate-800">{{ formatCurrency(summary.submitted_total) }}</span></span>
        <span>Approved: <span class="font-semibold text-emerald-700">{{ formatCurrency(summary.approved_total) }}</span></span>
        <span>Rejected: <span class="font-semibold text-slate-800">{{ formatCurrency(summary.rejected_total) }}</span></span>
      </div>
    </header>

    <p v-if="error" class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-700">{{ error }}</p>

    <form
      v-if="canSubmit"
      class="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 md:grid-cols-4"
      @submit.prevent="$emit('submit')"
    >
      <div class="md:col-span-2">
        <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500">Description</label>
        <input
          v-model="form.description"
          type="text"
          required
          class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
          placeholder="Taxi from auction"
        >
      </div>
      <div>
        <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500">Amount</label>
        <input
          v-model="form.amount"
          type="number"
          min="0"
          step="0.01"
          required
          class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
          placeholder="32.00"
        >
      </div>
      <div>
        <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500">VAT %</label>
        <input
          v-model="form.vat_rate"
          type="number"
          min="0"
          step="0.5"
          class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
        >
      </div>
      <div class="md:col-span-2">
        <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500">Receipt</label>
        <input
          :key="formKey"
          type="file"
          accept=".jpg,.jpeg,.png,.pdf"
          class="mt-1 w-full text-sm text-slate-600"
          @change="$emit('receipt-change', $event)"
        >
        <p class="mt-1 text-xs text-slate-500">Images or PDF up to 5 MB.</p>
      </div>
      <div class="flex items-end justify-end gap-2 md:col-span-2">
        <button
          v-if="editingId"
          type="button"
          class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100"
          @click="$emit('cancel-edit')"
        >
          Cancel
        </button>
        <button
          type="submit"
          class="rounded-xl bg-emerald-600 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
          :disabled="submitting"
        >
          <span v-if="submitting">{{ editingId ? "Updating..." : "Saving..." }}</span>
          <span v-else>{{ editingId ? "Update expense" : "Add expense" }}</span>
        </button>
      </div>
      <p v-if="formError" class="text-xs text-amber-700 md:col-span-4">{{ formError }}</p>
    </form>

    <div v-if="loading" class="rounded-xl border bg-slate-50 p-4 text-sm text-slate-600">Loading expenses...</div>
    <div
      v-else-if="!expenses.length"
      class="rounded-xl border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-600"
    >
      No expenses submitted yet.
    </div>

    <div v-else class="space-y-3">
      <article
        v-for="expense in expenses"
        :key="expense.id"
        class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <p class="text-sm font-semibold text-slate-900">{{ expense.description }}</p>
            <p class="text-xs text-slate-500">
              Submitted {{ formatDateTime(expense.submitted_at) }}
              <span v-if="expense.driver?.name"> - {{ expense.driver.name }}</span>
            </p>
          </div>
          <span
            class="badge"
            :class="{
              'bg-emerald-100 text-emerald-700': expense.status === 'approved',
              'bg-amber-100 text-amber-700': expense.status === 'submitted',
              'bg-rose-100 text-rose-700': expense.status === 'rejected'
            }"
          >
            {{ formatStatusLabel(expense.status, "Submitted") }}
          </span>
        </div>

        <dl class="mt-3 grid gap-2 text-xs text-slate-600 sm:grid-cols-3">
          <div>
            <dt class="uppercase tracking-wide">Net</dt>
            <dd class="font-semibold text-slate-900">{{ formatCurrency(expense.amount) }}</dd>
          </div>
          <div>
            <dt class="uppercase tracking-wide">VAT ({{ expense.vat_rate }}%)</dt>
            <dd class="font-semibold text-slate-900">{{ formatCurrency(expense.vat_amount) }}</dd>
          </div>
          <div>
            <dt class="uppercase tracking-wide">Total</dt>
            <dd class="font-semibold text-slate-900">{{ formatCurrency(expense.total_amount) }}</dd>
          </div>
        </dl>

        <p v-if="expense.review_note" class="mt-2 rounded-xl bg-slate-50 p-3 text-xs text-slate-600">
          Dealer note: {{ expense.review_note }}
        </p>

        <div class="mt-3 flex flex-wrap gap-2">
          <button
            v-if="expense.receipt_path"
            type="button"
            class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-60"
            :disabled="receiptDownloadingId === expense.id"
            @click="$emit('download-receipt', expense)"
          >
            <span v-if="receiptDownloadingId === expense.id">Downloading...</span>
            <span v-else>Receipt</span>
          </button>

          <button
            v-if="isAssignedDriver && expense.is_editable"
            type="button"
            class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
            @click="$emit('edit', expense)"
          >
            Edit
          </button>
          <button
            v-if="isAssignedDriver && expense.is_editable"
            type="button"
            class="rounded-xl border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50"
            @click="$emit('delete', expense)"
          >
            Delete
          </button>

          <button
            v-if="canReview && expense.status === 'submitted'"
            type="button"
            class="rounded-xl border border-emerald-200 px-3 py-2 text-xs font-semibold text-emerald-700 hover:bg-emerald-50"
            @click="$emit('review', expense, 'approved')"
          >
            Approve
          </button>
          <button
            v-if="canReview && expense.status === 'submitted'"
            type="button"
            class="rounded-xl border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50"
            @click="$emit('review', expense, 'rejected')"
          >
            Reject
          </button>
        </div>
      </article>
    </div>
  </section>
</template>
