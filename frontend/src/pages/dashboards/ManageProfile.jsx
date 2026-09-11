import { useRef, useState } from 'react';
import DashboardShell from './DashboardShell';
import Avatar from '../../components/Avatar';
import { useAuth } from '../../context/AuthContext';
import api from '../../lib/api';
import './dashboards.css';
import Banner from '../../components/Banner';

const ROLE_LABELS = {
  system_admin: 'System admin',
  office_admin: 'Office admin',
  user: 'User / office',
};

export default function ManageProfile() {
  const { user, refreshUser } = useAuth();
  const [fullName, setFullName] = useState(user?.name || '');
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [newPasswordConfirmation, setNewPasswordConfirmation] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');

  // Profile picture. Uploads immediately on choose rather than waiting
  // for "Save changes" — the two are independent, and making a photo
  // wait on the name form would be a surprise.
  const fileRef = useRef(null);
  const [avatarBusy, setAvatarBusy] = useState(false);

  async function uploadAvatar(file) {
    if (!file) return;
    setError('');
    setSuccess('');

    if (!file.type.startsWith('image/')) {
      setError('That file is not an image. Use a JPG, PNG, or WebP.');
      return;
    }
    if (file.size > 2 * 1024 * 1024) {
      setError('That image is too large — the limit is 2MB.');
      return;
    }

    setAvatarBusy(true);
    try {
      const form = new FormData();
      form.append('avatar', file);
      await api.post('/profile/avatar', form);
      await refreshUser?.();
      setSuccess('Profile picture updated.');
    } catch (err) {
      setError(
        err?.response?.data?.message ||
          Object.values(err?.response?.data?.errors || {})[0]?.[0] ||
          'Could not upload that picture.',
      );
    } finally {
      setAvatarBusy(false);
      // Let the same file be re-picked after a failure.
      if (fileRef.current) fileRef.current.value = '';
    }
  }

  async function removeAvatar() {
    setError('');
    setSuccess('');
    setAvatarBusy(true);
    try {
      await api.delete('/profile/avatar');
      await refreshUser?.();
      setSuccess('Profile picture removed.');
    } catch (err) {
      setError(err?.response?.data?.message || 'Could not remove the picture.');
    } finally {
      setAvatarBusy(false);
    }
  }

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');
    setSuccess('');

    if (newPassword && newPassword !== newPasswordConfirmation) {
      setError('The new password and confirmation do not match.');
      return;
    }

    setSaving(true);
    try {
      await api.patch('/profile', {
        full_name: fullName,
        current_password: newPassword ? currentPassword : undefined,
        new_password: newPassword || undefined,
        new_password_confirmation: newPassword ? newPasswordConfirmation : undefined,
      });
      setCurrentPassword('');
      setNewPassword('');
      setNewPasswordConfirmation('');
      setSuccess('Profile updated.');
      await refreshUser?.();
    } catch (err) {
      const message =
        err?.response?.data?.message ||
        Object.values(err?.response?.data?.errors || {})[0]?.[0] ||
        'Could not update your profile.';
      setError(message);
    } finally {
      setSaving(false);
    }
  }

  return (
    <DashboardShell eyebrow={ROLE_LABELS[user?.role] || 'Account'} title="Manage profile">
      {error && <Banner tone="error">{error}</Banner>}
      {success && <Banner tone="success">{success}</Banner>}

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">Profile picture</h2>
            <p className="panel-subtitle">
              Shown beside your name in the sidebar. Optional — your initials are used otherwise.
            </p>
          </div>
        </div>

        <div className="profile-picture-row">
          <Avatar name={user?.name} src={user?.avatar_url} size={72} />

          <div className="btn-row">
            <input
              ref={fileRef}
              id="avatarFile"
              type="file"
              className="u-visually-hidden"
              accept="image/jpeg,image/png,image/webp"
              onChange={(e) => uploadAvatar(e.target.files?.[0])}
            />
            <button
              type="button"
              className="btn btn--outline btn-sm"
              disabled={avatarBusy}
              onClick={() => fileRef.current?.click()}
            >
              {avatarBusy ? 'Working…' : user?.avatar_url ? 'Change picture' : 'Upload picture'}
            </button>
            {user?.avatar_url && (
              <button
                type="button"
                className="btn btn--danger-outline btn-sm"
                disabled={avatarBusy}
                onClick={removeAvatar}
              >
                Remove
              </button>
            )}
          </div>
        </div>

        <p className="cell-muted u-mt-1">JPG, PNG, or WebP — up to 2MB.</p>
      </section>

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">Account details</h2>
            <p className="panel-subtitle">Email, role, and office are managed by your system admin.</p>
          </div>
        </div>
        <dl className="detail-grid">
          <div>
            <dt>Email</dt>
            <dd>{user?.email}</dd>
          </div>
          <div>
            <dt>Role</dt>
            <dd>{ROLE_LABELS[user?.role] || user?.role}</dd>
          </div>
        </dl>
      </section>

      <section className="panel">
        <div className="panel-header">
          <div>
            <h2 className="panel-title">Edit profile</h2>
            <p className="panel-subtitle">Update your display name, or change your password.</p>
          </div>
        </div>

        <form onSubmit={handleSubmit}>
          <div className="dash-field">
            <label className="dash-label" htmlFor="fullName">Full name</label>
            <input
              id="fullName"
              className="dash-input"
              value={fullName}
              onChange={(e) => setFullName(e.target.value)}
            />
          </div>

          <div className="dash-row">
            <div className="dash-field">
              <label className="dash-label" htmlFor="currentPassword">Current password</label>
              <input
                id="currentPassword"
                type="password"
                className="dash-input"
                value={currentPassword}
                onChange={(e) => setCurrentPassword(e.target.value)}
                placeholder="Required to change your password"
                autoComplete="current-password"
              />
            </div>
            <div className="dash-field">
              <label className="dash-label" htmlFor="newPassword">New password</label>
              <input
                id="newPassword"
                type="password"
                className="dash-input"
                value={newPassword}
                onChange={(e) => setNewPassword(e.target.value)}
                placeholder="Leave blank to keep current password"
                autoComplete="new-password"
              />
            </div>
            <div className="dash-field">
              <label className="dash-label" htmlFor="newPasswordConfirmation">Confirm new password</label>
              <input
                id="newPasswordConfirmation"
                type="password"
                className="dash-input"
                value={newPasswordConfirmation}
                onChange={(e) => setNewPasswordConfirmation(e.target.value)}
                autoComplete="new-password"
              />
            </div>
          </div>

          <button type="submit" className="btn btn--primary" disabled={saving}>
            {saving ? 'Saving…' : 'Save changes'}
          </button>
        </form>
      </section>
    </DashboardShell>
  );
}
