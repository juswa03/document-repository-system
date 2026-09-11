import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import SubmissionsTable from './SubmissionsTable';

vi.mock('../../lib/download', () => ({
  downloadDocumentFile: vi.fn(),
  downloadReviewResponseFile: vi.fn(),
}));

function requestSubmission(overrides = {}) {
  return {
    kind: 'request',
    id: 1,
    ref: 'REQ-0001',
    type: 'Urgent request',
    status: 'pending',
    submitted_at: '2026-01-01T00:00:00Z',
    title: 'Copy of the operational plan',
    needed_by: '2026-02-01',
    amount: null,
    access_level: 'internal',
    description: 'A description of the request.',
    uploader_remarks: null,
    requires_justification: true,
    remarks: null,
    response_file: null,
    ...overrides,
  };
}

describe('SubmissionsTable detail view — reason-given row', () => {
  it('shows "Not provided" for a justification-requiring type with no reason, instead of hiding the row', async () => {
    const user = userEvent.setup();
    const submission = requestSubmission({ uploader_remarks: null, requires_justification: true });

    render(<SubmissionsTable submissions={[submission]} />);

    await user.click(screen.getByRole('button', { name: 'Details' }));

    expect(screen.getByText('Reason given')).toBeInTheDocument();
    expect(screen.getByText('Not provided')).toBeInTheDocument();
  });

  it('hides the reason-given row entirely for a type that does not require one', async () => {
    const user = userEvent.setup();
    const submission = requestSubmission({ uploader_remarks: null, requires_justification: false });

    render(<SubmissionsTable submissions={[submission]} />);

    await user.click(screen.getByRole('button', { name: 'Details' }));

    expect(screen.queryByText('Reason given')).not.toBeInTheDocument();
  });

  it('still shows the actual reason text when one was given', async () => {
    const user = userEvent.setup();
    const submission = requestSubmission({
      uploader_remarks: 'Needed for the accreditation visit next month.',
      requires_justification: true,
    });

    render(<SubmissionsTable submissions={[submission]} />);

    await user.click(screen.getByRole('button', { name: 'Details' }));

    expect(screen.getByText('Needed for the accreditation visit next month.')).toBeInTheDocument();
  });
});
