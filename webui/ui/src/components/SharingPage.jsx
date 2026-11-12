import React, { useState } from 'react';
import { api, bytes, slotName } from '../api.js';
import { usePoll, useAction } from '../hooks.js';
import { Card, Field, Icon, Message, Meter, Pill } from './ui.jsx';

// Sharing: bay disks on the network (Samba), one share per disk, and who may use which.
export default function SharingPage() {
  const poll = usePoll(() => api.get('sharing'), 15000);
  const [sharing, setSharing] = useState(null);
  const sh = sharing || poll.data?.sharing;
  // Every change answers with the new state; show it at once instead of waiting for the next poll.
  const apply = (r) => { if (r?.sharing) setSharing(r.sharing); poll.reload().then(() => setSharing(null)); };

  if (poll.error && !sh) return <div className="page"><p className="msg error">{poll.error}</p></div>;
  if (!sh) return <div className="loading">Reading the disks…</div>;

  return (
    <div className="page">
      <h1 className="page-title">Sharing</h1>
      <Overview sh={sh} apply={apply} />
      <Disks sh={sh} apply={apply} />
      <People sh={sh} apply={apply} />
    </div>
  );
}

function Step({ done, children }) {
  return (
    <li className={`step ${done ? 'done' : ''}`}>
      <Icon name={done ? 'ok' : 'info'} />
      <span>{children}</span>
    </li>
  );
}

function Overview({ sh, apply }) {
  const [act, run] = useAction();
  const host = sh.hostname;
  const someoneHasAccess = sh.users.some((u) => u.all || u.disks.length);
  const shared = sh.disks.filter((d) => d.mounted && d.users.length);

  const toggle = () =>
    run(async () => apply(await api.post('sharing', { on: !sh.enabled })),
      sh.enabled ? 'Sharing is off; the disks are unmounted.' : 'Sharing is on.');

  return (
    <Card title="Network sharing"
      actions={sh.samba.installed && (
        <button className={sh.enabled ? '' : 'primary'} onClick={toggle} disabled={act.busy}>
          {act.busy ? 'Working…' : sh.enabled ? 'Turn off' : 'Turn on'}
        </button>
      )}>
      <p>
        {sh.enabled ? <Pill level="ok">On</Pill> : <Pill level="muted">Off</Pill>}{' '}
        When sharing is on, every disk you put in a bay is mounted and shared on the network as its own
        folder, named after the disk. Each person below signs in with their own name and password and
        sees the disks you gave them.
      </p>
      {!sh.samba.installed && (
        <p className="msg warning">
          Samba (Windows file sharing) isn't installed. On the Drobo, run{' '}
          <code>sudo apt install --no-install-recommends samba wsdd2</code>, then reload this page.
        </p>
      )}
      {sh.samba.installed && !sh.samba.running && <p className="msg warning">Samba is installed but not running (<code>sudo systemctl start smbd</code>).</p>}
      <ol className="steps">
        <Step done={sh.enabled}>Turn sharing on.</Step>
        <Step done={sh.disks.some((d) => d.mounted)}>Put a disk in a bay. It appears under Disks within a few seconds, read-only to start with.</Step>
        <Step done={someoneHasAccess}>Add a person under People, and choose which disks they may use.</Step>
        <Step done={shared.length > 0}>
          Connect. On a Mac: Finder, Go, Connect to Server, <code>smb://{host}.local</code>. On Windows:{' '}
          <code>\\{host}</code> in File Explorer. Sign in with that person's name and password.
        </Step>
      </ol>
      <Message state={act} />
    </Card>
  );
}

function where(d) {
  if (d.slot) return <>{slotName(d.slot)}<div className="sub">/dev/{d.device}</div></>;
  const seen = d.lastSeen ? new Date(d.lastSeen * 1000).toLocaleDateString() : '';
  return <>Not connected<div className="sub">{d.lastSlot ? `last in ${slotName(d.lastSlot)}` : ''}{seen ? `, ${seen}` : ''}</div></>;
}

function diskState(d, sh) {
  if (!d.slot) return <Pill level="muted">Not connected</Pill>;
  if (!sh.enabled) return <Pill level="muted">Sharing off</Pill>;
  if (d.error) return <Pill level="critical">Can't mount</Pill>;
  if (!d.mounted) return <Pill level="warning">Not mounted</Pill>;
  if (!d.users.length) return <Pill level="warning">Nobody has access</Pill>;
  return <Pill level="ok">Shared</Pill>;
}

function Disks({ sh, apply }) {
  const [act, run] = useAction();
  const post = (body, msg) => run(async () => apply(await api.post('sharing/disk', body)), msg);

  const writable = (d) => {
    const on = !d.writable;
    if (on && !window.confirm(`Allow changes to "${d.name}"? People with access (and not set to read-only) will be able to add, change and delete files on it.`)) return;
    post({ uuid: d.uuid, action: 'writable', on }, on ? `${d.name}: changes allowed.` : `${d.name} is read-only again.`);
  };
  const eject = (d) => {
    if (!window.confirm(`Safely remove "${d.name}"? It's unmounted and its bay is switched off; when the bay light turns yellow you can pull it.`)) return;
    post({ uuid: d.uuid, action: 'eject' }, `${d.name} is unmounted and its bay is off. Pull it when the bay light is yellow.`);
  };
  const forget = (d) => {
    if (!window.confirm(`Forget "${d.name}"? Its name, its read-only setting and who may use it are dropped. Nothing on the disk changes.`)) return;
    post({ uuid: d.uuid, action: 'forget' }, `${d.name} forgotten.`);
  };
  const rename = (d) => {
    const name = window.prompt('Share name (what people see on the network):', d.name);
    if (name && name !== d.name) post({ uuid: d.uuid, action: 'rename', name }, `Renamed to ${name}.`);
  };

  return (
    <Card title="Disks">
      {sh.disks.length ? (
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th>Share</th><th>Where</th><th>Space</th><th>Changes</th><th>Who</th><th>State</th><th /></tr></thead>
            <tbody>
              {sh.disks.map((d) => (
                <tr key={d.uuid}>
                  <td>
                    <strong>{d.name}</strong>
                    <div className="sub">{d.fstype}{d.model ? ` · ${d.model}` : ''}</div>
                  </td>
                  <td>{where(d)}</td>
                  <td className="space">
                    {d.mounted ? (
                      <>
                        <Meter value={d.used} max={d.total} label={`${d.name} used`} />
                        <div className="sub">{bytes(d.total - d.used)} free of {bytes(d.total)}</div>
                      </>
                    ) : <span className="sub">{bytes(d.bytes)}</span>}
                  </td>
                  <td>
                    <label className="check">
                      <input type="checkbox" checked={d.writable} onChange={() => writable(d)} disabled={act.busy} />
                      <span>Allow changes</span>
                    </label>
                  </td>
                  <td>{d.users.length ? d.users.join(', ') : <span className="sub">nobody yet</span>}</td>
                  <td>{diskState(d, sh)}{d.error && <div className="sub">{d.error}</div>}</td>
                  <td className="actions">
                    <button className="link" onClick={() => rename(d)} disabled={act.busy}>Rename</button>
                    {d.slot
                      ? <button onClick={() => eject(d)} disabled={act.busy}>Safely remove</button>
                      : <button className="link" onClick={() => forget(d)} disabled={act.busy}>Forget</button>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <p className="hint">No disks yet. Put one in a bay{sh.enabled ? '' : ' and turn sharing on'}; disks are remembered here after they're removed.</p>
      )}
      {sh.other.length > 0 && (
        <>
          <h3 className="sub-title">Disks that can't be shared</h3>
          <ul className="plain">
            {sh.other.map((o) => (
              <li key={o.device}><strong>{slotName(o.slot)}</strong> (/dev/{o.device}{o.model ? `, ${o.model}` : ''}): {o.reason}</li>
            ))}
          </ul>
        </>
      )}
      <p className="hint">
        New disks are read-only until you allow changes, so nothing on a disk you just put in can be altered.
        exFAT, NTFS and FAT disks keep Mac Finder information in small <code>._</code> files.
        On Linux filesystems (ext4, XFS, Btrfs), changes need the files to belong to the <code>drobo-share</code> user.
      </p>
      <Message state={act} />
    </Card>
  );
}

function accessText(u, disks) {
  if (u.all) return 'All disks';
  if (!u.disks.length) return 'No disks';
  const names = u.disks.map((id) => disks.find((d) => d.uuid === id)?.name).filter(Boolean);
  return names.join(', ');
}

function People({ sh, apply }) {
  const [editing, setEditing] = useState(null);   // a name, or 'new'
  const [act, run] = useAction();

  const remove = (u) => {
    const what = u.managed
      ? `Remove ${u.name}? Their file-sharing account is deleted. Files they made stay on the disks.`
      : `Remove ${u.name}'s file-sharing password? Their Linux login stays; they can no longer connect to shares.`;
    if (!window.confirm(what)) return;
    run(async () => apply(await api.post('sharing/user', { action: 'remove', name: u.name })), `${u.name} removed.`);
  };

  return (
    <Card title="People" actions={editing !== 'new' && sh.samba.installed && <button onClick={() => setEditing('new')}>Add a person</button>}>
      {editing === 'new' && (
        <UserForm sh={sh} apply={apply} onDone={() => setEditing(null)} />
      )}
      {sh.users.length ? (
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th>Name</th><th>May use</th><th>Changes</th><th /></tr></thead>
            <tbody>
              {sh.users.map((u) => (
                <React.Fragment key={u.name}>
                  <tr>
                    <td><strong>{u.name}</strong>{!u.managed && <div className="sub">also a Linux login</div>}</td>
                    <td>{accessText(u, sh.disks)}</td>
                    <td>{u.readOnly ? 'Read-only' : 'Where the disk allows'}</td>
                    <td className="actions">
                      <button className="link" onClick={() => setEditing(editing === u.name ? null : u.name)}>{editing === u.name ? 'Close' : 'Edit'}</button>
                      <button className="link" onClick={() => remove(u)} disabled={act.busy}>Remove</button>
                    </td>
                  </tr>
                  {editing === u.name && (
                    <tr><td colSpan={4}><UserForm sh={sh} apply={apply} user={u} onDone={() => setEditing(null)} /></td></tr>
                  )}
                </React.Fragment>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        editing !== 'new' && <p className="hint">Nobody can connect yet. Add a person to give them a name and password for the shares.</p>
      )}
      <p className="hint">These names and passwords are only for file sharing. They're separate from the dashboard password, and from SSH.</p>
      <Message state={act} />
    </Card>
  );
}

function UserForm({ sh, apply, user, onDone }) {
  const isNew = !user;
  const [name, setName] = useState(user?.name || '');
  const [pw, setPw] = useState('');
  const [pw2, setPw2] = useState('');
  const [all, setAll] = useState(user ? user.all : true);
  const [disks, setDisks] = useState(user?.disks || []);
  const [readOnly, setReadOnly] = useState(user?.readOnly || false);
  const [act, run] = useAction();
  const toggle = (id) => setDisks(disks.includes(id) ? disks.filter((x) => x !== id) : [...disks, id]);

  const save = (e) => {
    e.preventDefault();
    if ((isNew || pw) && pw !== pw2) return run(() => Promise.reject(new Error("The passwords don't match")));
    run(async () => {
      const access = { name, all, disks, readOnly };
      let r = await api.post('sharing/user', { action: isNew ? 'add' : 'access', ...access, password: pw });
      if (!isNew && pw) r = await api.post('sharing/user', { action: 'password', name, password: pw });
      apply(r);
      onDone();
    }, 'Saved.');
  };

  return (
    <form className="form user-form" onSubmit={save}>
      <div className="field-row">
        <Field label="Name" hint={isNew ? 'Lower-case letters, digits, - and _. If it matches a Linux login, that login gets a sharing password.' : undefined}>
          <input value={name} onChange={(e) => setName(e.target.value.toLowerCase())} disabled={!isNew}
            pattern="[a-z_][a-z0-9_\-]{0,31}" required autoCapitalize="none" autoComplete="off" />
        </Field>
        <Field label={isNew ? 'Password' : 'New password'} hint={isNew ? 'At least 8 characters' : 'Leave empty to keep it'}>
          <input type="password" value={pw} onChange={(e) => setPw(e.target.value)} minLength={8} required={isNew} autoComplete="new-password" />
        </Field>
        <Field label="Again">
          <input type="password" value={pw2} onChange={(e) => setPw2(e.target.value)} required={isNew || pw !== ''} autoComplete="new-password" />
        </Field>
      </div>
      <div className="checks">
        <label className="check">
          <input type="radio" checked={all} onChange={() => setAll(true)} />
          <span>All disks, including ones added later</span>
        </label>
        <label className="check">
          <input type="radio" checked={!all} onChange={() => setAll(false)} />
          <span>Only these disks:</span>
        </label>
      </div>
      {!all && (
        <div className="checks indent">
          {sh.disks.map((d) => (
            <label key={d.uuid} className="check">
              <input type="checkbox" checked={disks.includes(d.uuid)} onChange={() => toggle(d.uuid)} />
              <span>{d.name}{!d.slot && <span className="sub"> (not connected)</span>}</span>
            </label>
          ))}
          {!sh.disks.length && <p className="hint">No disks seen yet. Put one in a bay first, or choose all disks.</p>}
        </div>
      )}
      <label className="check">
        <input type="checkbox" checked={readOnly} onChange={(e) => setReadOnly(e.target.checked)} />
        <span>Read-only for this person, even on disks that allow changes</span>
      </label>
      <div className="row">
        <button className="primary" disabled={act.busy}>{isNew ? 'Add' : 'Save'}</button>
        <button type="button" className="link" onClick={onDone}>Cancel</button>
      </div>
      <Message state={act} />
    </form>
  );
}
