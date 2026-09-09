import {
  computed,
  inject,
  onBeforeUnmount,
  onMounted,
  ref,
  toValue,
  type MaybeRefOrGetter,
} from "vue";
import { matchedRouteKey, onBeforeRouteLeave } from "vue-router";

export function useUnsavedChanges(
  snapshot: MaybeRefOrGetter<string>,
  pending: () => boolean,
) {
  const baseline = ref(toValue(snapshot));
  const dirty = computed(() => toValue(snapshot) !== baseline.value);
  function reset(): void {
    baseline.value = toValue(snapshot);
  }
  function confirmDiscard(): boolean {
    if (pending()) return false;
    return (
      !dirty.value || window.confirm("Ungespeicherte Änderungen verwerfen?")
    );
  }
  function beforeUnload(event: BeforeUnloadEvent): void {
    if (!dirty.value && !pending()) return;
    event.preventDefault();
    event.returnValue = "";
  }
  // Standalone component tests and embedded forms need no router registration.
  if (inject(matchedRouteKey, undefined)) onBeforeRouteLeave(confirmDiscard);
  onMounted(() => window.addEventListener("beforeunload", beforeUnload));
  onBeforeUnmount(() =>
    window.removeEventListener("beforeunload", beforeUnload),
  );
  return { dirty, reset, confirmDiscard };
}
