import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import ReviewQueue from './ReviewQueue';
import api from '../../lib/api';

vi.mock('../../lib/api');

vi.mock('../../context/AuthContext', () => ({
  useAuth: () => ({ user: { id: 1, role: 'office_admin', full_name: 'Test Reviewer' } }),
}));

function queueItem(overrides = {}) {
  return {
    kind: 'document',
    id: 1,
    ref: 'DOC-0001',
    submitter: 'Someone',
    type: 'Report',
    access_level: 'internal',
    assigned_to: null,
    submitted_at: '2026-01-01T00:00:00Z',
    ...overrides,
  };
}

function mockQueuePage(items, meta = { current_page: 1, last_page: 1, total: items.length, per_page: 20 }) {
  return { data: { data: items, meta } };
}

beforeEach(() => {
  vi.clearAllMocks();
});

function setupApi({ queuePages = {}, responseDocs = [] } = {}) {
  api.get.mockImplementation((url, config) => {
    if (url === '/office-admin/review-config') {
      return Promise.resolve({ data: { checklists: {}, reviewers: [] } });
    }
    if (url === '/categories') {
      return Promise.resolve({ data: [] });
    }
    if (url === '/office-admin/queue') {
      const scope = config?.params?.scope || 'all';
      const page = config?.params?.page || 1;
      const key = `${scope}:${page}`;
      return Promise.resolve(queuePages[key] || mockQueuePage([]));
    }
    if (url === '/office-admin/response-documents') {
      return Promise.resolve({ data: { data: responseDocs } });
    }
    return Promise.resolve({ data: {} });
  });
}

describe('ReviewQueue scope tabs', () => {
  it('resets the page back to 1 when switching scope tabs', async () => {
    const user = userEvent.setup();

    setupApi({
      queuePages: {
        'all:1': mockQueuePage([queueItem({ id: 1, ref: 'DOC-0001' })], {
          current_page: 1,
          last_page: 2,
          total: 2,
          per_page: 1,
        }),
        'all:2': mockQueuePage([queueItem({ id: 2, ref: 'DOC-0002' })], {
          current_page: 2,
          last_page: 2,
          total: 2,
          per_page: 1,
        }),
        // "mine" only has one page — if the page number from "all" (2)
        // leaks across scopes, this request is the one that would fire
        // and come back empty.
        'mine:1': mockQueuePage([queueItem({ id: 3, ref: 'DOC-0003' })]),
      },
    });

    render(
      <MemoryRouter>
        <ReviewQueue />
      </MemoryRouter>,
    );

    await waitFor(() => expect(screen.getByText('DOC-0001')).toBeInTheDocument());

    // Go to page 2 of "All pending".
    await user.click(screen.getByRole('button', { name: /next/i }));
    await waitFor(() => expect(screen.getByText('DOC-0002')).toBeInTheDocument());

    // Switching to "Assigned to me" must not carry page 2 along — the
    // component should request mine's page 1, not mine's (nonexistent) page 2.
    await user.click(screen.getByRole('button', { name: 'Assigned to me' }));

    await waitFor(() => expect(screen.getByText('DOC-0003')).toBeInTheDocument());

    const calledWithMinePage2 = api.get.mock.calls.some(
      ([url, config]) => url === '/office-admin/queue' && config?.params?.scope === 'mine' && config?.params?.page === 2,
    );
    expect(calledWithMinePage2).toBe(false);
  });
});

describe('ReviewQueue response-document picker', () => {
  it('ignores a slower, stale search response that resolves after a newer one', async () => {
    const user = userEvent.setup();

    let resolveFirst;
    const firstCallPromise = new Promise((resolve) => {
      resolveFirst = resolve;
    });

    let callCount = 0;
    api.get.mockImplementation((url, config) => {
      if (url === '/office-admin/review-config') {
        return Promise.resolve({ data: { checklists: {}, reviewers: [] } });
      }
      if (url === '/categories') {
        return Promise.resolve({ data: [] });
      }
      if (url === '/office-admin/queue') {
        return Promise.resolve(mockQueuePage([queueItem({ id: 1, ref: 'DOC-0001' })]));
      }
      if (url === '/office-admin/response-documents') {
        callCount += 1;
        if (callCount === 1) {
          // The first (stale) request resolves LATER, after the second.
          return firstCallPromise.then(() => ({
            data: { data: [{ id: 99, ref: 'STALE-0001', title: 'Stale result', category: null, reporting_period: null, version_number: 1 }] },
          }));
        }
        // The second (latest) request resolves immediately.
        return Promise.resolve({
          data: { data: [{ id: 100, ref: 'FRESH-0001', title: 'Fresh result', category: null, reporting_period: null, version_number: 1 }] },
        });
      }
      return Promise.resolve({ data: {} });
    });

    render(
      <MemoryRouter>
        <ReviewQueue />
      </MemoryRouter>,
    );

    await waitFor(() => expect(screen.getByText('DOC-0001')).toBeInTheDocument());

    await user.click(screen.getByRole('button', { name: /review & approve/i }));
    await user.click(screen.getByRole('button', { name: /send an existing document/i }));

    // First (stale) request fires on opening the picker; trigger the
    // second (fresh) request by typing in the search box.
    const search = await screen.findByPlaceholderText(/search this office/i);
    await user.type(search, 'q');

    await waitFor(() => expect(screen.getByText('Fresh result')).toBeInTheDocument());

    // Now let the slow first request resolve — it must NOT clobber the
    // fresh result that's already on screen.
    resolveFirst();
    await waitFor(() => expect(callCount).toBeGreaterThanOrEqual(2));

    expect(screen.getByText('Fresh result')).toBeInTheDocument();
    expect(screen.queryByText('Stale result')).not.toBeInTheDocument();
  });
});
