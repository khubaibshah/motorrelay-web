<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { downloadInvoice } from '@/services/invoices';
import { useAuthStore } from '@/stores/auth';
import { useInvoicesStore } from '@/stores/invoices';

const auth = useAuthStore();
const invoiceStore = useInvoicesStore();
const route = useRoute();
const invoices = computed(() => invoiceStore.items);
const loading = computed(() => invoiceStore.loading);
const downloadingId = ref(null);
const errorMessage = ref('');
const focusedJobId = computed(() => (typeof route.query.job === 'string' ? route.query.job : null));
const focusedInvoiceId = computed(() => (typeof route.query.invoice === 'string' ? route.query.invoice : null));
const visibleInvoices = computed(() => {
  if (focusedInvoiceId.value) {
    return invoices.value.filter((invoice) => String(invoice.id) === focusedInvoiceId.value);
  }

  if (focusedJobId.value) {
    return invoices.value.filter((invoice) => String(invoice.job?.id) === focusedJobId.value);
  }

  return invoices.value;
});

function formatCurrency(value, currencyCode = 'GBP') {
  try {
    return new Intl.NumberFormat('en-GB', {
      style: 'currency',
      currency: currencyCode || 'GBP',
      maximumFractionDigits: 2
    }).format(Number(value || 0));
  } catch {
    return `${currencyCode} ${Number(value || 0).toFixed(2)}`;
  }
}

function invoiceStatusLabel(status) {
  if (status === 'finalized') return 'Ready';
  if (status === 'draft') return 'Draft';
  return status || 'Draft';
}

async function loadInvoices() {
  if (!auth.token) {
    errorMessage.value = 'Log in to view invoices.';
    invoices.value = [];
    return;
  }

  errorMessage.value = '';
  try {
    await invoiceStore.fetch();
  } catch (error) {
    console.error('Failed to load invoices', error);
    errorMessage.value = 'Unable to load invoices right now.';
    invoiceStore.reset();
  } finally {
    loading.value = false;
  }
}

async function handleDownload(invoice) {
  if (!invoice?.id) return;

  downloadingId.value = invoice.id;
  try {
    const response = await downloadInvoice(invoice.id);
    const blob = new Blob([response.data], { type: 'application/pdf' });
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `${invoice.number || invoice.id}.pdf`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    window.URL.revokeObjectURL(url);
  } catch (error) {
    console.error('Failed to download invoice', error);
    alert('We could not download this invoice. Please try again later.');
  } finally {
    downloadingId.value = null;
  }
}

onMounted(async () => {
  if (!auth.user && auth.token) {
    await auth.fetchMe().catch(() => null);
  }
  loadInvoices();
});
</script>

<template>
  <div class="space-y-4">
    <header>
      <h1 class="text-2xl font-bold text-slate-900 dark:text-emerald-300">Invoices</h1>
      <p class="text-sm text-slate-600 dark:text-emerald-100">
        Generate and share MotorRelay invoices instantly after each delivery.
      </p>
    </header>

    <p v-if="errorMessage" class="text-sm text-amber-600">
      {{ errorMessage }}
    </p>

    <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900 dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100">
      <p class="font-bold">Why a download may be greyed out</p>
      <p>
        The PDF download is only available after the run is completed, inspection photos are uploaded, and the dealer approves the run.
        Demo or draft invoices can appear here before a PDF has been generated.
      </p>
    </div>

    <div v-if="loading" class="rounded-2xl border bg-white p-4 text-sm text-slate-600 dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100">
      Loading invoices...
    </div>

    <div v-else-if="!visibleInvoices.length" class="rounded-2xl border border-dashed border-slate-200 bg-white p-6 text-sm text-slate-600 dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100">
      {{ focusedJobId || focusedInvoiceId ? 'No invoice found for this run yet.' : 'No invoices yet. Approved runs will appear here once completion is signed off.' }}
    </div>

    <div v-else class="space-y-4">
      <div class="hidden md:block">
        <table class="min-w-full divide-y divide-slate-200 overflow-hidden rounded-2xl border bg-white dark:divide-white/10 dark:border-white/10 dark:bg-white/[0.06]">
          <thead class="bg-slate-50 dark:bg-slate-950">
            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-emerald-100">
              <th class="px-4 py-3">Invoice</th>
              <th class="px-4 py-3">Run</th>
              <th class="px-4 py-3">Subtotal</th>
              <th class="px-4 py-3">VAT</th>
              <th class="px-4 py-3">Total</th>
              <th class="px-4 py-3">Status</th>
              <th class="px-4 py-3">Issued</th>
              <th class="px-4 py-3 sr-only">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-200 text-sm text-slate-700 dark:divide-white/10 dark:text-emerald-100">
            <tr v-for="invoice in visibleInvoices" :key="invoice.id">
              <td class="px-4 py-3 font-semibold text-slate-900 dark:text-white">
                {{ invoice.number || invoice.id }}
              </td>
              <td class="px-4 py-3">
                <div class="font-medium text-slate-800 dark:text-white">
                  {{ invoice.job?.title || `Run #${invoice.job?.id ?? '--'}` }}
                </div>
                <div class="text-xs text-slate-500 dark:text-emerald-100">
                  Run ID: {{ invoice.job?.id ?? '--' }}
                </div>
              </td>
              <td class="px-4 py-3">
                {{ formatCurrency(invoice.subtotal, invoice.currency) }}
              </td>
              <td class="px-4 py-3">
                {{ formatCurrency(invoice.vat_total, invoice.currency) }}
              </td>
              <td class="px-4 py-3">
                {{ formatCurrency(invoice.total, invoice.currency) }}
              </td>
              <td class="px-4 py-3">
                <span
                  class="badge"
                  :class="{
                    'bg-emerald-100 text-emerald-700': invoice.status === 'finalized',
                    'bg-amber-100 text-amber-700': invoice.status === 'draft',
                    'bg-slate-200 text-slate-700 dark:bg-white/10 dark:text-emerald-100': !invoice.status
                  }"
                >
                  {{ invoiceStatusLabel(invoice.status) }}
                </span>
              </td>
              <td class="px-4 py-3">
                {{ invoice.issued_at ? new Date(invoice.issued_at).toLocaleDateString() : '--' }}
              </td>
              <td class="px-4 py-3 text-right">
                <button
                  type="button"
                  class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50 dark:border-white/10 dark:text-emerald-100 dark:hover:bg-white/10"
                  :disabled="!invoice.pdf_available || downloadingId === invoice.id"
                  :title="invoice.pdf_available ? 'Download invoice PDF' : 'PDF has not been generated for this invoice yet'"
                  @click="handleDownload(invoice)"
                >
                  <span v-if="downloadingId === invoice.id">Downloading...</span>
                  <span v-else-if="invoice.pdf_available">Download PDF</span>
                  <span v-else>PDF not ready</span>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="space-y-3 md:hidden">
        <article
          v-for="invoice in visibleInvoices"
          :key="`card-${invoice.id}`"
          class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.06]"
        >
          <header class="flex items-center justify-between gap-3">
            <div>
              <p class="text-sm font-semibold text-slate-900 dark:text-white">
                {{ invoice.number || invoice.id }}
              </p>
              <p class="text-xs text-slate-500 dark:text-emerald-100">
                {{ invoice.issued_at ? new Date(invoice.issued_at).toLocaleDateString() : 'Not issued' }}
              </p>
            </div>
            <span
              class="badge"
              :class="{
                'bg-emerald-100 text-emerald-700': invoice.status === 'finalized',
                'bg-amber-100 text-amber-700': invoice.status === 'draft',
                'bg-slate-200 text-slate-700 dark:bg-white/10 dark:text-emerald-100': !invoice.status
              }"
            >
              {{ invoiceStatusLabel(invoice.status) }}
            </span>
          </header>

          <dl class="mt-3 grid gap-2 text-xs text-slate-600 dark:text-emerald-100">
            <div>
              <dt class="font-semibold uppercase tracking-wide text-slate-500 dark:text-emerald-100">Run</dt>
              <dd class="text-sm font-medium text-slate-900 dark:text-white">
                {{ invoice.job?.title || `Run #${invoice.job?.id ?? '--'}` }}
              </dd>
            </div>
            <div class="flex items-center justify-between">
              <dt class="font-semibold uppercase tracking-wide text-slate-500 dark:text-emerald-100">Subtotal</dt>
              <dd class="text-sm font-semibold text-slate-900 dark:text-white">
                {{ formatCurrency(invoice.subtotal, invoice.currency) }}
              </dd>
            </div>
            <div class="flex items-center justify-between">
              <dt class="font-semibold uppercase tracking-wide text-slate-500 dark:text-emerald-100">VAT</dt>
              <dd class="text-sm font-semibold text-slate-900 dark:text-white">
                {{ formatCurrency(invoice.vat_total, invoice.currency) }}
              </dd>
            </div>
            <div class="flex items-center justify-between">
              <dt class="font-semibold uppercase tracking-wide text-slate-500 dark:text-emerald-100">Total</dt>
              <dd class="text-sm font-semibold text-emerald-700 dark:text-emerald-300">
                {{ formatCurrency(invoice.total, invoice.currency) }}
              </dd>
            </div>
          </dl>

          <button
            type="button"
            class="mt-3 w-full rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50 dark:border-white/10 dark:text-emerald-100 dark:hover:bg-white/10"
            :disabled="!invoice.pdf_available || downloadingId === invoice.id"
            :title="invoice.pdf_available ? 'Download invoice PDF' : 'PDF has not been generated for this invoice yet'"
            @click="handleDownload(invoice)"
          >
            <span v-if="downloadingId === invoice.id">Downloading...</span>
            <span v-else-if="invoice.pdf_available">Download PDF</span>
            <span v-else>PDF not ready</span>
          </button>
        </article>
      </div>
    </div>
  </div>
</template>
