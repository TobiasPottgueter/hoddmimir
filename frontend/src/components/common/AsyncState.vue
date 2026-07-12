<script setup lang="ts">
import Message from "primevue/message";
import ProgressSpinner from "primevue/progressspinner";

defineProps<{
  loading: boolean;
  error: string | null;
  empty: boolean;
  emptyTitle?: string;
  emptyDescription?: string;
}>();
</script>

<template>
  <div v-if="loading" class="async-state" role="status" aria-live="polite">
    <ProgressSpinner stroke-width="5" />
    <span>Daten werden geladen …</span>
  </div>
  <Message v-else-if="error" severity="error" :closable="false" role="alert">
    {{ error }}
  </Message>
  <div v-else-if="empty" class="async-state async-state--empty">
    <i class="pi pi-inbox" aria-hidden="true" />
    <strong>{{ emptyTitle ?? "Keine Daten vorhanden" }}</strong>
    <span>{{
      emptyDescription ??
      "Der Collector hat für diese Auswahl noch keine Daten erfasst."
    }}</span>
  </div>
  <slot v-else />
</template>
