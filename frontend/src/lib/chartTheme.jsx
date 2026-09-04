// Shared chart theme — every graph in the app pulls from here so
// colors and tooltip styling stay one system instead of each page
// picking its own palette.

export const CHART_COLORS = {
  ink: '#17253d',
  inkSoft: '#4a5568',
  seal: '#2e6e58',
  sealDeep: '#235645',
  sealSoft: 'rgba(46, 110, 88, 0.16)',
  ledger: '#b4823c',
  danger: '#b3432f',
  line: '#d8d3c4',
  paperRaised: '#f8f8f4',
};

export const STATUS_META = {
  pending: { label: 'Pending review', color: CHART_COLORS.inkSoft },
  approved: { label: 'Approved', color: CHART_COLORS.seal },
  rejected: { label: 'Rejected', color: CHART_COLORS.danger },
  revision: { label: 'Needs revision', color: CHART_COLORS.ledger },
};

export function monthLabel(yearMonth) {
  const [year, month] = yearMonth.split('-').map(Number);
  return new Date(year, month - 1, 1).toLocaleDateString(undefined, {
    month: 'short',
    year: '2-digit',
  });
}

/**
 * Drop-in replacement for recharts' default <Tooltip content>.
 * Matches the app's panel styling instead of the library's default
 * white box + drop shadow.
 */
export function ChartTooltip({ active, payload, label }) {
  if (!active || !payload?.length) return null;
  return (
    <div className="chart-tooltip">
      {label && <p className="chart-tooltip-label">{label}</p>}
      {payload.map((entry) => (
        <p className="chart-tooltip-row" key={entry.dataKey || entry.name}>
          <span className="chart-tooltip-dot" style={{ background: entry.color || entry.fill }} />
          {entry.name}: <strong>{entry.value}</strong>
        </p>
      ))}
    </div>
  );
}
