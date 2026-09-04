// Client-side mirror of the server's upload policy (config/documents.php).
// The server re-checks everything — this is just faster feedback.

export const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx'];
export const MAX_UPLOAD_MB = 20;

/**
 * @returns {string} an error message, or '' when the file is acceptable.
 */
export function checkUploadFile(file) {
  if (!file) return '';
  const ext = file.name.split('.').pop()?.toLowerCase();
  if (!ALLOWED_EXTENSIONS.includes(ext)) {
    return `Accepted file types: ${ALLOWED_EXTENSIONS.join(', ')}.`;
  }
  if (file.size > MAX_UPLOAD_MB * 1024 * 1024) {
    return `File is too large — the limit is ${MAX_UPLOAD_MB} MB.`;
  }
  return '';
}
