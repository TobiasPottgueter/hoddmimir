import { inject, watch } from "vue";
import { routerKey, type LocationQuery } from "vue-router";

export function queryChoice<T extends string>(
  query: LocationQuery,
  key: string,
  choices: readonly T[],
  fallback: T,
): T {
  const value = query[key];
  return typeof value === "string" && choices.includes(value as T)
    ? (value as T)
    : fallback;
}
export function queryUuid(query: LocationQuery, key: string): string {
  const value = query[key];
  return typeof value === "string" &&
    /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/.test(value)
    ? value
    : "";
}
export function queryText(
  query: LocationQuery,
  key: string,
  maximum = 200,
): string {
  const value = query[key];
  return typeof value === "string" && value.length <= maximum
    ? value.trim()
    : "";
}
/** URL is the applied filter state. Draft changes do not affect pagination/polling. */
export function useQueryFilters(
  read: (query: LocationQuery) => void,
  reload: () => void,
) {
  const router = inject(routerKey, undefined);
  if (router) {
    read(router.currentRoute.value.query);
    watch(
      () => router.currentRoute.value.query,
      (query) => {
        read(query);
        reload();
      },
    );
  }
  async function apply(
    query: Record<string, string | undefined>,
  ): Promise<void> {
    if (!router) {
      read(
        Object.fromEntries(
          Object.entries(query).filter(([, value]) => value !== undefined),
        ) as LocationQuery,
      );
      reload();
      return;
    }
    const values = Object.fromEntries(
      Object.entries(query).filter(
        ([, value]) => value !== undefined && value !== "",
      ),
    );
    const target = {
      path: router.currentRoute.value.path,
      query: values,
      hash: router.currentRoute.value.hash,
    };
    if (
      router.resolve(target).fullPath === router.currentRoute.value.fullPath
    ) {
      read(router.currentRoute.value.query);
      reload();
    } else await router.push(target);
  }
  return { apply };
}
