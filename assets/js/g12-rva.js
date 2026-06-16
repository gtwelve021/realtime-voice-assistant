(function () {
  const root = document.querySelector('[data-g12-rva]');
  if (!root || !window.g12RvaConfig) return;

  const config = window.g12RvaConfig;
  const panel = root.querySelector('[data-g12-rva-panel]');
  const toggle = root.querySelector('[data-g12-rva-toggle]');
  const closeBtn = root.querySelector('[data-g12-rva-close]');
  const startBtn = root.querySelector('[data-g12-rva-start]');
  const stopBtn = root.querySelector('[data-g12-rva-stop]');
  const statusEl = root.querySelector('[data-g12-rva-status]');
  const messageEl = root.querySelector('[data-g12-rva-message]');
  const linksEl = root.querySelector('[data-g12-rva-links]');
  const historyEl = root.querySelector('[data-g12-rva-history]');
  const leadForm = root.querySelector('[data-g12-rva-lead]');

  let pc = null;
  let dc = null;
  let stream = null;
  let audio = null;
  const handledCalls = new Set();
  const storageKey = 'g12RvaSession:v2';
  const leadFields = ['name', 'phone', 'email', 'message', 'preferred_time'];
  let session = loadSession();

  function loadSession() {
    try {
      const raw = window.localStorage.getItem(storageKey);
      const parsed = raw ? JSON.parse(raw) : {};
      return {
        messages: Array.isArray(parsed.messages) ? parsed.messages.slice(-20) : [],
        lead: parsed.lead && typeof parsed.lead === 'object' ? parsed.lead : {},
        updatedAt: parsed.updatedAt || ''
      };
    } catch (e) {
      return { messages: [], lead: {}, updatedAt: '' };
    }
  }

  function saveSession() {
    session.updatedAt = new Date().toISOString();
    try {
      window.localStorage.setItem(storageKey, JSON.stringify(session));
    } catch (e) {}
    renderHistory();
  }

  function remember(role, text) {
    const clean = String(text || '').replace(/\s+/g, ' ').trim();
    if (!clean) return;
    session.messages.push({ role: role, text: clean, time: Date.now() });
    session.messages = session.messages.slice(-20);
    saveSession();
  }

  function rememberLead(values) {
    leadFields.forEach((key) => {
      if (values[key]) session.lead[key] = String(values[key]).trim();
    });
    saveSession();
  }

  function renderHistory() {
    if (!historyEl) return;
    const last = session.messages.slice(-4);
    historyEl.innerHTML = '';
    last.forEach((item) => {
      const row = document.createElement('div');
      row.textContent = (item.role === 'user' ? 'You: ' : 'Guide: ') + item.text;
      historyEl.appendChild(row);
    });
    historyEl.hidden = last.length === 0;
  }

  function setStatus(text) {
    if (statusEl) statusEl.textContent = text;
  }

  function setMessage(text) {
    if (messageEl && text) messageEl.textContent = text;
  }

  function rest(path, body) {
    return fetch(config.restBase + path, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': config.nonce || ''
      },
      body: JSON.stringify(body || {})
    }).then(async (res) => {
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.message || 'Request failed');
      return data;
    });
  }

  function openPanel() {
    panel.hidden = false;
    setMessage(session.messages.length ? 'I remember this session. You can continue where you stopped.' : config.greeting);
    renderHistory();
    hydrateLeadForm();
  }

  function closePanel() {
    panel.hidden = true;
  }

  async function startVoice() {
    openPanel();
    if (!config.hasKey) {
      setStatus('Missing key');
      setMessage('OpenAI key is not configured on the server.');
      return;
    }
    if (!navigator.mediaDevices || !window.RTCPeerConnection) {
      setStatus('Unsupported');
      setMessage('This browser does not support live voice. Please use Chrome or Edge with microphone permission.');
      return;
    }

    try {
      setStatus('Starting...');
      startBtn.disabled = true;
      stopBtn.disabled = false;

      const tokenData = await rest('/client-secret', {});
      const token = tokenData.value || (tokenData.client_secret && tokenData.client_secret.value);
      if (!token) throw new Error('No client secret returned');

      pc = new RTCPeerConnection();
      dc = pc.createDataChannel('oai-events');
      dc.addEventListener('message', onRealtimeEvent);
      dc.addEventListener('open', () => {
        setStatus('Listening');
        root.classList.add('is-live');
        sendText(buildSessionPrompt(), false);
      });

      audio = document.createElement('audio');
      audio.autoplay = true;
      pc.ontrack = (event) => {
        audio.srcObject = event.streams[0];
      };

      stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      stream.getTracks().forEach((track) => pc.addTrack(track, stream));

      const offer = await pc.createOffer();
      await pc.setLocalDescription(offer);

      const sdpResponse = await fetch('https://api.openai.com/v1/realtime/calls', {
        method: 'POST',
        body: offer.sdp,
        headers: {
          'Authorization': 'Bearer ' + token,
          'Content-Type': 'application/sdp'
        }
      });
      if (!sdpResponse.ok) throw new Error('Realtime connection failed');

      await pc.setRemoteDescription({
        type: 'answer',
        sdp: await sdpResponse.text()
      });
    } catch (error) {
      setStatus('Error');
      setMessage(error.message || 'Could not start voice assistant.');
      stopVoice();
    }
  }

  function stopVoice() {
    if (dc) {
      try { dc.close(); } catch (e) {}
      dc = null;
    }
    if (pc) {
      try { pc.close(); } catch (e) {}
      pc = null;
    }
    if (stream) {
      stream.getTracks().forEach((track) => track.stop());
      stream = null;
    }
    if (audio) {
      audio.srcObject = null;
      audio = null;
    }
    handledCalls.clear();
    root.classList.remove('is-live');
    setStatus('Ready');
    startBtn.disabled = false;
    stopBtn.disabled = true;
  }

  function sendEvent(event) {
    if (dc && dc.readyState === 'open') {
      dc.send(JSON.stringify(event));
    }
  }

  function sendText(text, shouldRemember) {
    if (shouldRemember !== false) {
      remember('user', text);
    }
    sendEvent({
      type: 'conversation.item.create',
      item: {
        type: 'message',
        role: 'user',
        content: [{ type: 'input_text', text: text }]
      }
    });
    sendEvent({ type: 'response.create' });
  }

  function onRealtimeEvent(event) {
    let data;
    try {
      data = JSON.parse(event.data);
    } catch (e) {
      return;
    }

    if ((data.type === 'conversation.item.input_audio_transcription.completed' || data.type === 'input_audio_transcription.completed') && data.transcript) {
      remember('user', data.transcript);
    }

    if (data.type === 'response.audio_transcript.done' && data.transcript) {
      setMessage(data.transcript);
      remember('assistant', data.transcript);
    }

    if (data.type === 'response.function_call_arguments.done') {
      const call = {
        call_id: data.call_id,
        name: data.name,
        arguments: ''
      };
      call.arguments = data.arguments || call.arguments;
      handleFunctionCall(call);
    }
  }

  async function handleFunctionCall(call) {
    const callId = call.call_id || call.id;
    const name = call.name;
    let args = {};
    try {
      args = typeof call.arguments === 'string' ? JSON.parse(call.arguments || '{}') : (call.arguments || {});
    } catch (e) {
      args = {};
    }

    if (!callId || !name) return;
    if (handledCalls.has(callId)) return;
    handledCalls.add(callId);

    let output;
    try {
      if (name === 'site_search') {
        output = await siteSearch(args.query || '');
      } else if (name === 'open_page') {
        output = openPage(args.url || '');
      } else if (name === 'fill_contact_form') {
        output = fillContactForm(args);
      } else if (name === 'request_callback') {
        output = await requestCallback(args);
      } else {
        output = { ok: false, message: 'Unknown tool' };
      }
    } catch (error) {
      output = { ok: false, message: error.message || 'Tool failed' };
    }

    sendEvent({
      type: 'conversation.item.create',
      item: {
        type: 'function_call_output',
        call_id: callId,
        output: JSON.stringify(output)
      }
    });
    sendEvent({ type: 'response.create' });
  }

  async function siteSearch(query) {
    const data = await rest('/search', { query: query });
    renderLinks(data.results || []);
    remember('assistant', 'Suggested pages for: ' + query);
    return data;
  }

  function renderLinks(results) {
    if (!linksEl) return;
    linksEl.innerHTML = '';
    (results || []).slice(0, 5).forEach((item) => {
      const a = document.createElement('a');
      a.href = item.url;
      a.target = '_blank';
      a.rel = 'noopener';
      a.textContent = item.title || item.url;
      linksEl.appendChild(a);
    });
    linksEl.hidden = !linksEl.children.length;
  }

  function openPage(url) {
    const target = new URL(url, window.location.href);
    const home = new URL(config.homeUrl || window.location.origin);
    if (target.origin !== home.origin) {
      return { ok: false, message: 'Only same-site pages can be opened.' };
    }
    window.open(target.href, '_blank', 'noopener');
    return { ok: true, url: target.href, message: 'Opened in a new tab so this voice session stays active.' };
  }

  function fillContactForm(args) {
    const values = {
      name: args.name || session.lead.name || '',
      phone: args.phone || session.lead.phone || '',
      email: args.email || session.lead.email || '',
      message: args.message || session.lead.message || ''
    };
    values.preferred_time = args.preferred_time || session.lead.preferred_time || '';
    rememberLead(values);

    const selectors = {
      name: 'input[name*="name" i], input[name*="first" i], input[placeholder*="name" i]',
      phone: 'input[type="tel"], input[name*="phone" i], input[placeholder*="phone" i]',
      email: 'input[type="email"], input[name*="email" i], input[placeholder*="email" i]',
      message: 'textarea, input[name*="message" i], input[placeholder*="business" i]'
    };

    Object.keys(selectors).forEach((key) => {
      if (!values[key]) return;
      const field = document.querySelector(selectors[key]);
      if (field) {
        field.value = values[key];
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
      }
    });

    if (leadForm) {
      leadForm.hidden = false;
      hydrateLeadForm();
      showLeadStep(nextMissingLeadField() || 'confirm');
    }

    const missing = nextMissingLeadField();
    return {
      ok: true,
      missing: missing,
      message: missing ? 'Ask only for the next missing field: ' + fieldLabel(missing) + '.' : 'Visible fields were filled. Ask the user to confirm before submitting.'
    };
  }

  async function requestCallback(args) {
    rememberLead(args || {});
    const missing = nextMissingLeadField();
    if (missing) {
      hydrateLeadForm();
      showLeadStep(missing);
      return { ok: false, missing: missing, message: 'Ask only for the next missing field: ' + fieldLabel(missing) + '.' };
    }
    if (session.lead.already_sent) {
      return { ok: true, alreadySent: true, message: 'This callback request was already sent in this browser session.' };
    }
    const payload = Object.assign({}, session.lead, args, { page: window.location.href });
    const data = await rest('/lead', payload);
    session.lead.already_sent = true;
    saveSession();
    if (leadForm) {
      leadForm.hidden = true;
      showLeadStep('confirm');
    }
    remember('assistant', data.duplicate ? 'Callback request already saved with G12.' : 'Callback request sent to G12.');
    return data;
  }

  function hydrateLeadForm() {
    if (!leadForm) return;
    leadFields.forEach((key) => {
      const field = leadForm.elements[key];
      if (field && session.lead[key]) field.value = session.lead[key];
    });
    showLeadStep(nextMissingLeadField() || 'confirm');
  }

  function nextMissingLeadField(requiredOnly) {
    const required = requiredOnly || leadFields;
    return required.find((key) => !String(session.lead[key] || '').trim()) || '';
  }

  function fieldLabel(key) {
    const labels = {
      name: 'your name',
      phone: 'your phone number',
      email: 'your email address',
      message: 'your business activity or question',
      preferred_time: 'your preferred callback time'
    };
    return labels[key] || key;
  }

  function showLeadStep(step) {
    if (!leadForm) return;
    const stepEl = leadForm.querySelector('[data-g12-rva-lead-step]');
    const submit = leadForm.querySelector('button[type="submit"]');
    leadFields.forEach((key) => {
      const field = leadForm.querySelector('[data-g12-rva-field="' + key + '"]');
      if (field) field.hidden = key !== step;
    });
    if (step === 'confirm') {
      if (stepEl) stepEl.textContent = 'Please confirm. Should I send this callback request to G12?';
      if (submit) submit.textContent = 'Send request';
    } else {
      if (stepEl) stepEl.textContent = 'Please enter ' + fieldLabel(step) + '.';
      if (submit) submit.textContent = 'Next';
      const current = leadForm.querySelector('[data-g12-rva-field="' + step + '"]');
      if (current && !leadForm.hidden) current.focus();
    }
  }

  function buildSessionPrompt() {
    const notes = session.messages.slice(-8).map((item) => item.role + ': ' + item.text).join('\n');
    const lead = Object.keys(session.lead).filter((key) => session.lead[key]).map((key) => key + ': ' + session.lead[key]).join('\n');
    return [
      config.greeting,
      'Continue this browser session if context exists.',
      notes ? 'Recent voice chat:\n' + notes : '',
      lead ? 'Collected form details:\n' + lead : '',
      'If collecting callback details, ask one question only. Do not ask multiple form questions together.'
    ].filter(Boolean).join('\n\n');
  }

  toggle.addEventListener('click', () => {
    if (panel.hidden) openPanel();
    else closePanel();
  });
  closeBtn.addEventListener('click', closePanel);
  startBtn.addEventListener('click', startVoice);
  stopBtn.addEventListener('click', stopVoice);

  if (leadForm) {
    leadForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const fd = new FormData(leadForm);
      rememberLead(Object.fromEntries(fd.entries()));
      const missing = nextMissingLeadField();
      if (missing) {
        showLeadStep(missing);
        setMessage('Please add ' + fieldLabel(missing) + '.');
        return;
      }
      try {
        await requestCallback(Object.fromEntries(fd.entries()));
        setMessage('Thanks. Your request was sent to G12.');
      } catch (error) {
        setMessage(error.message || 'Could not send your request.');
      }
    });
  }
})();
