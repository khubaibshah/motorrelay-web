<script setup>
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { fetchThreads, fetchThreadMessages, sendMessage, markMessageViewed } from '@/services/messages';
import { useAuthStore } from '@/stores/auth';
import TrackingCard from '@/components/messages/TrackingCard.vue';

const auth = useAuthStore();

const threads = ref([]);
const threadsLoading = ref(false);
const threadsError = ref('');
const threadSearch = ref('');

const selectedThreadId = ref(null);
const mobileView = ref('list');
const messages = ref([]);
const messageContainer = ref(null);
const messagesLoading = ref(false);
const messagesError = ref('');

const composer = reactive({
  body: '',
  attachments: [],
  sending: false,
  error: ''
});

const selectedThread = computed(() => threads.value.find((thread) => thread.id === selectedThreadId.value) ?? null);
const otherParticipants = computed(() =>
  (selectedThread.value?.participants ?? []).filter((participant) => participant.id !== auth.user?.id)
);
const filteredThreads = computed(() => {
  const query = threadSearch.value.trim().toLowerCase();
  if (!query) return threads.value;

  return threads.value.filter((thread) => {
    const haystack = [
      thread?.subject,
      thread?.last_message,
      thread?.job_reference,
      thread?.job?.title,
      ...(Array.isArray(thread?.participants) ? thread.participants.map((participant) => participant?.name) : [])
    ]
      .filter(Boolean)
      .join(' ')
      .toLowerCase();

    return haystack.includes(query);
  });
});

const preview = reactive({
  open: false,
  index: 0,
  items: []
});

function isImageAttachment(attachment) {
  return String(attachment?.mime_type || '').startsWith('image/');
}

function isLocationMessage(message) {
  return Boolean(message?.meta?.type === 'location_update' && message.meta?.location);
}

onMounted(async () => {
  if (!auth.user && auth.token) {
    await auth.fetchMe().catch(() => null);
  }
  await loadThreads();
});

onUnmounted(() => {
  if (preview.open) {
    document.body.style.overflow = '';
    window.removeEventListener('keydown', handleKeydown);
  }
});

async function loadThreads() {
  threadsLoading.value = true;
  threadsError.value = '';
  try {
    const data = await fetchThreads();
    threads.value = Array.isArray(data?.data) ? data.data : [];
    if (selectedThreadId.value) {
      const active = threads.value.find((thread) => thread.id === selectedThreadId.value);
      if (!active && threads.value.length) {
        selectedThreadId.value = null;
        messages.value = [];
        mobileView.value = 'list';
      }
    }
  } catch (error) {
    console.error('Failed to load conversations', error);
    threadsError.value = 'Unable to load conversations right now.';
    threads.value = [];
  } finally {
    threadsLoading.value = false;
  }
}

async function selectThread(threadId) {
  const isSameThread = selectedThreadId.value === threadId;
  selectedThreadId.value = threadId;
  mobileView.value = 'thread';
  if (isSameThread) return;
  await loadMessages(threadId);
}

function backToThreadList() {
  mobileView.value = 'list';
}

async function loadMessages(threadId = selectedThreadId.value) {
  if (!threadId) return;
  messagesLoading.value = true;
  messagesError.value = '';
  try {
    const data = await fetchThreadMessages(threadId);
    messages.value = Array.isArray(data?.data) ? data.data : [];
    await syncViewReceipts();
    scrollMessagesToBottom();
  } catch (error) {
    console.error('Failed to load messages', error);
    messagesError.value = 'Unable to load messages right now.';
    messages.value = [];
  } finally {
    messagesLoading.value = false;
  }
}

function handleAttachmentChange(event) {
  const files = Array.from(event.target.files ?? []);
  composer.attachments = files;
  event.target.value = '';
}

function openImagePreview(attachments, attachment) {
  const images = attachments.filter(isImageAttachment);
  const index = images.findIndex((img) => img.id === attachment.id);
  if (!images.length || index === -1) return;

  preview.items = images;
  preview.index = index;
  preview.open = true;
  document.body.style.overflow = 'hidden';
}

function closePreview() {
  preview.open = false;
  preview.items = [];
  preview.index = 0;
  document.body.style.overflow = '';
}

function showNextImage() {
  if (!preview.items.length) return;
  preview.index = (preview.index + 1) % preview.items.length;
}

function showPreviousImage() {
  if (!preview.items.length) return;
  preview.index = (preview.index - 1 + preview.items.length) % preview.items.length;
}

function handleKeydown(event) {
  if (!preview.open) return;
  if (event.key === 'Escape') {
    event.preventDefault();
    closePreview();
  } else if (event.key === 'ArrowRight') {
    event.preventDefault();
    showNextImage();
  } else if (event.key === 'ArrowLeft') {
    event.preventDefault();
    showPreviousImage();
  }
}

watch(
  () => preview.open,
  (isOpen) => {
    if (isOpen) {
      window.addEventListener('keydown', handleKeydown);
    } else {
      window.removeEventListener('keydown', handleKeydown);
    }
  }
);

function receiptFor(message, userId) {
  return message.receipts?.find((receipt) => receipt.user_id === userId) ?? null;
}

async function syncViewReceipts() {
  const myId = auth.user?.id;
  if (!myId) return;

  const unseen = messages.value.filter((message) => {
    const receipt = receiptFor(message, myId);
    return receipt && !receipt.viewed_at && message.user.id !== myId;
  });

  await Promise.all(
    unseen.map(async (message) => {
      try {
        const { viewed_at } = await markMessageViewed(message.id);
        const receipt = receiptFor(message, myId);
        if (receipt) {
          receipt.viewed_at = viewed_at;
        }
      } catch (error) {
        console.error('Failed to mark message as viewed', error);
      }
    })
  );
}

async function sendCurrentMessage() {
  if (!selectedThreadId.value || composer.sending) return;
  if (!composer.body && composer.attachments.length === 0) {
    composer.error = 'Add a message or an attachment before sending.';
    return;
  }

  composer.error = '';
  composer.sending = true;

  try {
    const payload = { thread_id: selectedThreadId.value };
    if (composer.body.trim()) {
      payload.body = composer.body;
    }
    if (composer.attachments.length) {
      payload.attachments = composer.attachments;
    }
    const response = await sendMessage(payload);
    const { thread, message } = response || {};
    if (message) {
      messages.value.push(message);
      scrollMessagesToBottom();
    }
    if (thread) {
      updateThreadSummary(thread);
    }
    composer.body = '';
    composer.attachments = [];
    await syncViewReceipts();
  } catch (error) {
    console.error('Failed to send message', error);
    composer.error = error.response?.data?.message || 'Unable to send your message.';
  } finally {
    composer.sending = false;
  }
}

watch(selectedThreadId, () => {
  composer.body = '';
  composer.attachments = [];
  composer.error = '';
});

function updateThreadSummary(summary) {
  if (!summary) return;
  const index = threads.value.findIndex((thread) => thread.id === summary.id);
  if (index === -1) {
    threads.value.unshift(summary);
    return;
  }
  const updated = { ...threads.value[index], ...summary };
  threads.value.splice(index, 1);
  threads.value.unshift(updated);
}

function scrollMessagesToBottom() {
  nextTick(() => {
    if (messageContainer.value) {
      messageContainer.value.scrollTop = messageContainer.value.scrollHeight;
    }
  });
}
</script>

<template>
  <div class="space-y-5">
    <div class="grid gap-4 lg:grid-cols-[360px,1fr]">
      <aside class="space-y-4" :class="mobileView === 'thread' ? 'hidden lg:block' : ''">
        <section class="section-card space-y-4">
          <header class="space-y-2">
            <div class="flex items-center justify-between gap-3">
              <div>
                <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700">Conversation list</p>
                <h2 class="mt-1 text-xl font-black text-slate-950">Threads</h2>
              </div>
              <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600 dark:bg-emerald-400 dark:text-slate-950">
                {{ filteredThreads.length }}
              </span>
            </div>
            <input
              v-model="threadSearch"
              type="search"
              placeholder="Search runs, people, messages..."
              class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100 dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100"
            >
          </header>

          <div v-if="threadsLoading" class="rounded-2xl border bg-slate-50 p-4 text-sm text-slate-600 dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100">
            Loading conversations...
          </div>

          <p v-else-if="threadsError" class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-700">
            {{ threadsError }}
          </p>

          <div v-else class="max-h-[28rem] space-y-2 overflow-y-auto pr-1">
            <button
              v-for="thread in filteredThreads"
              :key="thread.id"
              type="button"
              class="w-full rounded-2xl border p-4 text-left transition hover:-translate-y-0.5 hover:shadow-md"
              :class="selectedThreadId === thread.id ? 'border-emerald-200 bg-emerald-50 shadow-sm dark:border-emerald-400/50 dark:bg-emerald-400/10' : 'border-slate-200 bg-white dark:border-white/10 dark:bg-white/[0.06]'"
              @click="selectThread(thread.id)"
            >
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <h3 class="truncate text-sm font-black text-slate-950 dark:text-white">
                    {{ thread.subject || thread.job?.title || 'Conversation' }}
                  </h3>
                  <p class="mt-1 truncate text-xs text-slate-500 dark:text-emerald-100">
                    {{ thread.last_message || 'No messages yet.' }}
                  </p>
                </div>
                <span v-if="thread.unread_count" class="rounded-full bg-emerald-600 px-2.5 py-1 text-[11px] font-bold text-white">
                  {{ thread.unread_count }}
                </span>
              </div>
              <div class="mt-3 flex flex-wrap items-center gap-2">
                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-700 dark:bg-white/10 dark:text-emerald-100">
                    {{ thread.job_id ? `Run #${thread.job_id}` : 'No run' }}
                </span>
                <span class="rounded-full bg-white px-2.5 py-1 text-[11px] font-semibold text-slate-500 ring-1 ring-slate-200 dark:bg-white/10 dark:text-emerald-100 dark:ring-white/10">
                  {{ thread.updated_at ? new Date(thread.updated_at).toLocaleDateString() : '--' }}
                </span>
              </div>
            </button>

            <div v-if="!filteredThreads.length" class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-600 dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100">
              No conversations match your search.
            </div>
          </div>
        </section>
      </aside>

      <section
        class="section-card min-h-[70vh] flex-col gap-4"
        :class="mobileView === 'list' ? 'hidden lg:flex' : 'flex lg:flex'"
      >
        <button
          v-if="mobileView === 'thread'"
          type="button"
          class="inline-flex w-fit items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm lg:hidden dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100"
          @click="backToThreadList"
        >
          <span aria-hidden="true">←</span>
          Threads
        </button>

        <div v-if="!selectedThread" class="flex flex-1 items-center justify-center rounded-3xl border border-dashed border-slate-200 bg-slate-50 p-8 text-center dark:border-white/10 dark:bg-white/[0.06]">
          <div class="max-w-md">
            <h2 class="text-xl font-black text-slate-950 dark:text-emerald-300">Select a conversation</h2>
            <p class="mt-2 text-sm text-slate-600 dark:text-emerald-100">
              Choose a thread on the left to review updates, proof, and run messages.
            </p>
          </div>
        </div>

        <template v-else>
          <header class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 pb-4 dark:border-white/10">
            <div class="min-w-0">
              <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-700">Active thread</p>
              <h2 class="mt-1 truncate text-2xl font-black text-slate-950 dark:text-emerald-300">
                {{ selectedThread.subject || selectedThread.job?.title || 'Conversation' }}
              </h2>
              <p class="mt-1 text-sm text-slate-600 dark:text-emerald-100">
                <span v-for="(participant, index) in selectedThread.participants" :key="participant.id">
                  {{ participant.name }}<span v-if="index < selectedThread.participants.length - 1">, </span>
                </span>
              </p>
            </div>
            <span v-if="selectedThread.job_id" class="badge bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-emerald-100">
              Run #{{ selectedThread.job_id }}
            </span>
          </header>

          <div v-if="messagesLoading" class="rounded-2xl border bg-slate-50 p-4 text-sm text-slate-600 dark:border-white/10 dark:bg-white/[0.06] dark:text-emerald-100">
            Loading messages...
          </div>

          <div
            v-else-if="messagesError"
            class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-700"
          >
            {{ messagesError }}
          </div>

          <div
            v-else
            ref="messageContainer"
            class="flex min-h-[26rem] flex-1 flex-col gap-3 overflow-y-auto rounded-3xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-slate-950"
          >
            <p v-if="!messages.length" class="text-sm text-slate-600 dark:text-emerald-100">
              No messages yet. Start the conversation below.
            </p>

            <article
              v-for="message in messages"
              :key="message.id"
              class="flex w-full flex-col gap-2"
              :class="isLocationMessage(message) ? 'items-stretch' : message.user.id === auth.user?.id ? 'items-end' : 'items-start'"
            >
              <TrackingCard
                v-if="isLocationMessage(message)"
                :location="message.meta.location"
                :destination="message.meta.destination"
                :driver="message.meta.driver"
                :eta-minutes="message.meta.eta_minutes ?? null"
                :recorded-at="message.meta.location?.recorded_at ?? message.created_at"
              />
              <div
                v-else
                class="max-w-[82%] rounded-3xl px-4 py-3 text-sm shadow-sm"
                :class="message.user.id === auth.user?.id ? 'bg-emerald-400 text-slate-950 dark:bg-emerald-400 dark:text-slate-950' : 'bg-white text-slate-800 ring-1 ring-slate-200 dark:bg-white/[0.08] dark:text-emerald-100 dark:ring-white/10'"
              >
                <p v-if="message.body" class="leading-6">{{ message.body }}</p>

                <div v-if="message.attachments?.length" class="mt-3 flex flex-wrap gap-2">
                  <template v-for="attachment in message.attachments" :key="attachment.id">
                    <button
                      v-if="isImageAttachment(attachment)"
                      type="button"
                      class="overflow-hidden rounded-2xl border border-white/40 bg-white/10"
                      @click="openImagePreview(message.attachments, attachment)"
                    >
                      <img
                        :src="attachment.url"
                        :alt="attachment.original_name"
                        class="h-24 w-24 object-cover"
                        loading="lazy"
                      />
                    </button>
                    <a
                      v-else
                      :href="attachment.url"
                      target="_blank"
                      class="inline-flex items-center gap-2 rounded-2xl border border-white/40 bg-white/10 px-3 py-2 text-xs font-semibold text-white underline-offset-2 hover:underline"
                    >
                      {{ attachment.original_name }}
                    </a>
                  </template>
                </div>
              </div>
              <div
                class="flex items-center gap-2 text-[11px] text-slate-500 dark:text-emerald-100"
                :class="isLocationMessage(message) ? 'self-start' : ''"
              >
                <span>{{ message.user.name }}</span>
                <span>•</span>
                <time>{{ new Date(message.created_at).toLocaleString() }}</time>
                <template v-if="message.user.id === auth.user?.id">
                  <span v-if="otherParticipants.some((p) => {
                    const receipt = receiptFor(message, p.id);
                    return receipt?.viewed_at;
                  })">
                    Viewed
                  </span>
                  <span v-else>Delivered</span>
                </template>
              </div>
            </article>
          </div>

          <form class="space-y-3 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.06]" @submit.prevent="sendCurrentMessage">
            <label class="text-xs font-black uppercase tracking-[0.16em] text-slate-500 dark:text-emerald-100" for="message-body">
              Message
            </label>
            <textarea
              id="message-body"
              v-model="composer.body"
              rows="3"
              class="w-full rounded-2xl border border-slate-200 px-3 py-3 text-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200"
              placeholder="Write your update..."
            />

            <div class="flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500 dark:text-emerald-100">
              <label class="flex items-center gap-2">
                <input type="file" multiple accept=".png,.jpg,.jpeg,.pdf" class="hidden" @change="handleAttachmentChange" />
                <span class="rounded-2xl border border-slate-200 px-3 py-2 font-semibold text-slate-700 hover:bg-slate-100 dark:border-white/10 dark:text-emerald-100 dark:hover:bg-white/10">
                  Attach files
                </span>
              </label>
              <span v-if="composer.attachments.length">{{ composer.attachments.length }} file(s) ready</span>
            </div>

            <p v-if="composer.error" class="text-sm text-amber-600">
              {{ composer.error }}
            </p>

            <div class="flex justify-end">
              <button
                type="submit"
                class="rounded-2xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="composer.sending"
              >
                <span v-if="composer.sending">Sending...</span>
                <span v-else>Send</span>
              </button>
            </div>
          </form>
        </template>
      </section>
    </div>

    <div
      v-if="preview.open && preview.items.length"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/80 p-4"
    >
      <button
        type="button"
        class="absolute right-4 top-4 rounded-full bg-white/90 px-3 py-1 text-sm font-semibold text-slate-700 shadow hover:bg-white"
        @click="closePreview"
      >
        Close
      </button>

      <button
        v-if="preview.items.length > 1"
        type="button"
        class="absolute left-4 top-1/2 -translate-y-1/2 rounded-full bg-white/80 px-3 py-2 text-sm font-semibold text-slate-700 shadow hover:bg-white"
        @click="showPreviousImage"
      >
        Prev
      </button>

      <figure class="flex max-h-[80vh] w-full max-w-4xl flex-col items-center gap-3">
        <img
          :src="preview.items[preview.index].url"
          :alt="preview.items[preview.index].original_name"
          class="max-h-[70vh] w-auto max-w-full rounded-2xl object-contain shadow-2xl"
        />
        <figcaption class="text-sm text-white">
          {{ preview.items[preview.index].original_name }} ({{ preview.index + 1 }} / {{ preview.items.length }})
        </figcaption>
      </figure>

      <button
        v-if="preview.items.length > 1"
        type="button"
        class="absolute right-4 top-1/2 -translate-y-1/2 rounded-full bg-white/80 px-3 py-2 text-sm font-semibold text-slate-700 shadow hover:bg-white"
        @click="showNextImage"
      >
        Next
      </button>
    </div>
  </div>
</template>
