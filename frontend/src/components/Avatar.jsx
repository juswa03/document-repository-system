import { useEffect, useState } from 'react';
import api from '../lib/api';

/**
 * A user's profile picture, with their initials as the fallback.
 *
 * Avatars live on the private disk and are served through an
 * authenticated endpoint, so they cannot be dropped straight into an
 * <img src>. The image is fetched as a blob and shown from an object
 * URL, which is revoked when the component unmounts or the source
 * changes so the browser does not leak them.
 */
export default function Avatar({ name, src, size = 36, className = '' }) {
  const [objectUrl, setObjectUrl] = useState(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    if (!src) {
      setObjectUrl(null);
      return undefined;
    }

    let revoked = false;
    let created = null;
    setFailed(false);

    api
      .get(src, { responseType: 'blob' })
      .then(({ data }) => {
        if (revoked) return;
        created = URL.createObjectURL(data);
        setObjectUrl(created);
      })
      .catch(() => {
        if (!revoked) setFailed(true);
      });

    return () => {
      revoked = true;
      if (created) URL.revokeObjectURL(created);
    };
  }, [src]);

  const initials = String(name || '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0])
    .join('')
    .toUpperCase() || '?';

  const style = { width: size, height: size, fontSize: Math.round(size * 0.4) };

  if (objectUrl && !failed) {
    return (
      <img
        className={`avatar ${className}`.trim()}
        style={style}
        src={objectUrl}
        alt=""
        aria-hidden="true"
      />
    );
  }

  return (
    <span className={`avatar avatar--initials ${className}`.trim()} style={style} aria-hidden="true">
      {initials}
    </span>
  );
}
