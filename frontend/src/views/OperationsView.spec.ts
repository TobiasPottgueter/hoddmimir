import { mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import PrimeVue from "primevue/config";
import { beforeEach, describe, expect, it, vi } from "vitest";

import {
  collectorRun,
  collectorScope,
  collectorStatus,
  UUID,
} from "@/test/fixtures";
import { useOperationsStore } from "@/stores/operations";
import OperationsView from "./OperationsView.vue";

describe("OperationsView", () => {
  beforeEach(() => setActivePinia(createPinia()));

  it("zeigt Heartbeat, Lauf- und Berechtigungszustände ausschließlich lesend", async () => {
    const store = useOperationsStore();
    store.status = {
      ...collectorStatus,
      heartbeat: { ...collectorStatus.heartbeat!, fresh: false },
    };
    store.runs = [
      collectorRun,
      { ...collectorRun, id: "partial", status: "partial" },
      { ...collectorRun, id: "failed", status: "failed" },
      {
        ...collectorRun,
        id: "abandoned",
        status: "abandoned",
        finishedAt: null,
      },
    ];
    store.runsPage = {
      limit: 25,
      count: 4,
      hasMore: true,
      nextCursor: "next",
    };
    store.selectedRunId = UUID;
    store.scopes = [
      collectorScope,
      { ...collectorScope, scopeKey: "node-a", status: "partial" },
    ];
    store.scopesPage = {
      limit: 50,
      count: 2,
      hasMore: true,
      nextCursor: "scope-next",
    };
    const load = vi.spyOn(store, "load").mockResolvedValue();
    const loadMore = vi.spyOn(store, "loadMoreRuns").mockResolvedValue();
    const loadMoreScopes = vi
      .spyOn(store, "loadMoreScopes")
      .mockResolvedValue();
    const selectRun = vi.spyOn(store, "selectRun").mockResolvedValue();
    const wrapper = mount(OperationsView, { global: { plugins: [PrimeVue] } });

    expect(load).toHaveBeenCalledOnce();
    expect(wrapper.text()).toContain("Heartbeat veraltet");
    expect(wrapper.text()).toContain("Berechtigungsprobleme");
    expect(wrapper.text()).toContain("Mindestens 1 unvollständig");
    expect(wrapper.text()).toContain("partial");
    expect(wrapper.text()).toContain("failed");
    expect(wrapper.text()).not.toMatch(/jetzt scannen|starten|stoppen/i);

    await wrapper
      .get('button[aria-label^="Scope-Ergebnisse"]')
      .trigger("click");
    expect(selectRun).toHaveBeenCalledWith(UUID);

    await wrapper.get(".table-pagination button").trigger("click");
    expect(loadMore).toHaveBeenCalledOnce();
    await wrapper
      .findAll(".table-pagination button")
      .find((button) => button.text().includes("Scope-Ergebnisse"))
      ?.trigger("click");
    expect(loadMoreScopes).toHaveBeenCalledOnce();
  });
});
