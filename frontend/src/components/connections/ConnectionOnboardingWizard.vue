<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import Button from "primevue/button";
import InputNumber from "primevue/inputnumber";
import InputText from "primevue/inputtext";
import Message from "primevue/message";
import Password from "primevue/password";
import Select from "primevue/select";
import Tag from "primevue/tag";
import Textarea from "primevue/textarea";
import type {
  ConnectionDetail,
  ConnectionEndpoint,
  OnboardingProduct,
  TlsMode as OnboardingTlsMode,
} from "@/api/generated/types.gen";
import { useConnectionOnboardingStore } from "@/stores/connectionOnboarding";
import {
  issueMessage,
  type OnboardingMode,
  useConnectionOnboarding,
  verificationLabel,
  verificationSeverity,
  warningMessage,
} from "@/composables/useConnectionOnboarding";

const props = withDefaults(
  defineProps<{
    operation?: OnboardingMode;
    connection?: ConnectionDetail | null;
    endpoint?: ConnectionEndpoint | null;
  }>(),
  { operation: "activate", connection: null, endpoint: null },
);
const emit = defineEmits<{ completed: [connectionId: string]; cancel: [] }>();
const store = useConnectionOnboardingStore();
const wizard = useConnectionOnboarding();
const copiedCommand = ref<string | null>(null);
const selectedEndpointId = ref<string | null>(null);
const isReactivation = computed(
  () => props.operation === "rotate" && props.connection?.enabled === false,
);
const isEndpointOperation = computed(() =>
  ["endpoint_add", "endpoint_update"].includes(props.operation),
);
const title = computed(() => {
  if (props.operation === "endpoint_add") {
    return "Verifizierten Failover-Endpoint hinzufügen";
  }
  if (props.operation === "endpoint_update") {
    return "Endpoint sicher ändern";
  }
  if (props.operation === "rotate") {
    return isReactivation.value
      ? "Proxmox-Verbindung erneut sicher prüfen"
      : "Proxmox-Zugangsdaten sicher rotieren";
  }
  return "Neue Proxmox-Verbindung";
});
const productOptions = [
  { label: "Proxmox VE", value: "pve" },
  { label: "Proxmox Backup Server", value: "pbs" },
];
const tlsOptions = [
  { label: "System-CA", value: "system_ca" },
  { label: "Eigene CA", value: "custom_ca" },
  { label: "SHA-256-Fingerprint", value: "sha256_fingerprint" },
];
const rotationEndpoints = computed(
  () => props.connection?.endpoints.filter((value) => value.enabled) ?? [],
);
const rotationEndpointOptions = computed(() =>
  rotationEndpoints.value.map((value) => ({
    label: `${value.host}:${value.port} · ${tlsOptions.find((option) => option.value === value.tlsMode)?.label ?? value.tlsMode}`,
    value: value.id,
  })),
);
const verificationRows = computed(() => {
  const verification = store.verification;
  if (verification === null) return [];
  return [
    ["TLS-Vertrauen", verification.tls],
    ["Produkt und Version", verification.product],
    ["Scanner-Rechte", verification.scanPermissions],
    ...(verification.backupPermissions === null
      ? []
      : [["Backup-Rechte", verification.backupPermissions]]),
    ["Lokale Aktivierung", verification.activation],
    ["Automatisches Inventar", verification.inventory],
  ];
});
const canContinueEndpoint = computed(
  () =>
    wizard.endpointValid.value &&
    (wizard.mode.value !== "rotate" || selectedEndpointId.value !== null),
);

async function chooseProduct(product: OnboardingProduct): Promise<void> {
  wizard.selectProduct(product);
  await store.loadGuidance(product);
}

function chooseTlsMode(mode: OnboardingTlsMode): void {
  wizard.selectTlsMode(mode);
}

function connectionSeed(endpoint: ConnectionEndpoint | null) {
  const connection = props.connection!;
  return {
    connectionId: connection.id,
    endpointId: endpoint?.id ?? null,
    revision: connection.revision,
    product: connection.product,
    displayName: connection.displayName,
    endpoint:
      endpoint === null
        ? null
        : {
            host: endpoint.host,
            port: endpoint.port,
            tlsMode: endpoint.tlsMode,
            customCaPem: null,
            sha256Fingerprint: endpoint.sha256Fingerprint,
          },
  };
}

function chooseRotationEndpoint(id: string): void {
  const endpoint = rotationEndpoints.value.find((value) => value.id === id);
  if (endpoint === undefined) return;
  selectedEndpointId.value = id;
  wizard.prepareConnectionOperation("rotate", connectionSeed(endpoint));
  wizard.step.value = 2;
}

async function copyCommand(id: string, command: string): Promise<void> {
  await navigator.clipboard.writeText(command);
  copiedCommand.value = id;
}

async function submit(): Promise<void> {
  try {
    switch (wizard.mode.value) {
      case "activate":
        await store.activate(wizard.activationRequest());
        break;
      case "rotate":
        await store.rotate(
          wizard.connectionId.value!,
          wizard.rotationRequest(),
        );
        break;
      case "endpoint_add":
        await store.addEndpoint(
          wizard.connectionId.value!,
          wizard.endpointMutationRequest(),
        );
        break;
      case "endpoint_update":
        await store.updateEndpoint(
          wizard.connectionId.value!,
          wizard.endpointId.value!,
          wizard.endpointMutationRequest(),
        );
        break;
    }
  } finally {
    wizard.clearSecrets();
  }
}

function finish(): void {
  emit("completed", store.result!.connectionId);
}

onMounted(async () => {
  store.reset();
  const connection = props.connection;
  const endpoints = rotationEndpoints.value;
  if (connection && props.operation === "rotate" && endpoints[0]) {
    wizard.prepareConnectionOperation("rotate", connectionSeed(endpoints[0]));
    selectedEndpointId.value = null;
  } else if (
    connection &&
    props.operation === "endpoint_update" &&
    props.endpoint
  ) {
    wizard.prepareConnectionOperation(
      "endpoint_update",
      connectionSeed(props.endpoint),
    );
  } else if (connection && props.operation === "endpoint_add") {
    wizard.prepareConnectionOperation("endpoint_add", connectionSeed(null));
  }
  await store.loadGuidance(connection?.product ?? "pve");
});
</script>

<template>
  <section class="onboarding-wizard" aria-labelledby="onboarding-title">
    <header class="onboarding-wizard__header">
      <div>
        <span class="section-kicker">Least Privilege</span>
        <h3 id="onboarding-title">{{ title }}</h3>
        <p>
          Hoddmímir zeigt sichere Einzelbefehle und prüft ausschließlich per
          Remote-Read. Es werden keine Benutzer, Rollen, Token oder ACLs durch
          die WebApp verändert.
        </p>
      </div>
      <Tag :value="`Schritt ${wizard.step.value} von 4`" severity="secondary" />
    </header>

    <ol class="onboarding-progress" aria-label="Onboarding-Fortschritt">
      <li :class="{ active: wizard.step.value === 1 }">Zielzustand</li>
      <li :class="{ active: wizard.step.value === 2 }">Endpoint &amp; TLS</li>
      <li :class="{ active: wizard.step.value === 3 }">Laufzeit-Token</li>
      <li :class="{ active: wizard.step.value === 4 }">
        {{
          isEndpointOperation
            ? "Prüfen & speichern"
            : isReactivation
              ? "Prüfen & reaktivieren"
              : "Prüfen & aktivieren"
        }}
      </li>
    </ol>

    <Message v-if="store.error" severity="error" :closable="false">
      {{ store.error }}
      <span v-if="store.conflictRevision !== null">
        Aktuelle Revision: {{ store.conflictRevision }}.
      </span>
    </Message>

    <section v-if="wizard.step.value === 1" class="onboarding-step">
      <label>
        <span>Proxmox-Produkt</span>
        <Select
          :model-value="wizard.product.value"
          :options="productOptions"
          option-label="label"
          option-value="value"
          aria-label="Proxmox-Produkt"
          :disabled="wizard.mode.value !== 'activate'"
          @update:model-value="chooseProduct($event as OnboardingProduct)"
        />
      </label>
      <Message severity="info" :closable="false">
        Führe jeden Befehl bewusst direkt auf dem Zielsystem aus. Bestehende
        abweichende Rollen oder ACLs müssen anhalten und geprüft werden. Token-
        Secrets werden nur beim Erzeugen angezeigt und gehören ausschließlich in
        die späteren write-only Felder.
      </Message>
      <p v-if="store.guidanceLoading" role="status">
        Sichere Einzelbefehle werden geladen …
      </p>
      <div v-else-if="store.guidance" class="onboarding-command-list">
        <article
          v-for="command in store.guidance.commands"
          :key="command.id"
          class="onboarding-command"
        >
          <div>
            <strong>{{ command.purpose }}</strong>
            <Tag
              :value="
                command.mutatesRemote ? 'ändert Zielzustand' : 'prüft nur'
              "
              :severity="command.mutatesRemote ? 'warn' : 'info'"
            />
          </div>
          <code>{{ command.command }}</code>
          <Button
            :label="
              copiedCommand === command.id ? 'Kopiert' : 'Befehl kopieren'
            "
            icon="pi pi-copy"
            severity="secondary"
            size="small"
            @click="copyCommand(command.id, command.command)"
          />
        </article>
        <ul v-if="store.guidance.warnings.length" class="onboarding-warnings">
          <li v-for="warning in store.guidance.warnings" :key="warning">
            {{ warning }}
          </li>
        </ul>
      </div>
    </section>

    <section v-else-if="wizard.step.value === 2" class="onboarding-step">
      <label v-if="wizard.mode.value === 'rotate'" class="onboarding-wide">
        <span>Aktivierten Endpoint auswählen</span>
        <Select
          :model-value="selectedEndpointId"
          :options="rotationEndpointOptions"
          option-label="label"
          option-value="value"
          placeholder="Endpoint bewusst auswählen"
          aria-label="Aktivierten Endpoint auswählen"
          @update:model-value="chooseRotationEndpoint($event as string)"
        />
      </label>
      <Message
        v-if="wizard.mode.value === 'rotate' && selectedEndpointId === null"
        severity="warn"
        :closable="false"
      >
        Wähle den aktivierten Endpoint, dessen vollständiger Credential-Satz
        atomar ersetzt werden soll.
      </Message>
      <div class="onboarding-form-grid">
        <label>
          <span>Anzeigename</span>
          <InputText
            v-model="wizard.form.displayName"
            :disabled="wizard.mode.value !== 'activate'"
            required
          />
        </label>
        <label>
          <span>DNS-Name oder IP-Adresse</span>
          <InputText
            v-model="wizard.form.host"
            :disabled="wizard.mode.value === 'rotate'"
            required
          />
        </label>
        <label>
          <span>Port</span>
          <InputNumber
            aria-label="Port"
            v-model="wizard.form.port"
            :disabled="wizard.mode.value === 'rotate'"
            :min="1"
            :max="65535"
          />
        </label>
        <label>
          <span>TLS-Modus</span>
          <Select
            aria-label="TLS-Modus"
            :model-value="wizard.form.tlsMode"
            :options="tlsOptions"
            option-label="label"
            option-value="value"
            :disabled="wizard.mode.value === 'rotate'"
            @update:model-value="chooseTlsMode($event as OnboardingTlsMode)"
          />
        </label>
        <label
          v-if="wizard.form.tlsMode === 'custom_ca'"
          class="onboarding-wide"
        >
          <span>CA-Zertifikat (PEM)</span>
          <Textarea v-model="wizard.form.customCaPem" rows="8" required />
        </label>
        <label
          v-if="wizard.form.tlsMode === 'sha256_fingerprint'"
          class="onboarding-wide"
        >
          <span>SHA-256-Fingerprint</span>
          <InputText
            v-model="wizard.form.sha256Fingerprint"
            :disabled="wizard.mode.value === 'rotate'"
            required
          />
        </label>
      </div>
      <Message severity="secondary" :closable="false">
        TLS-Verifikation bleibt immer aktiv. Genau ein Trust-Modus und nur das
        dazugehörige Trust-Material werden übertragen.
      </Message>
      <Message
        v-if="
          ['rotate', 'endpoint_update'].includes(wizard.mode.value) &&
          wizard.form.tlsMode === 'custom_ca' &&
          wizard.form.customCaPem === ''
        "
        severity="warn"
        :closable="false"
      >
        Das CA-Zertifikat wird nicht zurückgelesen. Gib es für die erneute
        sichere Prüfung vollständig ein.
      </Message>
    </section>

    <section v-else-if="wizard.step.value === 3" class="onboarding-step">
      <Message severity="warn" :closable="false">
        Secrets sind write-only. Nach der Übergabe an die API werden beide
        Secretfelder sofort geleert und niemals aus einer Antwort rekonstruiert.
      </Message>
      <div class="onboarding-credential-grid">
        <fieldset>
          <legend>Scanner-Token</legend>
          <label>
            <span>Scanner-Token-ID</span>
            <InputText v-model="wizard.form.scanTokenId" required />
          </label>
          <label>
            <span>Scanner-Secret</span>
            <Password
              aria-label="Scanner-Secret"
              v-model="wizard.form.scanTokenSecret"
              :feedback="false"
              toggle-mask
              autocomplete="new-password"
              required
            />
          </label>
        </fieldset>
        <fieldset v-if="wizard.requiresBackup.value">
          <legend>Backup-Worker-Token</legend>
          <label>
            <span>Backup-Token-ID</span>
            <InputText v-model="wizard.form.backupTokenId" required />
          </label>
          <label>
            <span>Backup-Secret</span>
            <Password
              aria-label="Backup-Secret"
              v-model="wizard.form.backupTokenSecret"
              :feedback="false"
              toggle-mask
              autocomplete="new-password"
              required
            />
          </label>
        </fieldset>
      </div>
      <Message
        v-if="!wizard.tokenIdsValid.value"
        severity="error"
        :closable="false"
      >
        Die Token-IDs müssen kanonisch sein und bei PVE zwei getrennte
        Identitäten verwenden.
      </Message>
    </section>

    <section v-else class="onboarding-step onboarding-verification">
      <h4>Sichere Read-only-Prüfung</h4>
      <p>
        Geprüft werden TLS, Produktversion, Rollendefinitionen sowie die
        effektive Pfad-/Privileg-Matrix. Es wird weder ein Schreibtest noch ein
        Sonderlauf des Collectors ausgelöst.
      </p>
      <div v-if="verificationRows.length" class="onboarding-verification-list">
        <div v-for="row in verificationRows" :key="row[0]">
          <span>{{ row[0] }}</span
          ><Tag
            :value="verificationLabel(row[1]!)"
            :severity="verificationSeverity(row[1]!)"
          />
        </div>
      </div>
      <ul v-if="store.issues.length" class="onboarding-issues">
        <li
          v-for="(issue, index) in store.issues"
          :key="`${issue.code}-${index}`"
        >
          {{ issueMessage(issue) }}
        </li>
      </ul>
      <Message v-if="store.result" severity="success" :closable="false">
        {{
          isEndpointOperation
            ? "Endpoint sicher geprüft und gespeichert."
            : wizard.mode.value === "rotate"
              ? isReactivation
                ? "Verbindung erneut sicher geprüft und aktiviert."
                : "Credentials sicher rotiert."
              : "Verbindung aktiviert."
        }}
        Der nächste automatische Inventarlauf ist ausstehend und startet am
        nächsten regulären Rasterpunkt. Es wird kein Sonderlauf ausgelöst.
      </Message>
      <Message
        v-if="store.verification?.warnings.length"
        severity="warn"
        :closable="false"
      >
        <span>Zusätzliche Read-only-Rechte wurden erkannt:</span>
        <ul>
          <li
            v-for="(warning, index) in store.verification.warnings"
            :key="`${warning.code}-${index}`"
          >
            {{ warningMessage(warning) }}
          </li>
        </ul>
      </Message>
    </section>

    <footer class="onboarding-actions">
      <Button
        label="Abbrechen"
        severity="secondary"
        text
        @click="emit('cancel')"
      />
      <Button
        v-if="wizard.step.value > 1 && store.result === null"
        label="Zurück"
        severity="secondary"
        @click="wizard.step.value -= 1"
      />
      <Button
        v-if="wizard.step.value === 1"
        label="Endpoint konfigurieren"
        :disabled="store.guidance === null"
        @click="wizard.step.value = 2"
      />
      <Button
        v-else-if="wizard.step.value === 2"
        label="Token eintragen"
        :disabled="!canContinueEndpoint"
        @click="wizard.step.value = 3"
      />
      <Button
        v-else-if="wizard.step.value === 3"
        label="Prüfung vorbereiten"
        :disabled="!wizard.credentialsValid.value"
        @click="wizard.step.value = 4"
      />
      <Button
        v-else-if="store.result === null"
        :label="
          isEndpointOperation
            ? 'Sicher prüfen und Endpoint speichern'
            : wizard.mode.value === 'rotate'
              ? isReactivation
                ? 'Erneut prüfen und aktivieren'
                : 'Sicher prüfen und rotieren'
              : 'Sicher prüfen und aktivieren'
        "
        icon="pi pi-shield"
        :loading="store.submitting"
        @click="submit"
      />
      <Button
        v-else
        :label="
          isEndpointOperation
            ? 'Endpoint-Einrichtung abschließen'
            : wizard.mode.value === 'rotate'
              ? isReactivation
                ? 'Reaktivierung abschließen'
                : 'Rotation abschließen'
              : 'Onboarding abschließen'
        "
        @click="finish"
      />
    </footer>
  </section>
</template>
