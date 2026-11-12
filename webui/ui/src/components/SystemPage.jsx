import React from 'react';
import { bytes, duration, memBytes, memInstalled, temp } from '../api.js';
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
            ['Memory', <span key="m">{memBytes(memUsed)} of {memBytes(sys.memTotal)} used ({memBytes(memInstalled(sys.memTotal), 0)} installed)<Meter value={memUsed} max={sys.memTotal} label="Memory" /></span>],
            ['Processor temperature', temp(s.temperatures.soc, unit)],
            ['Fan', s.pmu?.fanActual != null ? `level ${s.pmu.fanActual} of 31 (target ${s.pmu.fanTarget})` : '—'],
            ['Power controller', s.pmu?.version],
          ]} />
        </Card>
        <Card title="Power">
          <Facts rows={[
            ['Input', s.pmu?.onBattery
              ? <Pill key="p" level="critical">Power failed: running on battery</Pill>
              : s.pmu?.inputVolts != null ? `${s.pmu.inputVolts.toFixed(2)} V from the 12 V supply` : '—'],
            ['Backup battery', s.pmu?.batteryVolts != null
              ? <span key="b">{s.pmu.batteryVolts.toFixed(2)} V, about {s.pmu.batteryPercent}% charged<Meter value={s.pmu.batteryPercent} max={100} label="Battery"
                level={s.pmu.batteryPercent >= 30 ? 'ok' : s.pmu.batteryPercent >= 15 ? 'warning' : 'critical'} /></span>
              : '—'],
            ['What it\'s for', 'A single 18650 cell. If the power fails, it keeps the Drobo running for a few seconds so Linux can shut down cleanly.'],
            ['Power controller sensors', s.pmu?.sensors?.length ? s.pmu.sensors.map((t) => temp(t, unit)).join(' · ') : '—'],
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
