<script setup lang="ts">
import Message from "primevue/message";
import Tag from "primevue/tag";

import type {
  AdministrationHealthReport,
  OperationsWorkerHealth,
  OperationsWorkers,
} from "@/api/generated/types.gen";
import { formatUtc } from "@/composables/useFormatters";

defineProps<{
  workers: OperationsWorkers | null;
  health: AdministrationHealthReport | null;
  loading: boolean;
  error: string | null;
}>();

const workerKinds = ["collector", "backup"] as const;
const workerKindLabels = {
  collector: "Collector",
  backup: "Backup-Worker",
} as const;
const workerStatusLabels: Record<OperationsWorkerHealth["status"], string> = {
  starting: "Startet",
  ready: "Bereit",
  busy: "Beschäftigt",
  degraded: "Beeinträchtigt",
  stopping: "Wird beendet",
};
const workerStatusSeverities: Record<
  OperationsWorkerHealth["status"],
  "secondary" | "info" | "success" | "warn"
> = {
  starting: "info",
  ready: "success",
  busy: "info",
  degraded: "warn",
  stopping: "secondary",
};
const checkLabels: Record<string, string> = {
  database_schema: "Datenbankschema",
  encryption_keyring: "Verschlüsselungs-Schlüsselbund",
  readiness_configuration: "Readiness-Konfiguration",
};
const reasonLabels: Record<string, string> = {
  migration_metadata_missing: "Migrationsmetadaten fehlen",
  database_unavailable: "Datenbank nicht erreichbar",
  migration_version_mismatch: "Schema-Version stimmt nicht",
  missing: "Schlüsseldatei fehlt",
  revision_mismatch: "Schlüsselrevision stimmt nicht",
  invalid: "Konfiguration ungültig",
  usage_unavailable: "Schlüsselverwendung nicht prüfbar",
  missing_referenced_key: "Referenzierter Schlüssel fehlt",
  check_registration_invalid: "Readiness-Prüfung falsch registriert",
  check_failed: "Readiness-Prüfung fehlgeschlagen",
};

const checkLabel = (name: string) =>
  checkLabels[name] ?? "Unbekannte Readiness-Prüfung";
const reasonLabel = (reason: string) =>
  reasonLabels[reason] ?? "Nicht sicher bestimmbarer Fehler";
const workerStatusLabel = (status: OperationsWorkerHealth["status"]) =>
  workerStatusLabels[status];
const workerSeverity = (worker: OperationsWorkerHealth) =>
  worker.fresh ? workerStatusSeverities[worker.status] : "danger";
</script>

<template>
  <section aria-labelledby="administration-health-title">
    <h3 id="administration-health-title">
      Worker-, Datenbank- und Runtime-Health
    </h3>
    <Message v-if="error" severity="error" :closable="false">{{
      error
    }}</Message>
    <p v-if="loading" role="status">Betriebszustand wird geladen …</p>

    <div v-if="workers" class="component-grid">
      <article v-for="kind in workerKinds" :key="kind">
        <h4>{{ workerKindLabels[kind] }}</h4>
        <template v-if="workers[kind]">
          <Tag
            :value="
              workers[kind]!.fresh
                ? workerStatusLabel(workers[kind]!.status)
                : 'Heartbeat veraltet'
            "
            :severity="workerSeverity(workers[kind]!)"
          />
          <dl class="detail-grid">
            <dt>Heartbeat</dt>
            <dd>{{ formatUtc(workers[kind]!.heartbeatAt) }}</dd>
            <dt>Gültig bis</dt>
            <dd>{{ formatUtc(workers[kind]!.expiresAt) }}</dd>
            <dt>Nächste Aktion</dt>
            <dd>{{ formatUtc(workers[kind]!.nextActionAt) }}</dd>
            <dt>Aktivität</dt>
            <dd>{{ workers[kind]!.currentActivity ?? "Leerlauf" }}</dd>
            <dt>Build</dt>
            <dd>{{ workers[kind]!.buildVersion }}</dd>
          </dl>
        </template>
        <Message v-else severity="warn" :closable="false">
          Kein Heartbeat vorhanden.
        </Message>
      </article>
    </div>
    <Message v-else-if="!loading" severity="warn" :closable="false">
      Keine Worker-Projektion verfügbar.
    </Message>

    <article v-if="health" class="system-overview">
      <div class="target-evidence__heading">
        <h4>Runtime-Readiness</h4>
        <Tag
          :value="health.status === 'ok' ? 'Bereit' : 'Nicht bereit'"
          :severity="health.status === 'ok' ? 'success' : 'danger'"
        />
      </div>
      <p>Geprüft {{ formatUtc(health.checkedAt) }}</p>
      <dl class="detail-grid">
        <template v-for="(check, name) in health.checks" :key="name">
          <dt>{{ checkLabel(name) }}</dt>
          <dd>
            <Tag
              :value="check.status === 'ready' ? 'Bereit' : 'Nicht bereit'"
              :severity="check.status === 'ready' ? 'success' : 'danger'"
            />
            <span v-if="check.status === 'unavailable'">
              {{ reasonLabel(check.reason) }}
            </span>
          </dd>
        </template>
      </dl>
    </article>
    <Message v-else-if="!loading" severity="warn" :closable="false">
      Keine Runtime-Readiness verfügbar.
    </Message>
  </section>
</template>
