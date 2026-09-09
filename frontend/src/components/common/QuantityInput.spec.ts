import { mount } from "@vue/test-utils";
import PrimeVue from "primevue/config";
import { describe, expect, it } from "vitest";
import QuantityInput from "./QuantityInput.vue";

describe("QuantityInput", () => {
  it("wechselt Einheiten ohne den gespeicherten Wert zu ändern und meldet ungültige Eingaben", async () => {
    const wrapper = mount(QuantityInput, {
      props: {
        id: "quantity",
        modelValue: "90",
        kind: "duration",
        label: "Cooldown",
      },
      global: { plugins: [PrimeVue] },
    });
    await wrapper.get("select").setValue("1");
    expect(wrapper.get<HTMLInputElement>("input").element.value).toBe("1,5");
    expect(wrapper.emitted("update:modelValue")).toBeUndefined();
    await wrapper.get("input").setValue("2");
    expect(wrapper.emitted("update:modelValue")?.[0]).toEqual(["120"]);
    await wrapper.get("input").setValue("ungültig");
    await wrapper.get("input").trigger("blur");
    expect(wrapper.get("input").attributes("aria-invalid")).toBe("true");
    expect(wrapper.emitted("validation")?.at(-1)?.[0]).toContain("Zahl");
    await wrapper.get("select").setValue("2");
    expect(wrapper.text()).toContain("bisherige Einheit");
    await wrapper.setProps({ modelValue: "1" });
    expect(wrapper.get<HTMLSelectElement>("select").element.value).toBe("0");
  });
});
