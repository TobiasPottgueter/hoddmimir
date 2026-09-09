import { createPinia, setActivePinia } from "pinia";
import { flushPromises, mount, shallowMount } from "@vue/test-utils";
import PrimeVue from "primevue/config";
import InputText from "primevue/inputtext";
import Select from "primevue/select";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { UUID } from "@/test/fixtures";
import type {
  AdministrationAuditEvent,
  AdministrationRole,
  AdministrationUser,
} from "@/api/generated/types.gen";
import { useAdministrationStore } from "@/stores/administration";
import { useBackupOperationsStore } from "@/stores/backupOperations";
import { useAuthStore } from "@/stores/auth";
import AdministrationView from "./AdministrationView.vue";

describe("AdministrationView", () => {
  let pinia = createPinia();

  beforeEach(() => {
    pinia = createPinia();
    setActivePinia(pinia);
  });

  function mountView(permissions: Array<"security.manage" | "audit.read">) {
    useAuthStore().$patch({
      principal: { id: UUID, username: "admin", permissions },
      csrfToken: "csrf",
      initialized: true,
    });
    const store = useAdministrationStore();
    vi.spyOn(store, "loadUsers").mockResolvedValue();
    vi.spyOn(store, "loadRoles").mockResolvedValue();
    vi.spyOn(store, "loadAudit").mockResolvedValue();
    return {
      store,
      wrapper: shallowMount(AdministrationView, {
        global: {
          plugins: [pinia],
          stubs: {
            Button: {
              props: ["label"],
              template: "<button>{{ label }}<slot /></button>",
            },
            Message: { template: "<div><slot /></div>" },
            RouterLink: {
              props: ["to"],
              template: '<a :href="to"><slot /></a>',
            },
          },
        },
      }),
    };
  }

  it("zeigt Security-Verwaltung nur mit security.manage und lädt die Read-Modelle", () => {
    const { store, wrapper } = mountView(["security.manage", "audit.read"]);

    expect(wrapper.text()).toContain("Benutzer");
    expect(wrapper.text()).toContain("Rollen & Permissions");
    expect(wrapper.text()).toContain("Audit-Log");
    expect(wrapper.get('a[href="/connections"]').text()).toContain(
      "Verbindungen",
    );
    expect(
      wrapper
        .findAll("button")
        .some((button) => button.text() === "Benutzer anlegen"),
    ).toBe(true);
    expect(store.loadUsers).toHaveBeenCalledOnce();
    expect(store.loadRoles).toHaveBeenCalledOnce();
  });

  it("wendet geschlossene User- und Auditfilter an und startet jeweils auf Seite eins", async () => {
    useAuthStore().$patch({
      principal: {
        id: UUID,
        username: "admin",
        permissions: ["security.manage", "audit.read"],
      },
      csrfToken: "csrf",
      initialized: true,
    });
    const store = useAdministrationStore();
    const loadUsers = vi.spyOn(store, "loadUsers").mockResolvedValue();
    vi.spyOn(store, "loadRoles").mockResolvedValue();
    const loadAudit = vi.spyOn(store, "loadAudit").mockResolvedValue();
    const wrapper = mount(AdministrationView, {
      global: {
        plugins: [pinia, PrimeVue],
        stubs: {
          RouterLink: { props: ["to"], template: '<a :href="to"><slot /></a>' },
          teleport: true,
        },
      },
    });
    await flushPromises();

    wrapper.getComponent(InputText).vm.$emit("update:modelValue", "ada");
    wrapper.findAllComponents(Select)[0]!.vm.$emit("update:modelValue", "true");
    await wrapper.get('form[aria-label="Benutzer filtern"]').trigger("submit");
    expect(loadUsers).toHaveBeenLastCalledWith({
      limit: 50,
      search: "ada",
      enabled: true,
    });

    await wrapper
      .findAll("button")
      .find((button) => button.text().includes("Audit-Log"))!
      .trigger("click");
    await flushPromises();
    const selects = wrapper.findAllComponents(Select);
    selects.at(-2)!.vm.$emit("update:modelValue", "user_created");
    selects.at(-1)!.vm.$emit("update:modelValue", "denied");
    await wrapper.get('form[aria-label="Audit-Log filtern"]').trigger("submit");
    expect(loadAudit).toHaveBeenLastCalledWith({
      limit: 50,
      eventType: "user_created",
      outcome: "denied",
    });
  });

  it("fällt für Audit-Leser auf das Audit-Log zurück und verbirgt Mutationskontrollen", () => {
    const { store, wrapper } = mountView(["audit.read"]);

    expect(store.section).toBe("audit");
    expect(store.loadAudit).toHaveBeenCalledOnce();
    expect(wrapper.text()).not.toContain("Rollen & Permissions");
    expect(
      wrapper
        .findAll("button")
        .some((button) => button.text() === "Benutzer anlegen"),
    ).toBe(false);
  });

  it("zeigt ohne Administrationsberechtigung eine klare Permission-Meldung", () => {
    const { wrapper } = mountView([]);
    expect(wrapper.text()).toContain("security.manage");
    expect(wrapper.text()).toContain("audit.read");
  });

  it("führt Benutzer-, Rollen- und Audit-Interaktionen über den Store aus", async () => {
    useAuthStore().$patch({
      principal: {
        id: UUID,
        username: "admin",
        permissions: ["security.manage", "audit.read"],
      },
      csrfToken: "csrf",
      initialized: true,
    });
    const user: AdministrationUser = {
      id: UUID,
      username: "ada",
      displayName: "Ada",
      enabled: true,
      revision: 2,
      roles: ["admin"],
      createdAt: "2026-07-12T10:00:00.000000Z",
      updatedAt: "2026-07-12T10:00:00.000000Z",
      disabledAt: null,
      lastLoginAt: null,
    };
    const role: AdministrationRole = {
      id: UUID,
      name: "admin",
      displayName: "Administrator",
      permissions: ["security.manage", "audit.read"],
    };
    const event: AdministrationAuditEvent = {
      id: UUID,
      occurredAt: "2026-07-12T10:00:00.000000Z",
      actorUserId: UUID,
      actorSessionId: null,
      eventType: "user_created",
      outcome: "succeeded",
      subjectType: "user",
      subjectId: UUID,
      reasonCode: null,
      correlationId: UUID,
    };
    const store = useAdministrationStore();
    store.users = [user];
    store.roles = [role];
    store.auditEvents = [event];
    vi.spyOn(store, "loadUsers").mockResolvedValue();
    vi.spyOn(store, "loadRoles").mockResolvedValue();
    vi.spyOn(store, "loadAudit").mockResolvedValue();
    vi.spyOn(store, "selectAuditEvent").mockImplementation(async () => {
      store.selectedAuditEvent = event;
    });
    const createUser = vi.spyOn(store, "createUser").mockResolvedValue(true);
    const updateUser = vi.spyOn(store, "updateUser").mockResolvedValue(true);
    const replaceRoles = vi
      .spyOn(store, "replaceRoles")
      .mockResolvedValue(true);
    const disableUser = vi.spyOn(store, "disableUser").mockResolvedValue(true);
    const loadHealth = vi.spyOn(store, "loadHealth").mockResolvedValue();
    const loadMoreUsers = vi.spyOn(store, "loadMoreUsers").mockResolvedValue();
    const loadMoreRoles = vi.spyOn(store, "loadMoreRoles").mockResolvedValue();
    const loadMoreAudit = vi.spyOn(store, "loadMoreAudit").mockResolvedValue();
    vi.spyOn(useBackupOperationsStore(), "loadDashboard").mockResolvedValue();
    store.usersCursor = "users-next";
    store.rolesCursor = "roles-next";
    store.auditCursor = "audit-next";
    const wrapper = mount(AdministrationView, {
      attachTo: document.body,
      global: {
        plugins: [pinia, PrimeVue],
        stubs: {
          RouterLink: {
            props: ["to"],
            template: '<a :href="to"><slot /></a>',
          },
          teleport: true,
        },
      },
    });
    await flushPromises();

    await wrapper
      .findAll("button")
      .find((button) => button.text().includes("Weitere Benutzer"))!
      .trigger("click");
    expect(loadMoreUsers).toHaveBeenCalled();

    await wrapper
      .findAll("button")
      .find((button) => button.text().includes("Benutzer anlegen"))!
      .trigger("click");
    await wrapper.get('input[autocomplete="off"]').setValue("grace");
    await wrapper
      .findAll('input[autocomplete="off"]')[1]!
      .setValue("Grace Hopper");
    await wrapper
      .get('input[type="password"]')
      .setValue("correct horse battery staple");
    await wrapper.get("form.administration-form").trigger("submit");
    await flushPromises();
    expect(createUser).toHaveBeenCalledWith(
      expect.objectContaining({
        username: "grace",
        password: "correct horse battery staple",
      }),
    );

    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Bearbeiten")!
      .trigger("click");
    store.error = "Konflikt";
    store.conflictRevision = 3;
    await wrapper.vm.$nextTick();
    await wrapper
      .findAll("button")
      .find((button) => button.text().includes("Stand laden"))!
      .trigger("click");
    await flushPromises();
    await wrapper.findAll("form.administration-form").at(-1)!.trigger("submit");
    await flushPromises();
    expect(updateUser).toHaveBeenCalledWith(
      UUID,
      expect.objectContaining({ expectedRevision: 2 }),
    );

    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Rollen")!
      .trigger("click");
    await wrapper
      .findAll("button")
      .find((button) => button.text().includes("Rollen speichern"))!
      .trigger("click");
    await flushPromises();
    expect(replaceRoles).toHaveBeenCalled();

    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Deaktivieren")!
      .trigger("click");
    await wrapper
      .findAll("button")
      .filter((button) => button.text() === "Deaktivieren")
      .at(-1)!
      .trigger("click");
    await flushPromises();
    expect(disableUser).toHaveBeenCalled();

    await wrapper
      .findAll("button")
      .find((button) => button.text().includes("Rollen & Permissions"))!
      .trigger("click");
    await wrapper
      .findAll("button")
      .find((button) => button.text().includes("Weitere Rollen"))!
      .trigger("click");
    expect(loadMoreRoles).toHaveBeenCalled();
    await wrapper
      .findAll("button")
      .find((button) => button.text().includes("Audit-Log"))!
      .trigger("click");
    await wrapper
      .findAll("button")
      .find((button) => button.text().includes("Weitere Audit"))!
      .trigger("click");
    expect(loadMoreAudit).toHaveBeenCalled();
    await wrapper
      .findAll("button")
      .find((button) => button.text() === "Anzeigen")!
      .trigger("click");
    await flushPromises();
    expect(store.selectAuditEvent).toHaveBeenCalledWith(UUID);
    expect(wrapper.text()).toContain("Audit-Ereignis");

    await wrapper
      .findAll("button")
      .find((button) => button.text().includes("Worker & Runtime"))!
      .trigger("click");
    expect(loadHealth).toHaveBeenCalled();

    wrapper.unmount();
  });
});
