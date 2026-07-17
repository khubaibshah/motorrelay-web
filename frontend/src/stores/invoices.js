import { defineStore } from 'pinia';
import { fetchInvoices } from '@/services/invoices';

export const useInvoicesStore = defineStore('invoices', {
  state: () => ({
    items: [],
    loading: false,
    error: null,
    fetchedAt: null
  }),

  actions: {
    async fetch({ force = false } = {}) {
      if (!force && this.fetchedAt !== null) return this.items;
      this.loading = true;
      this.error = null;
      try {
        const payload = await fetchInvoices();
        this.items = Array.isArray(payload?.data) ? payload.data : [];
        this.fetchedAt = Date.now();
        return this.items;
      } catch (error) {
        this.error = error;
        throw error;
      } finally {
        this.loading = false;
      }
    },

    upsert(invoice) {
      if (!invoice?.id) return;
      const index = this.items.findIndex((item) => String(item.id) === String(invoice.id));
      if (index >= 0) this.items.splice(index, 1, { ...this.items[index], ...invoice });
      else this.items.unshift(invoice);
    },

    invalidate() {
      this.fetchedAt = null;
    },

    reset() {
      this.items = [];
      this.loading = false;
      this.error = null;
      this.fetchedAt = null;
    }
  }
});
