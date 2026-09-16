/**
 * Suggested values for the free-text "Reporting / coverage period" field.
 * The backend stores this as a plain string and matches it loosely
 * (`LIKE %value%`) everywhere it's filtered on — so there's no fixed
 * format to enforce. These are just the shapes people actually type,
 * generated relative to today so the current period is always first.
 */

const QUARTER_MONTHS = [
  ['Jan', 'Feb', 'Mar'],
  ['Apr', 'May', 'Jun'],
  ['Jul', 'Aug', 'Sep'],
  ['Oct', 'Nov', 'Dec'],
];

/** Philippine academic year runs June–May, so it always spans two calendar years. */
function academicYear(offset = 0) {
  const now = new Date();
  const startYear = now.getMonth() >= 5 ? now.getFullYear() : now.getFullYear() - 1;
  const start = startYear + offset;
  return `AY ${start}-${start + 1}`;
}

function quarter(offset = 0) {
  const now = new Date();
  const totalQuarters = now.getFullYear() * 4 + Math.floor(now.getMonth() / 3) + offset;
  const year = Math.floor(totalQuarters / 4);
  const q = ((totalQuarters % 4) + 4) % 4;
  return `Q${q + 1} ${year}`;
}

function monthRange(offset = 0) {
  const now = new Date();
  const totalQuarters = now.getFullYear() * 4 + Math.floor(now.getMonth() / 3) + offset;
  const year = Math.floor(totalQuarters / 4);
  const q = ((totalQuarters % 4) + 4) % 4;
  const [first, , last] = QUARTER_MONTHS[q];
  return `${first}-${last} ${year}`;
}

function calendarYear(offset = 0) {
  return String(new Date().getFullYear() + offset);
}

/**
 * A handful of ready-to-use suggestions, current period first. Deduplicated
 * since some offsets can coincide (e.g. two calendar-year phrasings).
 */
export function suggestedReportingPeriods() {
  const values = [
    academicYear(0),
    academicYear(-1),
    quarter(0),
    quarter(-1),
    monthRange(0),
    calendarYear(0),
    calendarYear(-1),
  ];

  return [...new Set(values)];
}
