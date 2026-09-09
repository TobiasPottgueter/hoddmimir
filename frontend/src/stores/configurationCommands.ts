import { ref } from "vue";
import { defineStore } from "pinia";

import {
  configurationApi,
  configurationCommandFailure,
  type ConfigurationCommandHeaders,
  type FullConfigurationApi,
} from "@/api/configurationApi";
import type {
  BulkConfigurationCommandRequest,
  ConfigurationCommandResult,
  PolicyCommandRequest,
  RevisionCommandRequest,
  TargetCommandRequest,
} from "@/api/generated/types.gen";
import { useAuthStore } from "@/stores/auth";

type Command = (
  api: FullConfigurationApi,
  headers: ConfigurationCommandHeaders,
) => Promise<ConfigurationCommandResult>;

const commandErrorMessages = {
  invalid: "Die Konfiguration ist ungültig. Bitte prüfe alle Eingaben.",
  permission: "Für diese Änderung fehlt die erforderliche Berechtigung.",
  conflict:
    "Die Konfiguration wurde zwischenzeitlich geändert. Lade den aktuellen Serverstand neu.",
  blocked: "Die Aktivierung wurde durch die serverseitige Prüfung blockiert.",
  unavailable:
    "Die Konfigurationsschnittstelle ist vorübergehend nicht verfügbar.",
  unknown: "Die Konfigurationsänderung konnte nicht angewendet werden.",
} as const;

export const useConfigurationCommandsStore = defineStore(
  "configuration-commands",
  () => {
    const pending = ref(false);
    const error = ref<string | null>(null);
    const success = ref<string | null>(null);
    const conflictRevision = ref<number | null>(null);
    const blockers = ref<string[]>([]);
    const lastRevision = ref<number | null>(null);

    function clearResult(): void {
      error.value = null;
      success.value = null;
      conflictRevision.value = null;
      blockers.value = [];
    }

    async function execute(
      command: Command,
      api: FullConfigurationApi = configurationApi,
      idempotencyKey: () => string = () => crypto.randomUUID(),
    ): Promise<boolean> {
      clearResult();
      const auth = useAuthStore();
      if (
        !auth.hasPermission("backup_configuration.manage") ||
        auth.csrfToken === null
      ) {
        error.value = commandErrorMessages.permission;
        return false;
      }

      pending.value = true;
      try {
        const result = await command(api, {
          idempotencyKey: idempotencyKey(),
          csrfToken: auth.csrfToken,
        });
        lastRevision.value = result.revision;
        success.value =
          result.status === "replayed"
            ? "Die bereits angewendete Änderung wurde bestätigt."
            : "Die Konfiguration wurde gespeichert.";
        return true;
      } catch (caught) {
        const failure = configurationCommandFailure(caught);
        error.value = commandErrorMessages[failure.kind];
        if (failure.kind === "conflict") {
          conflictRevision.value = failure.currentRevision;
        }
        if (failure.kind === "blocked") blockers.value = failure.blockers;
        return false;
      } finally {
        pending.value = false;
      }
    }

    function saveTarget(
      body: TargetCommandRequest,
      id: string | null = null,
      api: FullConfigurationApi = configurationApi,
      idempotencyKey?: () => string,
    ): Promise<boolean> {
      return execute(
        (client, headers) =>
          id === null
            ? client.createBackupTarget(body, headers)
            : client.updateBackupTarget(id, body, headers),
        api,
        idempotencyKey,
      );
    }

    function setTargetEnabled(
      id: string,
      body: RevisionCommandRequest,
      enabled: boolean,
      api: FullConfigurationApi = configurationApi,
      idempotencyKey?: () => string,
    ): Promise<boolean> {
      return execute(
        (client, headers) =>
          enabled
            ? client.enableBackupTarget(id, body, headers)
            : client.disableBackupTarget(id, body, headers),
        api,
        idempotencyKey,
      );
    }

    function savePolicy(
      body: PolicyCommandRequest,
      id: string | null = null,
      api: FullConfigurationApi = configurationApi,
      idempotencyKey?: () => string,
    ): Promise<boolean> {
      return execute(
        (client, headers) =>
          id === null
            ? client.createPolicy(body, headers)
            : client.updatePolicy(id, body, headers),
        api,
        idempotencyKey,
      );
    }

    function setPolicyEnabled(
      id: string,
      body: RevisionCommandRequest,
      enabled: boolean,
      api: FullConfigurationApi = configurationApi,
      idempotencyKey?: () => string,
    ): Promise<boolean> {
      return execute(
        (client, headers) =>
          enabled
            ? client.enablePolicy(id, body, headers)
            : client.disablePolicy(id, body, headers),
        api,
        idempotencyKey,
      );
    }

    function changeSelection(
      policyId: string,
      body: BulkConfigurationCommandRequest,
      operation:
        | "selection.upsert"
        | "selection.disable"
        | "guest_override.upsert"
        | "guest_override.disable",
      api: FullConfigurationApi = configurationApi,
      idempotencyKey?: () => string,
    ): Promise<boolean> {
      return execute(
        (client, headers) => {
          switch (operation) {
            case "selection.upsert":
              return client.upsertPolicySelection(policyId, body, headers);
            case "selection.disable":
              return client.disablePolicySelection(policyId, body, headers);
            case "guest_override.upsert":
              return client.upsertPolicyGuestOverrides(policyId, body, headers);
            case "guest_override.disable":
              return client.disablePolicyGuestOverrides(
                policyId,
                body,
                headers,
              );
          }
        },
        api,
        idempotencyKey,
      );
    }

    return {
      pending,
      error,
      success,
      conflictRevision,
      blockers,
      lastRevision,
      clearResult,
      execute,
      saveTarget,
      setTargetEnabled,
      savePolicy,
      setPolicyEnabled,
      changeSelection,
    };
  },
);
