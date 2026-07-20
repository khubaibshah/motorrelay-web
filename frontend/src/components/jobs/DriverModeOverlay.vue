<script setup>
import { ref } from "vue";

defineProps({
  job: {
    type: Object,
    required: true
  },
  routeLabel: {
    type: String,
    default: ""
  },
  statusLabel: {
    type: String,
    default: ""
  },
  nextActionText: {
    type: String,
    default: ""
  },
  primaryAction: {
    type: Object,
    default: null
  },
  showSecondaryTracking: {
    type: Boolean,
    default: false
  },
  trackingState: {
    type: Object,
    required: true
  },
  completionForm: {
    type: Object,
    required: true
  },
  completionError: {
    type: String,
    default: ""
  },
  completionSubmitting: {
    type: Boolean,
    default: false
  },
  canUploadInspection: {
    type: Boolean,
    default: false
  },
  minInspectionPhotoCount: {
    type: Number,
    required: true
  },
  requiredInspectionShots: {
    type: Array,
    default: () => []
  },
  uploadedPhotos: {
    type: Array,
    default: () => []
  },
  mapSrc: {
    type: String,
    default: ""
  },
  pickupShort: {
    type: String,
    default: ""
  },
  dropoffShort: {
    type: String,
    default: ""
  },
  destinationLabel: {
    type: String,
    default: ""
  },
  trackingLabel: {
    type: String,
    default: ""
  },
  completedWorkflowCount: {
    type: Number,
    default: 0
  },
  workflowTotalCount: {
    type: Number,
    default: 0
  },
  timelineItems: {
    type: Array,
    default: () => []
  },
  driverActionError: {
    type: String,
    default: ""
  },
  navigationLinks: {
    type: Array,
    default: () => []
  },
  canReportIncident: {
    type: Boolean,
    default: false
  }
});

const emit = defineEmits([
  "close",
  "inspection-files",
  "open-gallery",
  "submit-photos",
  "share-location",
  "open-chat",
  "report-issue"
]);

const inspectionInput = ref(null);

function openInspectionPicker() {
  inspectionInput.value?.click();
}
</script>

<template>
  <transition name="fade">
    <div class="fixed inset-x-0 bottom-0 top-[calc(env(safe-area-inset-top)*-1)] z-[100] min-h-[calc(100dvh+env(safe-area-inset-top))] overflow-hidden bg-slate-950 text-white">
      <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_top,rgba(16,185,129,0.22),transparent_34%),linear-gradient(180deg,#001f1a_0%,#020617_28%,#020617_100%)]" />

      <div class="relative mx-auto flex h-[calc(100dvh+env(safe-area-inset-top))] max-w-2xl flex-col px-4 pb-[max(1rem,env(safe-area-inset-bottom))] pt-[calc(env(safe-area-inset-top)+1.85rem)]">
        <header class="flex shrink-0 items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="text-xs font-black uppercase tracking-[0.2em] text-emerald-300">Driver mode</p>
            <h2 class="mt-1 truncate text-3xl font-black leading-none">{{ job.title || `Run #${job.id}` }}</h2>
            <p class="mt-2 truncate text-sm font-bold text-emerald-100/80">{{ routeLabel }}</p>
            <p
              v-if="job.listing_type === 'auction' && job.auction_reference"
              class="mt-1 text-xs font-black text-emerald-300"
            >
              Auction reference: {{ job.auction_reference }}
            </p>
          </div>
          <button
            type="button"
            class="rounded-2xl border border-white/15 bg-white/5 px-4 py-3 text-sm font-black text-white shadow-lg"
            @click="emit('close')"
          >
            Close
          </button>
        </header>

        <main class="mt-4 min-h-0 flex-1 overflow-y-auto pb-3 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
          <section class="rounded-[1.75rem] border border-white/10 bg-white/[0.06] p-4 shadow-2xl backdrop-blur">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <div class="flex items-center gap-2">
                  <span class="h-2.5 w-2.5 rounded-full bg-emerald-400 shadow-[0_0_20px_rgba(52,211,153,0.9)]" />
                  <p class="text-sm font-black text-white">Active run</p>
                </div>
                <p class="mt-2 truncate text-lg font-black">{{ routeLabel }}</p>
                <p class="mt-1 text-sm font-semibold text-emerald-100/80">
                  {{ nextActionText || "Keep this run moving and update the dealer as you progress." }}
                </p>
              </div>
              <span class="shrink-0 rounded-full bg-emerald-400/10 px-3 py-1 text-xs font-black text-emerald-200 ring-1 ring-emerald-300/20">
                {{ statusLabel }}
              </span>
            </div>
          </section>

          <input
            ref="inspectionInput"
            type="file"
            accept="image/*"
            multiple
            class="hidden"
            @change="emit('inspection-files', $event)"
          >

          <p v-if="completionError" class="mt-3 rounded-2xl border border-amber-300/30 bg-amber-400/10 p-3 text-sm font-bold text-amber-100">
            {{ completionError }}
          </p>

          <section
            v-if="canUploadInspection"
            class="mt-3 rounded-[1.5rem] border border-emerald-300/20 bg-emerald-400/10 p-4"
          >
            <div class="flex items-center justify-between gap-3">
              <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-300">Photo checklist</p>
              <span class="rounded-full bg-emerald-400 px-3 py-1 text-xs font-black text-slate-950">
                {{ completionForm.proof.length }}/{{ minInspectionPhotoCount }}
              </span>
            </div>
            <div class="mt-3 grid grid-cols-3 gap-2">
              <span
                v-for="(shot, index) in requiredInspectionShots"
                :key="`driver-mode-new-shot-${shot}`"
                class="rounded-2xl px-3 py-2 text-xs font-black"
                :class="completionForm.proof.length > index ? 'bg-emerald-400 text-slate-950' : 'bg-white/[0.06] text-emerald-100'"
              >
                {{ shot }}
              </span>
            </div>
            <div class="mt-3 grid grid-cols-2 gap-2">
              <button
                type="button"
                class="rounded-2xl border border-emerald-300/30 bg-white/[0.06] px-4 py-3 text-sm font-black text-emerald-100"
                @click="openInspectionPicker"
              >
                Add photos
              </button>
              <button
                type="button"
                class="rounded-2xl bg-emerald-400 px-4 py-3 text-sm font-black text-slate-950 disabled:cursor-not-allowed disabled:bg-slate-700 disabled:text-slate-300"
                :disabled="completionForm.proof.length < minInspectionPhotoCount || completionSubmitting"
                @click="emit('submit-photos')"
              >
                {{ completionSubmitting ? "Submitting..." : "Submit photos" }}
              </button>
            </div>
          </section>

          <section v-if="uploadedPhotos.length" class="mt-3 grid grid-cols-3 gap-2">
            <button
              v-for="(photo, index) in uploadedPhotos"
              :key="`driver-mode-new-photo-${photo.id}`"
              type="button"
              class="overflow-hidden rounded-2xl border border-white/10 bg-white/[0.06]"
              @click="emit('open-gallery', index)"
            >
              <img
                v-if="photo.previewUrl"
                :src="photo.previewUrl"
                :alt="photo.name"
                class="h-24 w-full object-cover"
              >
              <span v-else class="flex h-24 items-center justify-center px-2 text-center text-[0.65rem] font-bold text-emerald-100">
                {{ photo.name }}
              </span>
            </button>
          </section>

          <section class="mt-4 overflow-hidden rounded-[1.75rem] border border-emerald-300/15 bg-emerald-950/30 shadow-2xl">
            <div class="relative h-64 bg-slate-900">
              <iframe
                v-if="mapSrc"
                :src="mapSrc"
                class="h-full w-full border-0 opacity-90 grayscale-[25%] contrast-125 saturate-75"
                loading="lazy"
                referrerpolicy="no-referrer-when-downgrade"
                title="Driver route map"
              />
              <div
                v-else
                class="flex h-full items-center justify-center bg-[radial-gradient(circle_at_center,rgba(52,211,153,0.24),transparent_38%),#06120f] px-6 text-center text-sm font-bold text-emerald-100"
              >
                Route map will appear when pickup and drop-off details are available.
              </div>
              <div class="pointer-events-none absolute inset-x-3 top-3 flex items-center justify-between gap-3">
                <span class="max-w-[45%] truncate rounded-full bg-slate-950/75 px-3 py-2 text-xs font-black text-white backdrop-blur">
                  {{ pickupShort }}
                </span>
                <span class="max-w-[45%] truncate rounded-full bg-slate-950/75 px-3 py-2 text-xs font-black text-white backdrop-blur">
                  {{ dropoffShort }}
                </span>
              </div>
            </div>
            <div class="grid grid-cols-2 gap-3 border-t border-white/10 bg-slate-950/70 p-4">
              <div class="min-w-0">
                <p class="text-[0.65rem] font-black uppercase tracking-[0.18em] text-emerald-300">Go to</p>
                <p class="mt-1 truncate text-sm font-black text-white">{{ destinationLabel }}</p>
              </div>
              <div class="min-w-0">
                <p class="text-[0.65rem] font-black uppercase tracking-[0.18em] text-emerald-300">Tracking</p>
                <p class="mt-1 truncate text-sm font-black text-white">{{ trackingLabel }}</p>
              </div>
            </div>
          </section>

          <section class="mt-4 rounded-[1.75rem] border border-white/10 bg-white/[0.06] p-4 backdrop-blur">
            <div class="flex items-center justify-between gap-3">
              <h3 class="text-sm font-black text-white">Run updates</h3>
              <span class="text-xs font-black text-emerald-300">{{ completedWorkflowCount }}/{{ workflowTotalCount || timelineItems.length }}</span>
            </div>
            <ol class="mt-4 space-y-3">
              <li
                v-for="item in timelineItems"
                :key="`driver-mode-update-${item.id}`"
                class="flex gap-3"
              >
                <span
                  class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-black"
                  :class="item.complete ? 'bg-emerald-400 text-slate-950' : 'bg-white/10 text-emerald-100 ring-1 ring-white/10'"
                >
                  {{ item.complete ? "✓" : "•" }}
                </span>
                <span class="min-w-0">
                  <span class="block text-sm font-black text-white">{{ item.label }}</span>
                  <span class="block text-xs font-semibold text-emerald-100/75">{{ item.meta }}</span>
                </span>
              </li>
            </ol>
          </section>

          <p v-if="driverActionError" class="mt-4 rounded-2xl border border-amber-300/30 bg-amber-400/10 p-3 text-sm text-amber-100">
            {{ driverActionError }}
          </p>
          <p v-if="trackingState.error" class="mt-4 rounded-2xl border border-rose-300/30 bg-rose-400/10 p-3 text-sm text-rose-100">
            {{ trackingState.error }}
          </p>
        </main>

        <footer class="mt-3 shrink-0 space-y-3">
          <button
            v-if="primaryAction"
            type="button"
            class="w-full rounded-3xl bg-emerald-400 px-5 py-4 text-center text-base font-black text-slate-950 shadow-xl disabled:cursor-not-allowed disabled:bg-slate-700 disabled:text-slate-300"
            :disabled="primaryAction.disabled"
            @click="primaryAction.handler"
          >
            {{ primaryAction.label }}
          </button>
          <div
            v-else-if="trackingState.shared"
            class="w-full rounded-3xl bg-slate-800 px-5 py-4 text-center text-base font-black text-slate-300"
          >
            Live location shared
          </div>

          <button
            v-if="showSecondaryTracking"
            type="button"
            class="w-full rounded-3xl bg-emerald-400 px-5 py-4 text-center text-base font-black text-slate-950 shadow-xl disabled:cursor-not-allowed disabled:bg-slate-700 disabled:text-slate-300"
            :disabled="trackingState.sending"
            @click="emit('share-location')"
          >
            {{ trackingState.sending ? "Sharing location..." : "Share live location" }}
          </button>

          <div class="grid grid-cols-4 gap-2">
            <a
              v-for="link in navigationLinks"
              :key="`driver-mode-new-${link.id}`"
              :href="link.href"
              target="_blank"
              rel="noopener"
              class="rounded-2xl bg-white px-2 py-3 text-center text-xs font-black text-emerald-700 shadow-lg"
            >
              {{ link.id === "google" ? "Google" : "Waze" }}
            </a>
            <button
              type="button"
              class="rounded-2xl border border-white/10 bg-white/[0.08] px-2 py-3 text-center text-xs font-black text-white"
              @click="emit('open-chat')"
            >
              Chat
            </button>
            <button
              v-if="canReportIncident"
              type="button"
              class="rounded-2xl border border-amber-300/40 bg-amber-400/10 px-2 py-3 text-center text-xs font-black text-amber-100"
              @click="emit('report-issue')"
            >
              Issue
            </button>
            <button
              v-if="canUploadInspection"
              type="button"
              class="rounded-2xl border border-emerald-300/30 bg-white/[0.08] px-2 py-3 text-center text-xs font-black text-emerald-100"
              @click="openInspectionPicker"
            >
              Photos
            </button>
          </div>
        </footer>
      </div>
    </div>
  </transition>
</template>
