import { ref } from "vue";
import { fetchThreadMessages, fetchThreads, sendMessage } from "@/services/messages";

export function useDriverChat({ job }) {
  const open = ref(false);
  const loading = ref(false);
  const sending = ref(false);
  const error = ref("");
  const thread = ref(null);
  const messages = ref([]);
  const body = ref("");

  async function openChat() {
    if (!job.value?.id) return;

    open.value = true;
    error.value = "";
    loading.value = true;

    try {
      const payload = await fetchThreads();
      const threads = Array.isArray(payload?.data) ? payload.data : [];
      const matchingThread = threads.find((item) => Number(item.job_id) === Number(job.value.id)) ?? null;
      thread.value = matchingThread;

      if (matchingThread?.id) {
        const messagePayload = await fetchThreadMessages(matchingThread.id);
        messages.value = Array.isArray(messagePayload?.data) ? messagePayload.data : [];
      } else {
        messages.value = [];
      }
    } catch (chatError) {
      console.error("Failed to open driver chat", chatError);
      error.value = "Unable to load chat right now.";
    } finally {
      loading.value = false;
    }
  }

  async function sendChatMessage() {
    if (!job.value?.id || !body.value.trim() || sending.value) return;

    sending.value = true;
    error.value = "";

    try {
      const payload = thread.value?.id
        ? {
            thread_id: thread.value.id,
            body: body.value.trim()
          }
        : {
            job_id: job.value.id,
            recipient_id: job.value.posted_by_id,
            subject: job.value.title || `Run #${job.value.id}`,
            body: body.value.trim()
          };

      const response = await sendMessage(payload);
      if (response?.thread) {
        thread.value = response.thread;
      }
      if (response?.message) {
        messages.value.push(response.message);
      }
      body.value = "";
    } catch (chatError) {
      console.error("Failed to send driver chat message", chatError);
      error.value = chatError?.response?.data?.message || "Unable to send message right now.";
    } finally {
      sending.value = false;
    }
  }

  return {
    open,
    loading,
    sending,
    error,
    messages,
    body,
    openChat,
    sendChatMessage
  };
}
