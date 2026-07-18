import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import router from './router';
import './styles/tailwind.css';
import { useAuthStore as authStore } from './stores/auth';
import { useThemeStore as themeStore } from './stores/theme';
import { useRealtimeStore } from './stores/realtime';

const app = createApp(App);
const pinia = createPinia();
app.use(pinia);

function preventDoubleTapZoom() {
  if (typeof window === 'undefined' || typeof document === 'undefined') return;

  let lastTouchEndAt = 0;
  let lastTouchX = 0;
  let lastTouchY = 0;

  document.addEventListener(
    'touchend',
    (event) => {
      if (event.changedTouches.length !== 1) return;

      const touch = event.changedTouches[0];
      const now = Date.now();
      const deltaTime = now - lastTouchEndAt;
      const deltaX = Math.abs(touch.clientX - lastTouchX);
      const deltaY = Math.abs(touch.clientY - lastTouchY);

      if (deltaTime > 0 && deltaTime < 350 && deltaX < 30 && deltaY < 30) {
        event.preventDefault();
      }

      lastTouchEndAt = now;
      lastTouchX = touch.clientX;
      lastTouchY = touch.clientY;
    },
    { passive: false }
  );

  document.addEventListener('dblclick', (event) => {
    event.preventDefault();
  });
}

preventDoubleTapZoom();

const auth = authStore(pinia);
const theme = themeStore(pinia);
const realtime = useRealtimeStore(pinia);
theme.initialize();
realtime.initialize();
const initialization = auth.initialize();
const publicRoutes = new Set(['login', 'signup']);

router.beforeEach(async (to, from, next) => {
  await initialization;

  if (auth.token && !auth.user && !auth.loading) {
    await auth.fetchMe();
  }

  const isPublic = publicRoutes.has(to.name);

  if (!auth.isAuthenticated && !isPublic) {
    const redirectPath = to.fullPath !== '/login' ? to.fullPath : undefined;
    next(
      redirectPath
        ? { name: 'login', query: { redirect: redirectPath } }
        : { name: 'login' }
    );
    return;
  }

  if (auth.isAuthenticated && isPublic) {
    next({ name: 'home' });
    return;
  }

  const requiredRole = to.meta?.requiresRole;
  if (requiredRole && auth.role !== requiredRole) {
    next({ name: 'home' });
    return;
  }

  next();
});

async function bootstrap() {
  app.use(router);
  await initialization;
  await router.isReady();
  app.mount('#app');
}

void bootstrap();
