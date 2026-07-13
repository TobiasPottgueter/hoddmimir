import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { FullConfigurationApi } from "@/api/configurationApi";
import type {
  BulkConfigurationCommandRequest,
  PolicyCommandRequest,
  TargetCommandRequest,
} from "@/api/generated/types.gen";
import { useAuthStore } from "@/stores/auth";
import { OTHER_UUID, UUID } from "@/test/fixtures";
import { useConfigurationCommandsStore } from "./configurationCommands";

const targetBody: TargetCommandRequest = {
  expectedRevision: 0,
  displayName: "Target",
  connectionId: UUID,
  clusterId: OTHER_UUID,
  storageId: UUID,
  minimumFreeBytes: null,
  fixedParallelLimit: null,
  pbsConnectionId: null,
  pbsDatastoreId: null,
  pbsNamespaceId: null,
  allowedNodeIds: [],
};
const policyBody: PolicyCommandRequest = {
  expectedRevision: 0,
  displayName: "Policy",
  connectionId: UUID,
  clusterId: OTHER_UUID,
  targetId: null,
  priority: null,
  backupMode: null,
  compression: null,
  maximumAgeSeconds: null,
  bytesWrittenThreshold: null,
  cooldownSeconds: null,
  schedule: "collector_cycle",
  legacyMaxfiles: null,
  keepAll: null,
  keepLast: null,
  keepHourly: null,
  keepDaily: null,
  keepWeekly: null,
  keepMonthly: null,
  keepYearly: null,
  retentionExecutionEnabled: false,
  failureNotificationRecipients: [],
};
const bulkBody: BulkConfigurationCommandRequest = {
  expectedRevision: 1,
  entries: [{ id: UUID }],
};

function api(): FullConfigurationApi {
  const result = { status: "applied", revision: 2 } as const;
  return {
    getBackupTargetCandidates: vi.fn(),
    getBackupTargets: vi.fn(),
    createBackupTarget: vi.fn().mockResolvedValue(result),
    updateBackupTarget: vi.fn().mockResolvedValue(result),
    enableBackupTarget: vi.fn().mockResolvedValue(result),
    disableBackupTarget: vi.fn().mockResolvedValue(result),
    createPolicy: vi.fn().mockResolvedValue(result),
    updatePolicy: vi.fn().mockResolvedValue(result),
    enablePolicy: vi.fn().mockResolvedValue(result),
    disablePolicy: vi.fn().mockResolvedValue(result),
    upsertPolicySelection: vi.fn().mockResolvedValue(result),
    disablePolicySelection: vi.fn().mockResolvedValue(result),
    upsertPolicyGuestOverrides: vi.fn().mockResolvedValue(result),
    disablePolicyGuestOverrides: vi.fn().mockResolvedValue(result),
  };
}

describe("ConfigurationCommandsStore", () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    useAuthStore().$patch({
      principal: {
        id: UUID,
        username: "admin",
        permissions: ["backup_configuration.manage"],
      },
      csrfToken: "csrf",
      initialized: true,
    });
  });

  it("führt alle Ziel-, Policy-, Auswahl- und Override-Befehle revisioniert aus", async () => {
    const client = api();
    client.updateBackupTarget = vi
      .fn()
      .mockResolvedValue({ status: "replayed", revision: 3 });
    const store = useConfigurationCommandsStore();
    const key = () => "idempotency";

    await expect(store.saveTarget(targetBody, null, client)).resolves.toBe(
      true,
    );
    const targetUpdate = { ...targetBody, expectedRevision: 1 };
    await expect(
      store.saveTarget(targetUpdate, UUID, client, key),
    ).resolves.toBe(true);
    expect(store.success).toContain("bereits angewendete");
    await store.setTargetEnabled(
      UUID,
      { expectedRevision: 1 },
      true,
      client,
      key,
    );
    await store.setTargetEnabled(
      UUID,
      { expectedRevision: 1 },
      false,
      client,
      key,
    );
    await store.savePolicy(policyBody, null, client, key);
    const policyUpdate = { ...policyBody, expectedRevision: 1 };
    await store.savePolicy(policyUpdate, UUID, client, key);
    await store.setPolicyEnabled(
      UUID,
      { expectedRevision: 1 },
      true,
      client,
      key,
    );
    await store.setPolicyEnabled(
      UUID,
      { expectedRevision: 1 },
      false,
      client,
      key,
    );
    for (const operation of [
      "selection.upsert",
      "selection.disable",
      "guest_override.upsert",
      "guest_override.disable",
    ] as const) {
      await store.changeSelection(UUID, bulkBody, operation, client, key);
    }

    expect(client.createBackupTarget).toHaveBeenCalledWith(
      targetBody,
      expect.objectContaining({
        idempotencyKey: expect.any(String),
        csrfToken: "csrf",
      }),
    );
    expect(client.updateBackupTarget).toHaveBeenCalledWith(
      UUID,
      targetUpdate,
      expect.any(Object),
    );
    expect(client.createPolicy).toHaveBeenCalledWith(
      policyBody,
      expect.any(Object),
    );
    expect(client.updatePolicy).toHaveBeenCalledWith(
      UUID,
      policyUpdate,
      expect.any(Object),
    );
    expect(client.disablePolicyGuestOverrides).toHaveBeenCalledOnce();
    expect(store.lastRevision).toBe(2);
    expect(store.pending).toBe(false);
  });

  it("verweigert Befehle ohne Permission oder Memory-only-CSRF", async () => {
    const client = api();
    const auth = useAuthStore();
    auth.principal = {
      id: UUID,
      username: "viewer",
      permissions: ["inventory.read"],
    };
    const store = useConfigurationCommandsStore();
    await expect(
      store.saveTarget(targetBody, null, client, () => "key"),
    ).resolves.toBe(false);
    expect(client.createBackupTarget).not.toHaveBeenCalled();

    auth.principal.permissions = ["backup_configuration.manage"];
    auth.csrfToken = null;
    await expect(
      store.saveTarget(targetBody, null, client, () => "key"),
    ).resolves.toBe(false);
    expect(store.error).toContain("Berechtigung");
  });

  it.each([
    [400, {}, "ungültig", null, []],
    [403, {}, "Berechtigung", null, []],
    [409, { error: { currentRevision: 7 } }, "Serverstand", 7, []],
    [
      422,
      { error: { blockers: ["executor_evidence_missing"] } },
      "serverseitige Prüfung",
      null,
      ["executor_evidence_missing"],
    ],
    [503, {}, "vorübergehend", null, []],
    [500, {}, "konnte nicht", null, []],
  ] as const)(
    "projiziert HTTP-%s fail-closed",
    async (httpStatus, payload, message, conflictRevision, blockers) => {
      const client = api();
      client.createBackupTarget = vi
        .fn()
        .mockRejectedValue({ httpStatus, payload });
      const store = useConfigurationCommandsStore();

      await expect(
        store.saveTarget(targetBody, null, client, () => "key"),
      ).resolves.toBe(false);

      expect(store.error).toContain(message);
      expect(store.conflictRevision).toBe(conflictRevision);
      expect(store.blockers).toEqual(blockers);
      expect(store.pending).toBe(false);
      store.clearResult();
      expect(store.error).toBeNull();
      expect(store.success).toBeNull();
    },
  );
});
