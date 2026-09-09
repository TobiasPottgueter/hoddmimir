import { beforeEach, describe, expect, it, vi } from "vitest";
import { createPinia, setActivePinia } from "pinia";
import { inventoryApi } from "@/api/inventoryApi";
import { policyApi } from "@/api/policyApi";
import { configurationApi } from "@/api/configurationApi";
import { connectionApi } from "@/api/connectionApi";
import { useAuthStore } from "@/stores/auth";
import { resource, UUID } from "@/test/fixtures";
import {
  connectionPages,
  namespaceParentPages,
  resourceLabel,
  policyPages,
  targetPages,
} from "./usePickerPages";
const page = (kind: Parameters<typeof resource>[0]) => ({
  items: [resource(kind)],
  page: { limit: 100, count: 1, hasMore: false, nextCursor: null },
});
describe("Named filter choices", () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.restoreAllMocks();
  });
  it("uses read-only inventory for viewers and carries pagination from PVE to PBS", async () => {
    const read = vi
      .spyOn(inventoryApi, "getResources")
      .mockResolvedValueOnce(page("pve_cluster"))
      .mockResolvedValueOnce(page("pbs_server"));
    const admin = vi.spyOn(connectionApi, "list");
    const first = await connectionPages();
    const second = await connectionPages(first.page.nextCursor!);
    expect(admin).not.toHaveBeenCalled();
    expect(read.mock.calls.map((call) => call[0].kind)).toEqual([
      "pve_cluster",
      "pbs_server",
    ]);
    expect(first.items[0]!.id).toBe(UUID);
    expect(second.page.hasMore).toBe(false);
  });
  it("shows configured connections to managers, including those without inventory", async () => {
    useAuthStore().$patch({
      principal: {
        id: UUID,
        username: "test",
        permissions: ["backup_configuration.manage"],
      },
    });
    const list = vi.spyOn(connectionApi, "list").mockResolvedValue({
      items: [],
      page: { count: 0, limit: 100, hasMore: false, nextCursor: null },
    });
    await connectionPages("cursor");
    expect(list).toHaveBeenCalledWith({ limit: 100, cursor: "cursor" });
  });
  it("offers both datastore and nested namespace parents in the connection context", async () => {
    const read = vi
      .spyOn(inventoryApi, "getResources")
      .mockResolvedValueOnce(page("pbs_datastore"))
      .mockResolvedValueOnce(page("pbs_namespace"));
    const load = namespaceParentPages({
      connectionId: UUID,
      inventoryState: "archived",
    });
    const first = await load();
    const second = await load(first.page.nextCursor!);
    expect(read.mock.calls.map((call) => call[0])).toEqual([
      {
        kind: "pbs_datastore",
        connectionId: UUID,
        inventoryState: "archived",
        limit: 100,
      },
      {
        kind: "pbs_namespace",
        connectionId: UUID,
        inventoryState: "archived",
        limit: 100,
      },
    ]);
    expect(second.page.hasMore).toBe(false);
    expect(second.items[0]!.label).toContain("Namespace");
  });
  it("disambiguates guests with VMID, node, connection and immutable ID", () => {
    const label = resourceLabel(
      resource("pve_guest", {
        guestType: "qemu",
        vmid: 101,
        nodeName: "node-a",
      }) as Extract<ReturnType<typeof resource>, { kind: "pve_guest" }>,
    );
    expect(label).toContain("QEMU 101");
    expect(label).toContain("node-a");
    expect(label).toContain(UUID);
  });
  it("keeps all continuation pages for viewer connections and namespace parents", async () => {
    const read = vi.spyOn(inventoryApi, "getResources");
    for (const [loader, firstKind, secondKind, prefix, nextPrefix] of [
      [connectionPages, "pve_cluster", "pbs_server", "pve:", "pbs:"],
      [
        namespaceParentPages({ connectionId: UUID }),
        "pbs_datastore",
        "pbs_namespace",
        "datastore:",
        "namespace:",
      ],
    ] as const) {
      read.mockReset();
      read
        .mockResolvedValueOnce({
          ...page(firstKind),
          page: { limit: 100, count: 1, hasMore: true, nextCursor: "page2" },
        })
        .mockResolvedValueOnce(page(firstKind))
        .mockResolvedValueOnce({
          ...page(secondKind),
          page: { limit: 100, count: 1, hasMore: true, nextCursor: "last" },
        })
        .mockResolvedValueOnce(page(secondKind));
      let result = await loader();
      expect(result.page.nextCursor).toBe(prefix + "page2");
      result = await loader(result.page.nextCursor!);
      expect(read.mock.calls[1]![0]).toMatchObject({
        kind: firstKind,
        cursor: "page2",
      });
      result = await loader(result.page.nextCursor!);
      expect(result.page.nextCursor).toBe(nextPrefix + "last");
      result = await loader(result.page.nextCursor!);
      expect(read.mock.calls[3]![0]).toMatchObject({
        kind: secondKind,
        cursor: "last",
      });
      expect(result.page.hasMore).toBe(false);
    }
    expect(
      resourceLabel(
        resource("pve_guest", { guestType: "lxc", vmid: 202, nodeName: null }),
      ),
    ).toContain("LXC 202 · Node unbekannt");
  });
  it("loads configured names and IDs with and without continuation for every picker", async () => {
    useAuthStore().$patch({
      principal: {
        id: UUID,
        username: "test",
        permissions: ["backup_configuration.manage"],
      },
    });
    // These providers project only identity and display name from each API row.
    const result = {
      items: [{ id: UUID, displayName: "Daily" }],
      page: { limit: 100, count: 1, hasMore: true, nextCursor: "next" },
    };
    const connections = vi
      .spyOn(connectionApi, "list")
      .mockResolvedValue(
        result as Awaited<ReturnType<typeof connectionApi.list>>,
      );
    const policies = vi
      .spyOn(policyApi, "getPolicies")
      .mockResolvedValue(
        result as Awaited<ReturnType<typeof policyApi.getPolicies>>,
      );
    const targets = vi
      .spyOn(configurationApi, "getBackupTargets")
      .mockResolvedValue(
        result as Awaited<ReturnType<typeof configurationApi.getBackupTargets>>,
      );
    for (const [load, request] of [
      [connectionPages, connections],
      [policyPages, policies],
      [targetPages, targets],
    ] as const) {
      expect((await load()).items).toEqual([
        { id: UUID, label: `Daily · ${UUID}` },
      ]);
      expect(request).toHaveBeenLastCalledWith({ limit: 100 });
      expect((await load("next")).page.nextCursor).toBe("next");
      expect(request).toHaveBeenLastCalledWith({ limit: 100, cursor: "next" });
    }
  });
});
