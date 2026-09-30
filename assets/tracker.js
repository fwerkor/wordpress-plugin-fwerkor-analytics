(() => {
  'use strict';

  const config = window.FWERKOR_ANALYTICS;
  if (!config || !config.endpoint) return;

  if (config.respectDNT && (navigator.doNotTrack === '1' || window.doNotTrack === '1')) {
    return;
  }

  const send = () => {
    const payload = JSON.stringify({
      path: window.location.pathname,
      title: document.title || '',
      referrer: document.referrer || ''
    });

    if (navigator.sendBeacon) {
      const body = new Blob([payload], { type: 'text/plain;charset=UTF-8' });
      if (navigator.sendBeacon(config.endpoint, body)) return;
    }

    fetch(config.endpoint, {
      method: 'POST',
      body: payload,
      credentials: 'omit',
      keepalive: true,
      headers: { 'Content-Type': 'text/plain;charset=UTF-8' }
    }).catch(() => {});
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', send, { once: true });
  } else {
    send();
  }
})();
