<script setup lang="ts">
import { computed } from "vue";
import { formatRelativeTime, formatUtc } from "@/composables/useFormatters";
import { useFreshnessClock } from "@/composables/useFreshness";
const props = withDefaults(
  defineProps<{ timestamp: string | null; empty?: string }>(),
  { empty: "Noch nicht vorhanden" },
);
const now = useFreshnessClock();
const exact = computed(() =>
  props.timestamp ? formatUtc(props.timestamp) : props.empty,
);
</script>
<template>
  <time
    v-if="timestamp"
    :datetime="timestamp"
    :title="exact"
    :aria-label="`${formatRelativeTime(timestamp, now)} · ${exact}`"
    tabindex="0"
    >{{ formatRelativeTime(timestamp, now)
    }}<span class="relative-time__exact"> · {{ exact }}</span></time
  >
  <span v-else>{{ empty }}</span>
</template>
