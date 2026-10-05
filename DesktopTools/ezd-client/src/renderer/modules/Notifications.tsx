import { useEffect } from 'react';
import { fmtDateTime, isoOf } from '@shared/locale';
import { useLoad } from '../hooks';
import { Badge, EmptyState, PageHeader } from '../ui';

export function Notifications() {
  const list = useLoad(() => window.api.notif.list(), []);
  useEffect(() => window.api.notif.onNew(() => list.reload()), [list]);
  const unread = list.data?.filter((n) => !n.read).length ?? 0;
  return (
    <>
      <PageHeader title="Centrum powiadomień" lead={unread ? `Nieprzeczytane: ${unread}.` : 'Wszystko przeczytane.'}
        actions={<button onClick={() => void window.api.notif.markRead().then(list.reload)} disabled={!unread}>Oznacz wszystkie jako przeczytane</button>} />
      <section className="card" aria-label="Lista powiadomień">
        {list.data?.length === 0 && <EmptyState icon="bell" title="Brak powiadomień" />}
        <ul className="plain" aria-label="Powiadomienia">
          {list.data?.map((n) => (
            <li key={n.id} className="row" style={{ justifyContent: 'space-between' }}>
              <span>
                {!n.read && <Badge tone="info">Nowe</Badge>} <strong>{n.title}</strong><br />
                <span>{n.body}</span> <time className="muted" dateTime={isoOf(n.at)}>{fmtDateTime(n.at)}</time>
              </span>
              {!n.read && <button className="small" onClick={() => void window.api.notif.markRead(n.id).then(list.reload)} aria-label={`Oznacz jako przeczytane: ${n.title}`}>Przeczytane</button>}
            </li>
          ))}
        </ul>
      </section>
    </>
  );
}
