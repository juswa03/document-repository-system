import { suggestedReportingPeriods } from '../lib/reportingPeriods';

/**
 * The "Reporting / coverage period" field, shared by every document
 * upload/resubmit form. Plain free text underneath (the backend matches
 * it loosely, not against a fixed enum) — this just makes the common
 * shapes ("AY 2025-2026", "Q3 2026") a click away instead of something
 * every uploader has to remember and retype consistently.
 */
export default function ReportingPeriodField({ id, value, onChange }) {
  const suggestions = suggestedReportingPeriods();
  const listId = `${id}-suggestions`;

  return (
    <div>
      <input
        id={id}
        list={listId}
        className="dash-input"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder="e.g. AY 2025-2026, Q3 2026, Jan-Mar 2026"
        autoComplete="off"
      />
      <datalist id={listId}>
        {suggestions.map((s) => (
          <option key={s} value={s} />
        ))}
      </datalist>

      <div className="period-quick-picks">
        {suggestions.slice(0, 4).map((s) => (
          <button
            key={s}
            type="button"
            className={`chip-btn ${value === s ? 'is-active' : ''}`}
            onClick={() => onChange(s)}
          >
            {s}
          </button>
        ))}
      </div>
    </div>
  );
}
