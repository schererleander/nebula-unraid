(() => {
  'use strict';
  const byId = id => document.getElementById(id);
  let busy = false;
  let refreshing = false;
  let statusError = false;
  let configured = false;
  const message = (text, error = false) => {
    for (const id of ['nebula-message', 'nebula-settings-message']) {
      const box = byId(id);
      box.textContent = text;
      box.className = error ? 'red-text' : 'green-text';
      box.hidden = !text;
    }
  };
  const request = async (action, data = new FormData()) => {
    // Unraid's authentication subrequest can stall on multipart POST bodies.
    // Read the selected profile locally and use the same form encoding as WebGUI.
    const body = new URLSearchParams(data);
    body.delete('profile');
    if (action === 'save' && byId('nebula-autostart').checked) body.set('autostart', 'on');
    const profile = data.get('profile');
    if (action === 'save' && data.get('mode') === 'import' && profile instanceof File && profile.size > 0) {
      if (profile.size > 1048576) throw new Error('Choose a YAML profile smaller than 1 MiB.');
      body.set('yaml', await profile.text());
    }
    body.set('action', action);
    body.set('csrf_token', typeof csrf_token === 'string' ? csrf_token : '');
    const controller = new AbortController();
    // Mutations include service shutdown/startup; saving also validates first.
    const timeout = action === 'save' ? 180000 : ['start', 'stop', 'restart'].includes(action) ? 120000 : 15000;
    const timer = setTimeout(() => controller.abort(), timeout);
    try {
      const response = await fetch('/plugins/nebula/api.php', {
        method: 'POST', body, credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
      });
      let result;
      try { result = await response.json(); }
      catch (error) {
        if (error.name === 'AbortError') throw error;
        throw new Error('Unraid did not return a valid response. Reload this page.');
      }
      if (!response.ok || !result.ok) throw new Error(result.error || 'The request failed.');
      return result;
    } catch (error) {
      if (error.name === 'AbortError') throw new Error('Unraid did not respond in time. Try again or reload this page.');
      if (error.name === 'TypeError') throw new Error('Connection to Unraid was interrupted. Refresh status before trying again.');
      throw error;
    } finally { clearTimeout(timer); }
  };
  const render = status => {
    configured = status.configured;
    const state = status.running ? 'Running' : status.configured ? 'Disconnected' : 'Not configured';
    byId('nebula-state').textContent = state;
    byId('nebula-dot').className = 'fa fa-circle ' + (status.running ? 'green-text' : 'grey-text');
    const profile = status.profile;
    byId('nebula-name').textContent = profile?.name || '';
    byId('nebula-connection').textContent = state;
    byId('nebula-host').textContent = profile?.host || '—';
    byId('nebula-address').textContent = profile?.networks?.join(', ') || '—';
    byId('nebula-lighthouses').textContent = profile ? profile.lighthouses?.join(', ') || 'Static peers' : '—';
    byId('nebula-expires').textContent = profile?.expires || '—';
    byId('nebula-connect').disabled = busy || !status.configured || status.running;
    byId('nebula-disconnect').disabled = busy || !status.running;
    byId('nebula-reconnect').disabled = busy || !status.configured;
    if (configured) byId('nebula-autostart').checked = status.autostart;
    byId('nebula-autostart').disabled = busy;
  };
  const refresh = async () => {
    if (refreshing) return;
    refreshing = true;
    try {
      render((await request('status')).status);
      if (statusError) message('');
      statusError = false;
    } catch (error) {
      statusError = true;
      throw error;
    }
    finally { refreshing = false; }
  };
  const act = async (action, data) => {
    if (busy) return;
    busy = true;
    statusError = false;
    message(action === 'save' ? 'Validating your profile and starting Nebula…' : 'Updating connection…');
    document.querySelectorAll('button[id^="nebula-"]').forEach(button => { button.disabled = true; });
    let status;
    try {
      const result = await request(action, data);
      if (action === 'save') {
        message('Profile validated and saved. Nebula is starting.');
        ['nebula-yaml', 'nebula-ca', 'nebula-cert', 'nebula-key', 'nebula-profile'].forEach(id => { byId(id).value = ''; });
        document.querySelectorAll('[data-target]').forEach(input => { input.value = ''; });
      } else message(action === 'stop' ? 'Disconnected.' : action === 'autostart' ? 'Automatic startup updated.' : 'Nebula is starting.');
      status = result.status;
    } catch (error) { message(error.message, true); }
    finally {
      busy = false;
      document.querySelectorAll('button[id^="nebula-"]').forEach(button => { button.disabled = false; });
      if (status) render(status);
      else try { await refresh(); } catch (error) { message(error.message, true); }
    }
  };
  byId('nebula-connect').onclick = () => act('start');
  byId('nebula-disconnect').onclick = () => act('stop');
  byId('nebula-reconnect').onclick = () => act('restart');
  byId('nebula-refresh').onclick = () => refresh().then(() => message('')).catch(error => message(error.message, true));
  byId('nebula-autostart').onchange = event => {
    if (!configured) return;
    const data = new FormData();
    data.set('enabled', String(event.target.checked));
    act('autostart', data);
  };
  byId('nebula-form').onsubmit = event => { event.preventDefault(); act('save', new FormData(event.target)); };
  byId('nebula-mode').onchange = event => {
    byId('nebula-import-panel').hidden = event.target.value !== 'import';
    byId('nebula-manual-panel').hidden = event.target.value !== 'manual';
  };
  document.querySelectorAll('[data-target]').forEach(input => {
    input.onchange = async () => {
      const file = input.files[0];
      if (!file) return;
      if (file.size > 262144) { message('Choose a certificate or key smaller than 256 KiB.', true); return; }
      byId(input.dataset.target).value = await file.text();
    };
  });
  refresh().catch(error => message(error.message, true));
  setInterval(() => { if (!busy && !document.hidden) refresh().catch(error => message(error.message, true)); }, 10000);
})();
