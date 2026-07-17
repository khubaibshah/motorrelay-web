import { defineStore } from 'pinia';
import { fetchJob, fetchJobs, fetchJobHighlights } from '@/services/jobs';

function listKey(params = {}) {
  return JSON.stringify(
    Object.keys(params)
      .sort()
      .reduce((result, key) => {
        if (params[key] !== undefined && params[key] !== null && params[key] !== '') {
          result[key] = params[key];
        }
        return result;
      }, {})
  );
}

function extractJobs(payload) {
  return Array.isArray(payload?.data) ? payload.data : Array.isArray(payload?.jobs) ? payload.jobs : [];
}

export const useJobsStore = defineStore('jobs', {
  state: () => ({
    lists: {},
    details: {},
    highlights: [],
    highlightsFetchedAt: null,
    highlightsLoading: false,
    loading: false,
    error: null,
    lastUpdatedAt: null
  }),

  getters: {
    cachedList: (state) => (params = {}) => state.lists[listKey(params)]?.payload || null,
    cachedJob: (state) => (id) => state.details[String(id)]?.job || null
  },

  actions: {
    async fetchHighlights({ force = false } = {}) {
      if (!force && this.highlightsFetchedAt !== null) return this.highlights;
      this.highlightsLoading = true;
      try {
        const payload = await fetchJobHighlights();
        this.highlights = Array.isArray(payload?.jobs) ? payload.jobs : [];
        this.highlightsFetchedAt = Date.now();
        return this.highlights;
      } finally {
        this.highlightsLoading = false;
      }
    },

    async fetchList(params = {}, { force = false } = {}) {
      const key = listKey(params);
      const cached = this.lists[key];
      if (!force && cached?.payload) return cached.payload;

      this.lists[key] = { ...(cached || {}), loading: true, error: null };
      try {
        const payload = await fetchJobs(params);
        this.lists[key] = {
          payload,
          params: { ...params },
          loading: false,
          error: null,
          fetchedAt: Date.now()
        };
        this.lastUpdatedAt = new Date().toISOString();
        return payload;
      } catch (error) {
        this.lists[key] = { ...(this.lists[key] || {}), loading: false, error };
        this.error = error;
        throw error;
      }
    },

    async fetchDetail(id, { force = false } = {}) {
      const key = String(id);
      const cached = this.details[key];
      if (!force && cached?.job) return cached.job;

      this.details[key] = { ...(cached || {}), loading: true, error: null };
      try {
        const job = await fetchJob(id);
        this.details[key] = { job, loading: false, error: null, fetchedAt: Date.now() };
        this.lastUpdatedAt = new Date().toISOString();
        return job;
      } catch (error) {
        this.details[key] = { ...(this.details[key] || {}), loading: false, error };
        this.error = error;
        throw error;
      }
    },

    async refreshAffectedJob(id) {
      const key = String(id);
      const matchingEntries = Object.values(this.lists)
        .filter((entry) => extractJobs(entry?.payload).some((job) => String(job?.id) === key));

      const detail = await this.fetchDetail(id, { force: true });
      await Promise.allSettled(
        matchingEntries
          .filter((entry) => entry?.params)
          .map((entry) => this.fetchList(entry.params, { force: true }))
      );
      return detail;
    },

    upsert(job) {
      if (!job?.id) return;
      const key = String(job.id);
      this.details[key] = { ...(this.details[key] || {}), job, loading: false, error: null, fetchedAt: Date.now() };

      Object.values(this.lists).forEach((entry) => {
        if (!entry?.payload) return;
        const jobs = extractJobs(entry.payload);
        const index = jobs.findIndex((item) => String(item.id) === key);
        if (index >= 0) jobs.splice(index, 1, { ...jobs[index], ...job });
      });
      this.lastUpdatedAt = new Date().toISOString();
    },

    invalidateJob(id) {
      delete this.details[String(id)];
    },

    invalidateLists() {
      this.lists = {};
    },

    reset() {
      this.lists = {};
      this.details = {};
      this.highlights = [];
      this.highlightsFetchedAt = null;
      this.highlightsLoading = false;
      this.loading = false;
      this.error = null;
      this.lastUpdatedAt = null;
    }
  }
});
