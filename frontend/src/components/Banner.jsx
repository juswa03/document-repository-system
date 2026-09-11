/**
 * One inline status message. Replaces the old .error-banner / .form-error /
 * .auth-error / .success-banner / .auth-success spread — one component, one
 * look, `tone` picks the colour.
 *
 *   <Banner tone="error">{err}</Banner>
 *   <Banner tone="success">Saved.</Banner>
 *
 * Error banners announce themselves to assistive tech (role="alert").
 */
export default function Banner({ tone = 'info', children, className = '' }) {
  return (
    <p
      className={`banner banner--${tone} ${className}`.trim()}
      role={tone === 'error' ? 'alert' : 'status'}
    >
      {children}
    </p>
  );
}
