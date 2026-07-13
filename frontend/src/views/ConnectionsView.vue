<script setup lang="ts">
import { onMounted, reactive, ref } from "vue";
import Button from "primevue/button";
import Column from "primevue/column";
import DataTable from "primevue/datatable";
import Dialog from "primevue/dialog";
import InputText from "primevue/inputtext";
import Message from "primevue/message";
import Tag from "primevue/tag";
import ConnectionOnboardingWizard from "@/components/connections/ConnectionOnboardingWizard.vue";
import type {
  ConnectionDetail,
  ConnectionEndpoint,
  TlsMode,
} from "@/api/generated/types.gen";
import { useConnectionsStore } from "@/stores/connections";
import { formatUtc } from "@/composables/useFormatters";
import {
  inventoryStatusDescription,
  type OnboardingMode,
  verificationLabel,
  verificationSeverity,
} from "@/composables/useConnectionOnboarding";

const store = useConnectionsStore();
const onboardingOpen = ref(false);
const secureOperationOpen = ref(false);
const secureConnection = ref<ConnectionDetail | null>(null);
const secureEndpoint = ref<ConnectionEndpoint | null>(null);
const secureOperation = ref<Exclude<OnboardingMode, "activate">>("rotate");
const createOpen = ref(false);
const editedConnection = ref<ConnectionDetail | null>(null);
const tlsOptions = [
  { label: "System-CA", value: "system_ca" },
  { label: "Eigene CA", value: "custom_ca" },
  { label: "SHA-256-Fingerprint", value: "sha256_fingerprint" },
];
const form = reactive({
  displayName: "",
});
const tlsModeLabel = (mode: TlsMode) =>
  tlsOptions.find((option) => option.value === mode)?.label ?? mode;
const latestSuccessfulScan = (connection: ConnectionDetail) => {
  const values = connection.endpoints
    .map((value) => value.lastSuccessAt)
    .filter((value): value is string => value !== null)
    .sort();
  return values.at(-1) ?? null;
};
const versionSupportLabel = (connection: ConnectionDetail) =>
  ({
    supported: "Unterstützt",
    unsupported: "Nicht unterstützt",
    unknown: "Noch nicht erkannt",
  })[connection.versionSupportStatus];
const onboardingState = (connection: ConnectionDetail) =>
  connection.onboardingState;
const canDisableEndpoint = (connection: ConnectionDetail) =>
  connection.endpoints.filter((endpoint) => endpoint.enabled).length > 1;
function openCreate() {
  store.clearResult();
  onboardingOpen.value = true;
}
function currentConnection(connection: ConnectionDetail): ConnectionDetail {
  return (
    store.items.find((candidate) => candidate.id === connection.id) ??
    connection
  );
}
function openEdit(connection: ConnectionDetail) {
  store.clearResult();
  const current = currentConnection(connection);
  editedConnection.value = current;
  form.displayName = current.displayName;
  createOpen.value = true;
}
async function saveEdit() {
  const edited = editedConnection.value;
  if (edited === null) return;
  const saved = await store.update(edited.id, {
    expectedRevision: edited.revision,
    displayName: form.displayName,
  });
  if (saved) {
    createOpen.value = false;
    await store.load();
  }
}
async function onboardingCompleted(): Promise<void> {
  onboardingOpen.value = false;
  await store.load();
}
function openSecureOperation(
  connection: ConnectionDetail,
  operation: Exclude<OnboardingMode, "activate">,
  endpoint: ConnectionEndpoint | null = null,
): void {
  store.clearResult();
  secureConnection.value = currentConnection(connection);
  secureEndpoint.value = endpoint;
  secureOperation.value = operation;
  secureOperationOpen.value = true;
}
async function secureOperationCompleted(): Promise<void> {
  secureOperationOpen.value = false;
  secureConnection.value = null;
  secureEndpoint.value = null;
  await store.load();
}
async function disableConnection(connection: ConnectionDetail) {
  const current = currentConnection(connection);
  if (!current.enabled) return;
  if (await store.disable(current.id, { expectedRevision: current.revision }))
    await store.load();
}
async function disableEndpoint(
  connection: ConnectionDetail,
  value: ConnectionEndpoint,
) {
  const current = currentConnection(connection);
  if (
    await store.disableEndpoint(current.id, value.id, {
      expectedRevision: current.revision,
    })
  )
    await store.load();
}
async function reloadConflict() {
  const id = editedConnection.value?.id;
  if (!id) return;
  await store.select(id);
  if (store.selected) {
    if (editedConnection.value) editedConnection.value = store.selected;
    store.clearResult();
  }
}
onMounted(() => void store.load());
</script>

<template>
  <section
    class="data-view connection-view"
    aria-labelledby="connections-title"
  >
    <div class="view-heading">
      <div>
        <span class="section-kicker">PVE und PBS</span>
        <h2 id="connections-title">Verbindungen &amp; Credentials</h2>
        <p>
          Revisionierte Endpoints und API-Tokens. Secrets sind write-only und
          werden niemals wieder angezeigt.
        </p>
      </div>
      <Button
        v-if="store.canManage"
        label="Verbindung anlegen"
        icon="pi pi-plus"
        @click="openCreate"
      />
    </div>
    <Message v-if="store.error" severity="error" :closable="false"
      >{{ store.error }}
      <span v-if="store.conflictRevision !== null"
        >Aktuelle Revision: {{ store.conflictRevision }}.
        <Button
          label="Stand laden und erneut anwenden"
          size="small"
          text
          @click="reloadConflict"
        /> </span
      ><span v-if="store.blockers.length">
        Blocker: {{ store.blockers.join(", ") }}.</span
      ></Message
    >
    <Message v-if="store.success" severity="success" :closable="false">{{
      store.success
    }}</Message>
    <Message v-if="!store.canManage" severity="secondary" :closable="false"
      >Für Verbindungen wird
      <code>backup_configuration.manage</code> benötigt.</Message
    >
    <p v-if="store.loading && store.items.length === 0" role="status">
      Verbindungen werden geladen …
    </p>
    <div class="connection-grid">
      <article
        v-for="connection in store.items"
        :key="connection.id"
        class="connection-card"
      >
        <header>
          <div>
            <h3>{{ connection.displayName }}</h3>
            <span
              >{{ connection.product.toUpperCase() }} · Revision
              {{ connection.revision }}</span
            >
          </div>
          <Tag
            :value="connection.enabled ? 'Aktiv' : 'Deaktiviert'"
            :severity="connection.enabled ? 'success' : 'secondary'"
          />
        </header>
        <dl class="target-facts configured-target-facts">
          <div>
            <dt>Produkt</dt>
            <dd>
              {{
                connection.product === "pve"
                  ? "Proxmox VE"
                  : "Proxmox Backup Server"
              }}
            </dd>
          </div>
          <div>
            <dt>Erkannte Version</dt>
            <dd>
              {{ connection.detectedVersion ?? "Noch nicht erkannt" }} ·
              {{ versionSupportLabel(connection) }}
            </dd>
          </div>
          <div>
            <dt>TLS-Vertrauen</dt>
            <dd>
              {{
                connection.endpoints
                  .map((value) => tlsModeLabel(value.tlsMode))
                  .join(", ") || "Kein Endpoint"
              }}
            </dd>
          </div>
          <div>
            <dt>Letzter erfolgreicher Scan</dt>
            <dd>
              {{
                latestSuccessfulScan(connection)
                  ? formatUtc(latestSuccessfulScan(connection) as string)
                  : "Noch kein erfolgreicher Scan"
              }}
            </dd>
          </div>
          <div class="connection-onboarding-fact">
            <dt>Onboarding &amp; Inventar</dt>
            <dd>
              <Tag
                :value="
                  onboardingState(connection)
                    ? verificationLabel(onboardingState(connection)!.status)
                    : 'Nicht verifiziert eingerichtet'
                "
                :severity="
                  onboardingState(connection)
                    ? verificationSeverity(onboardingState(connection)!.status)
                    : 'danger'
                "
              />
              <small>
                {{
                  inventoryStatusDescription(
                    onboardingState(connection)?.status ?? null,
                  )
                }}
              </small>
            </dd>
          </div>
          <div>
            <dt>Sicher geprüft (UTC)</dt>
            <dd>
              {{
                onboardingState(connection)
                  ? formatUtc(onboardingState(connection)!.verifiedAt)
                  : "Kein Nachweis"
              }}
            </dd>
          </div>
          <div>
            <dt>Inventarstatus geändert (UTC)</dt>
            <dd>
              {{
                onboardingState(connection)
                  ? formatUtc(
                      onboardingState(connection)!.inventoryStatusChangedAt,
                    )
                  : "Kein Nachweis"
              }}
            </dd>
          </div>
          <div>
            <dt>Letzter automatischer Lauf</dt>
            <dd>
              {{
                onboardingState(connection)?.lastInventoryRunId ??
                "Noch kein automatischer Lauf"
              }}
            </dd>
          </div>
        </dl>
        <div class="table-actions">
          <Button
            label="Name bearbeiten"
            size="small"
            severity="secondary"
            @click="openEdit(connection)"
          />
          <Button
            :label="
              connection.enabled ? 'Deaktivieren' : 'Erneut sicher aktivieren'
            "
            size="small"
            @click="
              connection.enabled
                ? disableConnection(connection)
                : openSecureOperation(connection, 'rotate')
            "
          /><Button
            label="Verifizierten Endpoint hinzufügen"
            size="small"
            severity="secondary"
            @click="openSecureOperation(connection, 'endpoint_add')"
          /><Button
            label="Zugangsdaten sicher rotieren"
            size="small"
            severity="secondary"
            :disabled="!connection.endpoints.some((value) => value.enabled)"
            @click="openSecureOperation(connection, 'rotate')"
          />
        </div>
        <Message
          v-if="connection.enabled"
          severity="secondary"
          :closable="false"
        >
          Endpoint- und TLS-Änderungen laufen auch bei aktiven Verbindungen
          ausschließlich über die vollständige sichere Prüfung. Ein nichtletzter
          Failover-Endpoint darf separat deaktiviert werden.
        </Message>
        <h4>Endpoints</h4>
        <DataTable
          :key="`${connection.id}:${connection.revision}`"
          :value="connection.endpoints"
          data-key="id"
          ><Column field="host" header="Host" /><Column
            field="port"
            header="Port" /><Column header="TLS"
            ><template #body="{ data }">{{
              tlsModeLabel(data.tlsMode)
            }}</template></Column
          ><Column header="Letzter Erfolg"
            ><template #body="{ data }">{{
              data.lastSuccessAt ? formatUtc(data.lastSuccessAt) : "–"
            }}</template></Column
          ><Column header="Status"
            ><template #body="{ data }">{{
              data.enabled ? "Aktiv" : "Deaktiviert"
            }}</template></Column
          ><Column header="Aktionen"
            ><template #body="{ data }"
              ><div class="table-actions">
                <Button
                  label="Bearbeiten"
                  size="small"
                  text
                  @click="
                    openSecureOperation(connection, 'endpoint_update', data)
                  "
                /><Button
                  v-if="data.enabled"
                  label="Deaktivieren"
                  size="small"
                  severity="danger"
                  text
                  :disabled="!canDisableEndpoint(connection)"
                  @click="disableEndpoint(connection, data)"
                /></div></template></Column
        ></DataTable>
        <h4>Credentials</h4>
        <DataTable :value="connection.credentials"
          ><Column field="purpose" header="Zweck" /><Column
            field="principal"
            header="Principal"
          /><Column field="tokenName" header="Token" /><Column header="Secret"
            ><template #body>Konfiguriert · write-only</template></Column
          ></DataTable
        >
      </article>
    </div>
    <Message
      v-if="store.canManage && !store.loading && store.items.length === 0"
      severity="secondary"
      :closable="false"
      >Noch keine PVE-/PBS-Verbindung konfiguriert.</Message
    >
    <Button
      v-if="store.page.hasMore"
      label="Weitere Verbindungen"
      severity="secondary"
      icon="pi pi-angle-down"
      :loading="store.loading"
      @click="store.loadMore()"
    />

    <Dialog
      v-model:visible="onboardingOpen"
      modal
      class="onboarding-dialog"
      header="Verbindung sicher einrichten"
      :style="{ width: 'min(68rem, 96vw)' }"
      :dismissable-mask="false"
    >
      <ConnectionOnboardingWizard
        @completed="onboardingCompleted"
        @cancel="onboardingOpen = false"
      />
    </Dialog>
    <Dialog
      v-model:visible="secureOperationOpen"
      modal
      class="onboarding-dialog"
      header="Proxmox-Verbindung sicher prüfen"
      :style="{ width: 'min(68rem, 96vw)' }"
      :dismissable-mask="false"
      @hide="
        secureConnection = null;
        secureEndpoint = null;
      "
    >
      <ConnectionOnboardingWizard
        v-if="secureConnection"
        :operation="secureOperation"
        :connection="secureConnection"
        :endpoint="secureEndpoint"
        @completed="secureOperationCompleted"
        @cancel="secureOperationOpen = false"
      />
    </Dialog>
    <Dialog
      v-model:visible="createOpen"
      modal
      header="Verbindung bearbeiten"
      :style="{ width: '40rem' }"
      ><form class="administration-form" @submit.prevent="saveEdit">
        <label
          ><span>Name</span><InputText v-model="form.displayName" required
        /></label>
        <div class="dialog-actions">
          <Button
            label="Abbrechen"
            severity="secondary"
            text
            @click="createOpen = false"
          /><Button type="submit" label="Speichern" :loading="store.pending" />
        </div></form
    ></Dialog>
  </section>
</template>
