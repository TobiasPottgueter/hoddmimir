<script setup lang="ts">
import Button from "primevue/button";
import Message from "primevue/message";
import type { ReadState } from "@/stores/backupOperations";
import { formatUtc } from "@/composables/useFormatters";
import RelativeTime from "./RelativeTime.vue";

withDefaults(
  defineProps<{
    state: ReadState;
    pauseReason?: string;
    label?: string;
  }>(),
  { label: "Anzeige aktualisieren", pauseReason: "" },
);
defineEmits<{ refresh: [] }>();
</script>

<template>
  <div class="read-status">
    <div class="read-status__toolbar">
      <span v-if="state.updatedAt">
        Datenstand: <RelativeTime :timestamp="state.updatedAt" />
        <small>{{ formatUtc(state.updatedAt) }}</small>
      </span>
      <span v-else>Noch kein erfolgreicher Abruf</span>
      <Button
        :label="label"
        icon="pi pi-refresh"
        severity="secondary"
        :loading="state.loading"
        @click="$emit('refresh')"
      />
    </div>
    <p v-if="state.loading" role="status">Daten werden geladen …</p>
    <Message v-if="state.error" severity="error" :closable="false">
      {{ state.error }} Bitte die Anzeige erneut aktualisieren.
      <span v-if="state.loaded"
        >Die angezeigten Daten sind möglicherweise veraltet und stammen vom
        letzten erfolgreichen Abruf; sie können zu vorherigen Filtern
        gehören.</span
      >
    </Message>
    <small v-if="pauseReason">{{ pauseReason }}</small>
    <small v-else
      >Automatische Anzeigeaktualisierung alle 30 Sekunden im sichtbaren
      Tab.</small
    >
  </div>
</template>
