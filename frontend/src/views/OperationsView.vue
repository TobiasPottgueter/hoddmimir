<script setup lang="ts">
import { onMounted } from "vue";
import Button from "primevue/button";
import Column from "primevue/column";
import DataTable from "primevue/datatable";
import Message from "primevue/message";
import Tag from "primevue/tag";

import type { CollectorRun, CollectorScope } from "@/api/generated/types.gen";
import AsyncState from "@/components/common/AsyncState.vue";
import {
  collectorHealth,
  statusBoolean,
  statusNumber,
  statusString,
} from "@/composables/useCollectorStatus";
import { formatDuration, formatUtc } from "@/composables/useFormatters";
import { useOperationsStore } from "@/stores/operations";

const store = useOperationsStore();

const healthLabels = {
  unconfigured: "Nicht konfiguriert",
  missing: "Kein Heartbeat",
  healthy: "Collector aktiv",
  stale: "Heartbeat veraltet",
} as const;

function statusSeverity(value: string): "success" | "warn" | "danger" | "info" {
  if (["succeeded", "complete", "running"].includes(value)) return "success";
  if (["partial", "cancelled"].includes(value)) return "warn";
  if (["failed", "stale"].includes(value)) return "danger";
  return "info";
}

onMounted(() => void store.load());
</script>

<template>
  <section class="data-view" aria-labelledby="operations-title">
    <div class="view-heading">
      <div>
        <span class="section-kicker">Read-only Betrieb</span>
        <h2 id="operations-title">Collector-Status und Inventurläufe</h2>
        <p>
          Heartbeat, Zeitplan und Scope-Ergebnisse ohne Start-, Stop- oder
          Scan-Aktion.
        </p>
      </div>
      <Tag
        :value="healthLabels[collectorHealth(store.status)]"
        :severity="
          collectorHealth(store.status) === 'healthy' ? 'success' : 'warn'
        "
      />
    </div>

    <AsyncState :loading="store.loading" :error="store.error" :empty="false">
      <div class="metric-grid operations-metrics">
        <article>
          <span>Nächster Zyklus</span>
          <strong class="metric-grid__date">{{
            formatUtc(
              statusString(store.status?.schedule ?? null, "nextScanAt"),
            )
          }}</strong>
        </article>
        <article>
          <span>Rasterbreite</span>
          <strong
            >{{
              statusNumber(store.status?.schedule ?? null, "intervalSeconds") ??
              "–"
            }}
            s</strong
          >
        </article>
        <article>
          <span>Letzter Heartbeat</span>
          <strong class="metric-grid__date">{{
            formatUtc(
              statusString(store.status?.heartbeat ?? null, "heartbeatAt"),
            )
          }}</strong>
        </article>
        <article>
          <span>Aktivität</span>
          <strong class="metric-grid__date">{{
            statusString(store.status?.heartbeat ?? null, "currentActivity") ??
            "Keine Angabe"
          }}</strong>
        </article>
      </div>

      <Message
        v-if="
          store.status &&
          statusBoolean(store.status.heartbeat, 'fresh') === false
        "
        severity="warn"
        :closable="false"
      >
        Der letzte Collector-Heartbeat ist abgelaufen. Inventar- und
        Kapazitätsdaten können veraltet sein.
      </Message>

      <section class="table-section" aria-labelledby="runs-title">
        <div class="section-heading">
          <div>
            <span class="section-kicker">Historie</span>
            <h2 id="runs-title">Collector-Läufe</h2>
          </div>
        </div>
        <div class="table-panel">
          <DataTable
            :value="store.runs"
            data-key="id"
            striped-rows
            responsive-layout="scroll"
          >
            <Column field="connectionName" header="System" />
            <Column field="product" header="Produkt">
              <template #body="{ data }">{{
                (data as CollectorRun).product.toUpperCase()
              }}</template>
            </Column>
            <Column field="status" header="Status">
              <template #body="{ data }">
                <Tag
                  :value="(data as CollectorRun).status"
                  :severity="statusSeverity((data as CollectorRun).status)"
                />
              </template>
            </Column>
            <Column header="Erkannt">
              <template #body="{ data }">
                {{ (data as CollectorRun).nodesSeen }} Nodes ·
                {{ (data as CollectorRun).guestsSeen }} Gäste ·
                {{ (data as CollectorRun).storagesSeen }} Storages
              </template>
            </Column>
            <Column header="Dauer">
              <template #body="{ data }">{{
                formatDuration(
                  (data as CollectorRun).startedAt,
                  (data as CollectorRun).finishedAt,
                )
              }}</template>
            </Column>
            <Column header="Scopes">
              <template #body="{ data }">
                <Button
                  label="Details"
                  icon="pi pi-eye"
                  severity="secondary"
                  size="small"
                  text
                  :aria-label="`Scope-Ergebnisse für ${(data as CollectorRun).connectionName} anzeigen`"
                  @click="store.selectRun((data as CollectorRun).id)"
                />
              </template>
            </Column>
          </DataTable>
          <div v-if="store.runsPage.hasMore" class="table-pagination">
            <Button
              label="Weitere Läufe laden"
              icon="pi pi-angle-down"
              severity="secondary"
              :loading="store.loading"
              @click="store.loadMoreRuns()"
            />
          </div>
        </div>
      </section>

      <section
        v-if="store.selectedRunId"
        class="table-section"
        aria-labelledby="scopes-title"
      >
        <div class="section-heading">
          <div>
            <span class="section-kicker">Laufdetails</span>
            <h2 id="scopes-title">Scope-Ergebnisse</h2>
          </div>
          <span>
            {{ store.scopesPage.hasMore ? "Mindestens " : ""
            }}{{ store.incompleteScopeCount }} unvollständig
          </span>
        </div>
        <Message
          v-if="store.incompleteScopeCount > 0"
          severity="warn"
          :closable="false"
        >
          Teilweise oder fehlgeschlagene Scopes können auf Erreichbarkeits- oder
          Berechtigungsprobleme hinweisen. Positive Beobachtungen bleiben
          sichtbar; Abwesenheit wird nicht abgeleitet.
        </Message>
        <AsyncState
          :loading="store.scopesLoading"
          :error="store.scopesError"
          :empty="!store.scopesLoading && store.scopes.length === 0"
          empty-title="Keine Scope-Ergebnisse"
        >
          <div class="table-panel">
            <DataTable :value="store.scopes" data-key="scopeKey" striped-rows>
              <Column field="scopeType" header="Scope" />
              <Column field="scopeKey" header="Schlüssel" />
              <Column field="status" header="Status">
                <template #body="{ data }">
                  <Tag
                    :value="(data as CollectorScope).status"
                    :severity="statusSeverity((data as CollectorScope).status)"
                  />
                </template>
              </Column>
              <Column field="observedAt" header="Beobachtet">
                <template #body="{ data }">{{
                  formatUtc((data as CollectorScope).observedAt)
                }}</template>
              </Column>
            </DataTable>
            <div v-if="store.scopesPage.hasMore" class="table-pagination">
              <Button
                label="Weitere Scope-Ergebnisse laden"
                icon="pi pi-angle-down"
                severity="secondary"
                :loading="store.scopesLoading"
                @click="store.loadMoreScopes()"
              />
            </div>
          </div>
        </AsyncState>
      </section>
    </AsyncState>
  </section>
</template>
