import { Capacitor } from '@capacitor/core';
import { Geolocation } from '@capacitor/geolocation';
import { startJobLocationTracking, stopJobLocationTracking, updateJobLocation } from '@/services/jobs';

/**
 * Owns driver location permission, periodic updates, and the stop/restart
 * lifecycle. The page only supplies reactive job state and action permissions.
 */
export function useLiveTracking({ job, trackingState, canShareTracking, hasTrackingEnded }) {
  let liveTrackingInterval = null;
  let trackingSessionToken = null;

  function createLocationPermissionError() {
    const error = new Error('Location permission is blocked.');
    error.code = 1;
    return error;
  }

  function isLocationPermissionBlockedError(error) {
    const message = String(error?.message || error?.errorMessage || '').toLowerCase();
    return error?.code === 1 || message.includes('denied') || message.includes('permission');
  }

  function isLocationServicesDisabledError(error) {
    const message = String(error?.message || error?.errorMessage || '').toLowerCase();
    return error?.code === 'OS-PLUG-GLOC-0007' || message.includes('location services are not enabled');
  }

  function geolocationErrorMessage(error) {
    if (!error) return '';
    if (isLocationServicesDisabledError(error)) {
      return 'Location Services are switched off on this iPhone. Turn them on in Settings > Privacy & Security > Location Services, then tap Share live location again.';
    }
    if (isLocationPermissionBlockedError(error)) {
      return 'Location permission is blocked. Open MotorRelay settings, allow Location while using the app, then tap Share live location again.';
    }
    if (error.code === 2) return 'Your current location is unavailable right now. Check signal/location services and try again.';
    if (error.code === 3) return 'Location lookup timed out. Try again somewhere with better GPS signal.';
    return error.message || '';
  }

  async function getCurrentPosition(options = {}) {
    if (Capacitor.isNativePlatform()) {
      const currentPermission = await Geolocation.checkPermissions();
      if (currentPermission.location !== 'granted') {
        const requestedPermission = await Geolocation.requestPermissions({ permissions: ['location'] });
        if (requestedPermission.location !== 'granted') throw createLocationPermissionError();
      }
      return Geolocation.getCurrentPosition(options);
    }

    return new Promise((resolve, reject) => {
      if (!navigator.geolocation) {
        reject(new Error('Geolocation is not supported on this device.'));
        return;
      }
      navigator.geolocation.getCurrentPosition(resolve, reject, options);
    });
  }

  async function sendLiveLocationUpdate({ silent = false, allowSessionStart = false } = {}) {
    if (!job.value) return;
    if (!silent) {
      trackingState.error = '';
      trackingState.requestNotice = '';
      trackingState.locationBlocked = false;
      trackingState.locationServicesOff = false;
      trackingState.sending = true;
    }

    try {
      const position = await getCurrentPosition({ enableHighAccuracy: true, maximumAge: 30000, timeout: 15000 });
      const coords = position.coords || {};
      const heading = Number(coords.heading);
      const speed = Number(coords.speed);
      const payload = {
        latitude: coords.latitude,
        longitude: coords.longitude,
        accuracy: coords.accuracy ?? undefined,
        heading: Number.isFinite(heading) && heading >= 0 && heading <= 360 ? heading : undefined,
        speed_kph: Number.isFinite(speed) && speed >= 0 ? Math.min(speed * 3.6, 300) : undefined,
        source: Capacitor.isNativePlatform() ? 'ios' : 'web'
      };

      if (payload.latitude === undefined || payload.longitude === undefined) {
        throw new Error('Unable to determine your current position.');
      }

      if (!trackingSessionToken && allowSessionStart) {
        const session = await startJobLocationTracking(job.value.id);
        trackingSessionToken = session?.tracking_session_token || null;
      }
      if (!trackingSessionToken) {
        throw new Error('Live location is not active on this device. Tap Share live location to start it.');
      }

      const response = await updateJobLocation(job.value.id, {
        ...payload,
        tracking_session_token: trackingSessionToken
      });
      if (response?.job) {
        job.value = {
          ...job.value,
          current_latitude: response.job.current_latitude,
          current_longitude: response.job.current_longitude,
          last_tracked_at: response.job.last_tracked_at
        };
        trackingState.lastUpdate = response.job.last_tracked_at;
      }
      trackingState.shared = true;
    } catch (error) {
      console.error('Failed to share live location', error);
      trackingState.locationServicesOff = isLocationServicesDisabledError(error);
      trackingState.locationBlocked = !trackingState.locationServicesOff && isLocationPermissionBlockedError(error);
      if (error?.response?.status === 409) {
        trackingSessionToken = null;
        trackingState.shared = false;
        stopLiveTrackingUpdates({ endSession: false });
      }
      if (!silent) {
        trackingState.error = error?.response?.data?.message || geolocationErrorMessage(error) || 'We could not determine your current location. Please try again.';
      } else if (trackingState.locationBlocked || trackingState.locationServicesOff) {
        stopLiveTrackingUpdates();
      }
    } finally {
      if (!silent) trackingState.sending = false;
    }
  }

  function startLiveTrackingUpdates() {
    if (typeof window === 'undefined' || liveTrackingInterval || !canShareTracking.value) return;
    liveTrackingInterval = window.setInterval(() => {
      if (!canShareTracking.value || hasTrackingEnded.value) {
        stopLiveTrackingUpdates();
        return;
      }
      sendLiveLocationUpdate({ silent: true, allowSessionStart: false }).catch(() => null);
    }, 25000);
  }

  function stopLiveTrackingUpdates({ endSession = true } = {}) {
    if (liveTrackingInterval && typeof window !== 'undefined') window.clearInterval(liveTrackingInterval);
    liveTrackingInterval = null;
    if (endSession && trackingSessionToken && job.value?.id) {
      const token = trackingSessionToken;
      trackingSessionToken = null;
      stopJobLocationTracking(job.value.id, token).catch(() => null);
    }
  }

  async function shareLiveLocation() {
    await sendLiveLocationUpdate({ allowSessionStart: true });
    if (!trackingState.error && trackingState.shared) startLiveTrackingUpdates();
  }

  async function endLiveTrackingSession() {
    if (!trackingSessionToken || !job.value?.id) return;
    const token = trackingSessionToken;
    trackingSessionToken = null;
    await stopJobLocationTracking(job.value.id, token).catch(() => null);
  }

  return { sendLiveLocationUpdate, shareLiveLocation, startLiveTrackingUpdates, stopLiveTrackingUpdates, endLiveTrackingSession };
}
