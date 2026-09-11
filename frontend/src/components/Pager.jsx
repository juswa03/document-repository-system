/**
 * Shared list pager. Renders nothing when there's nothing to page — no
 * results, or everything already fits on one page.
 * `meta` is the {current_page, last_page, total} envelope every
 * paginated endpoint (and usePagination, for client-side lists) returns.
 */
export default function Pager({ meta, page, onPage }) {
  if (!meta || meta.total === 0) return null;

  const perPage = meta.per_page ?? 10;
  const from = (meta.current_page - 1) * perPage + 1;
  const to = Math.min(meta.current_page * perPage, meta.total);

  return (
    <div className="pager">
      <span>
        Showing {from}–{to} of {meta.total}
      </span>
      {meta.last_page > 1 && (
        <div className="btn-row">
          <button
            className="btn btn--outline btn-sm"
            disabled={page <= 1}
            onClick={() => onPage(page - 1)}
          >
            ← Previous
          </button>
          <button
            className="btn btn--outline btn-sm"
            disabled={page >= meta.last_page}
            onClick={() => onPage(page + 1)}
          >
            Next →
          </button>
        </div>
      )}
    </div>
  );
}
