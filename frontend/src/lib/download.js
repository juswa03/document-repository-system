import api from './api';

/**
 * Authenticated file downloads.
 *
 * A plain <a href="/api/documents/1/file"> won't work here since the
 * endpoint requires a Bearer token — fetch as a blob instead and trigger
 * the save via a throwaway link.
 *
 * The server already knows the correct filename and MIME type and sends
 * both (Content-Disposition, Content-Type), so prefer those over anything
 * guessed at the call site. Dropping them is how a PDF ends up saved as a
 * nameless .txt full of binary noise.
 */

/** Pull the filename out of a Content-Disposition header, if present. */
function filenameFromHeaders(headers, fallback) {
  const disposition = headers?.['content-disposition'] || headers?.['Content-Disposition'];
  if (!disposition) return fallback;

  // RFC 5987 form first (filename*=UTF-8''name.pdf), then the plain form.
  const encoded = /filename\*=(?:UTF-8'')?([^;]+)/i.exec(disposition);
  if (encoded?.[1]) {
    try {
      return decodeURIComponent(encoded[1].trim().replace(/^["']|["']$/g, ''));
    } catch {
      /* fall through to the plain form */
    }
  }

  const plain = /filename=("?)([^";]+)\1/i.exec(disposition);
  return plain?.[2]?.trim() || fallback;
}

/**
 * Save a response as a file, honouring the server's own filename and
 * content type.
 */
function saveResponse(response, fallbackName) {
  const type = response.headers?.['content-type'] || 'application/octet-stream';
  const filename = filenameFromHeaders(response.headers, fallbackName);

  saveBlob(response.data, filename, type);
}

/**
 * A failed request with responseType: 'blob' still comes back as a blob —
 * axios has no way to know the body is JSON until it's read — so the
 * server's real error message (e.g. a 403 reason) is trapped inside a
 * Blob instead of parsed into err.response.data.message. Every other
 * error handler in this codebase reads that field directly; without this,
 * download failures all look like the same generic message regardless of
 * why they failed.
 */
async function rethrowWithMessage(err) {
  const data = err?.response?.data;
  if (data instanceof Blob && data.type?.includes('json')) {
    try {
      const parsed = JSON.parse(await data.text());
      if (parsed?.message) {
        err.response.data = parsed;
      }
    } catch {
      /* not JSON after all — fall through with the original error */
    }
  }
  throw err;
}

export async function downloadDocumentFile(id, suggestedName) {
  try {
    const response = await api.get(`/documents/${id}/file`, { responseType: 'blob' });
    saveResponse(response, suggestedName || `document-${id}`);
  } catch (err) {
    await rethrowWithMessage(err);
  }
}

/**
 * Download the response file an office admin attached to an approved
 * review, or the repository document they linked in its place. Only the
 * original submitter may fetch it (enforced server-side).
 */
export async function downloadReviewResponseFile(reviewId, suggestedName) {
  try {
    const response = await api.get(`/reviews/${reviewId}/response-file`, { responseType: 'blob' });
    saveResponse(response, suggestedName || `response-${reviewId}`);
  } catch (err) {
    await rethrowWithMessage(err);
  }
}

/**
 * Authenticated report export (Phase 6.2). Streams the CSV the same
 * report endpoint produces with ?format=csv.
 */
export async function downloadReportCsv(key, params = {}) {
  try {
    const response = await api.get(`/reports/${key}`, {
      params: { ...params, format: 'csv' },
      responseType: 'blob',
    });
    saveResponse(response, `${key}-${new Date().toISOString().slice(0, 10)}.csv`);
  } catch (err) {
    await rethrowWithMessage(err);
  }
}

/** Authenticated audit-log export (Phase 17) — same endpoint, ?format=csv. */
export async function downloadAuditLogCsv(params = {}) {
  try {
    const response = await api.get('/admin/audit-log', {
      params: { ...params, format: 'csv' },
      responseType: 'blob',
    });
    saveResponse(response, `audit-log-${new Date().toISOString().slice(0, 10)}.csv`);
  } catch (err) {
    await rethrowWithMessage(err);
  }
}

function saveBlob(data, filename, type = 'application/octet-stream') {
  // The type matters: an untyped blob gives the browser nothing to go on,
  // and Windows in particular then saves it as .txt.
  const url = window.URL.createObjectURL(new Blob([data], { type }));
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.URL.revokeObjectURL(url);
}
