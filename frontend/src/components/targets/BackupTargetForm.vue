<script setup lang="ts">
import { computed, ref, watch } from "vue";
import Button from "primevue/button";
import InputNumber from "primevue/inputnumber";
import InputText from "primevue/inputtext";
import Message from "primevue/message";
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
const validationError = ref<string | null>(null);

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
    validationError.value = null;
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
  if (
    displayName.value.trim() === "" ||
    (candidate === null && target === null)
  ) {
    validationError.value =
      "Name und ein erkannter PVE-Storage sind erforderlich.";
    return;
  }
  validationError.value = null;
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
    <Message v-if="validationError" severity="error" :closable="false">{{
      validationError
    }}</Message>
    <div class="configuration-form__grid">
      <label><span>Anzeigename</span><InputText v-model="displayName" /></label>
      <label class="configuration-form__wide"
        ><span>Erkannter PVE-Storage</span
        ><Select
          v-model="selectedCandidateId"
          :options="candidateOptions"
          option-label="label"
          option-value="value"
          placeholder="Storage aus Collector-Inventar wählen"
      /></label>
      <label
        ><span>Mindestfreiplatz in Bytes</span
        ><InputText v-model="minimumFreeBytes" inputmode="numeric"
      /></label>
      <label
        ><span>Feste Parallelität</span
        ><InputNumber v-model="fixedParallelLimit" :min="1" :max="1000"
      /></label>
      <label class="configuration-form__wide"
        ><span>Erlaubte Nodes aus Storage-Evidenz</span
        ><MultiSelect
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
        severity="secondary"
        text
        @click="emit('cancel')"
      />
    </div>
  </form>
</template>
