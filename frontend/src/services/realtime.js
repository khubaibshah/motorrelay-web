import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import api from './api';

let echo = null;

function authToken() {
  if (typeof window === 'undefined') return null;
  return window.localStorage.getItem('mr_auth_token');
}

function apiBroadcastAuthEndpoint() {
  const baseUrl = String(api.defaults.baseURL || '').replace(/\/$/, '');
  return `${baseUrl}/broadcasting/auth`;
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

  return echo;
}

export function disconnectEchoClient() {
  if (!echo) return;
  echo.disconnect();
  echo = null;
}
