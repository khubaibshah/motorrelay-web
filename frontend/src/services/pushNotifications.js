import { Capacitor } from '@capacitor/core';
import { PushNotifications } from '@capacitor/push-notifications';
import api from '@/services/api';
import router from '@/router';

let started = false;
let currentToken = null;

function platform() {
  const value = Capacitor.getPlatform();
  if (value === 'ios') return 'ios';
  if (value === 'android') return 'android';
  return 'web';
}

function canUseNativePush() {
  return Capacitor.isNativePlatform() && ['ios', 'android'].includes(platform());
}

async function saveToken(token) {
  if (!token?.value) return;

  currentToken = token.value;

  await api.post('/push-subscriptions', {
    platform: platform(),
    token: token.value,
    // Keep the device key stable across APNs token rotations so the backend
    // can retire the previous token instead of sending duplicate pushes.
    device_id: platform()
  });
}

export async function startPushNotifications() {
  if (started || !canUseNativePush()) return;

  started = true;

  PushNotifications.addListener('registration', saveToken);

  PushNotifications.addListener('registrationError', (error) => {
    console.warn('Push registration failed', error);
  });

  PushNotifications.addListener('pushNotificationActionPerformed', (event) => {
    const url = event?.notification?.data?.url;
    if (url) {
      router.push(url).catch(() => null);
    }
  });

  const permission = await PushNotifications.requestPermissions();
  if (permission.receive !== 'granted') {
    return;
  }

  await PushNotifications.register();
}

export async function stopPushNotifications() {
  if (!started) return;

  started = false;

  if (currentToken) {
    await api.delete('/push-subscriptions', {
      data: {
        platform: platform(),
        token: currentToken
      }
    }).catch(() => null);
  }

  currentToken = null;
  await PushNotifications.removeAllListeners();
}
