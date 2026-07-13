<script setup lang="ts">
import { onMounted } from "vue";
import { RouterLink } from "vue-router";
import Button from "primevue/button";
import Message from "primevue/message";
import Select from "primevue/select";
import Tag from "primevue/tag";
import type { BackupRunState } from "@/api/generated/types.gen";
import {
  backupNotificationKindLabel,
  backupNotificationStateLabel,
  backupNotificationStateSeverity,
  backupRunStateLabel,
  backupRunStateSeverity,
  type BackupNotificationKind,
} from "@/composables/useBackupOperations";
import { formatUtc } from "@/composables/useFormatters";
import { useBackupOperationsStore } from "@/stores/backupOperations";
const store = useBackupOperationsStore();
const runStates: BackupRunState[] = [
  "awaiting_submission",
  "reconcile_required",
  "running",
  "cancel_requested",
  "succeeded",
  "failed",
  "cancelled",
  "unknown",
];
const runStateOptions = [
  { label: "Alle Laufzustände", value: "all" },
  ...runStates.map((state) => ({
    label: backupRunStateLabel(state),
    value: state,
  })),
];
const notificationKinds: BackupNotificationKind[] = [
  "failure",
  "attention_required",
  "recovery",
];
const notificationKindOptions = [
  { label: "Alle Meldungsarten", value: "all" },
  ...notificationKinds.map((kind) => ({
    label: backupNotificationKindLabel(kind),
    value: kind,
  })),
];
onMounted(() => {
  void store.loadRuns();
  void store.loadNotifications();
});
</script>
<template>
  <section class="data-view" aria-labelledby="runs-title">
    <div class="view-heading">
      <div>
        <span class="section-kicker">Historie und Zustellung</span>
        <h2 id="runs-title">Backup-Läufe</h2>
        <p>Status, Tasklogs und sanitisierte Matrix-Meldungen.</p>
      </div>
    </div>
    <Message v-if="store.error" severity="error" :closable="false">{{
      store.error
    }}</Message>
    <Message severity="info" :closable="false"
      >Diese Ansicht ist read-only. Aktionen sind nur mit
      <code>backup_operations.manage</code> möglich.</Message
    >
    <form
      class="filter-bar"
      aria-label="Backup-Läufe filtern"
      @submit.prevent="store.loadRuns()"
    >
      <Select
        v-model="store.runState"
        :options="runStateOptions"
        option-label="label"
        option-value="value"
        aria-label="Laufzustand"
      />
      <Button type="submit" label="Filter anwenden" icon="pi pi-filter" />
    </form>
    <div class="shadow-list">
      <RouterLink
        v-for="run in store.runs"
        :key="run.id"
        :to="`/runs/${run.id}`"
        class="shadow-decision"
      >
        <span
          ><strong>{{ String(run.guestName ?? `Lauf ${run.id}`) }}</strong
          ><small>{{ formatUtc(run.startedAt) }}</small></span
        >
        <Tag
          :value="backupRunStateLabel(run.state)"
          :severity="backupRunStateSeverity(run.state)"
        />
        <span>Versuch {{ run.attempt }}</span>
      </RouterLink>
    </div>
    <Message
      v-if="!store.loading && store.runs.length === 0"
      severity="secondary"
      :closable="false"
    >
      Keine Backup-Läufe für diesen Filter.
    </Message>
    <Button
      v-if="store.runsPage.hasMore"
      label="Weitere Läufe"
      severity="secondary"
      @click="store.loadRuns(undefined, true)"
    />
    <section aria-labelledby="notifications-title">
      <h3 id="notifications-title">Matrix-Meldungen</h3>
      <form
        class="filter-bar"
        aria-label="Matrix-Meldungen filtern"
        @submit.prevent="store.loadNotifications()"
      >
        <Select
          v-model="store.notificationKind"
          :options="notificationKindOptions"
          option-label="label"
          option-value="value"
          aria-label="Meldungsart"
        />
        <Button type="submit" label="Filter anwenden" icon="pi pi-filter" />
      </form>
      <div v-if="store.notificationHealth" class="system-overview">
        <p>
          Ausstehend {{ store.notificationHealth.byState.pending }} · in
          Zustellung {{ store.notificationHealth.byState.claimed }} · zugestellt
          {{ store.notificationHealth.byState.sent }}
        </p>
        <p>
          Älteste offene Meldung:
          {{ formatUtc(store.notificationHealth.oldestUnsentAt) }} · nächster
          Versuch:
          {{ formatUtc(store.notificationHealth.nextDeliveryAttemptAt) }}
        </p>
        <Message
          v-if="store.notificationHealth.lastErrorCode"
          severity="warn"
          :closable="false"
        >
          Letzter sicherer Zustellfehlercode:
          {{ store.notificationHealth.lastErrorCode }}
        </Message>
      </div>
      <article
        v-for="notification in store.notifications"
        :key="notification.id"
        class="shadow-evaluation"
      >
        <strong
          >{{ backupNotificationKindLabel(notification.kind) }} · Versuch
          {{ notification.attempt }}</strong
        ><span
          >{{ notification.guestName }} ({{ notification.vmid }}) ·
          {{ String(notification.problemCode ?? "Entwarnung") }}</span
        ><small
          >Zustellversuche {{ String(notification.deliveryAttempts ?? 0) }} ·
          nächster Retry
          {{
            formatUtc(
              typeof notification.nextRetryAt === "string"
                ? notification.nextRetryAt
                : null,
            )
          }}
          ·
          {{
            String(notification.lastErrorCode ?? "kein Zustellfehler")
          }}</small
        ><Tag
          :value="backupNotificationStateLabel(notification.state)"
          :severity="backupNotificationStateSeverity(notification.state)"
        />
      </article>
      <Message
        v-if="!store.loading && store.notifications.length === 0"
        severity="secondary"
        :closable="false"
      >
        Keine Matrix-Meldungen für diesen Filter.
      </Message>
      <Button
        v-if="store.notificationPage.hasMore"
        label="Weitere Meldungen"
        severity="secondary"
        @click="store.loadNotifications(undefined, true)"
      />
    </section>
  </section>
</template>
