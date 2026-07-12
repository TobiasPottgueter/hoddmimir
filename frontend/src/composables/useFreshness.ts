import {
  computed,
  onBeforeUnmount,
  onMounted,
  readonly,
  ref,
  type MaybeRefOrGetter,
  toValue,
} from "vue";

export const DEFAULT_STALE_AFTER_MS = 5 * 60 * 1000;

export type FreshnessState = "missing" | "fresh" | "stale" | "invalid";

export const FRESHNESS_CLOCK_INTERVAL_MS = 30_000;

export function useFreshnessClock(intervalMs = FRESHNESS_CLOCK_INTERVAL_MS) {
  const now = ref(Date.now());
  let timer: ReturnType<typeof setInterval>;

  onMounted(() => {
    timer = setInterval(() => {
      now.value = Date.now();
    }, intervalMs);
  });
  onBeforeUnmount(() => {
    clearInterval(timer);
  });

  return readonly(now);
}

export function freshnessState(
  timestamp: string | null,
  now = Date.now(),
  staleAfterMs = DEFAULT_STALE_AFTER_MS,
): FreshnessState {
  if (timestamp === null) return "missing";

  const observedAt = Date.parse(timestamp);
  if (!Number.isFinite(observedAt) || observedAt > now + 60_000)
    return "invalid";

  return now - observedAt > staleAfterMs ? "stale" : "fresh";
}

export function useFreshness(
  timestamp: MaybeRefOrGetter<string | null>,
  now: MaybeRefOrGetter<number> = () => Date.now(),
  staleAfterMs = DEFAULT_STALE_AFTER_MS,
) {
  const state = computed(() =>
    freshnessState(toValue(timestamp), toValue(now), staleAfterMs),
  );
  const stale = computed(() => state.value === "stale");

  return { state, stale };
}
