import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { apiErrorMessage } from "@/api/errors";

export const DISPLAY_REFRESH_MS = 30_000;

/** Refreshes Hoddmímir GET projections only, never collector or backup actions. */
export function useAutoRefresh(
  load: () => Promise<void>,
  canPoll: () => boolean = () => true,
  busy: () => boolean = () => false,
) {
  const running = ref(false);
  const error = ref<string | null>(null);
  const paused = computed(() => !canPoll());
  let mounted = false;
  let initial = true;
  let timer: ReturnType<typeof setTimeout> | undefined;

  function schedule(): void {
    clearTimeout(timer);
    if (mounted && document.visibilityState !== "hidden") {
      timer = setTimeout(() => void refresh(true), DISPLAY_REFRESH_MS);
    }
  }
  async function refresh(automatic = false): Promise<void> {
    if (running.value) return;
    clearTimeout(timer);
    if (
      busy() ||
      (automatic &&
        ((!initial && paused.value) || document.visibilityState === "hidden"))
    ) {
      schedule();
      return;
    }
    initial = false;
    running.value = true;
    error.value = null;
    try {
      await load();
    } catch (failure) {
      error.value = apiErrorMessage(failure);
    } finally {
      running.value = false;
      schedule();
    }
  }
  function visibilityChanged(): void {
    clearTimeout(timer);
    if (document.visibilityState !== "hidden") void refresh(true);
  }
  onMounted(() => {
    mounted = true;
    document.addEventListener("visibilitychange", visibilityChanged);
    void refresh(true);
  });
  onBeforeUnmount(() => {
    mounted = false;
    clearTimeout(timer);
    document.removeEventListener("visibilitychange", visibilityChanged);
  });
  return { refresh, running, paused, error };
}
