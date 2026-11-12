import React from 'react';

const PATHS = {
  drobo: 'M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zm2 4v2h10V7H7zm0 4v2h10v-2H7zm0 4v2h4v-2H7z',
  disk: 'M4 6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6zm8 2a4 4 0 1 0 0 8 4 4 0 0 0 0-8zm0 3a1 1 0 1 1 0 2 1 1 0 0 1 0-2z',
  chip: 'M9 2h2v2h2V2h2v2h2a2 2 0 0 1 2 2v2h2v2h-2v2h2v2h-2v2a2 2 0 0 1-2 2h-2v2h-2v-2h-2v2H9v-2H7a2 2 0 0 1-2-2v-2H3v-2h2v-2H3V8h2V6a2 2 0 0 1 2-2h2V2zm0 6v8h6V8H9z',
  gear: 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8zm9 4-.1-1.3 2-1.6-2-3.4-2.4.9a8 8 0 0 0-2.2-1.3L16 2h-4l-.4 2.4a8 8 0 0 0-2.2 1.3l-2.4-1-2 3.5 2 1.6a8 8 0 0 0 0 2.4l-2 1.6 2 3.4 2.4-.9a8 8 0 0 0 2.2 1.3L12 22h4l.4-2.4a8 8 0 0 0 2.2-1.3l2.4.9 2-3.4-2-1.6.1-1.2z',
  list: 'M4 5h2v2H4V5zm4 0h12v2H8V5zM4 11h2v2H4v-2zm4 0h12v2H8v-2zm-4 6h2v2H4v-2zm4 0h12v2H8v-2z',
  share: 'M3 6a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v3h-2V8H5v10h6v2H5a2 2 0 0 1-2-2V6zm15 7a2 2 0 1 1 0 4 2 2 0 0 1 0-4zm-4 5a2 2 0 1 1 0 4 2 2 0 0 1 0-4zm8 0a2 2 0 1 1 0 4 2 2 0 0 1 0-4zm-4.9-1.6 1.8 1-.8 1.4-1.8-1 .8-1.4zm-.8 3.4 1.8-1 .8 1.4-1.8 1-.8-1.4z',
  menu: 'M3 6h18v2H3V6zm0 5h18v2H3v-2zm0 5h18v2H3v-2z',
  ok: 'M12 2a10 10 0 1 1 0 20 10 10 0 0 1 0-20zm-1.2 13.6 6.4-6.4-1.4-1.4-5 5-2.2-2.2-1.4 1.4 3.6 3.6z',
  warning: 'M12 2 1 21h22L12 2zm-1 7h2v6h-2V9zm0 8h2v2h-2v-2z',
  critical: 'M12 2a10 10 0 1 1 0 20 10 10 0 0 1 0-20zm-1 5v7h2V7h-2zm0 9v2h2v-2h-2z',
  info: 'M12 2a10 10 0 1 1 0 20 10 10 0 0 1 0-20zm-1 8v7h2v-7h-2zm0-3v2h2V7h-2z',
};

export function Icon({ name, className = '' }) {
  return (
    <svg className={`icon ${className}`} viewBox="0 0 24 24" aria-hidden="true">
      <path d={PATHS[name] || PATHS.info} fill="currentColor" fillRule="evenodd" />
    </svg>
  );
}

export function Card({ title, actions, children, className = '' }) {
  return (
    <section className={`card ${className}`}>
      {(title || actions) && (
        <header>
          <h2>{title}</h2>
          {actions && <div className="card-actions">{actions}</div>}
        </header>
      )}
      <div className="card-body">{children}</div>
    </section>
  );
}

// A labelled list of facts: [[label, value], ...]
export function Facts({ rows }) {
  return (
    <dl className="facts">
      {rows.filter(Boolean).map(([k, v]) => (
        <React.Fragment key={k}>
          <dt>{k}</dt>
          <dd>{v ?? '—'}</dd>
        </React.Fragment>
      ))}
    </dl>
  );
}

// Horizontal meter; level picks the colour (ok / warning / critical)
export function Meter({ value, max = 100, level, label }) {
  const pct = max > 0 ? Math.min(100, Math.max(0, (value / max) * 100)) : 0;
  const lv = level || (pct >= 95 ? 'critical' : pct >= 85 ? 'warning' : 'ok');
  return (
    <div className="meter" role="meter" aria-valuenow={Math.round(pct)} aria-valuemin={0} aria-valuemax={100} aria-label={label}>
      <div className={`meter-fill ${lv}`} style={{ width: `${pct}%` }} />
    </div>
  );
}

export function Pill({ level = 'ok', children }) {
  return <span className={`pill ${level}`}>{children}</span>;
}

export function Message({ state }) {
  if (state.error) return <p className="msg error" role="alert">{state.error}</p>;
  if (state.message) return <p className="msg ok" role="status">{state.message}</p>;
  return null;
}

export function Field({ label, hint, children }) {
  return (
    <label className="field">
      <span className="field-label">{label}</span>
      {children}
      {hint && <span className="field-hint">{hint}</span>}
    </label>
  );
}
