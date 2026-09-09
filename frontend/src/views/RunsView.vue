<script setup lang="ts">
import { reactive, ref } from "vue";
import { useQueryFilters, queryChoice } from "@/composables/useQueryFilters";
import InputText from "primevue/inputtext";
import FormErrors from "@/components/common/FormErrors.vue";
import PagedPicker from "@/components/common/PagedPicker.vue";
import { resourcePages, targetPages } from "@/composables/usePickerPages";
import {
  emptyRunHistoryDraft,
  runHistoryDraftFromQuery,
  validateRunHistoryDraft,
} from "@/composables/useRunHistoryFilters";
import ReadStatus from "@/components/common/ReadStatus.vue";
import { useAutoRefresh } from "@/composables/useAutoRefresh";
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
const stateDraft = ref(store.runState);
const kindDraft = ref(store.notificationKind);
const draft = reactive({
  ...emptyRunHistoryDraft(),
  ...Object.fromEntries(
    Object.entries(store.runFilters).map(([key, value]) => [
      key,
      String(value),
    ]),
  ),
});
const filterErrors = ref<Record<string, string>>({});
const filterAttempt = ref(0);
const appliedFilterInvalid = ref(false);
const guestPages = resourcePages({ kind: "pve_guest" });
const nodePages = resourcePages({ kind: "pve_node" });
const url = useQueryFilters(
  (query) => {
    const parsed = runHistoryDraftFromQuery(query);
    Object.assign(draft, parsed.draft);
    stateDraft.value = queryChoice(
      query,
      "state",
      ["all", ...runStates],
      "all",
    );
    if (
      query.state !== undefined &&
      (typeof query.state !== "string" ||
        !["all", ...runStates].includes(query.state as BackupRunState))
    )
      parsed.errors["runs-state"] =
        "Der Link enthält einen ungültigen Laufzustand.";
    filterErrors.value = parsed.errors;
    appliedFilterInvalid.value = Object.keys(parsed.errors).length > 0;
    if (!appliedFilterInvalid.value)
      store.setRunFilters(
        stateDraft.value,
        validateRunHistoryDraft(parsed.draft).filters,
      );
    store.notificationKind = queryChoice(
      query,
      "kind",
      ["all", ...notificationKinds],
      "all",
    );
    kindDraft.value = store.notificationKind;
  },
  () => {
    void loadFilteredRuns();
    void store.loadNotifications();
  },
);
async function loadFilteredRuns() {
  if (!appliedFilterInvalid.value) await store.loadRuns();
}
function applyFilters() {
  const validated = validateRunHistoryDraft(draft);
  filterErrors.value = validated.errors;
  filterAttempt.value++;
  if (Object.keys(validated.errors).length) return;
  void url.apply({
    ...Object.fromEntries(
      Object.entries(validated.filters).map(([key, value]) => [
        key,
        String(value),
      ]),
    ),
    state: stateDraft.value,
    kind: store.notificationKind,
  });
}
function applyNotificationFilter() {
  const filters = Object.fromEntries(
    Object.entries(store.runFilters).map(([key, value]) => [
      key,
      String(value),
    ]),
  );
  void url.apply({ ...filters, state: store.runState, kind: kindDraft.value });
}
const { refresh, paused } = useAutoRefresh(
  loadFilteredRuns,
  () =>
    !appliedFilterInvalid.value && store.runs.length <= store.runsPage.limit,
  () => store.runsStatus.loading,
);
const notificationsRefresh = useAutoRefresh(
  () => store.loadNotifications(),
  () => store.notifications.length <= store.notificationPage.limit,
  () => store.notificationStatus.loading,
);
</script>
<template>
  <section class="data-view" aria-labelledby="runs-title">
    <div class="view-heading">
      <div>
        <span class="section-kicker">Historie und Zustellung</span>
        <h2 id="runs-title">Backup-Läufe</h2>
        <p>Status, Tasklogs und Matrix-Meldungen zu deinen Backups.</p>
      </div>
    </div>

    <ReadStatus
      v-if="!appliedFilterInvalid"
      :state="store.runsStatus"
      :pause-reason="
        paused
          ? 'Weitere Ergebnisse sind geöffnet. Anzeige aktualisieren lädt wieder die erste Seite.'
          : ''
      "
      @refresh="refresh()"
    />
    <Message severity="info" :closable="false"
      >Hier prüfst du Laufdetails und Zustellprobleme. Anforderungen und
      Abbrüche findest du in der Queue.</Message
    >
    <form
      class="filter-panel run-history-filters"
      aria-label="Backup-Läufe filtern"
      @submit.prevent="applyFilters"
      novalidate
    >
      <div class="run-filter-explanation">
        <p>
          Alle Filter gelten gemeinsam. Zeitraum: Beginn des Laufversuchs in
          UTC. Namen stammen aus dem aktuellen oder archivierten Inventar; Node
          und Ziel gehören zur damaligen Anforderung.
        </p>
      </div>
      <FormErrors
        class="run-filter-errors"
        :errors="filterErrors"
        :attempt="filterAttempt"
      />
      <label for="runs-state"
        ><span>Laufzustand</span
        ><Select
          input-id="runs-state"
          aria-label="Laufzustand"
          v-model="stateDraft"
          :options="runStateOptions"
          option-label="label"
          option-value="value"
          :aria-invalid="!!filterErrors['runs-state'] || undefined"
      /></label>
      <label for="runs-search"
        ><span>Gastname enthält</span
        ><InputText
          id="runs-search"
          v-model="draft.search"
          maxlength="190"
          :aria-invalid="!!filterErrors['runs-search'] || undefined"
          :aria-describedby="
            filterErrors['runs-search'] ? 'runs-search-error' : undefined
          "
        /><small
          v-if="filterErrors['runs-search']"
          id="runs-search-error"
          class="field-error"
          >{{ filterErrors["runs-search"] }}</small
        ></label
      >
      <label for="runs-vmid"
        ><span>VMID</span
        ><InputText
          id="runs-vmid"
          v-model="draft.vmid"
          inputmode="numeric"
          :aria-invalid="!!filterErrors['runs-vmid'] || undefined"
          :aria-describedby="
            filterErrors['runs-vmid'] ? 'runs-vmid-error' : undefined
          "
        /><small
          v-if="filterErrors['runs-vmid']"
          id="runs-vmid-error"
          class="field-error"
          >{{ filterErrors["runs-vmid"] }}</small
        ></label
      >
      <label
        ><span>Bestimmter Gast</span
        ><PagedPicker
          id="runs-guestId"
          label="Bestimmter Gast"
          v-model="draft.guestId"
          :load-page="guestPages"
          :error="filterErrors['runs-guestId'] ?? ''"
      /></label>
      <label
        ><span>Node der Anforderung</span
        ><PagedPicker
          id="runs-nodeId"
          label="Node der Anforderung"
          v-model="draft.nodeId"
          :load-page="nodePages"
          :error="filterErrors['runs-nodeId'] ?? ''"
      /></label>
      <label
        ><span>Backup-Ziel</span
        ><PagedPicker
          id="runs-targetId"
          label="Backup-Ziel"
          v-model="draft.targetId"
          :load-page="targetPages"
          :error="filterErrors['runs-targetId'] ?? ''"
      /></label>
      <label for="runs-startedFrom"
        ><span>Ab (UTC, einschließlich)</span
        ><InputText
          id="runs-startedFrom"
          v-model="draft.startedFrom"
          placeholder="2026-09-09T00:00:00Z"
          :aria-invalid="!!filterErrors['runs-startedFrom'] || undefined"
          aria-describedby="runs-time-hint runs-startedFrom-error"
        /><small id="runs-startedFrom-error" class="field-error">{{
          filterErrors["runs-startedFrom"]
        }}</small></label
      >
      <label for="runs-startedBefore"
        ><span>Bis (UTC, ausschließlich)</span
        ><InputText
          id="runs-startedBefore"
          v-model="draft.startedBefore"
          placeholder="2026-09-10T00:00:00Z"
          :aria-invalid="!!filterErrors['runs-startedBefore'] || undefined"
          aria-describedby="runs-time-hint runs-startedBefore-error"
        /><small id="runs-startedBefore-error" class="field-error">{{
          filterErrors["runs-startedBefore"]
        }}</small></label
      >
      <small id="runs-time-hint" class="run-filter-explanation"
        >Datum und Uhrzeit mit T und abschließendem Z; optional bis zu sechs
        Nachkommastellen für Sekunden.</small
      >
      <div class="filter-bar run-filter-actions">
        <Button
          type="submit"
          label="Filter anwenden"
          icon="pi pi-filter"
        /><Button
          type="button"
          label="Filter zurücksetzen"
          severity="secondary"
          @click="url.apply({ kind: store.notificationKind })"
        />
      </div>
    </form>
    <div v-if="!appliedFilterInvalid" class="shadow-list">
      <RouterLink
        v-for="run in store.runs"
        :key="run.id"
        :to="`/runs/${run.id}`"
        class="shadow-decision"
      >
        <span
          ><strong>{{ String(run.guestName ?? `Lauf ${run.id}`) }}</strong
          ><small
            >{{ run.guestType?.toUpperCase() }} {{ run.vmid }} ·
            {{ run.nodeName }} · {{ run.targetName }} ·
            {{ formatUtc(run.startedAt) }}</small
          ></span
        >
        <Tag
          :value="backupRunStateLabel(run.state)"
          :severity="backupRunStateSeverity(run.state)"
        />
        <span>Versuch {{ run.attempt }}</span>
      </RouterLink>
    </div>
    <Message
      v-if="
        !appliedFilterInvalid &&
        store.runsStatus.loaded &&
        !store.runsStatus.loading &&
        !store.runsStatus.error &&
        store.runs.length === 0
      "
      severity="secondary"
      :closable="false"
    >
      Keine Backup-Läufe für diesen Filter.
    </Message>
    <Button
      v-if="!appliedFilterInvalid && store.runsPage.hasMore"
      label="Weitere Läufe"
      :loading="store.runsStatus.loading"
      severity="secondary"
      @click="store.loadRuns(undefined, true)"
    />
    <section aria-labelledby="notifications-title">
      <h3 id="notifications-title">Matrix-Meldungen</h3>
      <ReadStatus
        :state="store.notificationStatus"
        label="Meldungen aktualisieren"
        :pause-reason="
          notificationsRefresh.paused.value
            ? 'Weitere Meldungen sind geöffnet. Aktualisieren lädt wieder die erste Seite.'
            : ''
        "
        @refresh="notificationsRefresh.refresh()"
      />
      <form
        class="filter-bar"
        aria-label="Matrix-Meldungen filtern"
        @submit.prevent="applyNotificationFilter"
      >
        <Select
          v-model="kindDraft"
          :options="notificationKindOptions"
          option-label="label"
          option-value="value"
          aria-label="Meldungsart"
        />
        <Button type="submit" label="Filter anwenden" icon="pi pi-filter" />
        <Button
          type="button"
          label="Filter zurücksetzen"
          severity="secondary"
          @click="
            kindDraft = 'all';
            applyNotificationFilter();
          "
        />
      </form>
      <div v-if="store.notificationHealth" class="system-overview">
        <p>
          Ausstehend {{ store.notificationHealth.byState.pending }} · in
          Zustellung {{ store.notificationHealth.byState.claimed }} · zugestellt
          {{ store.notificationHealth.byState.sent }}
        </p>
        <p>
          Älteste offene Meldung:
          {{
            formatUtc(
              store.notificationHealth.oldestUnsentAt,
              "Keine offene Meldung",
            )
          }}
          · nächster Versuch:
          {{
            formatUtc(
              store.notificationHealth.nextDeliveryAttemptAt,
              "Nicht geplant",
            )
          }}
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
          nächster Versuch
          {{
            formatUtc(
              typeof notification.nextRetryAt === "string"
                ? notification.nextRetryAt
                : null,
              "Nicht geplant",
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
        v-if="
          store.notificationStatus.loaded &&
          !store.notificationStatus.loading &&
          !store.notificationStatus.error &&
          store.notifications.length === 0
        "
        severity="secondary"
        :closable="false"
      >
        Keine Matrix-Meldungen für diesen Filter.
      </Message>
      <Button
        v-if="store.notificationPage.hasMore"
        label="Weitere Meldungen"
        :loading="store.notificationStatus.loading"
        severity="secondary"
        @click="store.loadNotifications(undefined, true)"
      />
    </section>
  </section>
</template>
