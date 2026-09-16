import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import ReportingPeriodField from './ReportingPeriodField';
import { suggestedReportingPeriods } from '../lib/reportingPeriods';

describe('suggestedReportingPeriods', () => {
  it('returns a non-empty list of distinct, non-empty strings', () => {
    const suggestions = suggestedReportingPeriods();
    expect(suggestions.length).toBeGreaterThan(0);
    expect(new Set(suggestions).size).toBe(suggestions.length);
    suggestions.forEach((s) => expect(s.trim()).not.toBe(''));
  });

  it('includes the current academic year in the AY YYYY-YYYY shape', () => {
    const suggestions = suggestedReportingPeriods();
    expect(suggestions.some((s) => /^AY \d{4}-\d{4}$/.test(s))).toBe(true);
  });
});

describe('ReportingPeriodField', () => {
  it('fills the field when a quick-pick chip is clicked', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(<ReportingPeriodField id="period" value="" onChange={onChange} />);

    const firstChip = screen.getAllByRole('button')[0];
    await user.click(firstChip);

    expect(onChange).toHaveBeenCalledWith(firstChip.textContent);
  });

  it('still accepts arbitrary free text typed directly, not just a suggestion', async () => {
    const user = userEvent.setup();
    let value = '';
    const onChange = vi.fn((v) => { value = v; });

    const { rerender } = render(<ReportingPeriodField id="period" value={value} onChange={onChange} />);

    const input = screen.getByPlaceholderText(/e.g. AY/i);
    for (const char of 'Custom period') {
      await user.type(input, char);
      rerender(<ReportingPeriodField id="period" value={value} onChange={onChange} />);
    }

    expect(value).toBe('Custom period');
  });

  it('highlights the chip matching the current value as active', () => {
    const suggestions = suggestedReportingPeriods();
    render(<ReportingPeriodField id="period" value={suggestions[0]} onChange={() => {}} />);

    const activeChip = screen.getByRole('button', { name: suggestions[0] });
    expect(activeChip).toHaveClass('is-active');
  });
});
