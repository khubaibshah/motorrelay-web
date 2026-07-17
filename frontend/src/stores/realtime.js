import { defineStore } from 'pinia';
import { useJobsStore } from '@/stores/jobs';

export const useRealtimeStore = defineStore('realtime', {
  state: () => ({
    initialized: false,
    lastEventAt: null
  }),

  actions: {
    initialize() {
      if (this.initialized || typeof window === 'undefined') return;
      this.initialized = true;
      window.addEventListener('motorrelay:job-event', this.handleJobEvent);
    },

    handleJobEvent: async function (event) {
      const jobId = event?.detail?.job_id;
      if (!jobId) return;
      this.lastEventAt = new Date().toISOString();
      const jobs = useJobsStore();
      jobs.invalidateJob(jobId);
      // Cached lists are refreshed only when a page has already loaded them.
      const entries = Object.values(jobs.lists || {});
      await Promise.allSettled(
        entries
          .filter((entry) => entry?.params)
          .map((entry) => jobs.fetchList(entry.params, { force: true }))
      );
    }
  }
});
