import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import api from './api';

let echo = null;
let connectionMonitoringBound = false;

function authToken() {
  if (typeof window === 'undefined') return null;
  return window.localStorage.getItem('mr_auth_token');
}

function apiBroadcastAuthEndpoint() {
  const baseUrl = String(api.defaults.baseURL || '').replace(/\/$/, '');
  return `${baseUrl}/broadcasting/auth`;
}

function dispatchRealtimeStatus(detail) {
  if (typeof window === 'undefined') return;

  window.dispatchEvent(new CustomEvent('motorrelay:realtime-status', {
    detail
  }));
}

function bindConnectionMonitoring(client) {
  const connection = client?.connector?.pusher?.connection;
  if (!connection || connectionMonitoringBound) return;

  connectionMonitoringBound = true;
  connection.bind('state_change', (states) => {
    dispatchRealtimeStatus({
      status: states?.current || 'unknown',
      previous: states?.previous || null
    });
  });
  connection.bind('connected', () => {
    dispatchRealtimeStatus({
      status: 'connected',
      connected_at: new Date().toISOString()
    });
  });
  connection.bind('error', (error) => {
    dispatchRealtimeStatus({
      status: 'error',
      error: error?.error?.message || error?.message || 'Reverb connection failed'
    });
  });
}

export function createEchoClient() {
  const key = import.meta.env.VITE_REVERB_APP_KEY || import.meta.env.VITE_PUSHER_APP_KEY;
  if (!key || typeof window === 'undefined') {
    return null;
  }

  if (echo) {
    return echo;
  }

  window.Pusher = Pusher;

  const scheme = import.meta.env.VITE_REVERB_SCHEME || import.meta.env.VITE_PUSHER_SCHEME || 'https';
  const host = import.meta.env.VITE_REVERB_HOST || import.meta.env.VITE_PUSHER_HOST || window.location.hostname;
  const port = Number(import.meta.env.VITE_REVERB_PORT || import.meta.env.VITE_PUSHER_PORT || (scheme === 'https' ? 443 : 80));

  echo = new Echo({
    broadcaster: import.meta.env.VITE_BROADCASTER || 'reverb',
    key,
    wsHost: host,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: apiBroadcastAuthEndpoint(),
    auth: {
      headers: {
        Accept: 'application/json',
        ...(authToken() ? { Authorization: `Bearer ${authToken()}` } : {})
      }
    }
  });

  bindConnectionMonitoring(echo);

  return echo;
}

export function disconnectEchoClient() {
  if (!echo) return;
  echo.disconnect();
  echo = null;
  connectionMonitoringBound = false;
}
