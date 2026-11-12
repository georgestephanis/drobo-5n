import { useCallback, useEffect, useRef, useState } from 'react';

// Fetch now and every `ms` while the tab is visible. Returns { data, error, loading, reload }.
export function usePoll(fn, ms) {
  const [state, setState] = useState({ data: null, error: '', loading: true });
  const fnRef = useRef(fn);
  fnRef.current = fn;

  const reload = useCallback(async () => {
    try {
      const data = await fnRef.current();
      setState({ data, error: '', loading: false });
    } catch (e) {
      if (e.status === 401) window.location.reload();
      setState((s) => ({ ...s, error: e.message, loading: false }));
    }
  }, []);

  useEffect(() => {
    let timer;
    let stopped = false;
    const tick = async () => {
      if (!document.hidden) await reload();
      if (!stopped && ms) timer = setTimeout(tick, ms);
    };
    tick();
    const onVis = () => !document.hidden && reload();
    document.addEventListener('visibilitychange', onVis);
    return () => {
      stopped = true;
      clearTimeout(timer);
      document.removeEventListener('visibilitychange', onVis);
    };
  }, [reload, ms]);

  return { ...state, reload };
}

// For a form action: run it, keep busy / message / error state.
export function useAction() {
  const [state, setState] = useState({ busy: false, message: '', error: '' });
  const run = useCallback(async (fn, okMessage = 'Saved.') => {
    setState({ busy: true, message: '', error: '' });
    try {
      const r = await fn();
      setState({ busy: false, message: okMessage, error: '' });
      return r;
    } catch (e) {
      setState({ busy: false, message: '', error: e.message });
      return undefined;
    }
  }, []);
  return [state, run];
}
