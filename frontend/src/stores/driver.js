import { defineStore } from 'pinia';
import { fetchDriverOverview } from '@/services/jobs';

export const useDriverStore = defineStore('driver', {
  state: () => ({
    overview: null,
    loading: false,
    error: null,
    fetchedAt: null,
    stale: false
  }),

  getters: {
    activeJobs: (state) => state.overview?.active ?? [],
    pendingApplications: (state) => state.overview?.applications ?? [],
    completedJobs: (state) => state.overview?.completed ?? [],
    stats: (state) => state.overview?.stats ?? {}
  },

  actions: {
    async fetchOverview({ force = false } = {}) {
      if (!force && this.overview && !this.stale) return this.overview;
      this.loading = true;
      this.error = null;
      try {
        this.overview = await fetchDriverOverview();
        this.fetchedAt = Date.now();
        this.stale = false;
        return this.overview;
      } catch (error) {
        this.error = error;
        throw error;
      } finally {
        this.loading = false;
      }
    },

    addPendingApplication(job, application = {}) {
      if (!job?.id) return;

      const existingApplications = this.overview?.applications ?? [];
      const applicationRecord = {
        ...application,
        job: application.job ?? job,
        job_id: application.job_id ?? job.id,
        status: application.status ?? 'pending',
        created_at: application.created_at ?? new Date().toISOString()
      };
      const withoutExisting = existingApplications.filter(
        (item) => Number(item.job_id ?? item.job?.id) !== Number(job.id)
      );

      this.overview = {
        ...(this.overview ?? { stats: {}, active: [], completed: [] }),
        applications: [applicationRecord, ...withoutExisting],
        stats: {
          ...(this.overview?.stats ?? {}),
          pending_applications: withoutExisting.length + 1
        }
      };
      this.stale = true;
    },

    invalidate() {
      this.overview = null;
      this.fetchedAt = null;
      this.stale = true;
    },

    reset() {
      this.overview = null;
      this.loading = false;
      this.error = null;
      this.fetchedAt = null;
      this.stale = false;
    }
  }
});
