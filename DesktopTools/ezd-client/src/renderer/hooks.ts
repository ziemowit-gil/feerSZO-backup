import { useCallback, useEffect, useState } from 'react';

/** Ładowanie danych z API z obsługą błędu; `reload` odświeża po akcji. */
export function useLoad<T>(fn: () => Promise<T>, deps: unknown[]): {
  data: T | undefined; error: string | null; reload: () => void;
} {
  const [data, setData] = useState<T>();
  const [error, setError] = useState<string | null>(null);
  const [tick, setTick] = useState(0);
  useEffect(() => {
    let alive = true;
    fn().then((d) => { if (alive) { setData(d); setError(null); } })
      .catch((e: unknown) => { if (alive) setError(cleanError(e)); });
    return () => { alive = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, tick]);
  return { data, error, reload: useCallback(() => setTick((t) => t + 1), []) };
}

export const cleanError = (e: unknown): string =>
  (e instanceof Error ? e.message : String(e)).replace(/^Error invoking remote method '[^']+': (Error: )?/, '');

export const STAGE_PL: Record<string, string> = {
  registered: 'Zarejestrowany', dispatched: 'Zadekretowany', in_review: 'Do akceptacji',
  approved: 'Zaakceptowany', signed: 'Podpisany', archived: 'Zarchiwizowany',
  lead: 'Lead', qualified: 'Kwalifikacja', proposal: 'Oferta', negotiation: 'Negocjacje', won: 'Wygrana', lost: 'Przegrana'
};
export const KIND_PL = { incoming: 'Przychodzące', outgoing: 'Wychodzące', internal: 'Wewnętrzne' } as const;
