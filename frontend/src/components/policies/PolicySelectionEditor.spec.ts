import { mount } from "@vue/test-utils";
import MultiSelect from "primevue/multiselect";
import PrimeVue from "primevue/config";
import Select from "primevue/select";
import { describe, expect, it, vi } from "vitest";

import type {
  ConfiguredPolicy,
  PolicySelectionEntry,
} from "@/api/generated/types.gen";
import { OTHER_UUID, UUID } from "@/test/fixtures";
import PolicySelectionEditor from "./PolicySelectionEditor.vue";

const policy = {
  id: UUID,
  revision: 2,
  status: "draft",
  displayName: "Nacht",
  connectionId: UUID,
  connectionName: "PVE",
  clusterId: OTHER_UUID,
  clusterName: "cluster-a",
  targetId: null,
  targetName: null,
  priority: null,
  mode: null,
  compression: null,
  maximumAgeSeconds: null,
  bytesWrittenThreshold: null,
  cooldownSeconds: null,
  schedule: null,
  desiredRetention: null,
  retentionExecutionEnabled: false,
  failureNotificationRecipients: [],
  disabledAt: null,
  canEnable: false,
  blockers: [],
} satisfies ConfiguredPolicy;
const entries: PolicySelectionEntry[] = [
  {
    id: UUID,
    revision: 1,
    status: "active",
    kind: "assignment",
    scope: "guest",
    connectionId: UUID,
    clusterId: OTHER_UUID,
    nodeId: null,
    guestId: OTHER_UUID,
    subjectName: "vm-101",
    selectionValue: "exclude",
    mode: null,
    compression: null,
    desiredRetention: null,
    disabledAt: null,
  },
  {
    id: OTHER_UUID,
    revision: 1,
    status: "active",
    kind: "guest_override",
    scope: "guest",
    connectionId: UUID,
    clusterId: OTHER_UUID,
    nodeId: null,
    guestId: UUID,
    subjectName: "ct-102",
    selectionValue: null,
    mode: "snapshot",
    compression: "zstd",
    desiredRetention: null,
    disabledAt: null,
  },
];
const node = {
  id: UUID,
  connectionId: UUID,
  connectionName: "PVE",
  parentId: OTHER_UUID,
  displayName: "pve-a",
  inventoryState: "active",
  firstSeenAt: "2026-07-12T10:00:00.000000Z",
  lastSeenAt: "2026-07-12T10:00:00.000000Z",
  archivedAt: null,
  stateObservedAt: null,
  kind: "pve_node",
  attributes: { apiStatus: "online" },
} as const;
const otherNode = { ...node, id: OTHER_UUID, displayName: "pve-b" };
const guest = (guestType: "qemu" | "lxc", id: string, vmid: number) => ({
  ...node,
  id,
  displayName: `${guestType}-${vmid}`,
  kind: "pve_guest" as const,
  attributes: {
    guestType,
    vmid,
    template: false,
    nodeId: UUID,
    nodeName: "pve-a",
  },
});
const guests = [guest("qemu", UUID, 101), guest("lxc", OTHER_UUID, 102)];

describe("PolicySelectionEditor", () => {
  it("zeigt QEMU/LXC und explizite Excludes und erzeugt bounded Commands", async () => {
    vi.spyOn(globalThis.crypto, "randomUUID").mockReturnValue(UUID);
    const wrapper = mount(PolicySelectionEditor, {
      props: {
        policy,
        entries,
        nodes: [node, otherNode],
        guests,
        canManage: true,
        pending: false,
      },
      global: { plugins: [PrimeVue] },
    });
    expect(wrapper.text()).toContain("QEMU und LXC");
    expect(wrapper.text()).toContain("Explizit ausgeschlossen");
    expect(wrapper.text()).toContain("maximal 500");

    await wrapper
      .get('form[aria-label="Auswahlregel konfigurieren"]')
      .trigger("submit");
    expect(wrapper.emitted("change")?.[0]).toEqual([
      "selection.upsert",
      [{ id: UUID, scope: "global", selectionValue: "include" }],
    ]);

    const disableButtons = wrapper
      .findAll("button")
      .filter((button) => button.text().includes("deaktivieren"));
    await disableButtons[0]?.trigger("click");
    await disableButtons[1]?.trigger("click");
    expect(wrapper.emitted("change")?.[1]).toEqual([
      "selection.disable",
      [{ id: UUID }],
    ]);
    expect(wrapper.emitted("change")?.[2]).toEqual([
      "guest_override.disable",
      [{ id: OTHER_UUID }],
    ]);

    const assignmentForm = wrapper.get(
      'form[aria-label="Auswahlregel konfigurieren"]',
    );
    const scopeSelect = wrapper.findAllComponents(Select)[0]!;
    for (const value of ["connection", "cluster", "node"] as const) {
      scopeSelect.vm.$emit("update:modelValue", value);
      await wrapper.vm.$nextTick();
      await assignmentForm.trigger("submit");
    }
    expect(wrapper.emitted("change")).toContainEqual([
      "selection.upsert",
      [
        {
          id: UUID,
          scope: "cluster",
          subjectConnectionId: UUID,
          subjectClusterId: OTHER_UUID,
          selectionValue: "include",
        },
      ],
    ]);
    assignmentForm
      .getComponent(MultiSelect)
      .vm.$emit("update:modelValue", [UUID, OTHER_UUID]);
    await wrapper.vm.$nextTick();
    await assignmentForm.trigger("submit");
    expect(wrapper.emitted("change")).toContainEqual([
      "selection.upsert",
      [
        {
          id: UUID,
          scope: "node",
          subjectConnectionId: UUID,
          subjectClusterId: OTHER_UUID,
          nodeId: UUID,
          selectionValue: "include",
        },
        {
          id: UUID,
          scope: "node",
          subjectConnectionId: UUID,
          subjectClusterId: OTHER_UUID,
          nodeId: OTHER_UUID,
          selectionValue: "include",
        },
      ],
    ]);
    scopeSelect.vm.$emit("update:modelValue", "guest");
    await wrapper.vm.$nextTick();
    await assignmentForm.trigger("submit");
    const guestSelects = assignmentForm.findAllComponents(Select);
    const guestMultiSelect = assignmentForm.getComponent(MultiSelect);
    expect(guestMultiSelect.props("options")).toEqual([
      expect.objectContaining({
        value: UUID,
        label: expect.stringContaining("QEMU 101"),
      }),
    ]);
    guestSelects[1]?.vm.$emit("update:modelValue", "lxc");
    await wrapper.vm.$nextTick();
    expect(guestMultiSelect.props("options")).toEqual([
      expect.objectContaining({
        value: OTHER_UUID,
        label: expect.stringContaining("LXC 102"),
      }),
    ]);
    guestMultiSelect.vm.$emit("update:modelValue", [OTHER_UUID]);
    guestSelects[2]?.vm.$emit("update:modelValue", "exclude");
    await wrapper.vm.$nextTick();
    await assignmentForm.trigger("submit");
    expect(wrapper.emitted("change")).toContainEqual([
      "selection.upsert",
      [
        {
          id: UUID,
          scope: "guest",
          subjectConnectionId: UUID,
          subjectClusterId: OTHER_UUID,
          guestId: OTHER_UUID,
          selectionValue: "exclude",
        },
      ],
    ]);

    const overrideForm = wrapper.get(
      'form[aria-label="Guest-Override konfigurieren"]',
    );
    await overrideForm.trigger("submit");
    const overrideSelects = overrideForm.findAllComponents(Select);
    overrideSelects[1]?.vm.$emit("update:modelValue", UUID);
    for (const select of overrideSelects.slice(2)) {
      select.vm.$emit("update:modelValue", null);
    }
    await wrapper.vm.$nextTick();
    await overrideForm.trigger("submit");
    expect(
      wrapper
        .emitted("change")
        ?.some((event) => event[0] === "guest_override.upsert"),
    ).toBe(true);
  });

  it("verbirgt Schreibformulare ohne Permission", () => {
    const wrapper = mount(PolicySelectionEditor, {
      props: {
        policy,
        entries: [],
        nodes: [],
        guests: [],
        canManage: false,
        pending: false,
      },
      global: { plugins: [PrimeVue] },
    });
    expect(wrapper.find("form").exists()).toBe(false);
    expect(wrapper.text()).toContain("Keine Auswahlregeln");
    expect(wrapper.text()).toContain("Keine Guest-Overrides");
  });

  it("verwendet bestehende IDs zum Ändern und Reaktivieren", async () => {
    const disabledEntries: PolicySelectionEntry[] = [
      {
        ...entries[0]!,
        status: "disabled",
        scope: "node",
        nodeId: UUID,
        guestId: null,
        subjectName: "pve-a",
        selectionValue: "exclude",
        disabledAt: "2026-07-13T10:00:00.000000Z",
      },
      {
        ...entries[1]!,
        status: "disabled",
        disabledAt: "2026-07-13T10:00:00.000000Z",
      },
    ];
    const wrapper = mount(PolicySelectionEditor, {
      props: {
        policy,
        entries: disabledEntries,
        nodes: [node],
        guests,
        canManage: true,
        pending: false,
      },
      global: { plugins: [PrimeVue] },
    });

    await wrapper
      .findAll("button")
      .find((button) => button.text().includes("Regel reaktivieren"))
      ?.trigger("click");
    await wrapper
      .get('form[aria-label="Auswahlregel konfigurieren"]')
      .trigger("submit");
    expect(wrapper.emitted("change")?.[0]).toEqual([
      "selection.upsert",
      [
        {
          id: disabledEntries[0]!.id,
          scope: "node",
          subjectConnectionId: UUID,
          subjectClusterId: OTHER_UUID,
          nodeId: UUID,
          selectionValue: "exclude",
        },
      ],
    ]);

    await wrapper
      .findAll("button")
      .find((button) => button.text().includes("Override reaktivieren"))
      ?.trigger("click");
    await wrapper
      .get('form[aria-label="Guest-Override konfigurieren"]')
      .trigger("submit");
    expect(wrapper.emitted("change")?.[1]?.[0]).toBe("guest_override.upsert");
    expect(wrapper.emitted("change")?.[1]?.[1]).toEqual([
      expect.objectContaining({
        id: disabledEntries[1]!.id,
        guestId: disabledEntries[1]!.guestId,
      }),
    ]);
  });
});
