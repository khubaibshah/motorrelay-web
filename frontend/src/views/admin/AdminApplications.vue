<script setup>
import { computed, inject, ref } from 'vue';
import { formatStatusLabel } from '@/utils/statusLabels';
import api from '@/services/api';

const dashboard = inject('adminDashboard');
const applications = computed(() => dashboard?.value?.applications || {});

const tabs = [
  { key: 'dealerships', label: 'Dealerships' },
  { key: 'drivers', label: 'Drivers' },
  { key: 'insurance', label: 'Insurance review' }
];

const activeTab = ref('dealerships');
const openingDocument = ref(null);
const documentError = ref('');

const rows = computed(() => {
  const list = applications.value?.[activeTab.value];
  return Array.isArray(list) ? list : [];
});

function setTab(key) {
  activeTab.value = key;
  documentError.value = '';
}

async function openDocument(row) {
  if (!row.has_document || openingDocument.value) return;

  openingDocument.value = row.driver_id;
  documentError.value = '';
  try {
    const response = await api.get(
      '/admin/drivers/' + row.driver_id + '/insurance-verification/document',
      { responseType: 'blob' }
    );
    const url = URL.createObjectURL(response.data);
    const link = document.createElement('a');
    link.href = url;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    link.click();
    window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
  } catch (error) {
    console.error('Failed to open insurance document', error);
    documentError.value = 'The insurance document could not be opened.';
  } finally {
    openingDocument.value = null;
  }
}

function formatDate(value) {
  if (!value) return '—';
  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) {
    return '—';
  }
  return parsed.toLocaleDateString();
}
</script>

<template>
  <div class="tile rounded-2xl bg-white p-6">
    <header class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
      <div>
        <h2 class="text-xl font-semibold text-slate-900">Account applications</h2>
        <p class="text-sm text-slate-600">Approve or decline new MotorRelay accounts.</p>
      </div>

      <div class="inline-flex rounded-full border border-emerald-200 bg-emerald-50 p-1">
        <button
          v-for="tab in tabs"
          :key="tab.key"
          type="button"
          class="rounded-full px-4 py-1.5 text-sm font-semibold transition"
          :class="tab.key === activeTab ? 'bg-white text-emerald-700 shadow-sm' : 'text-emerald-600'"
          @click="setTab(tab.key)"
        >
          {{ tab.label }}
        </button>
      </div>
    </header>

    <div class="mt-6">
      <div v-if="activeTab === 'insurance' && documentError" class="mb-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
        {{ documentError }}
      </div>

      <div
        v-if="!rows.length"
        class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-10 text-center text-sm text-slate-500"
      >
        No applications found.
      </div>

      <div v-else-if="activeTab === 'insurance'" class="overflow-hidden rounded-2xl border border-slate-200">
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-50 text-xs uppercase text-slate-500">
            <tr>
              <th class="px-4 py-3 font-semibold">Driver</th>
              <th class="px-4 py-3 font-semibold">Provider</th>
              <th class="px-4 py-3 font-semibold">Policy</th>
              <th class="px-4 py-3 font-semibold">Expires</th>
              <th class="px-4 py-3 font-semibold">Status</th>
              <th class="px-4 py-3 font-semibold">Document</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="row in rows" :key="row.driver_id">
              <td class="px-4 py-3">
                <div class="font-medium text-slate-900">{{ row.driver }}</div>
                <div class="text-xs text-slate-500">{{ row.email }}</div>
              </td>
              <td class="px-4 py-3 text-slate-600">{{ row.provider || '—' }}</td>
              <td class="px-4 py-3 text-slate-600">{{ row.policy_number || '—' }}</td>
              <td class="px-4 py-3 text-slate-600">{{ row.expires_at || '—' }}</td>
              <td class="px-4 py-3">
                <span class="rounded-full bg-emerald-100 px-2 py-1 text-xs font-semibold uppercase text-emerald-700">
                  {{ formatStatusLabel(row.status) }}
                </span>
              </td>
              <td class="px-4 py-3">
                <button
                  v-if="row.has_document"
                  type="button"
                  class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white transition hover:bg-slate-700 disabled:opacity-50"
                  :disabled="openingDocument === row.driver_id"
                  @click="openDocument(row)"
                >
                  {{ openingDocument === row.driver_id ? 'Opening…' : 'Open document' }}
                </button>
                <span v-else class="text-xs text-slate-500">Not supplied</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-else class="overflow-hidden rounded-2xl border border-slate-200">
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-50 text-xs uppercase text-slate-500">
            <tr>
              <th class="px-4 py-3 font-semibold">Name</th>
              <th class="px-4 py-3 font-semibold">Run</th>
              <th class="px-4 py-3 font-semibold">Status</th>
              <th class="px-4 py-3 font-semibold">Submitted</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="row in rows" :key="row.id">
              <td class="px-4 py-3 font-medium text-slate-900">{{ row.driver }}</td>
              <td class="px-4 py-3 text-slate-600">{{ row.job_title || 'Run' }}</td>
              <td class="px-4 py-3">
                <span class="rounded-full bg-emerald-100 px-2 py-1 text-xs font-semibold uppercase text-emerald-700">
                  {{ formatStatusLabel(row.status) }}
                </span>
              </td>
              <td class="px-4 py-3 text-sm text-slate-500">
                {{ formatDate(row.submitted_at) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
