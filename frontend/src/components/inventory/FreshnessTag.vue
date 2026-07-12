<script setup lang="ts">
import { computed } from "vue";
import Tag from "primevue/tag";

import { freshnessState, useFreshnessClock } from "@/composables/useFreshness";

const props = defineProps<{
  timestamp: string | null;
  now?: number;
}>();

const clock = useFreshnessClock();
const state = computed(() =>
  freshnessState(props.timestamp, props.now ?? clock.value),
);
const presentation = computed(() => {
  switch (state.value) {
    case "fresh":
      return { label: "Aktuell", severity: "success" as const };
    case "stale":
      return { label: "Veraltet", severity: "warn" as const };
    case "missing":
      return { label: "Keine Messung", severity: "secondary" as const };
    case "invalid":
      return { label: "Ungültig", severity: "danger" as const };
  }
});
</script>

<template>
  <Tag :value="presentation.label" :severity="presentation.severity" />
</template>
