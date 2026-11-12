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
        <Fan data={data} />
        <Gauge data={data} />
        <Password />
        <Alerts data={data} onSaved={load} />
        <Power />
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
