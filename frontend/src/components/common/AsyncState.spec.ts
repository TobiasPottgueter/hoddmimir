import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";

import AsyncState from "./AsyncState.vue";

describe("AsyncState", () => {
  it("zeigt Lade-, Fehler-, Leer- und Inhaltszustand exklusiv", async () => {
    const wrapper = mount(AsyncState, {
      props: { loading: true, error: null, empty: false },
      slots: { default: "Inhalt" },
    });
    expect(wrapper.text()).toContain("geladen");
    expect(wrapper.text()).not.toContain("Inhalt");

    await wrapper.setProps({ loading: false, error: "Fehler", empty: false });
    expect(wrapper.text()).toContain("Fehler");

    await wrapper.setProps({ error: null, empty: true });
    expect(wrapper.text()).toContain("Keine Daten vorhanden");

    await wrapper.setProps({ empty: false });
    expect(wrapper.text()).toContain("Inhalt");
  });

  it("verwendet erklärende optionale Leertexte", () => {
    const wrapper = mount(AsyncState, {
      props: {
        loading: false,
        error: null,
        empty: true,
        emptyTitle: "Nichts gefunden",
        emptyDescription: "Filter ändern",
      },
    });
    expect(wrapper.text()).toContain("Nichts gefunden");
    expect(wrapper.text()).toContain("Filter ändern");
  });
});
