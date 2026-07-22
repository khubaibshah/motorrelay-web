<script setup>
import { onMounted, onBeforeUnmount, ref, watch } from 'vue';
const props = defineProps({
  location: {
    type: Object,
    required: true
  },
  mapSrc: {
    type: String,
    required: true
  },
  updatedLabel: {
    type: String,
    required: true
  },
  trackingActive: {
    type: Boolean,
    default: true
  },
  routePoints: {
    type: Array,
    default: () => []
  }
});

const mapElement = ref(null);
const hasGoogleMapsKey = Boolean(import.meta.env.VITE_GOOGLE_MAPS_API_KEY);
const googleMapsFailed = ref(false);
let map;
let polyline;
let marker;

function loadGoogleMaps() {
  if (window.google?.maps) return Promise.resolve(window.google.maps);
  const key = import.meta.env.VITE_GOOGLE_MAPS_API_KEY;
  if (!key) return Promise.resolve(null);
  if (window.__motorRelayMapsPromise) return window.__motorRelayMapsPromise;
  window.__motorRelayMapsPromise = new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(key)}`;
    script.async = true;
    script.onload = () => resolve(window.google.maps);
    script.onerror = reject;
    document.head.appendChild(script);
  });
  return window.__motorRelayMapsPromise;
}

async function renderMap() {
  if (!mapElement.value) return;
  let maps;
  try {
    maps = await loadGoogleMaps();
  } catch (error) {
    googleMapsFailed.value = true;
    console.error('Failed to load Google Maps for tracking route', error);
    return;
  }
  if (!maps) return;
  const points = props.routePoints.length ? props.routePoints : [props.location];
  const path = points.map((point) => ({ lat: Number(point.lat), lng: Number(point.lng) }));
  if (!path.length) return;
  if (!map) {
    map = new maps.Map(mapElement.value, {
      center: path[path.length - 1],
      zoom: 11,
      mapTypeControl: false,
      streetViewControl: false
    });
    polyline = new maps.Polyline({
      map,
      geodesic: true,
      strokeColor: '#00a878',
      strokeOpacity: 0.95,
      strokeWeight: 5
    });
    marker = new maps.Marker({ map, title: 'Driver location' });
  }
  polyline.setPath(path);
  marker.setPosition(path[path.length - 1]);
  map.panTo(path[path.length - 1]);
  if (path.length > 1) {
    const bounds = new maps.LatLngBounds();
    path.forEach((point) => bounds.extend(point));
    map.fitBounds(bounds, 48);
  }
}

onMounted(renderMap);
watch(() => [props.location, props.routePoints], renderMap, { deep: true });
onBeforeUnmount(() => {
  map = null;
  polyline = null;
  marker = null;
});
</script>

<template>
  <section class="tile overflow-hidden border-emerald-200 bg-emerald-50/40 p-0 dark:border-emerald-400/30 dark:bg-emerald-400/10">
    <div class="flex items-center justify-between gap-3 px-4 py-3">
      <div>
        <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-300">
          {{ trackingActive ? 'Live tracking' : 'Tracking history' }}
        </p>
        <h2 class="mt-0.5 text-base font-black text-slate-950 dark:text-white">
          {{ trackingActive ? 'Driver location' : 'Last known driver location' }}
        </h2>
      </div>
      <span
        class="rounded-full px-3 py-1 text-xs font-black"
        :class="trackingActive
          ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-300 dark:text-slate-950'
          : 'bg-slate-200 text-slate-700 dark:bg-white/10 dark:text-emerald-100'"
      >
        {{ trackingActive ? 'Live' : 'Ended' }}
      </span>
    </div>

    <div ref="mapElement" class="relative h-52 bg-slate-200 dark:bg-slate-900">
      <iframe
        v-if="!hasGoogleMapsKey || googleMapsFailed"
        :key="`${props.location?.lat}-${props.location?.lng}`"
        :src="mapSrc"
        class="h-full w-full border-0"
        loading="lazy"
        referrerpolicy="no-referrer-when-downgrade"
        title="Driver live location map"
      />
    </div>

    <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-xs font-bold text-slate-600 dark:text-emerald-100">
      <span>{{ updatedLabel }}<span v-if="!trackingActive"> · Tracking ended after delivery</span></span>
      <a
        :href="`https://www.google.com/maps/search/?api=1&query=${props.location?.lat},${props.location?.lng}`"
        target="_blank"
        rel="noopener"
        class="text-emerald-700 underline dark:text-emerald-300"
      >
        Open map
      </a>
    </div>
  </section>
</template>
