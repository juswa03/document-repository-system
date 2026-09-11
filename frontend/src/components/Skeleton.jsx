/**
 * Loading placeholders shaped like the content they stand in for. Use
 * these instead of a "Loading…" line so the layout doesn't jump when the
 * data lands.
 */

export function Skeleton({ width = '100%', height = '1rem', style }) {
  return (
    <span
      className="skeleton"
      aria-hidden="true"
      style={{ width, height, ...style }}
    />
  );
}

/** A stand-in for a <table class="data-table"> that's still loading. */
export function TableSkeleton({ rows = 5, label = 'Loading…' }) {
  return (
    <div className="table-skeleton" role="status" aria-label={label}>
      {Array.from({ length: rows }).map((_, i) => (
        <Skeleton key={i} height="1.1rem" width={i === 0 ? '40%' : '100%'} />
      ))}
    </div>
  );
}

/** A stand-in for a card / panel body that's still loading. */
export function CardSkeleton({ count = 1, label = 'Loading…' }) {
  return (
    <div role="status" aria-label={label}>
      {Array.from({ length: count }).map((_, i) => (
        <Skeleton key={i} className="card-skeleton" height="4.5rem" />
      ))}
    </div>
  );
}
