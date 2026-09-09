import { flushPromises, mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import FormErrors from "./FormErrors.vue";

describe("Formularfehler", () => {
  it("fokussiert die Zusammenfassung und öffnet das betroffene Feld", async () => {
    const wrapper = mount(
      {
        components: { FormErrors },
        data: () => ({ errors: {}, attempt: 0 }),
        template:
          '<div><FormErrors :errors="errors" :attempt="attempt" /><details><summary>Weitere Felder</summary><input id="invalid-field" /></details></div>',
      },
      { attachTo: document.body },
    );
    await wrapper.setData({
      errors: { "invalid-field": "Wert fehlt" },
      attempt: 1,
    });
    await flushPromises();
    expect(document.activeElement).toBe(wrapper.get('[role="alert"]').element);
    await wrapper.get("a").trigger("click");
    expect(wrapper.get<HTMLDetailsElement>("details").element.open).toBe(true);
    expect(document.activeElement).toBe(wrapper.get("input").element);
  });
});
