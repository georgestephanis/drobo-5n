import React, { useState } from 'react';
import { api, bytes, duration, hours, memBytes, memInstalled, slotName, temp } from '../api.js';
import { usePoll, useAction } from '../hooks.js';
import FrontPanel, { bayLight, powerLight } from './FrontPanel.jsx';
import { Card, Facts, Icon, Meter, Message, Pill } from './ui.jsx';

export function gaugeUsage(filesystems = [], paths = ['/']) {
  const fs = filesystems.filter((f) => paths.includes(f.mount));
  const total = fs.reduce((s, f) => s + f.used + f.free, 0);
  const used = fs.reduce((s, f) => s + f.used, 0);
  return { total, used, free: total - used, pct: total ? (used * 100) / total : 0, fs };
}

export function StatusBanner({ problems }) {
  const list = problems.data?.problems;
  if (!list) return null;
  const shown = list.filter((p) => p.level !== 'info');
  if (!shown.length) {
    return (
      <div className="hero ok">
        <Icon name="ok" />
        <div>
          <h1>Your Drobo is working normally.</h1>
          <p>{list.length ? list.map((p) => p.message).join(' ') : 'All disks, temperatures and services look fine.'}</p>
        </div>
      </div>
    );
  }
  const worst = shown[0].level;
  return (
    <div className={`hero ${worst}`}>
      <Icon name={worst} />
      <div>
        <h1>{worst === 'critical' ? 'Your Drobo needs attention now.' : 'Your Drobo needs attention.'}</h1>
        <ul>{shown.map((p) => <li key={p.key}>{p.message}</li>)}</ul>
      </div>
    </div>
  );
}

export default function StatusPage({ status, problems, unit, admin }) {
  const [sel, setSel] = useState(null);
  const disks = usePoll(() => api.get('disks'), 60000);
  const s = status.data;
  if (!s) return <div className="loading">Reading the Drobo…</div>;

  const g = gaugeUsage(s.filesystems, s.gaugePaths);
  const gaugeLit = Math.round(g.pct / 10);
  const bySlot = Object.fromEntries((s.disks || []).map((d) => [d.slot, d]));
  const fan = s.pmu?.fanActual;
  const ip = s.network?.find((n) => n.up && n.addresses.length)?.addresses[0];

  return (
    <div className="page">
      <StatusBanner problems={problems} />
      <div className="grid status-grid">
        <Card title="Front panel" className="panel-card">
          <FrontPanel bays={s.bays} gaugeLit={gaugeLit} power={powerLight(s.system.state)} selected={sel} onSelect={setSel} />
          <p className="hint">Select a bay for details.</p>
        </Card>
        {sel == null ? (
          <BayList bays={s.bays} bySlot={bySlot} smart={disks.data?.smart} onSelect={setSel} />
        ) : (
          <BayDetail n={sel} bay={s.bays[sel]} disk={bySlot[`bay${sel}`]} onClose={() => setSel(null)}
            smart={disks.data?.smart} unit={unit} admin={admin} reload={status.reload} temps={s.temperatures} />
        )}
        <Card title="Capacity">
          <div className="capacity">
            <div className="capacity-numbers">
              <div><span className="big">{bytes(g.free)}</span><span>free</span></div>
              <div><span className="big">{bytes(g.used)}</span><span>used</span></div>
              <div><span className="big">{bytes(g.total)}</span><span>total</span></div>
            </div>
            <Meter value={g.pct} label="Space used" />
            <p className="hint">
              The front panel's blue lights show {g.fs.map((f) => f.mount).join(' + ') || 'nothing'}: {Math.round(g.pct)}% used.
            </p>
          </div>
          {s.filesystems.filter((f) => !g.fs.includes(f)).length > 0 && (
            <table className="table compact">
              <tbody>
                {s.filesystems.filter((f) => !g.fs.includes(f)).map((f) => (
                  <tr key={f.mount}>
                    <td>{f.mount}</td>
                    <td className="grow"><Meter value={f.used} max={f.total} label={f.mount} /></td>
                    <td className="num">{bytes(f.free)} free</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>
        <Card title="At a glance" className="span2">
          <div className="tiles">
            <Tile label="Processor" value={temp(s.temperatures.soc, unit)} />
            <Tile label="Fan" value={fan != null ? `${Math.round((fan / 31) * 100)}%` : '—'} sub={s.pmu?.fanTarget != null ? `target ${s.pmu.fanTarget}/31` : ''} />
            <Tile label="Backup battery" value={s.pmu?.batteryPercent != null ? `${s.pmu.batteryPercent}%` : '—'}
              sub={s.pmu?.onBattery ? 'power failed: running on battery' : s.pmu?.batteryVolts != null ? `${s.pmu.batteryVolts.toFixed(2)} V` : ''} />
            <Tile label="Power input" value={s.pmu?.inputVolts != null ? `${s.pmu.inputVolts.toFixed(2)} V` : '—'} sub="12 V supply" />
            <Tile label="Up for" value={duration(s.system.uptimeSeconds)} />
            <Tile label="Load" value={s.system.load[0].toFixed(2)} sub={`${s.system.cpus} cores`} />
            <Tile label="Memory" value={`${Math.round(((s.system.memTotal - s.system.memAvailable) / s.system.memTotal) * 100)}%`} sub={`of ${memBytes(memInstalled(s.system.memTotal), 0)}`} />
            <Tile label="Address" small value={ip ? ip.split('/')[0] : '—'} sub={`${s.system.hostname}.local`} />
          </div>
        </Card>
      </div>
    </div>
  );
}

function Tile({ label, value, sub, small }) {
  return (
    <div className="tile">
      <div className="tile-label">{label}</div>
      <div className={`tile-value ${small ? 'small' : ''}`}>{value}</div>
      {sub && <div className="tile-sub">{sub}</div>}
    </div>
  );
}

const LIGHT_TEXT = {
  off: 'Empty',
  green: 'Disk powered and ready',
  yellow: 'Disk present, bay powered off',
  'red-blink': 'Marked as failed',
};

const LIGHT_PILL = { green: 'ok', off: 'muted', yellow: 'warning', 'red-blink': 'critical' };

function BayList({ bays, bySlot, smart, onSelect }) {
  const rows = [0, 1, 2, 3, 4].map((n) => ({ n, slot: `bay${n}`, bay: bays[n] }));
  const internal = ['msata', 'usb'].filter((k) => bySlot[k]);
  return (
    <Card title="Bays">
      <table className="table compact clickable">
        <tbody>
          {rows.map(({ n, slot, bay }) => {
            const d = bySlot[slot];
            const light = bayLight(bay);
            return (
              <tr key={slot} onClick={() => onSelect(n)}>
                <td><strong>Bay {n}</strong></td>
                <td>{d ? `${d.model} · ${bytes(d.bytes)}` : <span className="sub">{bay?.present ? 'Disk present' : '—'}</span>}</td>
                <td className="num">{d ? smartText(smart?.[d.name]) : <Pill level={LIGHT_PILL[light]}>{LIGHT_TEXT[light]}</Pill>}</td>
              </tr>
            );
          })}
          {internal.map((k) => (
            <tr key={k}>
              <td><strong>{slotName(k)}</strong></td>
              <td>{bySlot[k].model} · {bytes(bySlot[k].bytes)}</td>
              <td className="num">{k === 'usb' ? <Pill level="muted">boot stick</Pill> : smartText(smart?.[bySlot[k].name])}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </Card>
  );
}

function BayDetail({ n, bay, disk, smart, unit, admin, reload, temps, onClose }) {
  const [act, run] = useAction();
  const light = bayLight(bay);
  const sm = disk ? smart?.[disk.name] : null;
  const toggle = () => {
    const on = !bay.powered;
    if (!on && !window.confirm(`Power off bay ${n}? The disk will spin down and disappear from Linux.`)) return;
    run(async () => {
      await api.post('bay', { bay: n, on });
      await reload();
    }, on ? 'Bay powered on; the disk shows up in a few seconds.' : 'Bay powered off.');
  };

  return (
    <Card title={`Bay ${n}`} actions={<div className="row"><Pill level={LIGHT_PILL[light]}>{LIGHT_TEXT[light]}</Pill><button className="link" onClick={onClose}>All bays</button></div>}>
      {disk ? (
        <Facts rows={[
          ['Disk', `${disk.vendor && disk.vendor !== 'ATA' ? disk.vendor + ' ' : ''}${disk.model}`],
          ['Size', bytes(disk.bytes)],
          ['Device', `/dev/${disk.name}`],
          ['Temperature', temp(temps.disks[disk.name] ?? sm?.temperature, unit)],
          ['Health', smartText(sm)],
          sm?.powerOnHours != null && ['Powered on', hours(sm.powerOnHours)],
          sm?.link && ['Link', sm.link],
        ]} />
      ) : (
        <p className="hint">{bay?.present ? (bay.powered ? 'The bay is powered but Linux hasn\'t found a disk yet.' : 'There is a disk here, but the bay is powered off.') : 'No disk in this bay.'}</p>
      )}
      {admin && bay?.present && (
        <div className="row">
          <button onClick={toggle} disabled={act.busy}>{bay.powered ? 'Power off bay' : 'Power on bay'}</button>
        </div>
      )}
      <Message state={act} />
    </Card>
  );
}

export function smartText(sm) {
  if (!sm) return 'Checking…';
  if (!sm.available) return 'SMART not available';
  if (sm.asleep) return 'Asleep (not woken to check)';
  if (sm.passed === false) return <Pill level="critical">Failing</Pill>;
  const bad = (sm.reallocated || 0) + (sm.pending || 0) + (sm.uncorrectable || 0);
  if (bad > 0) return <Pill level="warning">{bad} bad sectors</Pill>;
  return <Pill level="ok">Good</Pill>;
}

export { slotName };
