import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import NewRequestModal from './NewRequestModal';

vi.mock('../../lib/api', () => ({
  default: { post: vi.fn(() => Promise.resolve({ data: {} })) },
}));

const URGENT_TYPE = {
  id: 1,
  type_name: 'Urgent request',
  type_code: 'URG',
  requires_justification: true,
  lead_time_label: '1-2 days',
};

const SENSITIVE_TYPE = {
  id: 2,
  type_name: 'Sensitive data request',
  type_code: 'SENS',
  requires_justification: true,
  lead_time_label: '3-5 days',
};

const ROUTINE_TYPE = {
  id: 3,
  type_name: 'Routine copy',
  type_code: 'ROUTINE',
  requires_justification: false,
  lead_time_label: '5-7 days',
};

describe('NewRequestModal reason field', () => {
  it('clears a typed reason when switching between two justification-requiring types', async () => {
    const user = userEvent.setup();

    render(
      <NewRequestModal
        requestTypes={[URGENT_TYPE, SENSITIVE_TYPE, ROUTINE_TYPE]}
        offices={[]}
        onClose={() => {}}
        onSaved={() => {}}
      />,
    );

    const typeSelect = screen.getByLabelText(/what are you requesting/i);
    expect(typeSelect.value).toBe('1');

    const reasonBox = screen.getByLabelText(/reason for urgency/i);
    await user.type(reasonBox, 'Needed for the board meeting next week, urgently.');
    expect(reasonBox.value.length).toBeGreaterThan(0);

    // Switch to the OTHER justification-requiring type — needsReason stays
    // true the whole time, so a fix keyed only on needsReason going false
    // would leave the old reason sitting there, now mislabeled.
    await user.selectOptions(typeSelect, '2');

    const reasonBoxAfter = screen.getByLabelText(/reason for the request/i);
    expect(reasonBoxAfter.value).toBe('');
  });

  it('clears the reason when switching to a type that does not require one', async () => {
    const user = userEvent.setup();

    render(
      <NewRequestModal
        requestTypes={[URGENT_TYPE, ROUTINE_TYPE]}
        offices={[]}
        onClose={() => {}}
        onSaved={() => {}}
      />,
    );

    const typeSelect = screen.getByLabelText(/what are you requesting/i);
    const reasonBox = screen.getByLabelText(/reason for urgency/i);
    await user.type(reasonBox, 'Some reason that was typed in.');

    await user.selectOptions(typeSelect, '3');

    expect(screen.queryByLabelText(/reason for/i)).not.toBeInTheDocument();
  });
});
