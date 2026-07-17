import { defineStore } from 'pinia';
import { fetchDriverOverview } from '@/services/jobs';

export const useDriverStore = defineStore('driver', {
  state: () => ({
    overview: null,
    loading: false,
    error: null,
    fetchedAt: null
  }),

  getters: {
    activeJobs: (state) => state.overview?.active ?? [],
    pendingApplications: (state) => state.overview?.applications ?? [],
    completedJobs: (state) => state.overview?.completed ?? [],
    stats: (state) => state.overview?.stats ?? {}
  },

  actions: {
    async fetchOverview({ force = false } = {}) {
      if (!force && this.overview) return this.overview;
      this.loading = true;
      this.error = null;
      try {
        this.overview = await fetchDriverOverview();
        this.fetchedAt = Date.now();
        return this.overview;
      } catch (error) {
        this.error = error;
        throw error;
      } finally {
        this.loading = false;
      }
    },

    invalidate() {
      this.overview = null;
      this.fetchedAt = null;
    },

    reset() {
      this.overview = null;
      this.loading = false;
      this.error = null;
      this.fetchedAt = null;
    }
  }
});
