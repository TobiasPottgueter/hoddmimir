export interface CursorPageQuery {
  limit: number;
  cursor?: string;
}

export interface CursorPageMetadata {
  limit: number;
  count: number;
  hasMore: boolean;
  nextCursor: string | null;
}

export interface CursorPage<T> {
  items: T[];
  page: CursorPageMetadata;
}

export function pageContinuation(
  page: CursorPageMetadata,
  previousCursor?: string,
): string | undefined {
  if (page.hasMore !== (page.nextCursor !== null)) {
    throw new Error("Die API-Pagination ist inkonsistent.");
  }
  if (page.nextCursor !== null && page.nextCursor === previousCursor) {
    throw new Error("Die API-Pagination macht keinen Fortschritt.");
  }
  return page.nextCursor ?? undefined;
}
