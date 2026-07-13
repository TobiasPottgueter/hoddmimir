import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { PolicyApi } from "@/api/policyApi";
import type {
  ConfiguredPolicy,
  PolicySelectionEntry,
} from "@/api/generated/types.gen";
import { OTHER_UUID, UUID } from "@/test/fixtures";
import { policyErrorMessage, usePoliciesStore } from "./policies";

const page = <T>(items: T[], nextCursor: string | null = null) => ({
  items,
  page: {
    limit: 20,
    count: items.length,
    hasMore: nextCursor !== null,
    nextCursor,
  },
});

const policy = {
  id: UUID,
  revision: 1,
  status: "draft",
  displayName: "Nachtlauf",
  connectionId: UUID,
  connectionName: "PVE",
  clusterId: OTHER_UUID,
  clusterName: "cluster-a",
  targetId: null,
  targetName: null,
  priority: null,
  mode: null,
  compression: null,
  maximumAgeSeconds: null,
  bytesWrittenThreshold: null,
  cooldownSeconds: null,
  schedule: null,
  desiredRetention: null,
  retentionExecutionEnabled: false,
  failureNotificationRecipients: [],
  disabledAt: null,
  canEnable: false,
  blockers: ["configuration_incomplete", "executor_evidence_missing"],
} satisfies ConfiguredPolicy;

const selection = {
  id: OTHER_UUID,
  revision: 1,
  status: "active",
  kind: "guest_override",
  scope: "guest",
  connectionId: UUID,
  clusterId: OTHER_UUID,
  nodeId: null,
  guestId: OTHER_UUID,
  subjectName: "vm-101",
  selectionValue: "include",
  mode: null,
  compression: null,
  desiredRetention: null,
  disabledAt: null,
} satisfies PolicySelectionEntry;

function api(): PolicyApi {
  return {
    getPolicies: vi
      .fn()
      .mockResolvedValueOnce(page([policy], "next"))
      .mockResolvedValue(page([policy])),
    getSelection: vi
      .fn()
      .mockResolvedValueOnce(page([selection], "selection-next"))
      .mockResolvedValue(page([selection])),
  };
}

function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (reason: unknown) => void;
  const promise = new Promise<T>((onResolve, onReject) => {
    resolve = onResolve;
    reject = onReject;
  });
  return { promise, resolve, reject };
}

describe("PoliciesStore", () => {
  beforeEach(() => setActivePinia(createPinia()));

  it("filtert und paginiert Policies mit opaque Cursor", async () => {
    const client = api();
    const store = usePoliciesStore();
    store.setSearch("  Nacht  ");
    store.setStatus("draft");
    await store.load(client);
    await store.loadMore(client);
    expect(store.items).toHaveLength(2);
    expect(client.getPolicies).toHaveBeenLastCalledWith({
      limit: 20,
      cursor: "next",
      search: "Nacht",
      status: "draft",
    });
  });

  it("lädt mehr als 20 aktivierte Policies vollständig für Operations", async () => {
    const firstPage = Array.from({ length: 20 }, (_, index) => ({
      ...policy,
      id: `${index}`.padStart(32, "0"),
      status: "enabled" as const,
      displayName: `Policy ${index}`,
    }));
    const lastPolicy = {
      ...policy,
      id: "f".repeat(32),
      status: "enabled" as const,
      displayName: "Policy 21",
    };
    const client = api();
    client.getPolicies = vi
      .fn()
      .mockResolvedValueOnce(page(firstPage, "policies-next"))
      .mockResolvedValueOnce(page([lastPolicy]));
    const store = usePoliciesStore();

    await store.loadAllEnabledForOperations(client);

    expect(store.operationItems).toHaveLength(21);
    expect(store.operationItems.at(-1)?.displayName).toBe("Policy 21");
    expect(client.getPolicies).toHaveBeenLastCalledWith({
      limit: 100,
      status: "enabled",
      cursor: "policies-next",
    });
  });

  it("begrenzt die vollständige Operations-Policy-Pagination fail-closed", async () => {
    let pageNumber = 0;
    const client = api();
    client.getPolicies = vi
      .fn()
      .mockImplementation(() =>
        Promise.resolve(page([], `operation-page-${++pageNumber}`)),
      );
    const store = usePoliciesStore();
    store.operationItems = [policy];

    await store.loadAllEnabledForOperations(client);

    expect(client.getPolicies).toHaveBeenCalledTimes(10_000);
    expect(store.operationItems).toEqual([]);
    expect(store.operationError).toContain("sichere Seitenlimit");
    expect(store.operationLoading).toBe(false);
  });

  it("verwirft verspätete vollständige Operations-Erfolge und -Fehler", async () => {
    const oldSuccess = deferred<ReturnType<typeof page<ConfiguredPolicy>>>();
    const client = api();
    client.getPolicies = vi
      .fn()
      .mockReturnValueOnce(oldSuccess.promise)
      .mockResolvedValueOnce(page([policy]));
    const store = usePoliciesStore();

    const staleSuccess = store.loadAllEnabledForOperations(client);
    await store.loadAllEnabledForOperations(client);
    oldSuccess.resolve(page([]));
    await staleSuccess;
    expect(store.operationItems).toEqual([policy]);

    const oldFailure = deferred<ReturnType<typeof page<ConfiguredPolicy>>>();
    client.getPolicies = vi
      .fn()
      .mockReturnValueOnce(oldFailure.promise)
      .mockResolvedValueOnce(page([policy]));
    const staleFailure = store.loadAllEnabledForOperations(client);
    await store.loadAllEnabledForOperations(client);
    oldFailure.reject(new Error("stale operation"));
    await staleFailure;

    expect(store.operationItems).toEqual([policy]);
    expect(store.operationError).toBeNull();
    expect(store.operationLoading).toBe(false);
  });

  it("lädt Auswahl und Guest-Overrides getrennt und cursor-paginiert", async () => {
    const client = api();
    const store = usePoliciesStore();
    await store.selectPolicy(UUID, client);
    await store.loadMoreSelection(client);
    expect(store.selectionItems).toHaveLength(2);
    expect(client.getSelection).toHaveBeenLastCalledWith(UUID, {
      limit: 20,
      cursor: "selection-next",
    });
  });

  it("lädt für Änderungen auch deaktivierte Einträge über alle Cursor-Seiten", async () => {
    const firstPage = Array.from({ length: 20 }, (_, index) => ({
      ...selection,
      id: `${index}`.padStart(32, "0"),
    }));
    const disabled = {
      ...selection,
      id: "f".repeat(32),
      status: "disabled" as const,
      disabledAt: "2026-07-13T10:00:00.000000Z",
    };
    const client = api();
    client.getSelection = vi
      .fn()
      .mockResolvedValueOnce(page(firstPage, "selection-edit-next"))
      .mockResolvedValueOnce(page([disabled]));
    const store = usePoliciesStore();

    await store.selectPolicyForEditing(UUID, client);

    expect(store.selectionItems).toHaveLength(21);
    expect(store.selectionItems.at(-1)?.status).toBe("disabled");
    expect(client.getSelection).toHaveBeenLastCalledWith(UUID, {
      limit: 100,
      cursor: "selection-edit-next",
    });
    expect(store.selectionPage.hasMore).toBe(false);
  });

  it("verwirft veraltete vollständige Auswahlantworten bei jedem Policywechsel", async () => {
    const first = deferred<ReturnType<typeof page<PolicySelectionEntry>>>();
    const second = deferred<ReturnType<typeof page<PolicySelectionEntry>>>();
    const client = api();
    client.getSelection = vi
      .fn()
      .mockReturnValueOnce(first.promise)
      .mockReturnValueOnce(second.promise);
    const store = usePoliciesStore();

    const superseded = store.selectPolicyForEditing(UUID, client);
    const current = store.selectPolicyForEditing(OTHER_UUID, client);
    second.resolve(page([selection]));
    await current;
    first.resolve(page([]));
    await superseded;

    expect(store.selectedPolicyId).toBe(OTHER_UUID);
    expect(store.selectionItems).toEqual([selection]);

    const changedWithoutRequest =
      deferred<ReturnType<typeof page<PolicySelectionEntry>>>();
    client.getSelection = vi
      .fn()
      .mockReturnValueOnce(changedWithoutRequest.promise);
    const stalePolicy = store.selectPolicyForEditing(UUID, client);
    store.selectedPolicyId = OTHER_UUID;
    changedWithoutRequest.resolve(page([]));
    await stalePolicy;

    expect(store.selectedPolicyId).toBe(OTHER_UUID);
    expect(store.selectionItems).toEqual([]);
    expect(store.selectionError).toBeNull();
  });

  it("verwirft auch verspätete Fehler der vollständigen Auswahl", async () => {
    const oldFailure =
      deferred<ReturnType<typeof page<PolicySelectionEntry>>>();
    const client = api();
    client.getSelection = vi
      .fn()
      .mockReturnValueOnce(oldFailure.promise)
      .mockResolvedValueOnce(page([selection]));
    const store = usePoliciesStore();

    const staleFailure = store.selectPolicyForEditing(UUID, client);
    await store.selectPolicyForEditing(OTHER_UUID, client);
    oldFailure.reject(new Error("stale selection edit"));
    await staleFailure;

    expect(store.selectedPolicyId).toBe(OTHER_UUID);
    expect(store.selectionItems).toEqual([selection]);
    expect(store.selectionError).toBeNull();
    expect(store.selectionLoading).toBe(false);
  });

  it("begrenzt die vollständige Auswahlregel-Pagination fail-closed", async () => {
    let pageNumber = 0;
    const client = api();
    client.getSelection = vi
      .fn()
      .mockImplementation(() =>
        Promise.resolve(page([], `selection-page-${++pageNumber}`)),
      );
    const store = usePoliciesStore();

    await store.selectPolicyForEditing(UUID, client);

    expect(client.getSelection).toHaveBeenCalledTimes(10_000);
    expect(store.selectionItems).toEqual([]);
    expect(store.selectionPage).toEqual({
      limit: 20,
      count: 0,
      hasMore: false,
      nextCursor: null,
    });
    expect(store.selectionError).toContain("sichere Seitenlimit");
    expect(store.selectionLoading).toBe(false);
  });

  it.each([400, 503] as const)("maskiert HTTP-%s-Details", async (status) => {
    const client = api();
    client.getPolicies = vi.fn().mockRejectedValue({
      httpStatus: status,
      payload: { error: { message: "SQL secret" } },
    });
    const store = usePoliciesStore();
    await store.load(client);
    expect(store.error).not.toContain("SQL secret");
    expect(store.items).toEqual([]);
  });

  it("deckt generische Fehler, leere Filter und Empty-Zustände ab", async () => {
    expect(policyErrorMessage(null)).toBe(
      "Die Daten konnten nicht geladen werden.",
    );
    expect(policyErrorMessage({ httpStatus: "bad" })).toBe(
      "Die Daten konnten nicht geladen werden.",
    );
    expect(policyErrorMessage(new Error("sichtbarer Fehler"))).toBe(
      "sichtbarer Fehler",
    );
    const store = usePoliciesStore();
    expect(store.query()).toEqual({ limit: 20 });
    expect(store.empty).toBe(true);
    expect(store.selectionEmpty).toBe(true);
    store.loading = true;
    store.selectionLoading = true;
    expect(store.empty).toBe(false);
    expect(store.selectionEmpty).toBe(false);
    store.loading = false;
    store.selectionLoading = false;
    store.setSearch("   ");
    store.setStatus(null);
    expect(store.query("cursor")).toEqual({ limit: 20, cursor: "cursor" });
  });

  it("ignoriert veraltete Policy-Antworten und Fehler atomar", async () => {
    let resolveFirst:
      ((value: ReturnType<typeof page<ConfiguredPolicy>>) => void) | undefined;
    const first = new Promise<ReturnType<typeof page<ConfiguredPolicy>>>(
      (resolve) => {
        resolveFirst = resolve;
      },
    );
    const client: PolicyApi = {
      getPolicies: vi
        .fn()
        .mockReturnValueOnce(first)
        .mockResolvedValueOnce(page([policy])),
      getSelection: vi.fn(),
    };
    const store = usePoliciesStore();
    const stale = store.load(client);
    await store.load(client);
    resolveFirst?.(page([]));
    await stale;
    expect(store.items).toEqual([policy]);

    let rejectStale: ((reason?: unknown) => void) | undefined;
    const rejected = new Promise<never>((_resolve, reject) => {
      rejectStale = reject;
    });
    client.getPolicies = vi
      .fn()
      .mockReturnValueOnce(rejected)
      .mockResolvedValueOnce(page([policy]));
    const old = store.load(client);
    await store.load(client);
    rejectStale?.(new Error("stale"));
    await old;
    expect(store.error).toBeNull();
  });

  it("überspringt unzulässiges Load-more und behandelt Selection-Fehler", async () => {
    const client = api();
    const store = usePoliciesStore();
    await store.loadMore(client);
    expect(client.getPolicies).not.toHaveBeenCalled();
    store.loading = true;
    store.page = { limit: 20, count: 1, hasMore: true, nextCursor: "next" };
    await store.loadMore(client);
    expect(client.getPolicies).not.toHaveBeenCalled();
    store.loading = false;

    await store.loadSelection(client);
    expect(client.getSelection).not.toHaveBeenCalled();
    client.getSelection = vi.fn().mockRejectedValue({ httpStatus: 503 });
    await store.selectPolicy(UUID, client);
    expect(store.selectionItems).toEqual([]);
    expect(store.selectionError).toContain("vorübergehend");
    await store.selectPolicy(UUID, client);
    expect(client.getSelection).toHaveBeenCalledTimes(2);

    store.selectionLoading = true;
    store.selectionPage = {
      limit: 20,
      count: 1,
      hasMore: true,
      nextCursor: "selection-next",
    };
    await store.loadMoreSelection(client);
    store.selectionLoading = false;
    store.selectionPage = { ...store.selectionPage, hasMore: false };
    await store.loadMoreSelection(client);
    expect(client.getSelection).toHaveBeenCalledTimes(2);
  });

  it("verwirft Selection-Antworten nach Policywechsel und paginiert Append", async () => {
    let resolveFirst:
      | ((value: ReturnType<typeof page<PolicySelectionEntry>>) => void)
      | undefined;
    const pending = new Promise<ReturnType<typeof page<PolicySelectionEntry>>>(
      (resolve) => {
        resolveFirst = resolve;
      },
    );
    const client: PolicyApi = {
      getPolicies: vi.fn(),
      getSelection: vi
        .fn()
        .mockReturnValueOnce(pending)
        .mockResolvedValueOnce(page([selection], "selection-next"))
        .mockResolvedValueOnce(page([selection])),
    };
    const store = usePoliciesStore();
    const stale = store.selectPolicy(UUID, client);
    await store.selectPolicy(OTHER_UUID, client);
    resolveFirst?.(page([]));
    await stale;
    expect(store.selectedPolicyId).toBe(OTHER_UUID);
    expect(store.selectionItems).toEqual([selection]);
    await store.loadMoreSelection(client);
    expect(store.selectionItems).toEqual([selection, selection]);
  });

  it("ignoriert auch veraltete Selection-Fehler", async () => {
    let rejectFirst: ((reason?: unknown) => void) | undefined;
    const pending = new Promise<never>((_resolve, reject) => {
      rejectFirst = reject;
    });
    const client: PolicyApi = {
      getPolicies: vi.fn(),
      getSelection: vi
        .fn()
        .mockReturnValueOnce(pending)
        .mockResolvedValueOnce(page([selection])),
    };
    const store = usePoliciesStore();
    const stale = store.selectPolicy(UUID, client);
    await store.selectPolicy(OTHER_UUID, client);
    rejectFirst?.(new Error("stale selection"));
    await stale;
    expect(store.selectionError).toBeNull();
    expect(store.selectionItems).toEqual([selection]);
  });
});
