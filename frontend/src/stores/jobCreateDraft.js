import { defineStore } from 'pinia';
import { reactive, ref, watch } from 'vue';

const STORAGE_KEY = 'mr_job_create_draft';

function createDefaultFormState() {
  return {
    title: '',
    pickup_postcode: '',
    pickup_label: '',
    pickup_latitude: null,
    pickup_longitude: null,
    dropoff_postcode: '',
    dropoff_label: '',
    dropoff_latitude: null,
    dropoff_longitude: null,
    vehicle_make: '',
    price: '',
    transport_type: 'drive_away',
    pickup_at: '',
    delivery_at: ''
  };
}

function hasDraftContent(draft) {
  if (!draft || typeof draft !== 'object') return false;

  return Boolean(
    draft.currentStep ||
      draft.form?.title ||
      draft.form?.pickup_postcode ||
      draft.form?.pickup_label ||
      draft.form?.pickup_latitude ||
      draft.form?.pickup_longitude ||
      draft.form?.dropoff_postcode ||
      draft.form?.dropoff_label ||
      draft.form?.dropoff_latitude ||
      draft.form?.dropoff_longitude ||
      draft.form?.vehicle_make ||
      draft.form?.price ||
      draft.form?.pickup_at ||
      draft.form?.delivery_at ||
      draft.form?.transport_type === 'trailer' ||
      draft.verifiedVehicle?.registration ||
      draft.reviewUnlocked
  );
}

export const useJobCreateDraftStore = defineStore('jobCreateDraft', () => {
  const currentStep = ref(0);
  const verifiedVehicle = ref(null);
  const reviewUnlocked = ref(false);
  const form = reactive(createDefaultFormState());
  const hydrated = ref(false);

  function clearStorage() {
    if (typeof window === 'undefined') return;

    try {
      window.localStorage.removeItem(STORAGE_KEY);
    } catch (error) {
      console.warn('Could not clear job draft', error);
    }
  }

  function snapshot() {
    return {
      currentStep: currentStep.value,
      reviewUnlocked: reviewUnlocked.value,
      form: {
        ...form
      },
      verifiedVehicle: verifiedVehicle.value
        ? {
            ...verifiedVehicle.value
          }
        : null
    };
  }

  function persist() {
    if (typeof window === 'undefined' || !hydrated.value) return;

    const draft = snapshot();
    if (!hasDraftContent(draft)) {
      clearStorage();
      return;
    }

    try {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(draft));
    } catch (error) {
      console.warn('Could not save job draft', error);
    }
  }

  function restore() {
    if (typeof window === 'undefined') {
      hydrated.value = true;
      return;
    }

    try {
      const raw = window.localStorage.getItem(STORAGE_KEY);
      if (!raw) return;

      const draft = JSON.parse(raw);
      if (!hasDraftContent(draft)) return;

      Object.assign(form, createDefaultFormState(), draft.form || {});
      currentStep.value = Math.min(
        Math.max(Number(draft.currentStep ?? 0), 0),
        4
      );
      verifiedVehicle.value = draft.verifiedVehicle || null;
      reviewUnlocked.value = Boolean(draft.reviewUnlocked) || currentStep.value >= 4;
    } catch (error) {
      console.warn('Could not restore job draft', error);
    } finally {
      hydrated.value = true;
    }
  }

  function clearDraft() {
    currentStep.value = 0;
    verifiedVehicle.value = null;
    reviewUnlocked.value = false;
    Object.assign(form, createDefaultFormState());
    clearStorage();
  }

  function setStep(step) {
    const nextStep = Number(step) || 0;
    currentStep.value = nextStep;
    if (nextStep >= 4) {
      reviewUnlocked.value = true;
    }
  }

  restore();

  watch([currentStep, verifiedVehicle, form], persist, { deep: true });

  return {
    currentStep,
    verifiedVehicle,
    reviewUnlocked,
    form,
    hydrated,
    restore,
    persist,
    clearDraft,
    setStep
  };
});
