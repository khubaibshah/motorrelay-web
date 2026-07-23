<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from "vue";

const props = defineProps({
  pickup: { type: String, default: "" },
  dropoff: { type: String, default: "" },
  currentLocation: { type: Object, default: null },
  routePoints: { type: Array, default: () => [] }
});

const mapElement = ref(null);
const mapReady = ref(false);
const mapError = ref("");

let maps;
let map;
let directionsRenderer;
let currentMarker;
let trackingPolyline;

/**
 * Keep a lightweight embed fallback for native bundles that were built without
 * the optional Google Maps JavaScript key. It still renders the complete
 * pickup-to-drop-off route and avoids leaving the driver with an empty panel.
 */
const fallbackMapSrc = () => {
  if (!props.pickup || !props.dropoff) return "";

  const params = new URLSearchParams({
    output: "embed",
    saddr: props.pickup,
    daddr: props.dropoff,
    dirflg: "d"
  });

  return `https://www.google.com/maps?${params.toString()}`;
};

function loadGoogleMaps() {
  if (window.google?.maps) return Promise.resolve(window.google.maps);

  const key = import.meta.env.VITE_GOOGLE_MAPS_API_KEY;
  if (!key) return Promise.resolve(null);
  if (window.__motorRelayMapsPromise) return window.__motorRelayMapsPromise;

  window.__motorRelayMapsPromise = new Promise((resolve, reject) => {
    const script = document.createElement("script");
    script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(key)}`;
    script.async = true;
    script.onload = () => resolve(window.google.maps);
    script.onerror = reject;
    document.head.appendChild(script);
  });

  // Do not retain a rejected promise: a later route render may have a valid
  // key (or recover after the network becomes available) and should retry.
  window.__motorRelayMapsPromise.catch(() => {
    window.__motorRelayMapsPromise = null;
  });

  return window.__motorRelayMapsPromise;
}

function asPoint(point) {
  const lat = Number(point?.lat ?? point?.latitude);
  const lng = Number(point?.lng ?? point?.longitude);
  return Number.isFinite(lat) && Number.isFinite(lng) ? { lat, lng } : null;
}

function updateTrackingPath() {
  if (!trackingPolyline || !maps) return;

  const path = props.routePoints.map(asPoint).filter(Boolean);
  const current = asPoint(props.currentLocation);
  if (current && !path.length) path.push(current);
  trackingPolyline.setPath(path);

  if (currentMarker) {
    currentMarker.setPosition(current || path[path.length - 1] || null);
  }
}

async function drawRoute() {
  if (!maps || !map || !props.pickup || !props.dropoff) return;

  const service = new maps.DirectionsService();
  const result = await new Promise((resolve, reject) => {
    service.route(
      {
        origin: props.pickup,
        destination: props.dropoff,
        travelMode: maps.TravelMode.DRIVING,
        provideRouteAlternatives: false
      },
      (response, status) => (status === "OK" ? resolve(response) : reject(new Error(status)))
    );
  });

  directionsRenderer.setDirections(result);
  updateTrackingPath();
}

async function renderMap() {
  if (!mapElement.value) return;

  try {
    maps = await loadGoogleMaps();
    if (!maps) {
      mapError.value = "";
      return;
    }

    if (!map) {
      map = new maps.Map(mapElement.value, {
        center: { lat: 54.5, lng: -2.5 },
        zoom: 6,
        disableDefaultUI: true,
        clickableIcons: false,
        gestureHandling: "none",
        backgroundColor: "#06120f"
      });
      directionsRenderer = new maps.DirectionsRenderer({
        map,
        suppressMarkers: true,
        preserveViewport: false,
        polylineOptions: { strokeColor: "#35d5a2", strokeOpacity: 0.95, strokeWeight: 5 }
      });
      trackingPolyline = new maps.Polyline({
        map,
        geodesic: true,
        strokeColor: "#0ea5e9",
        strokeOpacity: 0.9,
        strokeWeight: 4
      });
      currentMarker = new maps.Marker({ map, title: "Current driver location" });
    }

    await drawRoute();
    mapReady.value = true;
    mapError.value = "";
  } catch (error) {
    mapError.value = "";
    console.error("Failed to render driver route map", error);
  }
}

onMounted(renderMap);
watch(() => [props.pickup, props.dropoff], renderMap);
watch(() => [props.currentLocation, props.routePoints], updateTrackingPath, { deep: true });

onBeforeUnmount(() => {
  map = null;
  directionsRenderer = null;
  trackingPolyline = null;
  currentMarker = null;
});
</script>

<template>
  <div ref="mapElement" class="relative h-full min-h-64 overflow-hidden bg-[radial-gradient(circle_at_center,rgba(52,211,153,0.24),transparent_38%),#06120f]">
    <div v-if="!mapReady && fallbackMapSrc()" class="absolute inset-0">
      <iframe
        :src="fallbackMapSrc()"
        class="h-full w-full border-0 opacity-90 grayscale-[25%] contrast-125 saturate-75"
        loading="lazy"
        referrerpolicy="no-referrer-when-downgrade"
        title="Driver pickup to drop-off route map"
      />
    </div>
    <div v-if="!mapReady && !fallbackMapSrc()" class="absolute inset-0 flex items-center justify-center px-6 text-center text-sm font-bold text-emerald-100">
      Loading full pickup-to-drop-off route…
    </div>
    <div v-if="mapReady" class="pointer-events-none absolute inset-x-3 bottom-3 flex justify-between gap-2">
      <span class="max-w-[45%] truncate rounded-full bg-slate-950/80 px-3 py-2 text-xs font-black text-white backdrop-blur">
        {{ pickup }}
      </span>
      <span class="max-w-[45%] truncate rounded-full bg-slate-950/80 px-3 py-2 text-xs font-black text-white backdrop-blur">
        {{ dropoff }}
      </span>
    </div>
  </div>
</template>
