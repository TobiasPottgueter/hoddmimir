import type { InventoryResource } from "@/api/generated/types.gen";

export function stringAttribute(
  resource: InventoryResource,
  key: string,
): string | null {
  const value = (resource.attributes as unknown as Record<string, unknown>)[
    key
  ];
  return typeof value === "string" ? value : null;
}

export function numberAttribute(
  resource: InventoryResource,
  key: string,
): number | null {
  const value = (resource.attributes as unknown as Record<string, unknown>)[
    key
  ];
  return typeof value === "number" && Number.isFinite(value) ? value : null;
}

export function booleanAttribute(
  resource: InventoryResource,
  key: string,
): boolean | null {
  const value = (resource.attributes as unknown as Record<string, unknown>)[
    key
  ];
  return typeof value === "boolean" ? value : null;
}

export function stringListAttribute(
  resource: InventoryResource,
  key: string,
): string[] {
  const value = (resource.attributes as unknown as Record<string, unknown>)[
    key
  ];
  return Array.isArray(value) && value.every((item) => typeof item === "string")
    ? value
    : [];
}
