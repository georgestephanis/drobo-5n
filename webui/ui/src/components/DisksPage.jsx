import React from 'react';
import { api, bytes, hours, slotName, temp } from '../api.js';
import { usePoll } from '../hooks.js';
import { Card, Pill } from './ui.jsx';
import { smartText } from './StatusPage.jsx';

export default function DisksPage({ status, unit, admin }) {
  const disks = usePoll(() => api.get('disks'), 60000);
  const s = status.data;
  const list = disks.data?.disks || s?.disks || [];
  const smart = disks.data?.smart || {};
  const arrays = s?.arrays || [];

  const refresh = () => api.get('disks', { fresh: 1 }).then(() => disks.reload());

  return (
    <div className="page">
      <h1 className="page-title">Disks</h1>
      <Card title="All disks" actions={admin && <button onClick={refresh}>Check SMART now</button>}>
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr><th>Slot</th><th>Disk</th><th className="num">Size</th><th className="num">Temp</th><th>Health</th><th className="num">Powered on</th><th className="num">Bad sectors</th></tr>
            </thead>
            <tbody>
              {list.map((d) => {
                const sm = smart[d.name];
                const bad = sm ? (sm.reallocated || 0) + (sm.pending || 0) + (sm.uncorrectable || 0) : null;
                return (
                  <tr key={d.name}>
                    <td><strong>{slotName(d.slot)}</strong><div className="sub">/dev/{d.name}</div></td>
                    <td>{d.model || '—'}<div className="sub">{sm?.rpm ? `${sm.rpm} rpm` : sm?.rpm === 0 ? 'SSD' : ''}{sm?.link ? ` · ${sm.link}` : ''}</div></td>
                    <td className="num">{bytes(d.bytes)}</td>
                    <td className="num">{temp(s?.temperatures?.disks?.[d.name] ?? sm?.temperature, unit)}</td>
                    <td>{d.slot === 'usb' ? <Pill level="muted">n/a</Pill> : smartText(sm)}</td>
                    <td className="num">{hours(sm?.powerOnHours)}</td>
                    <td className="num">{bad ?? '—'}</td>
                  </tr>
                );
              })}
              {!list.length && <tr><td colSpan={7} className="hint">No disks found.</td></tr>}
            </tbody>
          </table>
        </div>
        <p className="hint">SMART is read every two minutes at most, and sleeping disks are not woken to check.</p>
      </Card>

      <Card title="Filesystems">
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th>Mounted at</th><th>Device</th><th>Type</th><th className="num">Used</th><th className="num">Free</th><th className="num">Size</th></tr></thead>
            <tbody>
              {(s?.filesystems || []).map((f) => (
                <tr key={f.mount}>
                  <td><strong>{f.mount}</strong>{f.readOnly && <Pill level="warning">read-only</Pill>}</td>
                  <td>{f.device}</td>
                  <td>{f.type}</td>
                  <td className="num">{bytes(f.used)}</td>
                  <td className="num">{bytes(f.free)}</td>
                  <td className="num">{bytes(f.total)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>

      <Card title="RAID arrays">
        {arrays.length ? (
          <table className="table">
            <thead><tr><th>Array</th><th>Level</th><th>Members</th><th>Health</th></tr></thead>
            <tbody>
              {arrays.map((a) => (
                <tr key={a.name}>
                  <td><strong>{a.name}</strong></td>
                  <td>{a.level || '—'}</td>
                  <td>{a.members.join(', ')}</td>
                  <td>{a.degraded ? <Pill level="critical">Degraded [{a.health}]</Pill> : <Pill level="ok">{a.state} [{a.health}]</Pill>}{a.sync && <div className="sub">{a.sync}</div>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <p className="hint">No Linux software RAID (md) arrays. Disks from a Drobo disk pack use BeyondRAID, which Linux can't read; they need reformatting before use here.</p>
        )}
      </Card>
    </div>
  );
}
