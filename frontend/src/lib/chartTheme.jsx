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

/** Chart-side status colours. Labels match src/lib/statusLabels.js. */
export const STATUS_META = {
  draft: { label: 'Draft', color: CHART_COLORS.line },
  pending: { label: 'For review', color: CHART_COLORS.inkSoft },
  approved: { label: 'Approved', color: CHART_COLORS.seal },
  rejected: { label: 'Rejected', color: CHART_COLORS.danger },
  revision: { label: 'For revision', color: CHART_COLORS.ledger },
};

export function monthLabel(yearMonth) {
  const [year, month] = yearMonth.split('-').map(Number);
  return new Date(year, month - 1, 1).toLocaleDateString(undefined, {
    month: 'short',
    year: '2-digit',
  });
}

// Roughly how many pixels one character of the axis tick font occupies —
// used to decide when a category/office name needs truncating so it
// doesn't overflow its allotted label width or sit inconsistently
// distant from the bar depending on how long the name happens to be.
const AXIS_CHAR_WIDTH = 6.2;

function truncateToWidth(text, maxWidth) {
  const maxChars = Math.max(1, Math.floor(maxWidth / AXIS_CHAR_WIDTH));
  if (text.length <= maxChars) return text;
  return `${text.slice(0, Math.max(1, maxChars - 1))}…`;
}

/**
 * Drop-in <YAxis tick={<CategoryTick />}> for a horizontal bar chart whose
 * category labels (a document category, an office name, …) vary in
 * length. Recharts' default tick neither wraps nor ellipsizes long text,
 * so labels either overflow the axis band into the chart or get clipped
 * mid-character — inconsistent from one label to the next. This truncates
 * every label to the same allotted width instead, right-aligned like the
 * default, with the untruncated name available on hover via <title>.
 */
export function CategoryTick({ x, y, payload, width = 120 }) {
  const full = String(payload.value ?? '');
  const shown = truncateToWidth(full, width);
  return (
    <text x={x} y={y} dy={4} textAnchor="end">
      {shown !== full && <title>{full}</title>}
      {shown}
    </text>
  );
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
