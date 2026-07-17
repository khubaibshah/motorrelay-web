import { defineStore } from 'pinia';
import { useJobsStore } from '@/stores/jobs';

function applicationCount(job) {
  return Number(job?.pending_applications_count ?? job?.applications_count ?? job?.applications?.length ?? 0);
}

function withApplicationCount(job) {
  const count = applicationCount(job);
  return {
    ...job,
    pending_applications_count: count,
    applications_count: Math.max(Number(job?.applications_count ?? 0), count)
  };
}

export const useDealerStore = defineStore('dealer', {
  state: () => ({
    postedJobs: [],
    applicationJobs: [],
    loading: false,
    error: null,
    lastUpdatedAt: null
  }),

  getters: {
    jobsWithApplications: (state) => state.postedJobs
      .filter((job) => applicationCount(job) > 0)
      .sort((a, b) => new Date(b?.updated_at ?? b?.created_at ?? 0) - new Date(a?.updated_at ?? a?.created_at ?? 0))
  },

  actions: {
    syncFromProfile(jobs = []) {
      this.postedJobs = jobs.map(withApplicationCount);
      this.lastUpdatedAt = new Date().toISOString();
    },

    upsertApplicationJob(job) {
      if (!job?.id) return;
      const id = Number(job.id);
      const existing = this.applicationJobs.find((item) => Number(item?.id) === id)
        ?? this.postedJobs.find((item) => Number(item?.id) === id)
        ?? {};
      const merged = withApplicationCount({
        ...existing,
        ...job,
        id,
        pending_applications_count: Math.max(1, applicationCount(existing), applicationCount(job)),
        applications_count: Math.max(1, applicationCount(existing), applicationCount(job)),
        updated_at: job?.updated_at ?? new Date().toISOString()
      });

      this.applicationJobs = [merged, ...this.applicationJobs.filter((item) => Number(item?.id) !== id)];
      this.postedJobs = [merged, ...this.postedJobs.filter((item) => Number(item?.id) !== id)];
      this.lastUpdatedAt = new Date().toISOString();
    },

    async refreshApplicationJob(jobId) {
      const jobs = useJobsStore();
      const job = await jobs.fetchDetail(jobId, { force: true });
      const count = Array.isArray(job?.applications) ? job.applications.length : 1;
      this.upsertApplicationJob({
        ...job,
        pending_applications_count: Number(job?.pending_applications_count ?? count) || 1,
        applications_count: Number(job?.applications_count ?? count) || 1
      });
      return job;
    },

    reset() {
      this.postedJobs = [];
      this.applicationJobs = [];
      this.loading = false;
      this.error = null;
      this.lastUpdatedAt = null;
    }
  }
});
