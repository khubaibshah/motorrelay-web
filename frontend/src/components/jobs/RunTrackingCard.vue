<script setup>
defineProps({
  hasTrackingEnded: {
    type: Boolean,
    default: false
  },
  canShareTracking: {
    type: Boolean,
    default: false
  },
  canRequestTracking: {
    type: Boolean,
    default: false
  },
  trackingState: {
    type: Object,
    required: true
  },
  lastTrackedDisplay: {
    type: String,
    default: ""
  }
});

defineEmits(["share-location", "request-location", "open-navigation", "open-settings"]);
</script>

<template>
  <section class="tile space-y-3 p-4">
    <header class="space-y-1">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Live tracking</h2>
      <p v-if="hasTrackingEnded" class="text-xs text-slate-500">
        Live tracking has ended for this run. The last shared location is kept on the job record.
      </p>
      <p v-else-if="canShareTracking" class="text-xs text-slate-500">
        Share your current position with the dealer while this run is active.
      </p>
      <p v-else class="text-xs text-slate-500">
        Request a live location update from the assigned driver while the run is active.
      </p>
    </header>

    <div v-if="!hasTrackingEnded" class="flex flex-wrap gap-3">
      <button
        v-if="canShareTracking"
        type="button"
        class="rounded-xl px-4 py-2 text-sm font-semibold shadow disabled:cursor-not-allowed"
        :class="trackingState.shared
          ? 'bg-slate-200 text-slate-600'
          : 'bg-emerald-600 text-white hover:bg-emerald-700 disabled:bg-emerald-300'"
        :disabled="trackingState.sending || trackingState.shared"
        @click="$emit('share-location')"
      >
        <span v-if="trackingState.sending">Sharing location…</span>
        <span v-else-if="trackingState.shared">Live location shared</span>
        <span v-else>Share live location</span>
      </button>
      <button
        v-if="canRequestTracking"
        type="button"
        class="btn-primary w-full px-4 py-2 text-sm sm:w-auto"
        :disabled="trackingState.requesting"
        @click="$emit('request-location')"
      >
        <span v-if="trackingState.requesting">Requesting...</span>
        <span v-else>Request location update</span>
      </button>
      <button
        v-if="canShareTracking"
        type="button"
        class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
        @click="$emit('open-navigation')"
      >
        Open navigation apps
      </button>
    </div>

    <p v-if="trackingState.error" class="text-xs text-rose-600">{{ trackingState.error }}</p>
    <p v-if="trackingState.requestError" class="text-xs text-rose-600">{{ trackingState.requestError }}</p>
    <p v-if="trackingState.requestNotice" class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs font-semibold text-emerald-800">
      {{ trackingState.requestNotice }}
    </p>
    <p v-if="trackingState.locationServicesOff" class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs font-semibold text-amber-800">
      On iPhone, open Settings → Privacy & Security → Location Services, turn Location Services on, then return to MotorRelay.
    </p>
    <button
      v-if="trackingState.locationBlocked"
      type="button"
      class="btn-secondary w-full px-4 py-2 text-sm sm:w-auto"
      @click="$emit('open-settings')"
    >
      Open MotorRelay settings
    </button>
    <p v-if="lastTrackedDisplay" class="text-xs text-slate-500">Last shared: {{ lastTrackedDisplay }}</p>
    <p v-else-if="hasTrackingEnded" class="text-xs text-slate-500">
      No live location was shared before this run ended.
    </p>
  </section>
</template>
