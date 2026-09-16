import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import { CategoryTick } from './chartTheme';

// Reports.jsx's "By category" / "By office" bar charts pass real category
// and office names — some short ("HR"), some long ("Office of the
// University President for External Affairs") — through this component
// as the Y-axis tick. Before this, recharts' default tick neither wrapped
// nor truncated long labels: they either overflowed the axis band into
// the plot area or wrapped across 2-4 lines, throwing every row's label
// out of vertical alignment with its bar — the "not organized" layout.
describe('CategoryTick', () => {
  function renderTick(value, width = 122) {
    // <CategoryTick> is a recharts tick renderer: it receives {x, y,
    // payload, width} and must return a single <text> (or null), same
    // contract as any custom recharts axis tick.
    return render(
      <svg>
        <CategoryTick x={0} y={0} width={width} payload={{ value }} />
      </svg>
    );
  }

  // <text>'s own visible label, excluding the hidden <title> tooltip node
  // — text.textContent in jsdom concatenates both, which isn't what a
  // viewer actually sees on the chart.
  function visibleLabel(container) {
    const text = container.querySelector('text');
    const title = text.querySelector('title');
    return title ? text.textContent.replace(title.textContent, '') : text.textContent;
  }

  it('renders a short label unchanged, with no title tooltip needed', () => {
    const { container } = renderTick('HR');
    const text = container.querySelector('text');
    expect(text).toHaveTextContent('HR');
    expect(text.querySelector('title')).toBeNull();
  });

  it('truncates a label that would overflow the allotted width, with an ellipsis', () => {
    const long = 'Office of the University President for External Affairs';
    const { container } = renderTick(long, 122);
    const shown = visibleLabel(container);

    // Truncated, not the full string — this is the fix: every label gets
    // one consistent line/width instead of overflowing or wrapping.
    expect(shown).not.toBe(long);
    expect(shown.endsWith('…')).toBe(true);
    expect(shown.length).toBeLessThan(long.length);
  });

  it('keeps the full name available on hover via a <title> when truncated', () => {
    const long = 'Board Resolutions and Minutes of Meetings';
    const { container } = renderTick(long, 122);
    const title = container.querySelector('text > title');
    expect(title).toHaveTextContent(long);
  });

  it('renders every row right-aligned against the same axis edge (textAnchor="end")', () => {
    const { container: shortC } = renderTick('IT');
    const { container: longC } = renderTick('Procurement and Bidding Documents');
    expect(shortC.querySelector('text')).toHaveAttribute('text-anchor', 'end');
    expect(longC.querySelector('text')).toHaveAttribute('text-anchor', 'end');
  });

  it('gives a wider allotted width more room before truncating', () => {
    const long = 'Human Resource Management Office';
    const narrow = visibleLabel(renderTick(long, 80).container);
    const wide = visibleLabel(renderTick(long, 260).container);
    expect(narrow.length).toBeLessThan(wide.length);
    expect(wide).toBe(long); // wide enough to need no truncation at all
  });

  it('handles a missing/empty value without throwing', () => {
    expect(() => renderTick(undefined)).not.toThrow();
    expect(() => renderTick('')).not.toThrow();
  });
});
