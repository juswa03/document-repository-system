/**
 * Shared list pager. Renders nothing for a single-page result.
 * `meta` is the {current_page, last_page, total} envelope every
 * paginated endpoint returns.
 */
export default function Pager({ meta, page, onPage }) {
  if (!meta || meta.last_page <= 1) return null;

  return (
    <div className="pager">
      <span>
        Page {meta.current_page} of {meta.last_page} — {meta.total} total
      </span>
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
    </div>
  );
}
