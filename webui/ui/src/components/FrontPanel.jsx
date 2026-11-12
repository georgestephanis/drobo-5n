import React from 'react';

// The bay light rules are the same as drobo-leds.sh, so the picture matches the real front panel.
export function bayLight(b) {
  if (!b) return 'off';
  if (b.failed) return 'red-blink';
  if (!b.present) return 'off';
  return b.powered ? 'green' : 'yellow';
}

export function powerLight(state) {
  if (state === 'running') return 'green';
  if (['starting', 'initializing', 'degraded'].includes(state)) return 'yellow';
  return 'red';
}

const W = 340;
const BAY_H = 40;
const BAY_GAP = 8;
const TOP = 26;

/**
 * Front of the Drobo 5N: five bays top (0) to bottom (4), each with its light on the right,
 * then the row of ten blue capacity lights, with the power and activity lights at the far end.
 */
export default function FrontPanel({ bays = [], gaugeLit = 0, power = 'green', selected, onSelect }) {
  const gaugeY = TOP + 5 * (BAY_H + BAY_GAP) + 14;
  return (
    <svg className="front-panel" viewBox={`0 0 ${W} ${gaugeY + 34}`} role="img" aria-label="Drobo front panel">
      <defs>
        <linearGradient id="fp-body" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="#2c2f33" />
          <stop offset="1" stopColor="#141517" />
        </linearGradient>
        <linearGradient id="fp-drive" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="#4a4e54" />
          <stop offset="1" stopColor="#33363b" />
        </linearGradient>
        <filter id="fp-glow" x="-100%" y="-100%" width="300%" height="300%">
          <feGaussianBlur stdDeviation="2.2" result="b" />
          <feMerge><feMergeNode in="b" /><feMergeNode in="SourceGraphic" /></feMerge>
        </filter>
      </defs>
      <rect x="1" y="1" width={W - 2} height={gaugeY + 32} rx="16" fill="url(#fp-body)" stroke="#000" />
      {[0, 1, 2, 3, 4].map((n) => {
        const b = bays[n];
        const y = TOP + n * (BAY_H + BAY_GAP);
        const light = bayLight(b);
        const isSel = selected === n;
        return (
          <g key={n} className={`fp-bay ${isSel ? 'selected' : ''}`} onClick={() => onSelect?.(n)}
            role="button" tabIndex={0} aria-label={`Bay ${n}`}
            onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && onSelect?.(n)}>
            <rect x="20" y={y} width={W - 70} height={BAY_H} rx="5" fill="#0b0c0d" stroke={isSel ? '#7cc242' : '#000'} strokeWidth={isSel ? 2 : 1} />
            {b?.present && (
              <g>
                <rect x="24" y={y + 4} width={W - 78} height={BAY_H - 8} rx="3" fill="url(#fp-drive)" />
                <rect x="34" y={y + BAY_H / 2 - 2} width="60" height="4" rx="2" fill="#1d1f22" />
              </g>
            )}
            <text x="30" y={y + BAY_H / 2 + 4} className="fp-label" opacity={b?.present ? 0 : 1}>{n}</text>
            <circle cx={W - 30} cy={y + BAY_H / 2} r="6" className={`led ${light}`} filter={light === 'off' ? undefined : 'url(#fp-glow)'} />
          </g>
        );
      })}
      {Array.from({ length: 10 }, (_, i) => (
        <rect key={i} x={30 + i * 20} y={gaugeY} width="12" height="5" rx="1.5"
          className={`led gauge ${i < gaugeLit ? 'blue' : 'off'}`} filter={i < gaugeLit ? 'url(#fp-glow)' : undefined} />
      ))}
      <circle cx={W - 54} cy={gaugeY + 2.5} r="4" className="led act" />
      <circle cx={W - 30} cy={gaugeY + 2.5} r="5" className={`led ${power}`} filter="url(#fp-glow)" />
    </svg>
  );
}
