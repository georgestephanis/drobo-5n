import React, { useEffect, useRef, useState } from 'react';
import { api } from '../api.js';
import { Card } from './ui.jsx';

const UNITS = [
  ['drobo-fan', 'Fan control'],
  ['drobo-leds', 'Front panel lights'],
  ['drobo-bays', 'Bay power'],
  ['drobo-powerbtn', 'Power button'],
  ['drobo-boot-ok', 'Boot check'],
  ['drobo-webui-alerts', 'Alerts'],
  ['drobo-webui', 'Web dashboard'],
  ['smbd', 'Samba'],
  ['ssh', 'SSH'],
  ['kernel', 'Kernel'],
];

export default function LogsPage() {
  const [unit, setUnit] = useState('drobo-fan');
  const [lines, setLines] = useState(200);
  const [text, setText] = useState('');
  const [error, setError] = useState('');
  const pre = useRef(null);
  useEffect(() => { if (pre.current) pre.current.scrollTop = pre.current.scrollHeight; }, [text]);  // newest at the bottom, in view

  const load = () => {
    setError('');
    api.get('logs', { unit, lines }).then((r) => setText(r.text || '(nothing logged)'), (e) => setError(e.message));
  };
  useEffect(load, [unit, lines]);

  return (
    <div className="page">
      <h1 className="page-title">Logs</h1>
      <Card title="System journal" actions={
        <div className="row">
          <select value={unit} onChange={(e) => setUnit(e.target.value)} aria-label="Log">
            {UNITS.map(([u, l]) => <option key={u} value={u}>{l}</option>)}
          </select>
          <select value={lines} onChange={(e) => setLines(Number(e.target.value))} aria-label="Lines">
            {[100, 200, 500, 1000].map((n) => <option key={n} value={n}>last {n}</option>)}
          </select>
          <button onClick={load}>Refresh</button>
        </div>
      }>
        {error ? <p className="msg error">{error}</p> : <pre className="log" ref={pre}>{text}</pre>}
      </Card>
    </div>
  );
}
