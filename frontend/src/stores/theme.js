import { defineStore } from 'pinia';

const STORAGE_KEY = 'mr_theme';

function preferredTheme() {
  if (typeof window === 'undefined') return 'light';

  const stored = window.localStorage.getItem(STORAGE_KEY);
  if (stored === 'light' || stored === 'dark') return stored;

  return window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

function applyTheme(mode) {
  if (typeof document === 'undefined') return;
  document.documentElement.classList.toggle('dark', mode === 'dark');
  document.documentElement.style.colorScheme = mode;
}

export const useThemeStore = defineStore('theme', {
  state: () => ({
    mode: 'light',
    ready: false
  }),
  getters: {
    isDark: (state) => state.mode === 'dark',
    label: (state) => (state.mode === 'dark' ? 'Dark mode' : 'Light mode')
  },
  actions: {
    initialize() {
      this.mode = preferredTheme();
      this.ready = true;
      applyTheme(this.mode);
    },
    setMode(mode) {
      this.mode = mode === 'dark' ? 'dark' : 'light';
      if (typeof window !== 'undefined') {
        window.localStorage.setItem(STORAGE_KEY, this.mode);
      }
      applyTheme(this.mode);
    },
    toggle() {
      this.setMode(this.mode === 'dark' ? 'light' : 'dark');
    }
  }
});
