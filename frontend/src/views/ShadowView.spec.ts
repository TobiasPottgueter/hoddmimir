import { mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import PrimeVue from "primevue/config";
import PagedPicker from "@/components/common/PagedPicker.vue";
import Select from "primevue/select";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { useShadowStore } from "@/stores/shadow";
import ShadowView from "./ShadowView.vue";

describe("ShadowView", () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.stubGlobal(
      "ResizeObserver",
      class {
        observe() {}
        unobserve() {}
        disconnect() {}
      },
    );
  });

  it("zeigt Entscheidungen und erklärbare Detail-Gates ohne Schreibaktion", async () => {
    const store = useShadowStore();
    store.loadDecisions = vi.fn();
    store.loadEvaluations = vi.fn();
    store.decisions = [
      {
        id: "decision",
        evaluationId: "eval",
        guestId: "33333333-3333-4333-8333-333333333333",
        nodeId: null,
        outcome: "blocked",
        reason: "bytes_written",
        priority: 100,
        policyId: "11111111-1111-4111-8111-111111111111",
        policyRevision: 1,
        targetId: "22222222-2222-4222-8222-222222222222",
        targetRevision: 2,
        completedAt: "2026-07-12T10:00:00.000000Z",
      },
    ];
    store.detail = {
      ...store.decisions[0]!,
      gates: [
        {
          position: 1,
          code: "guest_enabled",
          passed: false,
          scope: "guest",
          subjectId: "33333333-3333-4333-8333-333333333333",
          observedAt: null,
          detailCode: "disabled",
        },
        {
          position: 2,
          code: "inventory_fresh",
          passed: true,
          scope: "inventory",
          subjectId: "33333333-3333-4333-8333-333333333333",
          observedAt: "2026-07-12T09:59:00.000000Z",
          detailCode: "passed",
        },
      ],
    };
    const wrapper = mount(ShadowView, { global: { plugins: [PrimeVue] } });
    expect(wrapper.text()).toContain("Shadow-Auswertungen");
    expect(wrapper.text()).toContain("Blockierende Gates");
    expect(wrapper.text()).toContain("Gast aktiviert");
    expect(wrapper.text()).toContain("Inventar aktuell");
    expect(wrapper.text()).toContain("#1");
    expect(wrapper.text()).toContain("Deaktiviert");
    expect(wrapper.text()).not.toContain("Jetzt ausführen");
  });

  it("zeigt Lade-, Leer- und maskierte Fehlerzustände", async () => {
    const store = useShadowStore();
    store.loadDecisions = vi.fn();
    store.loadEvaluations = vi.fn();
    store.decisionsLoading = true;

    const wrapper = mount(ShadowView, { global: { plugins: [PrimeVue] } });
    expect(wrapper.text()).toContain("Daten werden geladen");

    store.decisionsLoading = false;
    await wrapper.vm.$nextTick();
    expect(wrapper.text()).toContain("Keine Entscheidungen");

    store.decisionsError = "Shadow-Daten sind momentan nicht verfügbar.";
    await wrapper.vm.$nextTick();
    expect(wrapper.text()).toContain(
      "Shadow-Daten sind momentan nicht verfügbar.",
    );
  });

  it("wendet alle geschlossenen Filter an und bedient Details sowie beide Folgeseiten", async () => {
    const store = useShadowStore();
    vi.spyOn(store, "loadDecisions").mockResolvedValue();
    vi.spyOn(store, "loadEvaluations").mockResolvedValue();
    const apply = vi.spyOn(store, "applyDecisionFilters").mockResolvedValue();
    const select = vi.spyOn(store, "selectDecision").mockResolvedValue();
    const moreDecisions = vi
      .spyOn(store, "loadMoreDecisions")
      .mockResolvedValue();
    const moreEvaluations = vi
      .spyOn(store, "loadMoreEvaluations")
      .mockResolvedValue();
    store.decisions = [
      {
        id: "decision",
        evaluationId: "eval",
        guestId: "33333333-3333-4333-8333-333333333333",
        nodeId: null,
        outcome: "eligible",
        reason: "never_backed_up",
        priority: 300,
        policyId: "11111111-1111-4111-8111-111111111111",
        policyRevision: 1,
        targetId: "22222222-2222-4222-8222-222222222222",
        targetRevision: 1,
        completedAt: "2026-07-12T10:00:00.000000Z",
      },
    ];
    store.evaluations = [
      {
        id: "eval",
        cycleToken: "cycle",
        fencingToken: 1,
        evaluatorVersion: 1,
        decisionCount: 1,
        gateCount: 1,
        startedAt: "2026-07-12T09:59:00.000000Z",
        completedAt: "2026-07-12T10:00:00.000000Z",
        persistedAt: "2026-07-12T10:00:00.000000Z",
      },
    ];
    store.decisionPage = {
      limit: 20,
      count: 1,
      hasMore: true,
      nextCursor: "next",
    };
    store.evaluationPage = {
      limit: 20,
      count: 1,
      hasMore: true,
      nextCursor: "next",
    };
    const wrapper = mount(ShadowView, { global: { plugins: [PrimeVue] } });
    const selects = wrapper.findAllComponents(Select);
    selects[0]!.vm.$emit("update:modelValue", "eligible");
    selects[1]!.vm.$emit("update:modelValue", "never_backed_up");
    const inputs = wrapper.findAllComponents(PagedPicker);
    inputs[0]!.vm.$emit(
      "update:modelValue",
      "11111111-1111-4111-8111-111111111111",
    );
    inputs[1]!.vm.$emit(
      "update:modelValue",
      "22222222-2222-4222-8222-222222222222",
    );
    inputs[2]!.vm.$emit(
      "update:modelValue",
      "33333333-3333-4333-8333-333333333333",
    );
    await wrapper
      .get('form[aria-label="Shadow-Entscheidungen filtern"]')
      .trigger("submit");
    expect(apply).toHaveBeenLastCalledWith({
      outcome: "eligible",
      reason: "never_backed_up",
      policyId: "11111111-1111-4111-8111-111111111111",
      targetId: "22222222-2222-4222-8222-222222222222",
      guestId: "33333333-3333-4333-8333-333333333333",
    });
    await wrapper.get("button.shadow-decision").trigger("click");
    await wrapper
      .findAll("button")
      .find((button) => button.text().includes("Weitere Entscheidungen"))!
      .trigger("click");
    expect(select).toHaveBeenCalledWith("decision");
    expect(moreDecisions).toHaveBeenCalled();

    await wrapper
      .findAll('[role="tab"]')
      .find((tab) => tab.text().includes("Auswertungsläufe"))!
      .trigger("click");
    await wrapper.vm.$nextTick();
    await wrapper
      .findAll("button")
      .find((button) => button.text().includes("Weitere Läufe"))!
      .trigger("click");
    expect(moreEvaluations).toHaveBeenCalled();

    selects[0]!.vm.$emit("update:modelValue", "");
    selects[1]!.vm.$emit("update:modelValue", "");
    inputs.forEach((input) => input.vm.$emit("update:modelValue", ""));
    await wrapper
      .get('form[aria-label="Shadow-Entscheidungen filtern"]')
      .trigger("submit");
    expect(apply).toHaveBeenLastCalledWith({});
  });
});
