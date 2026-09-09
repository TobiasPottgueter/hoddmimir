import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";

import AdministrationHealthPanel from "./AdministrationHealthPanel.vue";

const stubs = {
  Message: { template: "<div><slot /></div>" },
  Tag: { template: "<span>{{ $attrs.value }}</span>" },
};

describe("AdministrationHealthPanel", () => {
  it("zeigt frische, stale und fehlende Worker verständlich", async () => {
    const wrapper = mount(AdministrationHealthPanel, {
      props: {
        workers: {
          collector: {
            status: "busy",
            fresh: true,
            heartbeatAt: "2026-07-13T00:00:00.000000Z",
            expiresAt: "2026-07-13T00:05:00.000000Z",
            nextActionAt: null,
            buildVersion: "2.0.0",
            currentActivity: "inventory",
          },
          backup: {
            status: "degraded",
            fresh: false,
            heartbeatAt: "2026-07-12T23:00:00.000000Z",
            expiresAt: "2026-07-12T23:05:00.000000Z",
            nextActionAt: "2026-07-13T01:00:00.000000Z",
            buildVersion: "2.0.0",
            currentActivity: null,
          },
        },
        health: null,
        loading: false,
        error: null,
      },
      global: { stubs },
    });
    expect(wrapper.text()).toContain("Beschäftigt");
    expect(wrapper.text()).toContain("Heartbeat veraltet");
    expect(wrapper.text()).toContain("Leerlauf");
    expect(wrapper.text()).toContain("Keine Runtime-Readiness");

    await wrapper.setProps({
      workers: { collector: null, backup: null },
    });
    expect(wrapper.text().match(/Kein Heartbeat vorhanden/g)).toHaveLength(2);
  });

  it("zeigt Readiness-Gründe geschlossen und unbekannte Werte fail-closed", () => {
    const wrapper = mount(AdministrationHealthPanel, {
      props: {
        workers: null,
        health: {
          status: "unavailable",
          checkedAt: "2026-07-13T00:00:00.000000Z",
          checks: {
            database_schema: {
              status: "unavailable",
              reason: "database_unavailable",
            },
            future_check: {
              status: "unavailable",
              reason: "future_reason",
            },
            encryption_keyring: { status: "ready" },
          },
        },
        loading: false,
        error: "Teilweise nicht verfügbar",
      },
      global: { stubs },
    });
    expect(wrapper.text()).toContain("Datenbank nicht erreichbar");
    expect(wrapper.text()).toContain("Unbekannte Readiness-Prüfung");
    expect(wrapper.text()).toContain("Nicht sicher bestimmbarer Fehler");
    expect(wrapper.text()).toContain("Verschlüsselungs-Schlüsselbund");
    expect(wrapper.text()).toContain("Teilweise nicht verfügbar");
  });

  it("zeigt einen expliziten Ladezustand", () => {
    const wrapper = mount(AdministrationHealthPanel, {
      props: { workers: null, health: null, loading: true, error: null },
      global: { stubs },
    });
    expect(wrapper.get('[role="status"]').text()).toContain("geladen");
    expect(wrapper.text()).not.toContain("Keine Worker-Projektion");
  });
});
