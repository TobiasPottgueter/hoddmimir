<script setup lang="ts">
import { computed, ref, watch } from "vue";
import FormErrors from "@/components/common/FormErrors.vue";
import QuantityInput from "@/components/common/QuantityInput.vue";
import { useUnsavedChanges } from "@/composables/useUnsavedChanges";
import Button from "primevue/button";
import Checkbox from "primevue/checkbox";
import InputNumber from "primevue/inputnumber";
import InputText from "primevue/inputtext";
import Message from "primevue/message";
import Select from "primevue/select";
import Textarea from "primevue/textarea";

import type {
  ConfiguredBackupTarget,
  ConfiguredPolicy,
  PolicyCommandRequest,
  PveClusterResource,
} from "@/api/generated/types.gen";

const props = defineProps<{
  policy: ConfiguredPolicy | null;
  targets: ConfiguredBackupTarget[];
  clusters: PveClusterResource[];
  pending: boolean;
}>();
const emit = defineEmits<{
  submit: [body: PolicyCommandRequest, id: string | null];
  cancel: [];
}>();

const displayName = ref("");
const selectedClusterId = ref("");
const targetId = ref("");
const priority = ref<number | null>(null);
const backupMode = ref<PolicyCommandRequest["backupMode"]>(null);
const compression = ref<PolicyCommandRequest["compression"]>(null);
const maximumAgeSeconds = ref("");
const bytesWrittenThreshold = ref("");
const cooldownSeconds = ref("");
const legacyMaxfiles = ref<number | null>(null);
const keepAll = ref(false);
const keepLast = ref<number | null>(null);
const keepHourly = ref<number | null>(null);
const keepDaily = ref<number | null>(null);
const keepWeekly = ref<number | null>(null);
const keepMonthly = ref<number | null>(null);
const keepYearly = ref<number | null>(null);
const retentionExecutionEnabled = ref(false);
const failureNotificationRecipients = ref("");
const errors = ref<Record<string, string>>({});
const quantityErrors = ref<Record<string, string>>({});
const validationAttempt = ref(0);
const snapshot = computed(() =>
  JSON.stringify([
    displayName.value,
    selectedClusterId.value,
    targetId.value,
    priority.value,
    backupMode.value,
    compression.value,
    maximumAgeSeconds.value,
    bytesWrittenThreshold.value,
    cooldownSeconds.value,
    legacyMaxfiles.value,
    keepAll.value,
    keepLast.value,
    keepHourly.value,
    keepDaily.value,
    keepWeekly.value,
    keepMonthly.value,
    keepYearly.value,
    retentionExecutionEnabled.value,
    failureNotificationRecipients.value,
    Object.entries(quantityErrors.value).filter(([, error]) => error),
  ]),
);
const unsaved = useUnsavedChanges(snapshot, () => props.pending);
defineExpose({ confirmDiscard: unsaved.confirmDiscard });
function cancel(): void {
  if (unsaved.confirmDiscard()) emit("cancel");
}

function targetUsesPbs(candidateTargetId: string | null | undefined): boolean {
  return props.targets.some(
    (target) => target.id === candidateTargetId && target.storageType === "pbs",
  );
}

const clusterOptions = computed(() => {
  const options = props.clusters.map((cluster) => ({
    value: cluster.id,
    connectionId: cluster.connectionId,
    label: `${cluster.connectionName} · ${cluster.displayName}`,
  }));
  if (
    props.policy !== null &&
    !options.some((option) => option.value === props.policy?.clusterId)
  ) {
    options.unshift({
      value: props.policy.clusterId,
      connectionId: props.policy.connectionId,
      label: `${props.policy.connectionName} · ${props.policy.clusterName}`,
    });
  }
  return options;
});
const selectedCluster = computed(
  () =>
    clusterOptions.value.find(
      (cluster) => cluster.value === selectedClusterId.value,
    ) ?? null,
);
const targetOptions = computed(() => {
  const options = props.targets
    .filter((target) => target.clusterId === selectedClusterId.value)
    .map((target) => ({ value: target.id, label: target.displayName }));
  if (
    props.policy?.targetId !== null &&
    props.policy?.targetId !== undefined &&
    !options.some((option) => option.value === props.policy?.targetId)
  ) {
    options.unshift({
      value: props.policy.targetId,
      label: props.policy.targetName ?? props.policy.targetId,
    });
  }
  return [{ value: "", label: "Kein Ziel (Entwurf)" }, ...options];
});
const retentionExecutionBlocked = computed(() => targetUsesPbs(targetId.value));

const modeOptions: Array<{
  label: string;
  value: PolicyCommandRequest["backupMode"];
}> = [
  { label: "Vom Backupziel übernehmen", value: null },
  { label: "Snapshot", value: "snapshot" },
  { label: "Suspend", value: "suspend" },
  { label: "Stop", value: "stop" },
];
const compressionOptions: Array<{
  label: string;
  value: PolicyCommandRequest["compression"];
}> = [
  { label: "Vom Backupziel übernehmen", value: null },
  { label: "Keine", value: "0" },
  { label: "Gzip", value: "gzip" },
  { label: "LZO", value: "lzo" },
  { label: "Zstandard", value: "zstd" },
];

watch(
  () => props.policy,
  (policy) => {
    displayName.value = policy?.displayName ?? "";
    selectedClusterId.value = policy?.clusterId ?? "";
    targetId.value = policy?.targetId ?? "";
    priority.value = policy?.priority ?? null;
    backupMode.value =
      policy?.mode === "snapshot" ||
      policy?.mode === "suspend" ||
      policy?.mode === "stop"
        ? policy.mode
        : null;
    compression.value =
      policy?.compression === "0" ||
      policy?.compression === "gzip" ||
      policy?.compression === "lzo" ||
      policy?.compression === "zstd"
        ? policy.compression
        : null;
    maximumAgeSeconds.value = policy?.maximumAgeSeconds?.toString() ?? "";
    bytesWrittenThreshold.value = policy?.bytesWrittenThreshold ?? "";
    cooldownSeconds.value = policy?.cooldownSeconds?.toString() ?? "";
    legacyMaxfiles.value = policy?.desiredRetention?.legacyMaxFiles ?? null;
    keepAll.value = policy?.desiredRetention?.keepAll ?? false;
    keepLast.value = policy?.desiredRetention?.keepLast ?? null;
    keepHourly.value = policy?.desiredRetention?.keepHourly ?? null;
    keepDaily.value = policy?.desiredRetention?.keepDaily ?? null;
    keepWeekly.value = policy?.desiredRetention?.keepWeekly ?? null;
    keepMonthly.value = policy?.desiredRetention?.keepMonthly ?? null;
    keepYearly.value = policy?.desiredRetention?.keepYearly ?? null;
    retentionExecutionEnabled.value =
      (policy?.retentionExecutionEnabled ?? false) &&
      !targetUsesPbs(policy?.targetId);
    failureNotificationRecipients.value =
      policy?.failureNotificationRecipients.join("\n") ?? "";
    errors.value = {};
    quantityErrors.value = {};
    unsaved.reset();
  },
  { immediate: true },
);

watch(retentionExecutionBlocked, (blocked) => {
  if (blocked) retentionExecutionEnabled.value = false;
});

function nullable(value: string): string | null {
  const trimmed = value.trim();
  return trimmed === "" ? null : trimmed;
}

function submit(): void {
  errors.value = { ...quantityErrors.value };
  if (displayName.value.trim() === "")
    errors.value["policy-name"] = "Ein Anzeigename ist erforderlich.";
  if (selectedCluster.value === null)
    errors.value["policy-cluster"] =
      "Verbindung und Cluster sind erforderlich.";
  const recipients = failureNotificationRecipients.value
    .split(/[\n,]/u)
    .map((address) => address.trim())
    .filter((address) => address !== "");
  if (
    recipients.length > 32 ||
    new Set(recipients.map((address) => address.toLowerCase())).size !==
      recipients.length
  ) {
    errors.value["policy-recipients"] =
      "Es sind höchstens 32 eindeutige E-Mail-Empfänger erlaubt.";
  }
  validationAttempt.value++;
  if (
    Object.values(errors.value).some(Boolean) ||
    selectedCluster.value === null ||
    props.pending
  )
    return;
  emit(
    "submit",
    {
      expectedRevision: props.policy?.revision ?? 0,
      displayName: displayName.value.trim(),
      connectionId: selectedCluster.value.connectionId,
      clusterId: selectedCluster.value.value,
      targetId: nullable(targetId.value),
      priority: priority.value,
      backupMode: backupMode.value,
      compression: compression.value,
      maximumAgeSeconds: nullable(maximumAgeSeconds.value),
      bytesWrittenThreshold: nullable(bytesWrittenThreshold.value),
      cooldownSeconds: nullable(cooldownSeconds.value),
      schedule: "collector_cycle",
      legacyMaxfiles: legacyMaxfiles.value,
      keepAll: keepAll.value
        ? true
        : [
              keepLast.value,
              keepHourly.value,
              keepDaily.value,
              keepWeekly.value,
              keepMonthly.value,
              keepYearly.value,
            ].some((value) => value !== null)
          ? false
          : null,
      keepLast: keepLast.value,
      keepHourly: keepHourly.value,
      keepDaily: keepDaily.value,
      keepWeekly: keepWeekly.value,
      keepMonthly: keepMonthly.value,
      keepYearly: keepYearly.value,
      retentionExecutionEnabled:
        !retentionExecutionBlocked.value && retentionExecutionEnabled.value,
      failureNotificationRecipients: recipients,
    },
    props.policy?.id ?? null,
  );
}
</script>

<template>
  <form
    class="configuration-form"
    aria-label="Policy konfigurieren"
    @submit.prevent="submit"
  >
    <FormErrors :errors="errors" :attempt="validationAttempt" />
    <fieldset>
      <legend>Basis</legend>
      <div class="configuration-form__grid">
        <label for="policy-name"
          ><span>Anzeigename *</span
          ><InputText
            id="policy-name"
            v-model="displayName"
            :aria-invalid="!!errors['policy-name']"
            :aria-describedby="
              errors['policy-name'] ? 'policy-name-error' : undefined
            "
          /><small
            v-if="errors['policy-name']"
            id="policy-name-error"
            class="field-error"
            >{{ errors["policy-name"] }}</small
          ></label
        >
        <label class="configuration-form__wide"
          ><span>Verbindung und Cluster aus Inventar</span
          ><Select
            v-model="selectedClusterId"
            input-id="policy-cluster"
            aria-label="Verbindung und Cluster aus Inventar"
            :aria-invalid="!!errors['policy-cluster']"
            :aria-describedby="
              errors['policy-cluster'] ? 'policy-cluster-error' : undefined
            "
            filter
            :options="clusterOptions"
            option-label="label"
            option-value="value"
            placeholder="Cluster wählen"
          /><small
            v-if="errors['policy-cluster']"
            id="policy-cluster-error"
            class="field-error"
            >{{ errors["policy-cluster"] }}</small
          ></label
        >
        <label
          ><span>Konfiguriertes Backupziel</span
          ><Select
            aria-label="Konfiguriertes Backupziel"
            v-model="targetId"
            :options="targetOptions"
            option-label="label"
            option-value="value"
        /></label>
        <label
          ><span>Priorität</span
          ><InputNumber aria-label="Priorität" v-model="priority" :min="0"
        /></label>
        <label
          ><span>Backupmodus</span
          ><Select
            aria-label="Backupmodus"
            v-model="backupMode"
            :options="modeOptions"
            option-label="label"
            option-value="value"
        /></label>
        <label
          ><span>Kompression</span
          ><Select
            aria-label="Kompression"
            v-model="compression"
            :options="compressionOptions"
            option-label="label"
            option-value="value"
        /></label>
      </div>
    </fieldset>
    <fieldset>
      <legend>Backup-Auslöser</legend>
      <p>
        Die Reihenfolge bleibt: noch nie gesichert, maximales Alter,
        Schreibvolumen. Leere Auslöser setzen keine eigene Schwelle.
      </p>
      <div class="configuration-form__grid">
        <QuantityInput
          id="policy-age"
          v-model="maximumAgeSeconds"
          label="Maximales Alter"
          kind="duration"
          :error="errors['policy-age'] ?? ''"
          @validation="quantityErrors['policy-age'] = $event"
        />
        <QuantityInput
          id="policy-bytes"
          v-model="bytesWrittenThreshold"
          label="Schreibschwelle"
          kind="bytes"
          :error="errors['policy-bytes'] ?? ''"
          @validation="quantityErrors['policy-bytes'] = $event"
        />
        <QuantityInput
          id="policy-cooldown"
          v-model="cooldownSeconds"
          label="Cooldown"
          kind="duration"
          :error="errors['policy-cooldown'] ?? ''"
          @validation="quantityErrors['policy-cooldown'] = $event"
        />
      </div>
    </fieldset>
    <fieldset>
      <legend>Benachrichtigungen</legend>
      <div class="configuration-form__grid">
        <label class="configuration-form__wide"
          ><span>Fehler-E-Mail-Empfänger</span
          ><Textarea
            v-model="failureNotificationRecipients"
            id="policy-recipients"
            :aria-invalid="!!errors['policy-recipients']"
            :aria-describedby="`policy-recipients-hint${errors['policy-recipients'] ? ' policy-recipients-error' : ''}`"
            rows="3"
            placeholder="backup@example.org, platform@example.org"
          /><small id="policy-recipients-hint"
            >Komma oder Zeilenumbruch; mindestens ein Empfänger ist zur
            Aktivierung erforderlich.</small
          >
          <small
            v-if="errors['policy-recipients']"
            id="policy-recipients-error"
            class="field-error"
            >{{ errors["policy-recipients"] }}</small
          ></label
        >
      </div>
    </fieldset>
    <details
      class="form-advanced"
      :open="!!policy?.desiredRetention || retentionExecutionEnabled"
    >
      <summary>Erweiterte Aufbewahrung</summary>
      <p>
        Ohne eigene Aufbewahrung gilt die Vorgabe des Backupziels. Die
        Ausführungsfreigabe bleibt eine separate Entscheidung.
      </p>
      <div class="configuration-form__grid">
        <label
          ><span>Legacy maxfiles</span
          ><InputNumber
            aria-label="Legacy maxfiles"
            v-model="legacyMaxfiles"
            :min="1"
        /></label>
        <label
          ><span>Letzte behalten</span
          ><InputNumber
            aria-label="Letzte behalten"
            v-model="keepLast"
            :min="1"
        /></label>
        <label
          ><span>Stündlich behalten</span
          ><InputNumber
            aria-label="Stündlich behalten"
            v-model="keepHourly"
            :min="1"
        /></label>
        <label
          ><span>Täglich behalten</span
          ><InputNumber
            aria-label="Täglich behalten"
            v-model="keepDaily"
            :min="1"
        /></label>
        <label
          ><span>Wöchentlich behalten</span
          ><InputNumber
            aria-label="Wöchentlich behalten"
            v-model="keepWeekly"
            :min="1"
        /></label>
        <label
          ><span>Monatlich behalten</span
          ><InputNumber
            aria-label="Monatlich behalten"
            v-model="keepMonthly"
            :min="1"
        /></label>
        <label
          ><span>Jährlich behalten</span
          ><InputNumber
            aria-label="Jährlich behalten"
            v-model="keepYearly"
            :min="1"
        /></label>
        <label class="configuration-form__check"
          ><Checkbox v-model="keepAll" binary /><span
            >Alle Backups behalten</span
          ></label
        >
        <label class="configuration-form__check"
          ><Checkbox
            v-model="retentionExecutionEnabled"
            binary
            :disabled="retentionExecutionBlocked"
          /><span>Retention-Ausführung aktivieren</span></label
        >
        <Message
          v-if="retentionExecutionBlocked"
          class="configuration-form__wide"
          severity="info"
          :closable="false"
        >
          Bei PBS-Backupzielen wird die Retention auf PBS verwaltet. Hoddmímir
          sendet deshalb keine löschwirksamen Retention-Parameter an vzdump.
        </Message>
      </div>
    </details>
    <div class="configuration-form__actions">
      <Button
        type="submit"
        label="Policy speichern"
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
