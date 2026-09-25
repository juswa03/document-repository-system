import { describe, it, expect, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import ManageUsers from './ManageUsers';
import api from '../../lib/api';

vi.mock('../../lib/api');

vi.mock('../../context/AuthContext', () => ({
  useAuth: () => ({ user: { id: 1, role: 'system_admin', full_name: 'Test Admin' }, logout: vi.fn() }),
}));

const LONG_EMAIL = 'a.very.long.email.address.that.would.overflow.a.narrow.table.cell@example-office.test';

function userRow(overrides = {}) {
  return {
    id: 1,
    full_name: 'Maria Santos',
    email: LONG_EMAIL,
    role: 'office_admin',
    office: { id: 1, office_name: 'Office of Research and Knowledge Management' },
    is_active: true,
    avatar_path: null,
    email_verified_at: '2026-01-05T08:00:00Z',
    created_at: '2026-01-01T08:00:00Z',
    updated_at: '2026-02-10T10:30:00Z',
    ...overrides,
  };
}

describe('ManageUsers — full user detail modal', () => {
  it('opens a detail modal with the complete record when the row icon is clicked, and closes it', async () => {
    const user = userEvent.setup();

    api.get.mockImplementation((url) => {
      if (url === '/admin/users') {
        return Promise.resolve({
          data: { data: [userRow()], meta: { current_page: 1, last_page: 1, total: 1, per_page: 25 } },
        });
      }
      return Promise.resolve({ data: {} });
    });

    render(
      <MemoryRouter>
        <ManageUsers />
      </MemoryRouter>,
    );

    await waitFor(() => expect(screen.getByText('Maria Santos')).toBeInTheDocument());

    // The table cell itself still truncates the long email...
    const cell = screen.getByText(LONG_EMAIL);
    expect(cell.closest('td')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: /view full details for maria santos/i }));

    const dialog = screen.getByRole('dialog', { name: /user details/i });
    // ...but the modal shows the same value in full, unclipped.
    expect(within(dialog).getByText(LONG_EMAIL)).toBeInTheDocument();
    expect(within(dialog).getByText('Office admin')).toBeInTheDocument();
    expect(within(dialog).getByText('Office of Research and Knowledge Management')).toBeInTheDocument();

    // Both the modal's own "×" and this footer button are accessibly named
    // "Close" — pick the footer one specifically by its visible text node.
    const footerCloseButton = within(dialog)
      .getAllByRole('button', { name: 'Close' })
      .find((btn) => btn.textContent === 'Close');
    await user.click(footerCloseButton);
    expect(screen.queryByRole('dialog', { name: /user details/i })).not.toBeInTheDocument();
  });

  it('shows sensible fallbacks for a deactivated user with no office assigned', async () => {
    const user = userEvent.setup();

    api.get.mockImplementation((url) => {
      if (url === '/admin/users') {
        return Promise.resolve({
          data: {
            data: [userRow({ id: 2, full_name: 'Unassigned Admin', office: null, is_active: false, email_verified_at: null })],
            meta: { current_page: 1, last_page: 1, total: 1, per_page: 25 },
          },
        });
      }
      return Promise.resolve({ data: {} });
    });

    render(
      <MemoryRouter>
        <ManageUsers />
      </MemoryRouter>,
    );

    await waitFor(() => expect(screen.getByText('Unassigned Admin')).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /view full details for unassigned admin/i }));

    const dialog = screen.getByRole('dialog', { name: /user details/i });
    expect(within(dialog).getByText('Not assigned')).toBeInTheDocument();
    expect(within(dialog).getByText('Not verified')).toBeInTheDocument();
    // "Deactivated" legitimately appears twice — the status badge next to
    // the name, and the Status row in the detail grid — so check there
    // are exactly the two expected occurrences rather than a single match.
    expect(within(dialog).getAllByText('Deactivated')).toHaveLength(2);
  });
});
