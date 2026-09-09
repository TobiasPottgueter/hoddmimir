<script setup lang="ts">
import { computed } from "vue";
import { RouterLink } from "vue-router";
import Message from "primevue/message";
import Tag from "primevue/tag";
import ReadStatus from "@/components/common/ReadStatus.vue";
import RelativeTime from "@/components/common/RelativeTime.vue";
import { useAutoRefresh } from "@/composables/useAutoRefresh";
import { useFreshnessClock } from "@/composables/useFreshness";
import {
  backupRequestStateLabel,
  backupRequestStateSeverity,
  backupRunStateLabel,
  backupRunStateSeverity,
  operationsWorkerStatusLabel,
  operationsWorkerStatusSeverity,
  operationsWorkerIsFresh,
} from "@/composables/useBackupOperations";
import { useAdministration } from "@/composables/useAdministration";
import { formatUtc } from "@/composables/useFormatters";
import { useBackupOperationsStore } from "@/stores/backupOperations";

const store = useBackupOperationsStore();
const dashboard = computed(() => store.dashboard);
const now = useFreshnessClock();
const { eventLabel, outcomeLabel } = useAdministration();
const resourceEntries = [
  ["systems", "Aktive Systeme", "/systems"],
  ["nodes", "Aktive Nodes", "/inventory?kind=pve_node"],
  ["guests", "Aktive Gäste", "/inventory?kind=pve_guest"],
  ["targets", "Aktive Backup-Ziele", "/backup-targets?enabled=true"],
  ["policies", "Aktive Policies", "/policies?status=enabled"],
] as const;
const queueStates = [
  "pending",
  "retry_wait",
  "leased",
  "starting",
  "running",
  "reconcile_required",
  "failed",
  "unknown",
] as const;
const queueReasons = [
  ["manual", "Manuell", 400],
  ["never_backed_up", "Noch nie gesichert", 300],
  ["max_age", "Maximales Alter", 200],
  ["bytes_written", "Schreibvolumen", 100],
] as const;
const runHighlights = ["running", "failed", "unknown", "succeeded"] as const;
const { refresh } = useAutoRefresh(
  () => store.loadDashboard(),
  () => true,
  () => store.dashboardStatus.loading,
);
</script>

<template>
  <section
    class="dashboard dashboard--operations"
    aria-labelledby="dashboard-title"
  >
    <div class="view-heading">
      <div>
        <span class="section-kicker">Betriebsübersicht</span>
        <h2 id="dashboard-title">Hoddmímir auf einen Blick</h2>
        <p>Backup-Betrieb, offene Probleme und letzte Ergebnisse.</p>
      </div>
    </div>
    <ReadStatus :state="store.dashboardStatus" @refresh="refresh()" />
    <template v-if="dashboard">
      <div class="dashboard-workers">
        <article
          v-for="kind in ['collector', 'backup'] as const"
          :key="kind"
          class="dashboard-worker"
        >
          <div class="dashboard-worker__heading">
            <h3>
              {{ kind === "collector" ? "Collector Worker" : "Backup Worker" }}
            </h3>
            <Tag
              v-if="dashboard.workers[kind]"
              :value="
                operationsWorkerIsFresh(dashboard.workers[kind], now)
                  ? operationsWorkerStatusLabel(dashboard.workers[kind]!.status)
                  : 'Heartbeat veraltet'
              "
              :severity="
                operationsWorkerIsFresh(dashboard.workers[kind], now)
                  ? operationsWorkerStatusSeverity(
                      dashboard.workers[kind]!.status,
                    )
                  : 'danger'
              "
            />
            <Tag v-else value="Kein Heartbeat" severity="warn" />
          </div>
          <p v-if="dashboard.workers[kind]">
            Letzte Rückmeldung:
            <RelativeTime :timestamp="dashboard.workers[kind]!.heartbeatAt" />
          </p>
          <p v-else>Noch keine Rückmeldung vorhanden.</p>
          <RouterLink :to="kind === 'collector' ? '/operations' : '/runs'"
            >{{
              kind === "collector"
                ? "Collector-Status ansehen"
                : "Backup-Läufe ansehen"
            }}
            <i class="pi pi-arrow-right" aria-hidden="true"
          /></RouterLink>
        </article>
      </div>
      <section
        class="dashboard-priorities"
        aria-label="Aktueller Backup-Betrieb"
      >
        <article
          class="dashboard-metric"
          :class="{ 'dashboard-metric--warning': dashboard.openProblems > 0 }"
        >
          <h3>Offene Probleme</h3>
          <strong>{{ dashboard.openProblems }}</strong>
          <span
            >Veraltete Nachweise: {{ dashboard.staleEvidence }} ·
            Shadow-Blocker: {{ dashboard.shadowBlockers }}</span
          >
          <RouterLink to="/shadow?outcome=blocked"
            >Sperrgründe prüfen</RouterLink
          >
        </article>
        <RouterLink to="/queue?state=pending" class="dashboard-metric">
          <h3>Wartende Anforderungen</h3>
          <strong>{{ dashboard.requestsByState.pending }}</strong>
          <span
            >{{ dashboard.requestsByState.retry_wait }} warten auf
            Wiederholung</span
          >
        </RouterLink>
        <RouterLink to="/runs?state=running" class="dashboard-metric">
          <h3>Laufende Backups</h3>
          <strong>{{ dashboard.runsByState.running }}</strong>
          <span
            >{{ dashboard.runsByState.failed }} fehlgeschlagen ·
            {{ dashboard.runsByState.unknown }} unklar</span
          >
        </RouterLink>
      </section>
      <article
        class="dashboard-last-backup"
        aria-labelledby="last-backup-title"
      >
        <div>
          <h3 id="last-backup-title">Letztes erfolgreiches Backup</h3>
          <template v-if="dashboard.lastSuccessfulRun">
            <RouterLink :to="`/runs/${dashboard.lastSuccessfulRun.runId}`"
              >{{ dashboard.lastSuccessfulRun.guestName }} (VMID
              {{ dashboard.lastSuccessfulRun.vmid }})</RouterLink
            >
            <span>
              · {{ dashboard.lastSuccessfulRun.nodeName }} ·
              {{ dashboard.lastSuccessfulRun.targetName }}</span
            >
          </template>
          <p v-else>Noch kein erfolgreiches V2-Backup vorhanden.</p>
        </div>
        <RelativeTime
          v-if="dashboard.lastSuccessfulRun"
          :timestamp="dashboard.lastSuccessfulRun.finishedAt"
        />
      </article>
      <section class="dashboard-section" aria-labelledby="queue-overview-title">
        <div class="target-evidence__heading">
          <h3 id="queue-overview-title">Queue nach Zustand und Priorität</h3>
          <small
            >Älteste fällige Anforderung:
            {{
              formatUtc(dashboard.oldestPendingAt, "Keine fällige Anforderung")
            }}</small
          >
        </div>
        <div class="dashboard-state-grid">
          <RouterLink
            v-for="state in queueStates"
            :key="state"
            :to="`/queue?state=${state}`"
            class="dashboard-count"
          >
            <Tag
              :value="backupRequestStateLabel(state)"
              :severity="backupRequestStateSeverity(state)"
            />
            <strong>{{ dashboard.requestsByState[state] }}</strong>
          </RouterLink>
        </div>
        <div class="dashboard-reasons">
          <div v-for="[reason, label, priority] in queueReasons" :key="reason">
            <span>{{ label }}</span
            ><strong>{{ dashboard.requestsByReason[reason] }}</strong
            ><small>Priorität {{ priority }}</small>
          </div>
        </div>
      </section>
      <section class="dashboard-section" aria-labelledby="resources-title">
        <h3 id="resources-title">Konfigurierte Umgebung</h3>
        <div class="dashboard-resources">
          <RouterLink
            v-for="[key, label, to] in resourceEntries"
            :key="key"
            :to="to"
            ><span>{{ label }}</span
            ><strong>{{ dashboard.resources[key] }}</strong></RouterLink
          >
        </div>
      </section>
      <details class="dashboard-section" open>
        <summary>Weitere Betriebsergebnisse</summary>
        <section v-if="dashboard.collectorSchedule">
          <h3>Collector-Zeitplan</h3>
          <p>
            Nächster Zyklus:
            {{
              formatUtc(
                dashboard.collectorSchedule.nextScanAt,
                "Noch nicht geplant",
              )
            }}
          </p>
          <p>
            Letzter Versuch:
            {{
              formatUtc(
                dashboard.collectorSchedule.lastAttemptFinishedAt,
                "Noch kein abgeschlossener Versuch",
              )
            }}
            · Letzte erfolgreiche Inventarübernahme:
            {{
              formatUtc(
                dashboard.collectorSchedule.lastSuccessfulAppliedAt,
                "Noch keine erfolgreiche Übernahme",
              )
            }}
          </p>
        </section>
        <section aria-labelledby="run-overview-title">
          <h3 id="run-overview-title">Backup-Läufe</h3>
          <div class="dashboard-state-grid">
            <RouterLink
              v-for="state in runHighlights"
              :key="state"
              :to="`/runs?state=${state}`"
              class="dashboard-count"
              ><Tag
                :value="backupRunStateLabel(state)"
                :severity="backupRunStateSeverity(state)"
              /><strong>{{ dashboard.runsByState[state] }}</strong></RouterLink
            >
          </div>
        </section>
        <section aria-labelledby="delivery-title">
          <h3 id="delivery-title">Matrix-Zustellung</h3>
          <p>
            Ausstehend {{ dashboard.notifications.byState.pending }} · in
            Zustellung {{ dashboard.notifications.byState.claimed }} ·
            zugestellt {{ dashboard.notifications.byState.sent }}
          </p>
          <p>
            Älteste offene Meldung:
            {{
              formatUtc(
                dashboard.notifications.oldestUnsentAt,
                "Keine offene Meldung",
              )
            }}
            · nächster Zustellversuch:
            {{
              formatUtc(
                dashboard.notifications.nextDeliveryAttemptAt,
                "Nicht geplant",
              )
            }}
          </p>
          <Message
            v-if="dashboard.notifications.lastErrorCode"
            severity="warn"
            :closable="false"
            >Zustellung fehlgeschlagen:
            {{ dashboard.notifications.lastErrorCode }}.
            <RouterLink to="/runs#notifications-title"
              >Meldungen prüfen</RouterLink
            >.</Message
          >
        </section>
        <section v-if="dashboard.auditVisible">
          <h3>Letzte Audit-Ereignisse</h3>
          <ul>
            <li v-for="event in dashboard.recentAuditEvents" :key="event.id">
              {{ formatUtc(event.occurredAt) }} ·
              {{ eventLabel(event.eventType) }} ·
              {{ outcomeLabel(event.outcome) }}
            </li>
          </ul>
        </section>
      </details>
    </template>
    <Message
      v-else-if="!store.dashboardStatus.loading && !store.dashboardStatus.error"
      severity="secondary"
      :closable="false"
      >Noch keine Betriebsdaten verfügbar. Prüfe den Collector-Status.</Message
    >
  </section>
</template>
