import React from 'react';
import { bytes, duration, temp } from '../api.js';
import { Card, Facts, Meter, Pill } from './ui.jsx';

export default function SystemPage({ status, unit }) {
  const s = status.data;
  if (!s) return <div className="loading">Reading the Drobo…</div>;
  const sys = s.system;
  const memUsed = sys.memTotal - sys.memAvailable;

  return (
    <div className="page">
      <h1 className="page-title">System</h1>
      <div className="grid two">
        <Card title="Software">
          <Facts rows={[
            ['Device name', `${sys.hostname} (${sys.hostname}.local)`],
            ['System', sys.os],
            ['Kernel', sys.kernel],
            ['Boot files', sys.bootSet],
            ['Boot copy on SSD', sys.bootCopyOnSsd ? 'Yes (fast boot)' : 'No (boots from the USB stick)'],
            ['State', <Pill key="st" level={sys.state === 'running' ? 'ok' : 'warning'}>{sys.state}</Pill>],
            ['Up for', duration(sys.uptimeSeconds)],
            ['Clock', new Date(sys.time).toLocaleString()],
          ]} />
        </Card>
        <Card title="Hardware">
          <Facts rows={[
            ['Processor', `Marvell Armada XP, ${sys.cpus} cores`],
            ['Load', sys.load.map((l) => l.toFixed(2)).join(' · ')],
            ['Memory', <span key="m">{bytes(memUsed)} of {bytes(sys.memTotal)} used<Meter value={memUsed} max={sys.memTotal} label="Memory" /></span>],
            ['Processor temperature', temp(s.temperatures.soc, unit)],
            ['Fan', s.pmu?.fanActual != null ? `level ${s.pmu.fanActual} of 31 (target ${s.pmu.fanTarget})` : '—'],
            ['Power controller', s.pmu?.version],
          ]} />
        </Card>
        <Card title="Network">
          {s.network.map((n) => (
            <Facts key={n.name} rows={[
              ['Interface', <span key="i">{n.name} <Pill level={n.up ? 'ok' : 'muted'}>{n.up ? (n.speedMbps ? `${n.speedMbps >= 1000 ? n.speedMbps / 1000 + ' Gb/s' : n.speedMbps + ' Mb/s'}` : 'up') : 'down'}</Pill></span>],
              ['Addresses', n.addresses.join(', ') || '—'],
              ['Received / sent', `${bytes(n.rxBytes)} / ${bytes(n.txBytes)}`],
            ]} />
          ))}
        </Card>
        <Card title="Services">
          <table className="table compact">
            <tbody>
              {s.services.map((v) => (
                <tr key={v.unit}>
                  <td>{v.label}<div className="sub">{v.unit}</div></td>
                  <td className="num"><Pill level={v.state === 'active' || v.state === 'done' ? 'ok' : v.state === 'failed' ? 'critical' : 'muted'}>{v.state}</Pill></td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      </div>
    </div>
  );
}
