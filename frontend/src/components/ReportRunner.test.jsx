import { describe, it, expect, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import ReportRunner from './ReportRunner';
import api from '../lib/api';

vi.mock('../lib/api');
vi.mock('../lib/download', () => ({ downloadReportCsv: vi.fn() }));

describe('ReportRunner summary rendering', () => {
  it('renders a report whose summary contains a nested breakdown object without crashing', async () => {
    const user = userEvent.setup();

    api.get.mockImplementation((url) => {
      if (url === '/reports') {
        return Promise.resolve({
          data: [{ key: 'document-inventory', label: 'Document Inventory', description: 'desc', filters: [] }],
        });
      }
      if (url === '/categories') return Promise.resolve({ data: [] });
      if (url === '/offices') return Promise.resolve({ data: [] });
      if (url === '/reports/document-inventory') {
        return Promise.resolve({
          data: {
            key: 'document-inventory',
            label: 'Document Inventory',
            generated_at: '2026-01-01 00:00:00',
            // The real bug: `by_status` is a keyed breakdown object, not a
            // scalar — rendering it directly as a React child throws and
            // blanks the whole /reports page.
            summary: { total: 61, by_status: { approved: 21, pending: 20, rejected: 20 } },
            columns: [{ key: 'ref', label: 'Reference' }],
            rows: [{ ref: 'DOC-0001' }],
            total_rows: 1,
            truncated: false,
          },
        });
      }
      return Promise.resolve({ data: {} });
    });

    render(<ReportRunner />);

    await waitFor(() => expect(screen.getByRole('button', { name: /run report/i })).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /run report/i }));

    await waitFor(() => expect(screen.getByText('total')).toBeInTheDocument());

    // The nested breakdown must render as a readable label/count list, not
    // crash the tree and not overflow its tile as one long joined string.
    expect(screen.getByText('approved')).toBeInTheDocument();
    expect(screen.getByText('21')).toBeInTheDocument();
    expect(screen.getByText('pending')).toBeInTheDocument();
    expect(screen.getByText('rejected')).toBeInTheDocument();
    expect(screen.getByText('DOC-0001')).toBeInTheDocument();
  });

  it('caps a long breakdown to a few visible rows and summarises the rest', async () => {
    const user = userEvent.setup();
    const byAction = Object.fromEntries(
      Array.from({ length: 12 }, (_, i) => [`action_${i + 1}`, 12 - i])
    );

    api.get.mockImplementation((url) => {
      if (url === '/reports') {
        return Promise.resolve({
          data: [{ key: 'audit-trail', label: 'Audit Trail', description: 'desc', filters: [] }],
        });
      }
      if (url === '/categories') return Promise.resolve({ data: [] });
      if (url === '/offices') return Promise.resolve({ data: [] });
      if (url === '/reports/audit-trail') {
        return Promise.resolve({
          data: {
            key: 'audit-trail',
            label: 'Audit Trail',
            generated_at: '2026-01-01 00:00:00',
            summary: { total_events: 500, by_action: byAction },
            columns: [{ key: 'at', label: 'When' }, { key: 'description', label: 'Description', wrap: true }],
            rows: [{ at: '2026-01-01', description: 'A very long description that would overflow a fixed-width truncating cell if it were not allowed to wrap onto more than one line.' }],
            total_rows: 1,
            truncated: false,
          },
        });
      }
      return Promise.resolve({ data: {} });
    });

    render(<ReportRunner />);

    await waitFor(() => expect(screen.getByRole('button', { name: /run report/i })).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /run report/i }));

    await waitFor(() => expect(screen.getByText('action_1')).toBeInTheDocument());

    // Only the first 5 rows of a 12-entry breakdown render as rows...
    expect(screen.getByText('action_5')).toBeInTheDocument();
    expect(screen.queryByText('action_6')).not.toBeInTheDocument();

    // ...and the remainder is behind a real toggle, not a dead-end label.
    const toggle = screen.getByRole('button', { name: '+7 more' });
    expect(toggle).toHaveAttribute('aria-expanded', 'false');

    await user.click(toggle);
    expect(screen.getByText('action_6')).toBeInTheDocument();
    expect(screen.getByText('action_12')).toBeInTheDocument();
    const collapse = screen.getByRole('button', { name: 'Show fewer' });
    expect(collapse).toHaveAttribute('aria-expanded', 'true');

    await user.click(collapse);
    expect(screen.queryByText('action_6')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: '+7 more' })).toBeInTheDocument();

    // The wrap-marked column carries the wrapping class instead of
    // truncating; a non-wrap column does not.
    const descCell = screen.getByText(/A very long description/);
    expect(descCell).toHaveClass('col-wrap');
    const whenHeader = screen.getByText('When');
    expect(whenHeader).not.toHaveClass('col-wrap');
  });
});

describe('ReportRunner pagination', () => {
  it('pages a report result 10 rows at a time instead of dumping everything at once', async () => {
    const user = userEvent.setup();

    // 25 rows — enough to force 3 pages at the 10-per-page default and to
    // prove the table never renders more than one page's worth at a time.
    const allRows = Array.from({ length: 25 }, (_, i) => ({ ref: `DOC-${String(i + 1).padStart(4, '0')}` }));

    api.get.mockImplementation((url) => {
      if (url === '/reports') {
        return Promise.resolve({
          data: [{ key: 'document-inventory', label: 'Document Inventory', description: 'desc', filters: [] }],
        });
      }
      if (url === '/categories') return Promise.resolve({ data: [] });
      if (url === '/offices') return Promise.resolve({ data: [] });
      if (url === '/reports/document-inventory') {
        return Promise.resolve({
          data: {
            key: 'document-inventory',
            label: 'Document Inventory',
            generated_at: '2026-01-01 00:00:00',
            summary: { total: 25 },
            columns: [{ key: 'ref', label: 'Reference' }],
            rows: allRows,
            total_rows: 25,
            truncated: false,
          },
        });
      }
      return Promise.resolve({ data: {} });
    });

    render(<ReportRunner />);

    await waitFor(() => expect(screen.getByRole('button', { name: /run report/i })).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /run report/i }));

    await waitFor(() => expect(screen.getByText('DOC-0001')).toBeInTheDocument());

    // Page 1: only the first 10 rows are in the DOM.
    expect(screen.getByText('DOC-0010')).toBeInTheDocument();
    expect(screen.queryByText('DOC-0011')).not.toBeInTheDocument();
    expect(screen.queryByText('DOC-0025')).not.toBeInTheDocument();
    expect(screen.getByText('Showing 1–10 of 25')).toBeInTheDocument();

    // Advance to page 2 — the row set swaps, it doesn't accumulate.
    await user.click(screen.getByRole('button', { name: /next/i }));

    expect(screen.getByText('DOC-0011')).toBeInTheDocument();
    expect(screen.getByText('DOC-0020')).toBeInTheDocument();
    expect(screen.queryByText('DOC-0001')).not.toBeInTheDocument();
    expect(screen.getByText('Showing 11–20 of 25')).toBeInTheDocument();

    // Final partial page still reads correctly and disables "Next".
    await user.click(screen.getByRole('button', { name: /next/i }));

    expect(screen.getByText('DOC-0025')).toBeInTheDocument();
    expect(screen.getByText('Showing 21–25 of 25')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /next/i })).toBeDisabled();
  });

  it('resets to page 1 when a fresh run replaces a shorter result', async () => {
    const user = userEvent.setup();
    const bigRows = Array.from({ length: 15 }, (_, i) => ({ ref: `DOC-${i + 1}` }));
    let call = 0;

    api.get.mockImplementation((url) => {
      if (url === '/reports') {
        return Promise.resolve({
          data: [{ key: 'document-inventory', label: 'Document Inventory', description: 'desc', filters: [] }],
        });
      }
      if (url === '/categories') return Promise.resolve({ data: [] });
      if (url === '/offices') return Promise.resolve({ data: [] });
      if (url === '/reports/document-inventory') {
        call += 1;
        const rows = call === 1 ? bigRows : [{ ref: 'DOC-1' }];
        return Promise.resolve({
          data: {
            key: 'document-inventory',
            label: 'Document Inventory',
            generated_at: '2026-01-01 00:00:00',
            summary: {},
            columns: [{ key: 'ref', label: 'Reference' }],
            rows,
            total_rows: rows.length,
            truncated: false,
          },
        });
      }
      return Promise.resolve({ data: {} });
    });

    render(<ReportRunner />);
    await waitFor(() => expect(screen.getByRole('button', { name: /run report/i })).toBeInTheDocument());

    await user.click(screen.getByRole('button', { name: /run report/i }));
    await waitFor(() => expect(screen.getByText('Showing 1–10 of 15')).toBeInTheDocument());

    await user.click(screen.getByRole('button', { name: /next/i }));
    await waitFor(() => expect(screen.getByText('Showing 11–15 of 15')).toBeInTheDocument());

    // Re-running with a result that no longer has a page 2 must not leave
    // the view stranded on an empty page.
    await user.click(screen.getByRole('button', { name: /run report/i }));
    await waitFor(() => expect(screen.getByText('Showing 1–1 of 1')).toBeInTheDocument());
    expect(screen.getByText('DOC-1')).toBeInTheDocument();
  });
});
