import React, { useEffect, useState } from 'react';
import { api } from '../api.js';
import { useAction } from '../hooks.js';
import { Card, Field, Message } from './ui.jsx';

export default function SettingsPage({ reloadSession }) {
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const load = () => api.get('settings').then(setData, (e) => setError(e.message));
  useEffect(() => { load(); }, []);

  if (error) return <div className="page"><p className="msg error">{error}</p></div>;
  if (!data) return <div className="loading">Loading settings…</div>;

  return (
    <div className="page">
      <h1 className="page-title">Settings</h1>
      <div className="grid two">
        <General data={data} onSaved={() => { load(); reloadSession(); }} />
        <DateTime data={data} onSaved={load} />
        <Fan data={data} />
        <Gauge data={data} />
        <DiskCare data={data} onSaved={load} />
        <Password />
        <Alerts data={data} onSaved={load} />
        <Power />
        <Updates />
      </div>
    </div>
  );
}

function General({ data, onSaved }) {
  const [hostname, setHostname] = useState(data.hostname);
  const [ui, setUi] = useState(data.config.ui);
  const [act, run] = useAction();

  const save = (e) => {
    e.preventDefault();
    run(async () => {
      if (hostname !== data.hostname) await api.post('settings/hostname', { hostname });
      await api.post('settings/config', { ui });
      onSaved();
    }, hostname !== data.hostname ? `Saved. The Drobo is now ${hostname}.local; you may need to open that address.` : 'Saved.');
  };

  return (
    <Card title="General">
      <form onSubmit={save} className="form">
        <Field label="Device name" hint="Shown on the network as NAME.local. Letters, digits and hyphens.">
          <input value={hostname} onChange={(e) => setHostname(e.target.value)} pattern="[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?" required />
        </Field>
        <div className="field-row">
          <Field label="Temperatures in">
            <select value={ui.tempUnit} onChange={(e) => setUi({ ...ui, tempUnit: e.target.value })}>
              <option value="C">Celsius</option>
              <option value="F">Fahrenheit</option>
            </select>
          </Field>
          <Field label="Refresh every (seconds)">
            <input type="number" min="2" max="120" value={ui.refreshSeconds} onChange={(e) => setUi({ ...ui, refreshSeconds: e.target.value })} />
          </Field>
        </div>
        <label className="check">
          <input type="checkbox" checked={ui.publicStatus} onChange={(e) => setUi({ ...ui, publicStatus: e.target.checked })} />
          <span>Anyone on the network can see the Status, Disks and System pages without signing in</span>
        </label>
        <div className="row"><button className="primary" disabled={act.busy}>Save</button></div>
        <Message state={act} />
      </form>
    </Card>
  );
}

function DateTime({ data, onSaved }) {
  const t = data.time;
  const [tz, setTz] = useState(t.timezone);
  const [ntp, setNtp] = useState(t.ntp);
  const [servers, setServers] = useState(t.servers.join(' '));
  const [manual, setManual] = useState('');
  const [act, run] = useAction();
  // The Drobo's clock, ticking locally from the offset seen when the page loaded.
  const [offset] = useState(() => t.now * 1000 - Date.now());
  const [now, setNow] = useState(() => Date.now() + offset);
  useEffect(() => {
    const id = setInterval(() => setNow(Date.now() + offset), 1000);
    return () => clearInterval(id);
  }, [offset]);

  const fmt = (ms, zone) => new Date(ms).toLocaleString(undefined, { timeZone: zone, dateStyle: 'medium', timeStyle: 'medium' });
  const drift = Math.round(offset / 1000);
  const list = servers.split(/[\s,]+/).filter(Boolean);

  const save = (e) => {
    e.preventDefault();
    run(async () => {
      if (tz !== t.timezone) await api.post('settings/timezone', { timezone: tz });
      if (ntp !== t.ntp || list.join(' ') !== t.servers.join(' ')) await api.post('settings/ntp', { on: ntp, servers: list });
      onSaved();
    }, 'Saved.');
  };
  const setClock = (epochMs) => run(async () => {
    await api.post('settings/time', { epoch: Math.round(epochMs / 1000) });
    onSaved();
  }, 'Clock set.');

  return (
    <Card title="Date and time">
      <form onSubmit={save} className="form">
        <p className="clock">
          <strong>{fmt(now, t.timezone)}</strong>{' '}
          <span className={`pill ${t.ntp ? (t.synchronized ? 'ok' : 'warning') : 'muted'}`}>
            {t.ntp ? (t.synchronized ? `synced${t.currentServer ? ` with ${t.currentServer}` : ''}` : 'not synced yet') : 'network time off'}
          </span>
        </p>
        {Math.abs(drift) >= 60 && (
          <p className="msg warning">The Drobo's clock is {Math.abs(drift)} s {drift > 0 ? 'ahead of' : 'behind'} this computer's.</p>
        )}
        <Field label="Time zone">
          <select value={tz} onChange={(e) => setTz(e.target.value)}>
            {t.timezones.map((z) => <option key={z} value={z}>{z.replace(/_/g, ' ')}</option>)}
          </select>
        </Field>
        <label className="check">
          <input type="checkbox" checked={ntp} onChange={(e) => setNtp(e.target.checked)} />
          <span>Set the clock from the internet (network time)</span>
        </label>
        {ntp && (
          <Field label="Time servers" hint={`Space-separated. Leave empty for Debian's defaults (${t.defaultServers.slice(0, 2).join(', ')}, …).`}>
            <input value={servers} onChange={(e) => setServers(e.target.value)} placeholder="pool.ntp.org or your router's address" />
          </Field>
        )}
        <div className="row"><button className="primary" disabled={act.busy}>Save</button></div>
        {!t.ntp && (
          <>
            <Field label="Set the clock by hand" hint={`In the Drobo's time zone (${t.timezone}). Network time must be off and saved first.`}>
              <div className="row">
                <input type="datetime-local" step="1" value={manual} onChange={(e) => setManual(e.target.value)} />
                <button type="button" disabled={act.busy || !manual} onClick={() => setClock(zonedToEpoch(manual, t.timezone))}>Set</button>
                <button type="button" disabled={act.busy} onClick={() => setClock(Date.now())}>Use this computer's time</button>
              </div>
            </Field>
          </>
        )}
        <Message state={act} />
      </form>
    </Card>
  );
}

/** "YYYY-MM-DDTHH:MM[:SS]" read as wall time in `zone`, to epoch milliseconds. */
function zonedToEpoch(local, zone) {
  const wall = Date.parse(`${local.length === 16 ? `${local}:00` : local}Z`);
  const fmt = new Intl.DateTimeFormat('en-US', {
    timeZone: zone, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', second: '2-digit',
  });
  // The zone's offset at a moment: show it in the zone and compare with UTC.
  const offsetAt = (ms) => {
    const p = Object.fromEntries(fmt.formatToParts(new Date(ms)).map((x) => [x.type, x.value]));
    return Date.UTC(p.year, p.month - 1, p.day, p.hour, p.minute, p.second) - ms;
  };
  // Two passes, so a time just after a daylight-saving change uses the offset in force then.
  let ms = wall - offsetAt(wall);
  ms = wall - offsetAt(ms);
  return ms;
}

function Fan({ data }) {
  const [f, setF] = useState(data.fan);
  const [act, run] = useAction();
  const num = (k) => (e) => setF({ ...f, [k]: e.target.value });
  const save = (e) => {
    e.preventDefault();
    run(() => api.post('settings/fan', f), 'Saved; the fan service restarted with the new settings.');
  };
  return (
    <Card title="Fan">
      <form onSubmit={save} className="form">
        <p className="hint">The fan follows the hotter of the processor and the disks: at the low temperature it runs at the minimum speed, at the high one it runs flat out.</p>
        <Field label="Minimum speed (1–31)" hint="Too low and the fan can stall; 8 works for the replacement fan, 16 for the stock one.">
          <input type="number" min="1" max="31" value={f.FLOOR} onChange={num('FLOOR')} />
        </Field>
        <div className="field-row">
          <Field label="Processor low (°C)"><input type="number" value={f.SOC_LO} onChange={num('SOC_LO')} /></Field>
          <Field label="Processor high (°C)"><input type="number" value={f.SOC_HI} onChange={num('SOC_HI')} /></Field>
        </div>
        <div className="field-row">
          <Field label="Disks low (°C)"><input type="number" value={f.DISK_LO} onChange={num('DISK_LO')} /></Field>
          <Field label="Disks high (°C)"><input type="number" value={f.DISK_HI} onChange={num('DISK_HI')} /></Field>
        </div>
        <div className="row"><button className="primary" disabled={act.busy}>Save</button></div>
        <Message state={act} />
      </form>
    </Card>
  );
}

function Gauge({ data }) {
  const [paths, setPaths] = useState(data.gaugePaths.filter((p) => data.mountPoints.includes(p)));
  const [act, run] = useAction();
  const toggle = (p) => setPaths(paths.includes(p) ? paths.filter((x) => x !== p) : [...paths, p]);
  const save = (e) => {
    e.preventDefault();
    run(() => api.post('settings/gauge', { paths }), 'Saved; the front panel lights restarted.');
  };
  return (
    <Card title="Capacity lights">
      <form onSubmit={save} className="form">
        <p className="hint">The ten blue lights on the front show how full these filesystems are, added together.</p>
        <div className="checks">
          {data.mountPoints.map((p) => (
            <label key={p} className="check">
              <input type="checkbox" checked={paths.includes(p)} onChange={() => toggle(p)} />
              <span>{p}</span>
            </label>
          ))}
        </div>
        <div className="row"><button className="primary" disabled={act.busy || !paths.length}>Save</button></div>
        <Message state={act} />
      </form>
    </Card>
  );
}

function Alerts({ data, onSaved }) {
  const [a, setA] = useState({ ...data.config.alerts, password: '' });
  const [act, run] = useAction();
  const [test, runTest] = useAction();
  const set = (k) => (e) => setA({ ...a, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value });

  const save = (e) => {
    e.preventDefault();
    run(async () => {
      const { passwordSet, ...send } = a;
      await api.post('settings/config', { alerts: send });
      setA({ ...a, password: '' });
      onSaved();
    });
  };
  const sendTest = () => runTest(async () => {
    const r = await api.post('test-email');
    return r;
  }, `Test message sent to ${a.to}.`);

  return (
    <Card title="E-mail alerts" className="span2">
      <form onSubmit={save} className="form">
        <label className="check">
          <input type="checkbox" checked={a.enabled} onChange={set('enabled')} />
          <span>Send an e-mail when a problem starts or clears (checked every 5 minutes)</span>
        </label>
        <div className="field-row">
          <Field label="Send to"><input type="email" value={a.to} onChange={set('to')} placeholder="you@example.com" /></Field>
          <Field label="Send from" hint="Blank: drobo@NAME"><input type="email" value={a.from} onChange={set('from')} /></Field>
          <Field label="Alert on">
            <select value={a.minLevel} onChange={set('minLevel')}>
              <option value="critical">Critical problems only</option>
              <option value="warning">Warnings and critical</option>
              <option value="info">Everything</option>
            </select>
          </Field>
        </div>
        <div className="field-row">
          <Field label="Mail server"><input value={a.server} onChange={set('server')} placeholder="smtp.example.com" /></Field>
          <Field label="Port"><input type="number" value={a.port} onChange={set('port')} /></Field>
          <Field label="Security">
            <select value={a.security} onChange={set('security')}>
              <option value="starttls">STARTTLS (usually port 587)</option>
              <option value="ssl">SSL/TLS (usually port 465)</option>
              <option value="none">None (port 25, local relays only)</option>
            </select>
          </Field>
        </div>
        <div className="field-row">
          <Field label="User name" hint="Blank if the server needs no sign-in"><input value={a.username} onChange={set('username')} autoComplete="off" /></Field>
          <Field label="Password" hint={a.passwordSet ? 'Saved. Leave blank to keep it.' : ''}>
            <input type="password" value={a.password} onChange={set('password')} autoComplete="new-password" />
          </Field>
        </div>
        <div className="field-row">
          <Field label="Disk temperature limit (°C)"><input type="number" value={a.diskTempMax} onChange={set('diskTempMax')} /></Field>
          <Field label="Processor temperature limit (°C)"><input type="number" value={a.socTempMax} onChange={set('socTempMax')} /></Field>
          <Field label="Warn when a filesystem is over (%)"><input type="number" value={a.capacityPct} onChange={set('capacityPct')} /></Field>
        </div>
        <div className="row">
          <button className="primary" disabled={act.busy}>Save</button>
          <button type="button" onClick={sendTest} disabled={test.busy || !data.config.alerts.server}>{test.busy ? 'Sending…' : 'Send a test message'}</button>
        </div>
        <Message state={act} />
        <Message state={test} />
      </form>
    </Card>
  );
}

function Password() {
  const [p, setP] = useState({ current: '', next: '', again: '' });
  const [act, run] = useAction();
  const save = (e) => {
    e.preventDefault();
    if (p.next !== p.again) {
      run(() => Promise.reject(new Error("The two new passwords don't match.")));
      return;
    }
    run(async () => {
      await api.post('settings/password', { current: p.current, new: p.next });
      setP({ current: '', next: '', again: '' });
    }, 'Password changed.');
  };
  const set = (k) => (e) => setP({ ...p, [k]: e.target.value });
  return (
    <Card title="Dashboard password">
      <form onSubmit={save} className="form">
        <Field label="Current password"><input type="password" value={p.current} onChange={set('current')} autoComplete="current-password" /></Field>
        <Field label="New password" hint="At least 8 characters"><input type="password" value={p.next} onChange={set('next')} autoComplete="new-password" minLength={8} /></Field>
        <Field label="New password again"><input type="password" value={p.again} onChange={set('again')} autoComplete="new-password" /></Field>
        <div className="row"><button className="primary" disabled={act.busy || !p.current || !p.next}>Change password</button></div>
        <Message state={act} />
        <p className="hint">Forgot it? On the Drobo, run <code>sudo drobo-webui-passwd</code>.</p>
      </form>
    </Card>
  );
}

function Power() {
  const [act, run] = useAction();
  const go = (action, question, done) => {
    if (!window.confirm(question)) return;
    run(() => api.post('power', { action }), done);
  };
  return (
    <Card title="Power">
      <p className="hint">Restarting takes about a minute and a half. After a shut down, press the power button on the back to start it again.</p>
      <div className="row">
        <button onClick={() => go('reboot', 'Restart the Drobo now? File transfers will be interrupted.', 'Restarting. This page reconnects when it is back.')} disabled={act.busy}>Restart</button>
        <button className="danger" onClick={() => go('poweroff', 'Shut the Drobo down now?', 'Shutting down.')} disabled={act.busy}>Shut down</button>
      </div>
      <Message state={act} />
    </Card>
  );
}

const SCHEDULES = {
  off: 'Off',
  weekly: 'Short test every Sunday at 3:00',
  'weekly-monthly': 'Short test every Sunday, long test on the 1st of each month',
};

function DiskCare({ data, onSaved }) {
  const d = data.disks;
  const [min, setMin] = useState(d.spindownMinutes);
  const [sched, setSched] = useState(d.smartSchedule);
  const [act, run] = useAction();
  const save = (e) => {
    e.preventDefault();
    run(async () => {
      const r = await api.post('settings/disks', { spindownMinutes: Number(min), smartSchedule: sched });
      onSaved();
      return r;
    }, 'Saved.');
  };
  return (
    <Card title="Disk sleep and self-tests">
      <form onSubmit={save} className="form">
        <Field label="Spin bay disks down after" hint={d.hdparm ? 'Only spinning disks in the bays. A sleeping disk takes several seconds to wake for the first file.' : 'Needs hdparm: sudo apt install hdparm'}>
          <select value={min} onChange={(e) => setMin(Number(e.target.value))} disabled={!d.hdparm}>
            {d.spindownChoices.map((m) => <option key={m} value={m}>{m === 0 ? 'Never' : m < 60 ? `${m} minutes idle` : `${m / 60} hour${m > 60 ? 's' : ''} idle`}</option>)}
          </select>
        </Field>
        <Field label="SMART self-tests" hint={d.smartd ? 'Run by smartd. A test due while a disk sleeps runs when it wakes. Failures show on the Disks page and blink the bay red.' : 'smartd is not running.'}>
          <select value={sched} onChange={(e) => setSched(e.target.value)}>
            {Object.entries(SCHEDULES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            {!(sched in SCHEDULES) && <option value={sched}>{sched === 'custom' ? 'Your own schedule in /etc/smartd.conf' : 'Unknown'}</option>}
          </select>
        </Field>
        <div className="row"><button className="primary" disabled={act.busy}>Save</button></div>
        <Message state={act} />
      </form>
    </Card>
  );
}

function Updates() {
  const [u, setU] = useState(null);
  const [error, setError] = useState('');
  const [act, run] = useAction();
  const load = () => api.get('updates').then((r) => { setU(r.updates); setError(''); }, (e) => setError(e.message));
  useEffect(() => { load(); }, []);
  // Poll while apt runs in the background.
  useEffect(() => {
    if (!u?.running) return undefined;
    const id = setInterval(load, 3000);
    return () => clearInterval(id);
  }, [u?.running]);

  const start = (action, done) => run(async () => {
    const r = await api.post('updates', { action });
    setU(r.updates);
  }, done);
  const n = u?.packages.length ?? 0;
  const sec = u?.packages.filter((p) => p.security).length ?? 0;
  const ago = u?.checked ? new Date(u.checked * 1000).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : 'never';

  return (
    <Card title="Software updates" className="span2">
      {error && <p className="msg error">{error}</p>}
      {!u && !error && <p className="hint">Checking…</p>}
      {u && (
        <>
          <p>
            {u.running ? <span className="pill warning">apt is running…</span>
              : n ? <span className={`pill ${sec ? 'warning' : 'ok'}`}>{n} update{n > 1 ? 's' : ''}{sec ? `, ${sec} security` : ''}</span>
                : <span className="pill ok">Up to date</span>}{' '}
            <span className="hint">Package lists last refreshed {ago}. {u.freeMB} MB free on the system disk.</span>
          </p>
          {u.rebootNeeded && <p className="msg warning">A new kernel is installed. Restart to use it.</p>}
          {n > 0 && (
            <details>
              <summary>Packages</summary>
              <ul className="plain">
                {u.packages.map((p) => <li key={p.name}><code>{p.name}</code> {p.from ? `${p.from} → ` : ''}{p.to}{p.security ? ' (security)' : ''}</li>)}
              </ul>
            </details>
          )}
          <div className="row">
            <button onClick={() => start('check', 'Refreshing the package lists.')} disabled={act.busy || u.running}>Check now</button>
            <button className="primary" disabled={act.busy || u.running || !n}
              onClick={() => window.confirm(`Install ${n} update${n > 1 ? 's' : ''}? Services may restart, and this page may stop answering for a moment.`) && start('install', 'Installing in the background.')}>
              Install updates
            </button>
          </div>
          <Message state={act} />
          {u.log && (
            <details>
              <summary>Last run</summary>
              <pre className="log">{u.log}</pre>
            </details>
          )}
        </>
      )}
    </Card>
  );
}
