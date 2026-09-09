<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import {
  useQueryFilters,
  queryChoice,
  queryText,
  queryUuid,
} from "@/composables/useQueryFilters";
import PagedPicker from "@/components/common/PagedPicker.vue";
import { connectionPages, resourcePages } from "@/composables/usePickerPages";
import Button from "primevue/button";
import InputText from "primevue/inputtext";
import Message from "primevue/message";
import Select from "primevue/select";

import type {
  BackupTargetCandidate,
  ConfiguredBackupTarget,
  TargetCommandRequest,
} from "@/api/generated/types.gen";
import AsyncState from "@/components/common/AsyncState.vue";
import BackupTargetCandidateCard from "@/components/targets/BackupTargetCandidateCard.vue";
import BackupTargetForm from "@/components/targets/BackupTargetForm.vue";
import ConfiguredBackupTargetCard from "@/components/targets/ConfiguredBackupTargetCard.vue";
import { useBackupTargetCandidates } from "@/composables/useBackupTargetCandidates";
import { useConfiguredBackupTargets } from "@/composables/useConfiguredBackupTargets";
import { useAuthStore } from "@/stores/auth";
import { useConfigurationCommandsStore } from "@/stores/configurationCommands";

const { store, hasFreshnessBlocker } = useBackupTargetCandidates();
const { store: configuredStore, hasIncompleteConfiguration } =
  useConfiguredBackupTargets();
const auth = useAuthStore();
const commands = useConfigurationCommandsStore();
const canManage = computed(() =>
  auth.hasPermission("backup_configuration.manage"),
);
const formOpen = ref(false);
const targetForm = ref<InstanceType<typeof BackupTargetForm> | null>(null);
const editedTarget = ref<ConfiguredBackupTarget | null>(null);
const sourceCandidate = ref<BackupTargetCandidate | null>(null);
const connectionDraft = ref(store.connectionId);
const clusterDraft = ref(store.clusterId);
const searchDraft = ref(configuredStore.search);
const enabledDraft = ref<boolean | null>(configuredStore.enabled);
const enabledOptions: Array<{ label: string; value: boolean | null }> = [
  { label: "Alle Zustände", value: null },
  { label: "Aktiviert", value: true },
  { label: "Deaktiviert", value: false },
];

const clusterPages = computed(() =>
  resourcePages({
    kind: "pve_cluster",
    ...(connectionDraft.value ? { connectionId: connectionDraft.value } : {}),
  }),
);
const url = useQueryFilters(
  (query) => {
    store.setConnectionId(queryUuid(query, "connectionId"));
    store.setClusterId(queryUuid(query, "clusterId"));
    configuredStore.setSearch(queryText(query, "search", 100));
    const enabled = queryChoice(query, "enabled", ["", "true", "false"], "");
    configuredStore.setEnabled(enabled === "" ? null : enabled === "true");
    connectionDraft.value = store.connectionId;
    clusterDraft.value = store.clusterId;
    searchDraft.value = configuredStore.search;
    enabledDraft.value = configuredStore.enabled;
  },
  () => {
    void store.load();
    void configuredStore.load();
  },
);
function applyFilters(): void {
  void url.apply({
    connectionId: connectionDraft.value,
    clusterId: clusterDraft.value,
    search: configuredStore.search,
    enabled:
      configuredStore.enabled === null ? "" : String(configuredStore.enabled),
  });
}
function applyConfiguredFilters(): void {
  void url.apply({
    connectionId: store.connectionId,
    clusterId: store.clusterId,
    search: searchDraft.value,
    enabled: enabledDraft.value === null ? "" : String(enabledDraft.value),
  });
}

function openCreate(candidate: BackupTargetCandidate | null = null): void {
  if (formOpen.value && !targetForm.value?.confirmDiscard()) return;
  commands.clearResult();
  editedTarget.value = null;
  sourceCandidate.value = candidate;
  formOpen.value = true;
}

function openEdit(target: ConfiguredBackupTarget): void {
  if (formOpen.value && !targetForm.value?.confirmDiscard()) return;
  commands.clearResult();
  editedTarget.value = target;
  sourceCandidate.value = null;
  formOpen.value = true;
}

function closeForm(): void {
  formOpen.value = false;
  editedTarget.value = null;
  sourceCandidate.value = null;
}

async function saveTarget(
  body: TargetCommandRequest,
  id: string | null,
): Promise<void> {
  if (await commands.saveTarget(body, id)) {
    closeForm();
    await configuredStore.load();
  }
}

async function toggleTarget(target: ConfiguredBackupTarget): Promise<void> {
  if (
    await commands.setTargetEnabled(
      target.id,
      { expectedRevision: target.revision },
      !target.enabled,
    )
  ) {
    await configuredStore.load();
  }
}

async function reloadServerState(): Promise<void> {
  commands.clearResult();
  closeForm();
  await configuredStore.load();
}

onMounted(() => {
  void configuredStore.load();
  void store.load();
});
</script>

<template>
  <section class="data-view target-view" aria-labelledby="backup-targets-title">
    <div class="view-heading">
      <div>
        <span class="section-kicker">Backupziel-Konfiguration</span>
        <h2 id="backup-targets-title">Backup-Ziele</h2>
        <p>
          Konfigurierte Ziele und PVE-/PBS-Kandidaten aus dem automatischen
          Collector. Wähle den Speicherort und prüfe seine Verfügbarkeit.
        </p>
      </div>
      <Button
        v-if="canManage"
        type="button"
        label="Backupziel anlegen"
        icon="pi pi-plus"
        @click="openCreate()"
      />
    </div>

    <Message v-if="!canManage" severity="secondary" :closable="false">
      Diese Ansicht ist read-only. Für Änderungen wird
      <code>backup_configuration.manage</code> benötigt.
    </Message>
    <Message v-else severity="warn" :closable="false">
      Inventar-, Placement-, Kapazitäts- und Executor-Nachweise dürfen höchstens
      fünf Minuten alt sein. Die serverseitige Aktivierungsprüfung bleibt
      maßgeblich.
    </Message>

    <Message v-if="commands.success" severity="success" :closable="false">{{
      commands.success
    }}</Message>
    <Message v-if="commands.error" severity="error" :closable="false">
      {{ commands.error }}
      <span v-if="commands.conflictRevision !== null">
        Aktuelle Serverrevision: {{ commands.conflictRevision }}.
      </span>
      <span v-if="commands.blockers.length">
        Blocker: {{ commands.blockers.join(", ") }}.
      </span>
    </Message>
    <Button
      v-if="commands.conflictRevision !== null"
      type="button"
      label="Aktuellen Serverstand laden"
      icon="pi pi-refresh"
      severity="secondary"
      @click="reloadServerState"
    />

    <BackupTargetForm
      ref="targetForm"
      v-if="formOpen && canManage"
      :target="editedTarget"
      :candidate="sourceCandidate"
      :candidates="store.items"
      :pending="commands.pending"
      @submit="saveTarget"
      @cancel="closeForm"
    />

    <section
      class="configured-target-section"
      aria-labelledby="configured-targets-title"
    >
      <div class="target-section-heading">
        <div>
          <span class="section-kicker">Persistierte Konfiguration</span>
          <h3 id="configured-targets-title">Konfigurierte Backupziele</h3>
        </div>
      </div>

      <form
        class="filter-panel target-filter configured-target-filter"
        aria-label="Konfigurierte Backupziele filtern"
        @submit.prevent="applyConfiguredFilters"
      >
        <label>
          <span>Suche</span>
          <InputText
            v-model="searchDraft"
            placeholder="Name, Verbindung, Cluster oder Storage"
            autocomplete="off"
          />
        </label>
        <label>
          <span>Status</span>
          <Select
            v-model="enabledDraft"
            :options="enabledOptions"
            option-label="label"
            option-value="value"
            aria-label="Aktivierungsstatus"
          />
        </label>
        <Button
          type="submit"
          label="Filter anwenden"
          icon="pi pi-filter"
          :loading="configuredStore.loading"
        />
        <Button
          type="button"
          label="Filter zurücksetzen"
          severity="secondary"
          @click="url.apply({})"
        />
      </form>

      <AsyncState
        :loading="configuredStore.loading"
        :error="configuredStore.error"
        :empty="configuredStore.empty"
        empty-title="Keine konfigurierten Backupziele"
        empty-description="Es wurden noch keine Ziele konfiguriert oder der Filter liefert keine Treffer."
      >
        <Message
          v-if="hasIncompleteConfiguration"
          severity="warn"
          :closable="false"
        >
          Mindestens ein Ziel besitzt eine unvollständige Konfiguration und
          bleibt deshalb gesperrt.
        </Message>
        <div class="target-grid">
          <ConfiguredBackupTargetCard
            v-for="target in configuredStore.items"
            :key="target.id"
            :target="target"
            :can-manage="canManage"
            @edit="openEdit"
            @toggle="toggleTarget"
          />
        </div>
        <div
          v-if="configuredStore.page.hasMore"
          class="table-pagination configured-target-pagination"
        >
          <Button
            label="Weitere konfigurierte Ziele laden"
            icon="pi pi-angle-down"
            severity="secondary"
            :loading="configuredStore.loading"
            @click="configuredStore.loadMore()"
          />
        </div>
      </AsyncState>
    </section>

    <section
      class="candidate-target-section"
      aria-labelledby="candidate-targets-title"
    >
      <div class="target-section-heading">
        <div>
          <span class="section-kicker">Collector-Evidenz</span>
          <h3 id="candidate-targets-title">Backupziel-Kandidaten</h3>
        </div>
      </div>

      <form
        class="filter-panel target-filter"
        aria-label="Backupziel-Kandidaten filtern"
        @submit.prevent="applyFilters"
      >
        <label
          ><span>Verbindung</span
          ><PagedPicker
            :model-value="connectionDraft"
            label="Verbindung"
            :load-page="connectionPages"
            @update:model-value="
              connectionDraft = $event;
              clusterDraft = '';
            "
        /></label>
        <label
          ><span>Cluster</span
          ><PagedPicker
            v-model="clusterDraft"
            label="Cluster"
            :load-page="clusterPages"
        /></label>
        <Button
          type="submit"
          label="Filter anwenden"
          icon="pi pi-filter"
          :loading="store.loading"
        />
        <Button
          type="button"
          label="Filter zurücksetzen"
          severity="secondary"
          @click="url.apply({})"
        />
      </form>

      <AsyncState
        :loading="store.loading"
        :error="store.error"
        :empty="store.empty"
        empty-title="Keine Backupziel-Kandidaten"
        empty-description="Passe die Filter an oder warte auf den nächsten automatischen Collector-Zyklus."
      >
        <Message
          v-if="hasFreshnessBlocker"
          severity="secondary"
          :closable="false"
          class="target-freshness-note"
        >
          <strong>Freshness-Blocker:</strong> Mindestens ein Nachweis fehlt, ist
          älter als fünf Minuten oder liegt in der Zukunft. Exakt fünf Minuten
          alte Evidenz gilt noch als frisch.
        </Message>

        <div class="target-grid">
          <div
            v-for="candidate in store.items"
            :key="candidate.id"
            class="candidate-configuration-card"
          >
            <BackupTargetCandidateCard :candidate="candidate" />
            <Button
              v-if="canManage"
              type="button"
              label="Als Ziel konfigurieren"
              icon="pi pi-plus"
              severity="secondary"
              @click="openCreate(candidate)"
            />
          </div>
        </div>

        <div
          v-if="store.page.hasMore"
          class="table-pagination target-pagination"
        >
          <Button
            label="Weitere Kandidaten laden"
            icon="pi pi-angle-down"
            severity="secondary"
            :loading="store.loading"
            @click="store.loadMore()"
          />
        </div>
      </AsyncState>
    </section>
  </section>
</template>
