(function () {
  const root = document.querySelector('[data-g12-rva]');
  if (!root || !window.g12RvaConfig) return;

  const config = window.g12RvaConfig;
  const panel = root.querySelector('[data-g12-rva-panel]');
  const toggle = root.querySelector('[data-g12-rva-toggle]');
  const closeBtn = root.querySelector('[data-g12-rva-close]');
  const resetBtn = root.querySelector('[data-g12-rva-reset]');
  const startBtn = root.querySelector('[data-g12-rva-start]');
  const stopBtn = root.querySelector('[data-g12-rva-stop]');
  const statusEl = root.querySelector('[data-g12-rva-status]');
  const messageEl = root.querySelector('[data-g12-rva-message]');
  const linksEl = root.querySelector('[data-g12-rva-links]');
  const historyEl = root.querySelector('[data-g12-rva-history]');
  const leadForm = root.querySelector('[data-g12-rva-lead]');
  const composer = root.querySelector('[data-g12-rva-composer]');
  const suggestions = root.querySelectorAll('[data-g12-rva-suggest]');

  let pc = null;
  let dc = null;
  let stream = null;
  let audio = null;
  let mediaRecorder = null;
  let audioChunks = [];
  let recordingMime = '';
  let sessionLogged = false;
  const handledCalls = new Set();
  const storageKey = 'g12RvaSession:v2';
  const profileFields = ['language', 'intent', 'urgency', 'service_interest', 'setup_location', 'visa_need', 'timeline'];
  const leadFields = ['message', 'setup_location', 'visa_need', 'timeline', 'name', 'phone', 'email', 'preferred_time'];
  let session = loadSession();

  function loadSession() {
    try {
      const raw = window.localStorage.getItem(storageKey);
      const parsed = raw ? JSON.parse(raw) : {};
      return {
        messages: Array.isArray(parsed.messages) ? parsed.messages.slice(-20) : [],
        lead: parsed.lead && typeof parsed.lead === 'object' ? parsed.lead : {},
        profile: parsed.profile && typeof parsed.profile === 'object' ? parsed.profile : {},
        updatedAt: parsed.updatedAt || ''
      };
    } catch (e) {
      return { messages: [], lead: {}, profile: {}, updatedAt: '' };
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
    values = values || {};
    const before = leadFingerprint();
    leadFields.forEach((key) => {
      if (values[key]) session.lead[key] = String(values[key]).trim();
    });
    rememberProfile(values);
    if (before !== leadFingerprint()) {
      session.lead.details_confirmed = '';
    }
  }

  function rememberProfile(values) {
    values = values || {};
    profileFields.forEach((key) => {
      if (values[key]) session.profile[key] = String(values[key]).trim();
    });
    saveSession();
  }

  function leadFingerprint(values) {
    const data = values || session.lead || {};
    return ['name', 'phone', 'email', 'message', 'setup_location', 'visa_need', 'timeline', 'preferred_time']
      .map((key) => String(data[key] || '').trim().toLowerCase())
      .join('|');
  }

  function phoneDigits(value) {
    return String(value || '').replace(/\D+/g, '');
  }

  function isValidPhone(value) {
    const digits = phoneDigits(value);
    return digits.length >= 7 && digits.length <= 15;
  }

  function isValidEmail(value) {
    const text = String(value || '').trim();
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(text);
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

  function restForm(path, formData) {
    return fetch(config.restBase + path, {
      method: 'POST',
      headers: {
        'X-WP-Nonce': config.nonce || ''
      },
      body: formData
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

  function resetSession() {
    session = { messages: [], lead: {}, profile: {}, updatedAt: '' };
    sessionLogged = false;
    handledCalls.clear();
    try {
      window.localStorage.removeItem(storageKey);
    } catch (e) {}
    renderHistory();
    hydrateLeadForm();
    setMessage(config.greeting);
    renderLinks([]);
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
      startAudioRecording(stream);
      stream.getTracks().forEach((track) => pc.addTrack(track, stream));

      const offer = await pc.createOffer();
      await pc.setLocalDescription(offer);

      let answerSdp = '';
      if (config.connectionMode === 'server') {
        try {
          const connection = await rest('/connect', { sdp: offer.sdp });
          answerSdp = connection.sdp || '';
        } catch (serverError) {
          setStatus('Retrying...');
          answerSdp = await connectWithClientSecret(offer.sdp);
        }
      } else {
        answerSdp = await connectWithClientSecret(offer.sdp);
      }
      if (!answerSdp) throw new Error('Realtime connection failed');

      await pc.setRemoteDescription({
        type: 'answer',
        sdp: answerSdp
      });
    } catch (error) {
      setStatus('Error');
      setMessage(error.message || 'Could not start voice assistant.');
      stopVoice();
    }
  }

  async function connectWithClientSecret(offerSdp) {
    const tokenData = await rest('/client-secret', {});
    const token = tokenData.value || (tokenData.client_secret && tokenData.client_secret.value);
    if (!token) throw new Error('No client secret returned');

    const sdpResponse = await fetch('https://api.openai.com/v1/realtime/calls', {
      method: 'POST',
      body: offerSdp,
      headers: {
        'Authorization': 'Bearer ' + token,
        'Content-Type': 'application/sdp'
      }
    });
    if (!sdpResponse.ok) throw new Error('Realtime connection failed');
    return sdpResponse.text();
  }

  async function stopVoice() {
    await stopAudioRecording();
    await logSession();
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

  function startAudioRecording(sourceStream) {
    audioChunks = [];
    recordingMime = '';
    if (!config.storeAudio || !window.MediaRecorder || !sourceStream) return;
    const types = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
    const mime = types.find((type) => {
      try {
        return MediaRecorder.isTypeSupported(type);
      } catch (e) {
        return false;
      }
    }) || '';
    try {
      mediaRecorder = new MediaRecorder(sourceStream, mime ? { mimeType: mime } : undefined);
      recordingMime = mediaRecorder.mimeType || mime || 'audio/webm';
      mediaRecorder.addEventListener('dataavailable', (event) => {
        if (event.data && event.data.size > 0) audioChunks.push(event.data);
      });
      mediaRecorder.addEventListener('start', () => root.classList.add('is-recording'));
      mediaRecorder.addEventListener('stop', () => root.classList.remove('is-recording'));
      mediaRecorder.start(1000);
    } catch (e) {
      mediaRecorder = null;
      audioChunks = [];
    }
  }

  function stopAudioRecording() {
    if (!mediaRecorder) return Promise.resolve();
    return new Promise((resolve) => {
      const recorder = mediaRecorder;
      mediaRecorder = null;
      const finish = async () => {
        recorder.removeEventListener('stop', finish);
        root.classList.remove('is-recording');
        await uploadAudioRecording();
        resolve();
      };
      recorder.addEventListener('stop', finish);
      try {
        if (recorder.state !== 'inactive') recorder.stop();
        else finish();
      } catch (e) {
        resolve();
      }
    });
  }

  async function uploadAudioRecording() {
    if (!config.storeAudio || !audioChunks.length) return;
    const blob = new Blob(audioChunks, { type: recordingMime || 'audio/webm' });
    audioChunks = [];
    if (!blob.size) return;
    const ext = (blob.type.indexOf('ogg') !== -1) ? 'ogg' : (blob.type.indexOf('mp4') !== -1 ? 'm4a' : 'webm');
    const form = new FormData();
    form.append('audio', blob, 'g12-voice-session-' + Date.now() + '.' + ext);
    form.append('page', window.location.href);
    form.append('messages', JSON.stringify(session.messages || []));
    form.append('lead', JSON.stringify(session.lead || {}));
    form.append('profile', JSON.stringify(session.profile || {}));
    try {
      const data = await restForm('/audio-log', form);
      if (data && data.attachmentId) {
        session.audioAttachmentId = data.attachmentId;
        saveSession();
      }
    } catch (e) {}
  }

  async function logSession() {
    if (sessionLogged || !config.storeSessions) return;
    if (!session.messages.length && !Object.keys(session.lead || {}).length) return;
    sessionLogged = true;
    try {
      await rest('/session-log', {
        messages: session.messages,
        lead: session.lead,
        profile: session.profile,
        page: window.location.href
      });
    } catch (e) {}
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
      } else if (name === 'update_visitor_profile') {
        output = updateVisitorProfile(args);
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

  async function askTypedQuestion(text) {
    const clean = String(text || '').trim();
    if (!clean) return;
    openPanel();
    if (dc && dc.readyState === 'open') {
      sendText(clean, true);
      setMessage('I am checking that now.');
      return;
    }
    remember('user', clean);
    setMessage('Here are the closest G12 pages. Start voice if you want a live consultant-style answer.');
    try {
      await siteSearch(clean);
    } catch (e) {
      setMessage('Start voice and I can help with that directly.');
    }
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
      message: args.message || session.lead.message || '',
      setup_location: args.setup_location || session.lead.setup_location || session.profile.setup_location || '',
      visa_need: args.visa_need || session.lead.visa_need || session.profile.visa_need || '',
      timeline: args.timeline || session.lead.timeline || session.profile.timeline || ''
    };
    values.preferred_time = args.preferred_time || session.lead.preferred_time || '';
    profileFields.forEach((key) => {
      if (args[key]) values[key] = args[key];
    });
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

  function updateVisitorProfile(args) {
    rememberProfile(args || {});
    const profileText = profileFields
      .filter((key) => session.profile[key])
      .map((key) => key + ': ' + session.profile[key])
      .join(', ');
    return { ok: true, profile: session.profile, message: profileText ? 'Profile updated: ' + profileText : 'Profile updated.' };
  }

  async function requestCallback(args) {
    rememberLead(args || {});
    const missing = nextMissingLeadField();
    if (missing) {
      hydrateLeadForm();
      showLeadStep(missing);
      return { ok: false, missing: missing, message: 'Ask only for the next missing field: ' + fieldLabel(missing) + '.' };
    }
    const payload = Object.assign({}, session.profile, session.lead, args, { page: window.location.href });
    const confirmed = args && (args.confirmed_details === true || args.confirmed_details === 'yes' || args.confirmed_details === 'true');
    if (!confirmed && session.lead.details_confirmed !== 'yes') {
      if (leadForm) {
        leadForm.hidden = false;
        hydrateLeadForm();
        showLeadStep('confirm');
      }
      return {
        ok: false,
        needsConfirmation: true,
        message: 'Read back the exact name, phone or email, and business need. Ask the user to confirm they are correct before calling request_callback again with confirmed_details=true.'
      };
    }
    payload.confirmed_details = true;
    const fingerprint = leadFingerprint(payload);
    if (session.lead.already_sent && session.lead.sent_fingerprint === fingerprint) {
      return { ok: true, alreadySent: true, message: 'This callback request was already sent in this browser session.' };
    }
    const data = await rest('/lead', payload);
    session.lead.already_sent = true;
    session.lead.sent_fingerprint = fingerprint;
    saveSession();
    logSession();
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
    return required.find((key) => {
      if (key === 'phone' && String(session.lead.phone || '').trim() && !isValidPhone(session.lead.phone)) return true;
      if (key === 'email' && String(session.lead.email || '').trim() && !isValidEmail(session.lead.email)) return true;
      if (key === 'phone' && String(session.lead.email || '').trim()) return false;
      if (key === 'email' && String(session.lead.phone || '').trim()) return false;
      return !String(session.lead[key] || '').trim();
    }) || '';
  }

  function fieldLabel(key) {
    const labels = {
      name: 'your name',
      phone: 'your phone number',
      email: 'your email address',
      message: 'your business activity or question',
      setup_location: 'your preferred setup location or type',
      visa_need: 'whether you need visas',
      timeline: 'your timeline',
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
      if (stepEl) stepEl.textContent = 'Please confirm these details are correct: ' + confirmationSummary();
      if (submit) submit.textContent = 'Send request';
    } else {
      if (stepEl) stepEl.textContent = 'Please enter ' + fieldLabel(step) + '.';
      if (submit) submit.textContent = 'Next';
      const current = leadForm.querySelector('[data-g12-rva-field="' + step + '"]');
      if (current && !leadForm.hidden) current.focus();
    }
  }

  function confirmationSummary() {
    const parts = [];
    if (session.lead.name) parts.push('Name: ' + session.lead.name);
    if (session.lead.phone) parts.push('Phone: ' + session.lead.phone);
    if (session.lead.email) parts.push('Email: ' + session.lead.email);
    if (session.lead.message) parts.push('Need: ' + session.lead.message);
    return (parts.join(' | ') || 'the callback details') + '.';
  }

  function buildSessionPrompt() {
    const notes = session.messages.slice(-8).map((item) => item.role + ': ' + item.text).join('\n');
    const lead = Object.keys(session.lead).filter((key) => session.lead[key]).map((key) => key + ': ' + session.lead[key]).join('\n');
    const profile = Object.keys(session.profile || {}).filter((key) => session.profile[key]).map((key) => key + ': ' + session.profile[key]).join('\n');
    return [
      config.greeting,
      'Continue this browser session if context exists.',
      'Multilingual mode: ' + (config.multilingual ? 'on' : 'off') + '. Qualification depth: ' + (config.qualificationDepth || 'smart') + '.',
      config.storeAudio ? 'The visitor has been shown a notice that microphone audio may be saved with this session.' : '',
      notes ? 'Recent voice chat:\n' + notes : '',
      profile ? 'Visitor profile:\n' + profile : '',
      lead ? 'Collected form details:\n' + lead : '',
      'Lead flow: ask one question at a time in this order when details are missing: business activity, setup location or type, visa need, timeline, then contact details.',
      'If collecting callback details, ask one question only. Do not ask multiple form questions together.'
    ].filter(Boolean).join('\n\n');
  }

  toggle.addEventListener('click', () => {
    if (panel.hidden) openPanel();
    else closePanel();
  });
  closeBtn.addEventListener('click', closePanel);
  if (resetBtn) resetBtn.addEventListener('click', resetSession);
  startBtn.addEventListener('click', startVoice);
  stopBtn.addEventListener('click', stopVoice);

  suggestions.forEach((button) => {
    button.addEventListener('click', () => {
      askTypedQuestion(button.getAttribute('data-g12-rva-suggest') || button.textContent);
    });
  });

  if (composer) {
    composer.addEventListener('submit', (event) => {
      event.preventDefault();
      const input = composer.elements.prompt;
      const value = input ? input.value : '';
      if (input) input.value = '';
      askTypedQuestion(value);
    });
  }

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
        const values = Object.fromEntries(fd.entries());
        session.lead.details_confirmed = 'yes';
        await requestCallback(Object.assign(values, { confirmed_details: true }));
        setMessage('Thanks. Your request was sent to G12.');
      } catch (error) {
        setMessage(error.message || 'Could not send your request.');
      }
    });
  }
})();
