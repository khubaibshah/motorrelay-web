import { computed, ref } from "vue";

export function useInspectionGallery({ completionForm }) {
  const uploadedPhotos = ref([]);
  const galleryOpen = ref(false);
  const galleryIndex = ref(0);
  const galleryTouchStartX = ref(null);
  const currentPhoto = computed(() => uploadedPhotos.value[galleryIndex.value] ?? null);

  function resetUploadedPhotos() {
    uploadedPhotos.value.forEach((photo) => {
      if (photo.previewUrl) {
        URL.revokeObjectURL(photo.previewUrl);
      }
    });
    uploadedPhotos.value = [];
  }

  function syncSelectedPreviews() {
    resetUploadedPhotos();
    uploadedPhotos.value = completionForm.proof.map((file, index) => ({
      id: `${file.name}-${file.size}-${file.lastModified}-${index}`,
      name: file.name,
      previewUrl: file.type?.startsWith("image/") ? URL.createObjectURL(file) : ""
    }));
  }

  function appendFiles(files) {
    const incoming = Array.from(files ?? []).filter((file) => file?.type?.startsWith("image/"));
    if (!incoming.length) return;

    const existing = completionForm.proof.map((file) => `${file.name}-${file.size}-${file.lastModified}`);
    const nextFiles = [...completionForm.proof];

    incoming.forEach((file) => {
      const key = `${file.name}-${file.size}-${file.lastModified}`;
      if (!existing.includes(key) && nextFiles.length < 20) {
        nextFiles.push(file);
        existing.push(key);
      }
    });

    completionForm.proof = nextFiles;
    syncSelectedPreviews();
  }

  function removeFile(index) {
    completionForm.proof = completionForm.proof.filter((_, fileIndex) => fileIndex !== index);
    syncSelectedPreviews();

    if (galleryIndex.value >= uploadedPhotos.value.length) {
      galleryIndex.value = Math.max(uploadedPhotos.value.length - 1, 0);
    }

    if (!uploadedPhotos.value.length) {
      galleryOpen.value = false;
    }
  }

  function openGallery(index = 0) {
    if (!uploadedPhotos.value.length) return;
    galleryIndex.value = Math.min(Math.max(index, 0), uploadedPhotos.value.length - 1);
    galleryOpen.value = true;
  }

  function closeGallery() {
    galleryOpen.value = false;
    galleryTouchStartX.value = null;
  }

  function previousPhoto() {
    if (!uploadedPhotos.value.length) return;
    galleryIndex.value = galleryIndex.value === 0 ? uploadedPhotos.value.length - 1 : galleryIndex.value - 1;
  }

  function nextPhoto() {
    if (!uploadedPhotos.value.length) return;
    galleryIndex.value = galleryIndex.value === uploadedPhotos.value.length - 1 ? 0 : galleryIndex.value + 1;
  }

  function onTouchStart(event) {
    galleryTouchStartX.value = event.changedTouches?.[0]?.clientX ?? null;
  }

  function onTouchEnd(event) {
    if (galleryTouchStartX.value === null) return;

    const endX = event.changedTouches?.[0]?.clientX ?? galleryTouchStartX.value;
    const delta = endX - galleryTouchStartX.value;
    galleryTouchStartX.value = null;

    if (Math.abs(delta) < 40) return;
    if (delta < 0) {
      nextPhoto();
    } else {
      previousPhoto();
    }
  }

  return {
    uploadedPhotos,
    galleryOpen,
    galleryIndex,
    currentPhoto,
    resetUploadedPhotos,
    appendFiles,
    removeFile,
    openGallery,
    closeGallery,
    previousPhoto,
    nextPhoto,
    onTouchStart,
    onTouchEnd
  };
}
