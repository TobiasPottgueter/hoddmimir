<script setup lang="ts">
import { computed, onMounted } from "vue";
import Message from "primevue/message";
import Tag from "primevue/tag";
import {
  backupRequestStateLabel,
  backupRequestStateSeverity,
  backupRunStateLabel,
  backupRunStateSeverity,
  operationsWorkerStatusLabel,
  operationsWorkerStatusSeverity,
} from "@/composables/useBackupOperations";
import { useAdministration } from "@/composables/useAdministration";
import { formatUtc } from "@/composables/useFormatters";
import { useBackupOperationsStore } from "@/stores/backupOperations";

const store = useBackupOperationsStore();
const dashboard = computed(() => store.dashboard);
const { eventLabel, outcomeLabel } = useAdministration();
const resourceEntries = [
  ["systems", "Aktive Systeme"],
  ["nodes", "Aktive Nodes"],
  ["guests", "Aktive Gäste"],
  ["targets", "Aktive Backup-Ziele"],
  ["policies", "Aktive Policies"],
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
onMounted(() => void store.loadDashboard());
</script>
<template>
  <section class="dashboard" aria-labelledby="dashboard-title">
    <div class="dashboard-hero">
      <div>
        <span class="section-kicker">Betriebsübersicht</span>
        <h2 id="dashboard-title">Hoddmímir auf einen Blick</h2>
        <p>
          Collector, Backup-Worker, Scheduler-Evidenz und Queue aus der
          serverseitigen Operations-Projektion.
        </p>
      </div>
    </div>
    <Message v-if="store.error" severity="error" :closable="false">{{
      store.error
    }}</Message>
    <p v-if="store.loading" role="status">Betriebsdaten werden geladen …</p>
    <template v-if="dashboard">
      <div class="component-grid">
        <article v-for="kind in ['collector', 'backup'] as const" :key="kind">
          <i class="pi pi-wave-pulse" />
          <div>
            <h3>
              {{ kind === "collector" ? "Collector Worker" : "Backup Worker" }}
            </h3>
            <template v-if="dashboard.workers[kind]"
              ><Tag
                :value="
                  dashboard.workers[kind]!.fresh
                    ? operationsWorkerStatusLabel(
                        dashboard.workers[kind]!.status,
                      )
                    : 'Heartbeat veraltet'
                "
                :severity="
                  dashboard.workers[kind]!.fresh
                    ? operationsWorkerStatusSeverity(
                        dashboard.workers[kind]!.status,
                      )
                    : 'danger'
                "
              />
              <p>
                Heartbeat
                {{ formatUtc(dashboard.workers[kind]!.heartbeatAt) }} ·
                {{ dashboard.workers[kind]!.buildVersion }}
              </p></template
            >
            <p v-else>Kein Heartbeat vorhanden.</p>
          </div>
        </article>
      </div>
      <section v-if="dashboard.collectorSchedule" class="system-overview">
        <h3>Collector-Zeitplan</h3>
        <p>
          Nächster Zyklus:
          {{ formatUtc(dashboard.collectorSchedule.nextScanAt) }}
        </p>
        <p>
          Letzter Versuch:
          {{ formatUtc(dashboard.collectorSchedule.lastAttemptFinishedAt) }} ·
          Letzter erfolgreicher Apply:
          {{ formatUtc(dashboard.collectorSchedule.lastSuccessfulAppliedAt) }}
        </p>
      </section>
      <div class="setup-grid">
        <article
          v-for="[key, label] in resourceEntries"
          :key="key"
          class="setup-card"
        >
          <h3>{{ label }}</h3>
          <strong>{{ dashboard.resources[key] }}</strong>
        </article>
      </div>
      <div class="component-grid">
        <article>
          <div>
            <h3>Stale Evidenz</h3>
            <strong>{{ dashboard.staleEvidence }}</strong>
          </div>
        </article>
        <article>
          <div>
            <h3>Shadow-Blocker</h3>
            <strong>{{ dashboard.shadowBlockers }}</strong>
          </div>
        </article>
        <article>
          <div>
            <h3>Offene Probleme</h3>
            <strong>{{ dashboard.openProblems }}</strong>
          </div>
        </article>
      </div>
      <section class="system-overview" aria-labelledby="queue-overview-title">
        <div class="target-evidence__heading">
          <h3 id="queue-overview-title">Queue nach Zustand und Priorität</h3>
          <small
            >Älteste fällige Anforderung:
            {{ formatUtc(dashboard.oldestPendingAt) }}</small
          >
        </div>
        <div class="setup-grid">
          <article v-for="state in queueStates" :key="state" class="setup-card">
            <Tag
              :value="backupRequestStateLabel(state)"
              :severity="backupRequestStateSeverity(state)"
            />
            <strong>{{ dashboard.requestsByState[state] }}</strong>
          </article>
        </div>
        <div class="setup-grid">
          <article
            v-for="[reason, label, priority] in queueReasons"
            :key="reason"
            class="setup-card"
          >
            <h4>{{ label }}</h4>
            <strong>{{ dashboard.requestsByReason[reason] }}</strong>
            <small>Priorität {{ priority }}</small>
          </article>
        </div>
      </section>
      <section class="system-overview" aria-labelledby="run-overview-title">
        <h3 id="run-overview-title">Backup-Läufe</h3>
        <div class="setup-grid">
          <article
            v-for="state in runHighlights"
            :key="state"
            class="setup-card"
          >
            <Tag
              :value="backupRunStateLabel(state)"
              :severity="backupRunStateSeverity(state)"
            />
            <strong>{{ dashboard.runsByState[state] }}</strong>
          </article>
        </div>
        <article v-if="dashboard.lastSuccessfulRun" class="shadow-evaluation">
          <div>
            <strong>Letztes erfolgreiches Backup</strong>
            <span>
              {{ dashboard.lastSuccessfulRun.guestName }} (VMID
              {{ dashboard.lastSuccessfulRun.vmid }}) ·
              {{ dashboard.lastSuccessfulRun.nodeName }} ·
              {{ dashboard.lastSuccessfulRun.targetName }}
            </span>
          </div>
          <Tag value="Erfolgreich" severity="success" />
          <small>{{ formatUtc(dashboard.lastSuccessfulRun.finishedAt) }}</small>
        </article>
        <Message v-else severity="secondary" :closable="false">
          Noch kein erfolgreiches V2-Backup vorhanden.
        </Message>
      </section>
      <section class="system-overview" aria-labelledby="delivery-title">
        <h3 id="delivery-title">Matrix-Zustellung</h3>
        <p>
          Ausstehend {{ dashboard.notifications.byState.pending }} · in
          Zustellung {{ dashboard.notifications.byState.claimed }} · zugestellt
          {{ dashboard.notifications.byState.sent }}
        </p>
        <p>
          Älteste offene Meldung:
          {{ formatUtc(dashboard.notifications.oldestUnsentAt) }} · nächster
          Zustellversuch:
          {{ formatUtc(dashboard.notifications.nextDeliveryAttemptAt) }}
        </p>
        <Message
          v-if="dashboard.notifications.lastErrorCode"
          severity="warn"
          :closable="false"
        >
          Letzter sicherer Zustellfehlercode:
          {{ dashboard.notifications.lastErrorCode }}
        </Message>
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
    </template>
    <Message
      v-else-if="!store.loading && !store.error"
      severity="secondary"
      :closable="false"
    >
      Keine Betriebsprojektion verfügbar.
    </Message>
  </section>
</template>
