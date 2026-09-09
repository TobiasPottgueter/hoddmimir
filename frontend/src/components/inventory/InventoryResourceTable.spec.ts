import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";

import {
  pbsBackupGroup,
  pbsNamespace,
  pbsSnapshot,
  resource,
} from "@/test/fixtures";
import InventoryResourceTable from "./InventoryResourceTable.vue";

describe("InventoryResourceTable", () => {
  it("zeigt alle PVE-/PBS-Ressourcentypen, Kapazitäten und Warnzustände", () => {
    const wrapper = mount(InventoryResourceTable, {
      props: {
        resources: [
          resource("pve_cluster", { topology: "clustered" }),
          resource("pve_cluster"),
          resource("pve_node", { apiStatus: "online" }),
          resource("pve_node"),
          resource("pve_guest", {
            guestType: "qemu",
            vmid: 101,
            nodeName: "pve-a",
          }),
          resource("pve_guest"),
          {
            ...resource("pve_guest", {
              guestType: "lxc",
              vmid: 102,
              nodeName: "stale-node-must-not-render",
            }),
            id: "archived-guest",
            inventoryState: "archived",
            archivedAt: "2026-07-12T10:01:00.000000Z",
          },
          resource("pve_storage", {
            storageType: "pbs",
            content: ["backup"],
            disabled: true,
            supportsBackup: true,
          }),
          {
            ...resource("pve_storage", {
              disabled: false,
              supportsBackup: false,
            }),
            id: "storage-no-backup",
          },
          resource("pbs_server", {
            version: "4.2",
            memoryUsedBytes: 1024,
            memoryTotalBytes: 2048,
          }),
          resource("pbs_server"),
          resource("pbs_datastore", {
            backendType: "filesystem",
            availableBytes: 2048,
            totalBytes: 4096,
            allowsBackupWrites: false,
          }),
          {
            ...resource("pbs_datastore", {
              maintenanceMode: "read-only",
              allowsBackupWrites: true,
            }),
            id: "datastore-maintenance",
          },
          {
            ...resource("pbs_datastore", { allowsBackupWrites: true }),
            id: "datastore-ok",
          },
          pbsNamespace(),
          pbsNamespace({
            id: "66666666-6666-4666-8666-666666666666",
            parentId: null,
            displayName: "tenant/orphan",
            attributes: {
              ...pbsNamespace().attributes,
              namespacePath: "tenant/orphan",
              namespaceDepth: 2,
              parentNamespaceId: null,
            },
          }),
          pbsBackupGroup(),
          pbsSnapshot(),
          {
            ...pbsSnapshot(),
            id: "77777777-7777-4777-8777-777777777777",
            attributes: {
              ...pbsSnapshot().attributes,
              protected: false,
              sizeBytes: null,
              verificationState: "failed",
            },
          },
        ],
      },
    });

    const text = wrapper.text();
    expect(text).toContain("PVE-Cluster");
    expect(text).toContain("PVE-Node");
    expect(text).toContain("QEMU 101 · pve-a");
    expect(text).toContain("Gast – · ohne Placement");
    expect(text).toContain("LXC 102 · ohne Placement");
    expect(text).not.toContain("stale-node-must-not-render");
    expect(text).toContain("pbs · backup");
    expect(text).toContain("Deaktiviert");
    expect(text).toContain("Kein Backup-Content");
    expect(text).toContain("PBS 4.2");
    expect(text).toContain("Frei 2 KiB / 4 KiB");
    expect(text).toContain("Nur lesbar");
    expect(text).toContain("Wartung: read-only");
    expect(text).toContain("PBS-Namespace");
    expect(text).toContain("tenant/acme · Tiefe 2");
    expect(text).toContain("tenant/orphan · Parent nicht sichtbar (ACL)");
    expect(text).toContain("PBS-Backup-Gruppe");
    expect(text).toContain("VM · 101");
    expect(text).toContain("1 GiB · geschützt · verifiziert");
    expect(text).toContain("– · nicht geschützt · Verifikation fehlgeschlagen");
    expect(text).not.toMatch(/owner|fingerprint|upid|files_json|comment/i);
  });
});
