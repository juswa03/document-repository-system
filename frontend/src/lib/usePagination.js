import { useMemo, useState, useEffect } from 'react';

const PAGE_SIZE = 10;

/**
 * Client-side pagination for a list that's already fully loaded (the
 * server returned everything, or the list is filtered/searched in the
 * browser). Slices to `pageSize` (default 10) and returns a `meta` object
 * shaped exactly like the server-paginated envelope, so the same <Pager>
 * works for both.
 *
 *   const { pageItems, page, setPage, meta } = usePagination(visible);
 *   ...
 *   {pageItems.map(...)}
 *   <Pager meta={meta} page={page} onPage={setPage} />
 *
 * Resets to page 1 whenever the list identity/length changes (e.g. a new
 * search term), so a filter never leaves the view stranded on an empty
 * trailing page.
 */
export default function usePagination(items, pageSize = PAGE_SIZE) {
  const [page, setPage] = useState(1);
  const total = items.length;
  const lastPage = Math.max(1, Math.ceil(total / pageSize));

  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => setPage(1), [total]);

  const safePage = Math.min(page, lastPage);

  const pageItems = useMemo(
    () => items.slice((safePage - 1) * pageSize, safePage * pageSize),
    [items, safePage, pageSize]
  );

  const meta = { current_page: safePage, last_page: lastPage, total };

  return { pageItems, page: safePage, setPage, meta };
}
