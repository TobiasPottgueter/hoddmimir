<script setup lang="ts">
import { computed, ref, watch } from "vue";
import Button from "primevue/button";
import Checkbox from "primevue/checkbox";
import InputNumber from "primevue/inputnumber";
import InputText from "primevue/inputtext";
import FormErrors from "@/components/common/FormErrors.vue";
import QuantityInput from "@/components/common/QuantityInput.vue";
import { useUnsavedChanges } from "@/composables/useUnsavedChanges";
import MultiSelect from "primevue/multiselect";
import Select from "primevue/select";

import type {
  BackupTargetCandidate,
  ConfiguredBackupTarget,
  TargetCommandRequest,
} from "@/api/generated/types.gen";

const props = defineProps<{
  target: ConfiguredBackupTarget | null;
  candidate: BackupTargetCandidate | null;
  candidates: BackupTargetCandidate[];
  pending: boolean;
}>();
const emit = defineEmits<{
  submit: [body: TargetCommandRequest, id: string | null];
  cancel: [];
}>();

const displayName = ref("");
const selectedCandidateId = ref("");
const minimumFreeBytes = ref("");
const fixedParallelLimit = ref<number | null>(null);
const selectedAllowedNodeIds = ref<string[]>([]);
const defaultBackupMode = ref<TargetCommandRequest["defaultBackupMode"]>(null);
const defaultCompression =
  ref<TargetCommandRequest["defaultCompression"]>(null);
const defaultKeepAll = ref(false);
const defaultLegacyMaxfiles = ref<number | null>(null);
const defaultKeepLast = ref<number | null>(null);
const defaultKeepHourly = ref<number | null>(null);
const defaultKeepDaily = ref<number | null>(null);
const defaultKeepWeekly = ref<number | null>(null);
const defaultKeepMonthly = ref<number | null>(null);
const defaultKeepYearly = ref<number | null>(null);
const modeOptions = [
  { label: "Keine Vorgabe", value: null },
  { label: "Snapshot", value: "snapshot" },
  { label: "Suspend", value: "suspend" },
  { label: "Stop", value: "stop" },
];
const compressionOptions = [
  { label: "Keine Vorgabe", value: null },
  { label: "Keine Kompression", value: "0" },
  { label: "Gzip", value: "gzip" },
  { label: "LZO", value: "lzo" },
  { label: "Zstandard", value: "zstd" },
];
const errors = ref<Record<string, string>>({});
const quantityError = ref("");
const validationAttempt = ref(0);
const snapshot = computed(() =>
  JSON.stringify([
    displayName.value,
    selectedCandidateId.value,
    minimumFreeBytes.value,
    fixedParallelLimit.value,
    selectedAllowedNodeIds.value,
    defaultBackupMode.value,
    defaultCompression.value,
    defaultKeepAll.value,
    defaultLegacyMaxfiles.value,
    defaultKeepLast.value,
    defaultKeepHourly.value,
    defaultKeepDaily.value,
    defaultKeepWeekly.value,
    defaultKeepMonthly.value,
    defaultKeepYearly.value,
    quantityError.value,
  ]),
);
const unsaved = useUnsavedChanges(snapshot, () => props.pending);
defineExpose({ confirmDiscard: unsaved.confirmDiscard });
function cancel(): void {
  if (unsaved.confirmDiscard()) emit("cancel");
}

const candidateOptions = computed(() => {
  const options = props.candidates.map((candidate) => ({
    value: candidate.id,
    label: `${candidate.connectionName} · ${candidate.clusterName} · ${candidate.storageName}`,
  }));
  if (
    props.target !== null &&
    !options.some((option) => option.value === props.target?.storageId)
  ) {
    options.unshift({
      value: props.target.storageId,
      label: `${props.target.connectionName} · ${props.target.clusterName} · ${props.target.storageName}`,
    });
  }
  return options;
});
const selectedCandidate = computed(
  () =>
    props.candidates.find(
      (candidate) => candidate.id === selectedCandidateId.value,
    ) ?? null,
);
const nodeOptions = computed(() => {
  if (selectedCandidate.value !== null) {
    return selectedCandidate.value.nodes.map((node) => ({
      value: node.nodeId,
      label: node.nodeName,
      disabled: !node.configuredForStorage || node.enabled === false,
    }));
  }
  return (props.target?.allowedNodes ?? []).map((node) => ({
    value: node.id,
    label: node.name,
    disabled: false,
  }));
});
const pbsSummary = computed(() => {
  const pbs = selectedCandidate.value?.pbs;
  if (pbs !== null && pbs !== undefined) {
    return `${pbs.server} · ${pbs.datastore}${pbs.namespace ? ` · ${pbs.namespace}` : ""}`;
  }
  return props.target?.pbsDatastoreId === null || props.target === null
    ? "Kein PBS-Ziel erkannt"
    : `PBS-Datastore ${props.target.pbsDatastoreId}`;
});

watch(
  () => [props.target, props.candidate] as const,
  ([target, candidate]) => {
    displayName.value = target?.displayName ?? candidate?.storageName ?? "";
    selectedCandidateId.value = candidate?.id ?? target?.storageId ?? "";
    minimumFreeBytes.value = target?.minimumFreeBytes ?? "";
    fixedParallelLimit.value = target?.fixedParallelLimit ?? null;
    selectedAllowedNodeIds.value =
      target?.allowedNodes.map((node) => node.id) ??
      candidate?.nodes.map((node) => node.nodeId) ??
      [];
    defaultBackupMode.value = target?.defaultBackupMode ?? null;
    defaultCompression.value = target?.defaultCompression ?? null;
    defaultKeepAll.value = target?.defaultKeepAll ?? false;
    defaultLegacyMaxfiles.value = target?.defaultLegacyMaxfiles ?? null;
    defaultKeepLast.value = target?.defaultKeepLast ?? null;
    defaultKeepHourly.value = target?.defaultKeepHourly ?? null;
    defaultKeepDaily.value = target?.defaultKeepDaily ?? null;
    defaultKeepWeekly.value = target?.defaultKeepWeekly ?? null;
    defaultKeepMonthly.value = target?.defaultKeepMonthly ?? null;
    defaultKeepYearly.value = target?.defaultKeepYearly ?? null;
    errors.value = {};
    quantityError.value = "";
    unsaved.reset();
  },
  { immediate: true },
);

function nullable(value: string): string | null {
  const trimmed = value.trim();
  return trimmed === "" ? null : trimmed;
}

function submit(): void {
  const candidate = selectedCandidate.value;
  const target = props.target;
  errors.value = { "target-space": quantityError.value };
  if (displayName.value.trim() === "")
    errors.value["target-name"] = "Ein Anzeigename ist erforderlich.";
  if (candidate === null && target === null)
    errors.value["target-storage"] =
      "Name und ein erkannter PVE-Storage sind erforderlich.";
  validationAttempt.value++;
  if (Object.values(errors.value).some(Boolean) || props.pending) return;
  emit(
    "submit",
    {
      expectedRevision: target?.revision ?? 0,
      displayName: displayName.value.trim(),
      connectionId: candidate?.connectionId ?? target!.connectionId,
      clusterId: candidate?.clusterId ?? target!.clusterId,
      storageId: candidate?.id ?? target!.storageId,
      minimumFreeBytes: nullable(minimumFreeBytes.value),
      fixedParallelLimit: fixedParallelLimit.value,
      pbsConnectionId:
        candidate?.pbs?.pbsConnectionId ?? target?.pbsConnectionId ?? null,
      pbsDatastoreId:
        candidate?.pbs?.pbsDatastoreId ?? target?.pbsDatastoreId ?? null,
      pbsNamespaceId:
        candidate?.pbs?.pbsNamespaceId ?? target?.pbsNamespaceId ?? null,
      allowedNodeIds: selectedAllowedNodeIds.value,
      defaultBackupMode: defaultBackupMode.value ?? null,
      defaultCompression: defaultCompression.value ?? null,
      defaultKeepAll: defaultKeepAll.value ? true : null,
      defaultLegacyMaxfiles: defaultLegacyMaxfiles.value,
      defaultKeepLast: defaultKeepLast.value,
      defaultKeepHourly: defaultKeepHourly.value,
      defaultKeepDaily: defaultKeepDaily.value,
      defaultKeepWeekly: defaultKeepWeekly.value,
      defaultKeepMonthly: defaultKeepMonthly.value,
      defaultKeepYearly: defaultKeepYearly.value,
    },
    target?.id ?? null,
  );
}
</script>

<template>
  <form
    class="configuration-form"
    aria-label="Backupziel konfigurieren"
    @submit.prevent="submit"
  >
    <FormErrors :errors="errors" :attempt="validationAttempt" />
    <fieldset>
      <legend>Backupziel und Kapazität</legend>
      <div class="configuration-form__grid">
        <label for="target-name"
          ><span>Anzeigename *</span
          ><InputText
            id="target-name"
            v-model="displayName"
            :aria-invalid="!!errors['target-name']"
            :aria-describedby="
              errors['target-name'] ? 'target-name-error' : undefined
            "
          /><small
            v-if="errors['target-name']"
            id="target-name-error"
            class="field-error"
            >{{ errors["target-name"] }}</small
          ></label
        >
        <label class="configuration-form__wide"
          ><span>Erkannter PVE-Storage</span
          ><Select
            v-model="selectedCandidateId"
            input-id="target-storage"
            aria-label="Erkannter PVE-Storage"
            :aria-invalid="!!errors['target-storage']"
            :aria-describedby="
              errors['target-storage'] ? 'target-storage-error' : undefined
            "
            filter
            :options="candidateOptions"
            option-label="label"
            option-value="value"
            placeholder="Storage aus Collector-Inventar wählen"
          /><small
            v-if="errors['target-storage']"
            id="target-storage-error"
            class="field-error"
            >{{ errors["target-storage"] }}</small
          ></label
        >
        <QuantityInput
          id="target-space"
          v-model="minimumFreeBytes"
          label="Mindestfreiplatz"
          kind="bytes"
          :error="errors['target-space'] ?? ''"
          @validation="quantityError = $event"
        />
        <label
          ><span>Feste Parallelität</span
          ><InputNumber
            aria-label="Feste Parallelität"
            v-model="fixedParallelLimit"
            :min="1"
            :max="1000"
        /></label>
        <label class="configuration-form__wide"
          ><span>Erlaubte Nodes aus Storage-Evidenz</span
          ><MultiSelect
            aria-label="Erlaubte Nodes aus Storage-Evidenz"
            v-model="selectedAllowedNodeIds"
            :options="nodeOptions"
            option-label="label"
            option-value="value"
            option-disabled="disabled"
            display="chip"
            placeholder="Nodes wählen"
        /></label>
        <div class="configuration-form__wide">
          <strong>PBS-Zuordnung aus Collector-Evidenz</strong>
          <p>{{ pbsSummary }}</p>
        </div>
      </div>
    </fieldset>
    <fieldset>
      <legend>Backup-Vorgaben</legend>
      <div class="configuration-form__grid">
        <div class="configuration-form__wide">
          <p>
            Policies übernehmen nicht gesetzte Werte von diesem Ziel. Gastwerte
            haben Vorrang. Vor Änderungen an diesen Vorgaben müssen die
            zugehörigen Policies deaktiviert werden.
          </p>
        </div>
        <label
          ><span>Vorgabe Backup-Modus</span
          ><Select
            aria-label="Vorgabe Backup-Modus"
            v-model="defaultBackupMode"
            :options="modeOptions"
            option-label="label"
            option-value="value"
        /></label>
        <label
          ><span>Vorgabe Kompression</span
          ><Select
            aria-label="Vorgabe Kompression"
            v-model="defaultCompression"
            :options="compressionOptions"
            option-label="label"
            option-value="value"
        /></label>
      </div>
    </fieldset>
    <details
      class="form-advanced"
      :open="
        !!(
          target?.defaultKeepAll ||
          target?.defaultLegacyMaxfiles ||
          target?.defaultKeepLast ||
          target?.defaultKeepHourly ||
          target?.defaultKeepDaily ||
          target?.defaultKeepWeekly ||
          target?.defaultKeepMonthly ||
          target?.defaultKeepYearly
        )
      "
    >
      <summary>Erweiterte Aufbewahrungsvorgaben</summary>
      <div class="configuration-form__grid">
        <label
          ><span>Vorgabe Legacy maxfiles</span
          ><InputNumber
            aria-label="Vorgabe Legacy maxfiles"
            v-model="defaultLegacyMaxfiles"
            :min="1"
            :max="1000000"
        /></label>
        <label
          ><span>Vorgabe Letzte behalten</span
          ><InputNumber
            aria-label="Vorgabe Letzte behalten"
            v-model="defaultKeepLast"
            :min="1"
            :max="1000000"
        /></label>
        <label
          ><span>Vorgabe Stündlich behalten</span
          ><InputNumber
            aria-label="Vorgabe Stündlich behalten"
            v-model="defaultKeepHourly"
            :min="1"
            :max="1000000"
        /></label>
        <label
          ><span>Vorgabe Täglich behalten</span
          ><InputNumber
            aria-label="Vorgabe Täglich behalten"
            v-model="defaultKeepDaily"
            :min="1"
            :max="1000000"
        /></label>
        <label
          ><span>Vorgabe Wöchentlich behalten</span
          ><InputNumber
            aria-label="Vorgabe Wöchentlich behalten"
            v-model="defaultKeepWeekly"
            :min="1"
            :max="1000000"
        /></label>
        <label
          ><span>Vorgabe Monatlich behalten</span
          ><InputNumber
            aria-label="Vorgabe Monatlich behalten"
            v-model="defaultKeepMonthly"
            :min="1"
            :max="1000000"
        /></label>
        <label
          ><span>Vorgabe Jährlich behalten</span
          ><InputNumber
            aria-label="Vorgabe Jährlich behalten"
            v-model="defaultKeepYearly"
            :min="1"
            :max="1000000"
        /></label>
        <label class="configuration-form__check"
          ><Checkbox v-model="defaultKeepAll" binary /><span
            >Vorgabe: alle Backups behalten</span
          ></label
        >
        <p class="configuration-form__wide">
          Leere Retention-Felder geben keine Aufbewahrung vor. Legacy maxfiles,
          gezählte Regeln und „alle behalten“ sind alternative Einstellungen.
          Die Freigabe zur Retention-Ausführung erfolgt weiterhin separat in der
          Policy; PBS-Pruning bleibt bei PBS.
        </p>
      </div>
    </details>
    <div class="configuration-form__actions">
      <Button
        type="submit"
        label="Backupziel speichern"
        icon="pi pi-save"
        :loading="pending"
      />
      <Button
        type="button"
        label="Abbrechen"
        :disabled="pending"
        severity="secondary"
        text
        @click="cancel"
      />
    </div>
  </form>
</template>
