import { mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import PrimeVue from "primevue/config";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { BackupTargetCandidate } from "@/api/generated/types.gen";
import { OTHER_UUID, UUID } from "@/test/fixtures";
import { useBackupTargetsStore } from "@/stores/backupTargets";
import BackupTargetsView from "./BackupTargetsView.vue";

const candidate = {
  id: UUID,
  connectionId: UUID,
  connectionName: "PVE",
  clusterId: OTHER_UUID,
  clusterName: "cluster-a",
  storageName: "backup",
  storageType: "dir",
  shared: true,
  inventoryState: "active",
  observedAt: "2026-07-12T10:00:00.000000Z",
  canEnable: false,
  nodes: [],
  pbs: null,
  blockers: ["freshness_policy_unconfigured", "no_active_node"],
} satisfies BackupTargetCandidate;

describe("BackupTargetsView", () => {
  beforeEach(() => setActivePinia(createPinia()));

  it("zeigt fail-closed Kandidaten, Filter und Cursorpagination ohne Schreibaktionen", async () => {
    const store = useBackupTargetsStore();
    store.items = [candidate];
    store.page = { limit: 20, count: 1, hasMore: true, nextCursor: "next" };
    const load = vi.spyOn(store, "load").mockResolvedValue();
    const loadMore = vi.spyOn(store, "loadMore").mockResolvedValue();
    const wrapper = mount(BackupTargetsView, {
      global: { plugins: [PrimeVue] },
    });

    expect(load).toHaveBeenCalledOnce();
    expect(wrapper.text()).toContain("Backupziel-Kandidaten");
    expect(wrapper.text()).toContain("Nicht aktivierbar");
    expect(wrapper.text()).toContain("Freshness unresolved");
    expect(wrapper.text()).not.toMatch(
      /jetzt scannen|backup starten|speichern|aktivieren|stoppen|abbrechen/i,
    );

    await wrapper.get(".target-pagination button").trigger("click");
    expect(loadMore).toHaveBeenCalledOnce();

    const fields = wrapper.findAll("input");
    await fields[0]?.setValue(` ${UUID} `);
    await fields[1]?.setValue(` ${OTHER_UUID} `);
    await wrapper.get("form").trigger("submit");
    expect(store.connectionId).toBe(UUID);
    expect(store.clusterId).toBe(OTHER_UUID);
    expect(store.page.nextCursor).toBeNull();
    expect(load).toHaveBeenCalledTimes(2);
  });

  it.each([
    [true, null, "Daten werden geladen"],
    [
      false,
      "Die Backupziel-Projektion ist vorübergehend nicht verfügbar.",
      "vorübergehend nicht verfügbar",
    ],
    [false, null, "Keine Backupziel-Kandidaten"],
  ] as const)(
    "zeigt Loading-, Fehler- und Empty-Zustand",
    (loading, error, expected) => {
      const store = useBackupTargetsStore();
      store.loading = loading;
      store.error = error;
      store.items = [];
      vi.spyOn(store, "load").mockImplementation(
        () => new Promise<void>(() => undefined),
      );
      const wrapper = mount(BackupTargetsView, {
        global: { plugins: [PrimeVue] },
      });
      expect(wrapper.text()).toContain(expected);
    },
  );
});
