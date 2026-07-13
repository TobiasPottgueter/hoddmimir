<script setup lang="ts">
import { computed, ref } from "vue";
import Button from "primevue/button";
import InputNumber from "primevue/inputnumber";
import Message from "primevue/message";
import MultiSelect from "primevue/multiselect";
import Select from "primevue/select";
import Tag from "primevue/tag";

import type {
  BulkConfigurationCommandRequest,
  ConfiguredPolicy,
  GuestType,
  PolicyCommandRequest,
  PolicySelectionEntry,
  PveGuestResource,
  PveNodeResource,
} from "@/api/generated/types.gen";
import { policyScopeLabel, retentionSummary } from "@/composables/usePolicies";

type Operation =
  | "selection.upsert"
  | "selection.disable"
  | "guest_override.upsert"
  | "guest_override.disable";

const props = defineProps<{
  policy: ConfiguredPolicy;
  entries: PolicySelectionEntry[];
  nodes: PveNodeResource[];
  guests: PveGuestResource[];
  canManage: boolean;
  pending: boolean;
}>();
const emit = defineEmits<{
  change: [
    operation: Operation,
    entries: BulkConfigurationCommandRequest["entries"],
  ];
}>();

const scope = ref<PolicySelectionEntry["scope"]>("global");
const selectionValue = ref("include");
const nodeIds = ref<string[]>([]);
const guestIds = ref<string[]>([]);
const guestType = ref<GuestType>("qemu");
const overrideGuestId = ref("");
const overrideGuestType = ref<GuestType>("qemu");
const overrideMode = ref<PolicyCommandRequest["backupMode"]>(null);
const overrideCompression = ref<PolicyCommandRequest["compression"]>(null);
const overrideKeepLast = ref<number | null>(null);
const validationError = ref<string | null>(null);

const assignments = computed(() =>
  props.entries.filter((entry) => entry.kind === "assignment"),
);
const overrides = computed(() =>
  props.entries.filter((entry) => entry.kind === "guest_override"),
);
const nodeOptions = computed(() =>
  props.nodes.map((node) => ({ value: node.id, label: node.displayName })),
);
const guestOptions = computed(() =>
  props.guests
    .filter((guest) => guest.attributes.guestType === guestType.value)
    .map((guest) => ({
      value: guest.id,
      label: `${guest.attributes.guestType.toUpperCase()} ${guest.attributes.vmid} · ${guest.displayName}`,
    })),
);
const overrideGuestOptions = computed(() =>
  props.guests
    .filter((guest) => guest.attributes.guestType === overrideGuestType.value)
    .map((guest) => ({
      value: guest.id,
      label: `${guest.attributes.guestType.toUpperCase()} ${guest.attributes.vmid} · ${guest.displayName}`,
    })),
);
const scopeOptions: Array<{
  label: string;
  value: PolicySelectionEntry["scope"];
}> = [
  { label: "Global", value: "global" },
  { label: "Verbindung", value: "connection" },
  { label: "Cluster", value: "cluster" },
  { label: "Node", value: "node" },
  { label: "Gast", value: "guest" },
];
const guestTypeOptions: Array<{ label: string; value: GuestType }> = [
  { label: "QEMU-VM", value: "qemu" },
  { label: "LXC-Container", value: "lxc" },
];
const modeOptions: Array<{
  label: string;
  value: PolicyCommandRequest["backupMode"];
}> = [
  { label: "Policy-Standard", value: null },
  { label: "Snapshot", value: "snapshot" },
  { label: "Suspend", value: "suspend" },
  { label: "Stop", value: "stop" },
];
const compressionOptions: Array<{
  label: string;
  value: PolicyCommandRequest["compression"];
}> = [
  { label: "Policy-Standard", value: null },
  { label: "Keine", value: "0" },
  { label: "Gzip", value: "gzip" },
  { label: "LZO", value: "lzo" },
  { label: "Zstandard", value: "zstd" },
];

function submitAssignment(): void {
  if (scope.value === "node" && nodeIds.value.length === 0) {
    validationError.value =
      "Für den Node-Scope ist mindestens ein Node erforderlich.";
    return;
  }
  if (scope.value === "guest" && guestIds.value.length === 0) {
    validationError.value =
      "Für den Gast-Scope ist mindestens ein Gast erforderlich.";
    return;
  }
  const subjectIds =
    scope.value === "node"
      ? nodeIds.value
      : scope.value === "guest"
        ? guestIds.value
        : [null];
  if (subjectIds.length > 500) {
    validationError.value =
      "Eine Massenaktion darf maximal 500 Einträge enthalten.";
    return;
  }
  validationError.value = null;
  emit(
    "change",
    "selection.upsert",
    subjectIds.map((subjectId) => ({
      id:
        assignments.value.find(
          (entry) =>
            entry.scope === scope.value &&
            (scope.value === "node"
              ? entry.nodeId === subjectId
              : scope.value === "guest"
                ? entry.guestId === subjectId
                : true),
        )?.id ?? crypto.randomUUID(),
      scope: scope.value,
      ...(scope.value === "connection"
        ? { subjectConnectionId: props.policy.connectionId }
        : {}),
      ...(scope.value === "cluster"
        ? {
            subjectConnectionId: props.policy.connectionId,
            subjectClusterId: props.policy.clusterId,
          }
        : {}),
      ...(scope.value === "node"
        ? {
            subjectConnectionId: props.policy.connectionId,
            subjectClusterId: props.policy.clusterId,
            nodeId: subjectId!,
          }
        : {}),
      ...(scope.value === "guest"
        ? {
            subjectConnectionId: props.policy.connectionId,
            subjectClusterId: props.policy.clusterId,
            guestId: subjectId!,
          }
        : {}),
      selectionValue: selectionValue.value,
    })),
  );
}

function submitOverride(): void {
  if (overrideGuestId.value.trim() === "") {
    validationError.value =
      "Für einen Guest-Override ist eine Gast-ID erforderlich.";
    return;
  }
  validationError.value = null;
  emit("change", "guest_override.upsert", [
    {
      id:
        overrides.value.find(
          (entry) => entry.guestId === overrideGuestId.value.trim(),
        )?.id ?? crypto.randomUUID(),
      guestId: overrideGuestId.value.trim(),
      backupMode: overrideMode.value,
      compression: overrideCompression.value,
      legacyMaxfiles: null,
      keepAll: null,
      keepLast: overrideKeepLast.value,
      keepHourly: null,
      keepDaily: null,
      keepWeekly: null,
      keepMonthly: null,
      keepYearly: null,
    },
  ]);
}

function editAssignment(entry: PolicySelectionEntry): void {
  scope.value = entry.scope;
  selectionValue.value = entry.selectionValue ?? "include";
  nodeIds.value = entry.nodeId === null ? [] : [entry.nodeId];
  guestIds.value = entry.guestId === null ? [] : [entry.guestId];
  if (entry.guestId !== null) {
    guestType.value =
      props.guests.find((guest) => guest.id === entry.guestId)?.attributes
        .guestType ?? "qemu";
  }
  validationError.value = null;
}

function editOverride(entry: PolicySelectionEntry): void {
  if (entry.guestId === null) return;
  overrideGuestId.value = entry.guestId;
  overrideGuestType.value =
    props.guests.find((guest) => guest.id === entry.guestId)?.attributes
      .guestType ?? "qemu";
  overrideMode.value = entry.mode as PolicyCommandRequest["backupMode"];
  overrideCompression.value =
    entry.compression as PolicyCommandRequest["compression"];
  overrideKeepLast.value = entry.desiredRetention?.keepLast ?? null;
  validationError.value = null;
}

function disable(operation: Operation, id: string): void {
  emit("change", operation, [{ id }]);
}
</script>

<template>
  <section class="selection-editor" aria-labelledby="selection-editor-title">
    <header>
      <div>
        <span class="section-kicker"
          >Bounded Massenaktion · maximal 500 Einträge</span
        >
        <h4 id="selection-editor-title">
          Auswahlhierarchie und Guest-Overrides
        </h4>
      </div>
      <Tag value="QEMU und LXC" severity="info" />
    </header>

    <Message v-if="validationError" severity="error" :closable="false">{{
      validationError
    }}</Message>
    <Message severity="secondary" :closable="false">
      Ein explizites Ausschließen gewinnt immer vor geerbten Includes. Global,
      Verbindung und Cluster werden aus der aktuellen Policy gebunden; Node und
      Gast benötigen eine konkrete Inventar-ID.
    </Message>

    <div v-if="canManage" class="selection-editor__forms">
      <form
        aria-label="Auswahlregel konfigurieren"
        @submit.prevent="submitAssignment"
      >
        <h5>Auswahlregel</h5>
        <label
          ><span>Ebene</span
          ><Select
            v-model="scope"
            :options="scopeOptions"
            option-label="label"
            option-value="value"
        /></label>
        <p v-if="scope === 'global'">Global für diese Policy</p>
        <p v-else-if="scope === 'connection'">
          Verbindung {{ policy.connectionName }}
        </p>
        <p v-else-if="scope === 'cluster'">Cluster {{ policy.clusterName }}</p>
        <label v-else-if="scope === 'node'"
          ><span>Node aus Inventar (Mehrfachauswahl)</span
          ><MultiSelect
            v-model="nodeIds"
            :options="nodeOptions"
            option-label="label"
            option-value="value"
            display="chip"
            filter
            :selection-limit="500"
            placeholder="Nodes wählen"
        /></label>
        <template v-else>
          <label
            ><span>Gasttyp</span
            ><Select
              v-model="guestType"
              :options="guestTypeOptions"
              option-label="label"
              option-value="value"
          /></label>
          <label
            ><span>Gast aus Inventar (Mehrfachauswahl)</span
            ><MultiSelect
              v-model="guestIds"
              :options="guestOptions"
              option-label="label"
              option-value="value"
              display="chip"
              filter
              :selection-limit="500"
              placeholder="QEMU-VMs oder LXC-Container wählen"
          /></label>
        </template>
        <label
          ><span>Entscheidung</span
          ><Select
            v-model="selectionValue"
            :options="[
              { label: 'Einschließen', value: 'include' },
              { label: 'Explizit ausschließen', value: 'exclude' },
            ]"
            option-label="label"
            option-value="value"
        /></label>
        <Button
          type="submit"
          label="Auswahlregel speichern"
          icon="pi pi-save"
          :loading="pending"
        />
      </form>

      <form
        aria-label="Guest-Override konfigurieren"
        @submit.prevent="submitOverride"
      >
        <h5>Guest-Override</h5>
        <label
          ><span>Gasttyp</span
          ><Select
            v-model="overrideGuestType"
            :options="guestTypeOptions"
            option-label="label"
            option-value="value"
        /></label>
        <label
          ><span>Gast aus Inventar</span
          ><Select
            v-model="overrideGuestId"
            :options="overrideGuestOptions"
            option-label="label"
            option-value="value"
            placeholder="QEMU-VM oder LXC-Container wählen"
        /></label>
        <label
          ><span>Backupmodus</span
          ><Select
            v-model="overrideMode"
            :options="modeOptions"
            option-label="label"
            option-value="value"
        /></label>
        <label
          ><span>Kompression</span
          ><Select
            v-model="overrideCompression"
            :options="compressionOptions"
            option-label="label"
            option-value="value"
        /></label>
        <label
          ><span>Letzte behalten</span
          ><InputNumber v-model="overrideKeepLast" :min="1"
        /></label>
        <Button
          type="submit"
          label="Guest-Override speichern"
          icon="pi pi-save"
          :loading="pending"
        />
      </form>
    </div>

    <div class="policy-selection-grid">
      <section>
        <h5>Aktive Zuweisungen</h5>
        <p v-if="assignments.length === 0">Keine Auswahlregeln vorhanden.</p>
        <article
          v-for="entry in assignments"
          :key="entry.id"
          class="policy-selection-card"
        >
          <header>
            <strong>{{
              entry.subjectName ?? policyScopeLabel(entry.scope)
            }}</strong>
            <Tag
              :value="
                entry.selectionValue === 'exclude'
                  ? 'Explizit ausgeschlossen'
                  : 'Eingeschlossen'
              "
              :severity="
                entry.selectionValue === 'exclude' ? 'danger' : 'success'
              "
            />
          </header>
          <small
            >{{ policyScopeLabel(entry.scope) }} · Revision
            {{ entry.revision }}</small
          >
          <Tag
            :value="entry.status === 'active' ? 'Aktiv' : 'Deaktiviert'"
            :severity="entry.status === 'active' ? 'success' : 'secondary'"
          />
          <Button
            v-if="canManage"
            type="button"
            :label="
              entry.status === 'active'
                ? 'Regel bearbeiten'
                : 'Regel reaktivieren'
            "
            severity="secondary"
            text
            :loading="pending"
            @click="editAssignment(entry)"
          />
          <Button
            v-if="canManage && entry.status === 'active'"
            type="button"
            label="Regel deaktivieren"
            severity="secondary"
            text
            :loading="pending"
            @click="disable('selection.disable', entry.id)"
          />
        </article>
      </section>
      <section>
        <h5>Guest-Overrides</h5>
        <p v-if="overrides.length === 0">Keine Guest-Overrides vorhanden.</p>
        <article
          v-for="entry in overrides"
          :key="entry.id"
          class="policy-selection-card"
        >
          <header>
            <strong>{{ entry.subjectName ?? "Unbenannter Gast" }}</strong
            ><Tag value="Guest-Override" severity="warn" />
          </header>
          <small
            >{{ entry.mode ?? "Policy-Modus" }} ·
            {{ entry.compression ?? "Policy-Kompression" }} ·
            {{ retentionSummary(entry.desiredRetention) }}</small
          >
          <Tag
            :value="entry.status === 'active' ? 'Aktiv' : 'Deaktiviert'"
            :severity="entry.status === 'active' ? 'success' : 'secondary'"
          />
          <Button
            v-if="canManage"
            type="button"
            :label="
              entry.status === 'active'
                ? 'Override bearbeiten'
                : 'Override reaktivieren'
            "
            severity="secondary"
            text
            :loading="pending"
            @click="editOverride(entry)"
          />
          <Button
            v-if="canManage && entry.status === 'active'"
            type="button"
            label="Override deaktivieren"
            severity="secondary"
            text
            :loading="pending"
            @click="disable('guest_override.disable', entry.id)"
          />
        </article>
      </section>
    </div>
  </section>
</template>
