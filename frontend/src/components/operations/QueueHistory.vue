<script setup lang="ts">
import { computed, ref, watch } from "vue";
import Button from "primevue/button";
import Message from "primevue/message";
import Select from "primevue/select";
import { loadQueueHistory } from "@/api/queueMetricsApi";
import type { QueueMetricHistory } from "@/api/generated/types.gen";
import { formatUtc } from "@/composables/useFormatters";

const hours = ref<24 | 168 | 720>(24);
const data = ref<QueueMetricHistory | null>(null);
const loading = ref(false);
const error = ref(false);
const page = ref(0);
let generation = 0;
const periods = [
  { label: "24 Stunden", value: 24 },
  { label: "7 Tage", value: 168 },
  { label: "30 Tage", value: 720 },
];
const rows = computed(() =>
  [...(data.value?.items ?? [])]
    .reverse()
    .slice(page.value * 24, (page.value + 1) * 24),
);
async function load() {
  const current = ++generation;
  loading.value = true;
  error.value = false;
  data.value = null;
  page.value = 0;
  try {
    const result = await loadQueueHistory(hours.value);
    if (current === generation) data.value = result;
  } catch {
    if (current === generation) error.value = true;
  } finally {
    if (current === generation) loading.value = false;
  }
}
watch(hours, () => void load(), { immediate: true });
</script>

<template>
  <section
    class="setup-card queue-history"
    aria-labelledby="queue-history-title"
    :aria-busy="loading"
  >
    <div class="view-heading">
      <h3 id="queue-history-title">Queue-Verlauf</h3>
      <div class="filter-bar">
        <label for="queue-history-period">Zeitraum</label>
        <Select
          v-model="hours"
          input-id="queue-history-period"
          :options="periods"
          option-label="label"
          option-value="value"
        />
        <Button
          label="Verlauf aktualisieren"
          icon="pi pi-refresh"
          severity="secondary"
          :loading="loading"
          @click="load"
        />
      </div>
    </div>
    <p>
      Gesamte Queue · 30 Tage Aufbewahrung. Fehlende Messungen bedeuten keine
      beobachtete Null. „Ungeklärt“ umfasst laufende Taskklärungen.
      Abgeschlossene Anfragen sind nicht enthalten; „Aktiv“ zählt auch
      reservierte Starts.
    </p>
    <Message v-if="error" severity="error" :closable="false"
      >Der Verlauf konnte nicht geladen werden. Bitte erneut
      aktualisieren.</Message
    >
    <p v-else-if="loading" role="status">Verlauf wird geladen …</p>
    <p v-else-if="!data?.items.length">
      Noch keine Messungen in diesem Zeitraum. Der Collector erfasst den Verlauf
      automatisch.
    </p>
    <template v-else>
      <p>
        Intervalle: {{ data.bucketSeconds / 60 }} Minuten. Wartend: Durchschnitt
        / Höchstwert; aktiv und ungeklärt: Höchstwert. Neueste Intervalle
        zuerst.
      </p>
      <div
        class="queue-history-table"
        tabindex="0"
        role="region"
        aria-label="Queue-Messwerte"
      >
        <table>
          <caption>
            Queue je Zeitintervall (UTC)
          </caption>
          <thead>
            <tr>
              <th scope="col">Intervallbeginn</th>
              <th scope="col">Messungen</th>
              <th scope="col">Wartend Ø / Max.</th>
              <th scope="col">Aktiv</th>
              <th scope="col">Ungeklärt</th>
              <th scope="col">Längste Wartezeit</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="point in rows" :key="point.observedAt">
              <th scope="row">{{ formatUtc(point.observedAt) }}</th>
              <td>{{ point.samples }}</td>
              <td>
                {{
                  point.waitingAverage.toLocaleString("de-DE", {
                    maximumFractionDigits: 1,
                  })
                }}
                / {{ point.waitingPeak }}
              </td>
              <td>{{ point.activePeak }}</td>
              <td>{{ point.unresolvedPeak }}</td>
              <td>{{ Math.ceil(point.oldestWaitSeconds / 60) }} min</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="filter-bar">
        <Button
          label="Neuere Intervalle"
          severity="secondary"
          :disabled="page === 0"
          @click="page--"
        />
        <span
          >Seite {{ page + 1 }} von
          {{ Math.ceil(data.items.length / 24) }}</span
        >
        <Button
          label="Ältere Intervalle"
          severity="secondary"
          :disabled="(page + 1) * 24 >= data.items.length"
          @click="page++"
        />
      </div>
    </template>
  </section>
</template>

<style scoped>
.queue-history {
  min-width: 0;
}
.queue-history-table {
  overflow-x: auto;
}
table {
  width: 100%;
  border-collapse: collapse;
  font-variant-numeric: tabular-nums;
}
th,
td {
  text-align: start;
  padding: 0.75rem;
  border-bottom: 1px solid var(--p-content-border-color);
}
caption {
  text-align: start;
  padding-block: 0.5rem;
}
</style>
