import { defineStore } from 'pinia';
import { useJobsStore } from '@/stores/jobs';

export const useRealtimeStore = defineStore('realtime', {
  state: () => ({
    initialized: false,
    lastEventAt: null,
    connectionStatus: 'disconnected',
    lastConnectedAt: null,
    lastConnectionError: null,
    reconnectCount: 0
  }),

  actions: {
    initialize() {
      if (this.initialized || typeof window === 'undefined') return;
      this.initialized = true;
      window.addEventListener('motorrelay:job-event', this.handleJobEvent);
      window.addEventListener('motorrelay:realtime-status', this.handleRealtimeStatus);
    },

    handleJobEvent: async function (event) {
      const jobId = event?.detail?.job_id;
      if (!jobId) return;
      this.lastEventAt = new Date().toISOString();
      const jobs = useJobsStore();
      await jobs.refreshAffectedJob(jobId);
    },

    handleRealtimeStatus(event) {
      const detail = event?.detail || {};
      const status = detail.status || 'unknown';

      this.connectionStatus = status;
      if (status === 'connected') {
        this.lastConnectedAt = detail.connected_at || new Date().toISOString();
        this.lastConnectionError = null;
      }

      if (status === 'error' || status === 'unavailable' || status === 'failed') {
        this.lastConnectionError = detail.error || 'Reverb connection failed';
        this.reconnectCount += 1;
        console.warn('[MotorRelay] Reverb connection failure', detail);
      }
    }
  }
});
