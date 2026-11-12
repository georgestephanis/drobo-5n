import React, { useCallback, useEffect, useState } from 'react';
import { api } from './api.js';
import { usePoll } from './hooks.js';
import Login from './components/Login.jsx';
import StatusPage from './components/StatusPage.jsx';
import DisksPage from './components/DisksPage.jsx';
import SystemPage from './components/SystemPage.jsx';
import SettingsPage from './components/SettingsPage.jsx';
import LogsPage from './components/LogsPage.jsx';
import { Icon } from './components/ui.jsx';

const PAGES = [
  { id: 'status', label: 'Status', icon: 'drobo' },
  { id: 'disks', label: 'Disks', icon: 'disk' },
  { id: 'system', label: 'System', icon: 'chip' },
  { id: 'settings', label: 'Settings', icon: 'gear', admin: true },
  { id: 'logs', label: 'Logs', icon: 'list', admin: true },
];

function useHashPage() {
  const read = () => (window.location.hash.replace(/^#\/?/, '') || 'status').split('/')[0];
  const [page, setPage] = useState(read);
  useEffect(() => {
    const on = () => setPage(read());
    window.addEventListener('hashchange', on);
    return () => window.removeEventListener('hashchange', on);
  }, []);
  return page;
}

export default function App() {
  const [session, setSession] = useState(null);
  const [error, setError] = useState('');
  const page = useHashPage();

  const loadSession = useCallback(() => {
    api.get('session').then(setSession, (e) => setError(e.message));
  }, []);
  useEffect(loadSession, [loadSession]);

  if (error) return <div className="boot-error">Can't reach the Drobo: {error}</div>;
  if (!session) return <div className="boot">Loading…</div>;

  const admin = session.loggedIn;
  const canSee = admin || session.publicStatus;
  if (!canSee || (!admin && PAGES.find((p) => p.id === page)?.admin)) {
    return <Login session={session} onDone={loadSession} />;
  }
  return <Shell session={session} page={page} admin={admin} reloadSession={loadSession} />;
}

function Shell({ session, page, admin, reloadSession }) {
  const refresh = (session.ui?.refreshSeconds || 5) * 1000;
  const status = usePoll(() => api.get('status'), refresh);
  const problems = usePoll(() => api.get('problems'), 30000);
  const [menuOpen, setMenuOpen] = useState(false);
  const ctx = { session, admin, unit: session.ui?.tempUnit || 'C', status, problems, reloadSession };

  const logout = async () => {
    await api.post('logout');
    reloadSession();
  };

  const Page = { status: StatusPage, disks: DisksPage, system: SystemPage, settings: SettingsPage, logs: LogsPage }[page] || StatusPage;
  const hostname = status.data?.system?.hostname || session.hostname;
  const worst = problems.data?.problems?.[0]?.level;

  return (
    <div className="app">
      <aside className={`sidebar ${menuOpen ? 'open' : ''}`}>
        <div className="brand">
          <span className="brand-mark" aria-hidden="true">
            <i /><i /><i />
          </span>
          <div>
            <div className="brand-name">{hostname}</div>
            <div className="brand-sub">Drobo 5N · Debian</div>
          </div>
          <button className="menu-toggle" onClick={() => setMenuOpen(!menuOpen)} aria-label="Menu">
            <Icon name="menu" />
          </button>
        </div>
        <nav onClick={() => setMenuOpen(false)}>
          {PAGES.filter((p) => admin || !p.admin).map((p) => (
            <a key={p.id} href={`#/${p.id}`} className={page === p.id ? 'active' : ''}>
              <Icon name={p.icon} />
              <span>{p.label}</span>
              {p.id === 'status' && worst && <span className={`dot ${worst}`} title={`${problems.data.problems.length} problem(s)`} />}
            </a>
          ))}
        </nav>
        <div className="sidebar-foot">
          {admin ? (
            <button className="link" onClick={logout}>Sign out</button>
          ) : (
            <a href="#/settings" className="link">Sign in for settings</a>
          )}
          <div className="version">drobo-webui {session.version}</div>
        </div>
      </aside>
      <main>
        {status.error && <div className="banner critical">Lost contact with the Drobo: {status.error}</div>}
        <Page {...ctx} />
      </main>
    </div>
  );
}
