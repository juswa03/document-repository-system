import api from './api';

/**
 * Authenticated file download. A plain <a href="/api/documents/1/file">
 * won't work here since the endpoint requires a Bearer token — fetch
 * as a blob instead and trigger the save via a throwaway link.
 */
export async function downloadDocumentFile(id, suggestedName) {
  const response = await api.get(`/documents/${id}/file`, { responseType: 'blob' });
  saveBlob(response.data, suggestedName || `document-${id}`);
}

/**
 * Authenticated report export (Phase 6.2). Streams the CSV the same
 * report endpoint produces with ?format=csv.
 */
export async function downloadReportCsv(key, params = {}) {
  const response = await api.get(`/reports/${key}`, {
    params: { ...params, format: 'csv' },
    responseType: 'blob',
  });
  saveBlob(response.data, `${key}-${new Date().toISOString().slice(0, 10)}.csv`);
}

/** Authenticated audit-log export (Phase 17) — same endpoint, ?format=csv. */
export async function downloadAuditLogCsv(params = {}) {
  const response = await api.get('/admin/audit-log', {
    params: { ...params, format: 'csv' },
    responseType: 'blob',
  });
  saveBlob(response.data, `audit-log-${new Date().toISOString().slice(0, 10)}.csv`);
}

function saveBlob(data, filename) {
  const url = window.URL.createObjectURL(new Blob([data]));
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.URL.revokeObjectURL(url);
}
