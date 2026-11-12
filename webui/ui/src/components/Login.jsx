import React, { useState } from 'react';
import { api } from '../api.js';

export default function Login({ session, onDone }) {
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true);
    setError('');
    try {
      await api.post('login', { password });
      onDone();
    } catch (err) {
      setError(err.message);
      setBusy(false);
    }
  };

  return (
    <div className="login">
      <form className="login-box" onSubmit={submit}>
        <div className="login-brand">
          <span className="brand-mark" aria-hidden="true"><i /><i /><i /></span>
          <div>
            <div className="brand-name">{session.hostname}</div>
            <div className="brand-sub">Drobo 5N dashboard</div>
          </div>
        </div>
        {session.passwordSet ? (
          <>
            <label className="field">
              <span className="field-label">Password</span>
              <input type="password" autoFocus autoComplete="current-password" value={password}
                onChange={(e) => setPassword(e.target.value)} />
            </label>
            {error && <p className="msg error" role="alert">{error}</p>}
            <button className="primary" disabled={busy || !password}>{busy ? 'Signing in…' : 'Sign in'}</button>
            {session.publicStatus && <a className="link" href="#/status">Back to the status page</a>}
          </>
        ) : (
          <div className="setup">
            <p>No dashboard password is set yet. Sign in to the Drobo over SSH and run:</p>
            <pre>sudo drobo-webui-passwd</pre>
            <p>Then reload this page.</p>
          </div>
        )}
      </form>
    </div>
  );
}
