/**
 * A single figure with a label. `tone` adds a coloured left rule using a
 * status token — pass a name, never a colour, so a new accent is a new
 * modifier class here, not a hex threaded through every caller.
 *
 *   tone: 'default' (none) | 'success' | 'warning' | 'danger' | 'info'
 */
export default function StatCard({ label, value, tone = 'default' }) {
  const cls = tone && tone !== 'default' ? `stat-card stat-card--${tone}` : 'stat-card';
  return (
    <div className={cls}>
      <span className="stat-value">{value ?? '—'}</span>
      <span className="stat-label">{label}</span>
    </div>
  );
}
