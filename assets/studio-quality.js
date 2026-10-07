(function () {
    'use strict';
    const form = document.getElementById('studioForm');
    if (!form) return;
    const byId = id => document.getElementById(id);
    const prompt = byId('prompt');
    const caption = byId('caption');
    const stage = byId('previewStage');
    const button = byId('createButton');
    const message = byId('formMessage');
    const draftKey = 'oldora_studio_draft_' + form.dataset.userId;
    const jobKey = 'oldora_studio_job_' + form.dataset.userId;
    let busy = false;
    let activeJob = null;
    let pollTimer = null;
    let pollErrors = 0;
    let pollCount = 0;

    const storage = {
        get(key) { try { return sessionStorage.getItem(key); } catch (_) { return null; } },
        set(key, value) { try { sessionStorage.setItem(key, value); } catch (_) {} },
        remove(key) { try { sessionStorage.removeItem(key); } catch (_) {} }
    };
    function mediaType() { return form.querySelector('input[name="media_type"]:checked').value; }
    function selectedAccounts() { return form.querySelectorAll('input[name="token_ids[]"]:checked').length; }
    function setMessage(text, type) { message.textContent = text; message.className = 'message ' + type; }
    function updateCounts() {
        byId('promptCount').textContent = Array.from(prompt.value).length;
        byId('captionCount').textContent = Array.from(caption.value).length;
    }
    function syncSettings() {
        const image = mediaType() === 'image';
        form.querySelectorAll('[data-image-setting]').forEach(node => { node.hidden = !image; });
        form.querySelectorAll('[data-video-setting]').forEach(node => { node.hidden = image; });
        const voiceEnabled = !image && form.dataset.videoProvider === 'moneyprinterturbo';
        byId('voiceControls').hidden = !voiceEnabled;
        byId('voiceControls').querySelectorAll('select').forEach(node => { node.disabled = !voiceEnabled; });
        byId('voiceHint').textContent = voiceEnabled
            ? 'Narration voices follow your selected language. Video length follows the generated script.'
            : 'Voice, music and subtitle controls are available with MoneyPrinterTurbo. The current provider controls video audio.';
        byId('formatHint').hidden = !image;
        form.querySelectorAll('.account').forEach(card => {
            const blocked = image && card.dataset.platform !== 'instagram';
            const input = card.querySelector('input');
            card.classList.toggle('disabled', blocked);
            input.disabled = blocked;
            if (blocked) input.checked = false;
        });
        byId('youtubeVisibilityField').hidden = !form.querySelector('.account[data-platform="youtube"] input:checked');
        byId('brandColorValue').value = byId('useBrandColor').checked ? byId('brandColor').value : '';
        const arabic = ['ar', 'ar-MA'].includes(byId('contentLanguage').value);
        prompt.dir = arabic ? 'rtl' : 'auto';
        caption.dir = arabic ? 'rtl' : 'auto';
        const cost = Number(image ? form.dataset.imageCost : form.dataset.videoCost);
        const accounts = selectedAccounts();
        byId('generationSummary').textContent = cost + ' credit' + (cost === 1 ? '' : 's') + ' per generation · ' +
            (accounts ? accounts + ' account' + (accounts === 1 ? '' : 's') + ' selected for publishing' : 'Generate a draft for download');
        updateCounts();
    }
    function saveDraft() {
        const draft = {};
        ['prompt', 'caption', 'language', 'tone', 'visual_style', 'image_aspect', 'voice', 'voice_pace', 'subtitle_style', 'music', 'audience', 'brand_color', 'youtube_privacy'].forEach(key => {
            const field = form.elements.namedItem(key);
            if (field) draft[key] = field.value;
        });
        draft.media_type = mediaType();
        storage.set(draftKey, JSON.stringify(draft));
        byId('draftStatus').textContent = 'Brief saved in this browser tab.';
    }
    try {
        const draft = JSON.parse(storage.get(draftKey) || 'null');
        if (draft && typeof draft === 'object') {
            Object.keys(draft).forEach(key => {
                if (key === 'media_type') {
                    const radio = ['image', 'video'].includes(draft[key]) && byId(draft[key] === 'image' ? 'typeImage' : 'typeVideo');
                    if (radio && !location.search.includes('type=')) radio.checked = true;
                } else {
                    const field = form.elements.namedItem(key);
                    if (field && typeof draft[key] === 'string') field.value = draft[key];
                }
            });
            if (draft.brand_color) { byId('brandColor').value = draft.brand_color; byId('useBrandColor').checked = true; }
            byId('draftStatus').textContent = 'Restored your saved brief.';
        }
    } catch (_) { storage.remove(draftKey); }
    ['prompt', 'caption'].forEach(key => {
        const existing = storage.get('oldora_draft_' + key);
        if (existing) { form.elements.namedItem(key).value = existing; storage.remove('oldora_draft_' + key); }
    });
    try { byId('timezone').value = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'; } catch (_) {}
    function scheduleMinimum() {
        const date = new Date(Date.now() + 60000);
        const pad = n => String(n).padStart(2, '0');
        byId('scheduledAt').min = date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes());
    }
    scheduleMinimum();
    byId('scheduledAt').addEventListener('focus', scheduleMinimum);
    form.addEventListener('input', () => { syncSettings(); saveDraft(); });
    form.addEventListener('change', () => { syncSettings(); saveDraft(); });
    const briefs = {
        education: { tone: 'educational', text: 'Explain one practical way to improve focus during a busy workday. Open with a relatable problem, show a simple habit anyone can try, and finish with a useful takeaway. Use calm, everyday work scenes.' },
        product: { tone: 'promotional', text: 'Showcase a reusable coffee cup for people who commute. Focus on the design, a morning coffee ritual, and how it fits into an everyday bag. Keep the message honest and warm, with natural light and clean compositions.' },
        story: { tone: 'storytelling', text: 'Tell a short story about a small business owner preparing for their first order. Start with anticipation, show the care behind the packaging, and end with the satisfaction of sending it out. Use warm, authentic details.' }
    };
    form.querySelectorAll('[data-brief]').forEach(node => node.addEventListener('click', () => {
        if (prompt.value.trim() && prompt.value !== briefs[node.dataset.brief].text) {
            setMessage('Your brief is already filled in. Clear it first to use a starting point.', 'error');
            prompt.focus();
            return;
        }
        prompt.value = briefs[node.dataset.brief].text;
        byId('contentTone').value = briefs[node.dataset.brief].tone;
        syncSettings(); saveDraft(); prompt.focus();
    }));
    function setBusy(value) {
        busy = value;
        button.disabled = value;
        button.querySelector('span').textContent = value ? 'Creating…' : 'Create content';
        button.setAttribute('aria-busy', String(value));
    }
    function resetStage() { stage.querySelectorAll('img,video').forEach(node => node.remove()); }
    function showProgress(percent, text) {
        resetStage();
        byId('placeholder').style.display = 'none';
        byId('progressWrap').style.display = 'block';
        byId('progressFill').style.width = Math.max(6, Math.min(100, Number(percent) || 0)) + '%';
        byId('progressText').textContent = text;
        byId('previewStatus').textContent = (Number(percent) || 0) + '%';
        byId('resultActions').hidden = true;
    }
    function showAsset(type, url) {
        resetStage();
        byId('progressWrap').style.display = 'none';
        byId('placeholder').style.display = 'none';
        const element = document.createElement(type === 'image' ? 'img' : 'video');
        element.src = url;
        if (type === 'video') { element.controls = true; element.preload = 'metadata'; }
        else element.alt = 'Your generated image';
        stage.appendChild(element);
        byId('previewStatus').textContent = 'Ready';
        byId('downloadAsset').href = url;
        byId('downloadAsset').hidden = false;
        byId('startAnother').hidden = false;
        byId('checkStatus').hidden = true;
        byId('resultActions').hidden = false;
    }
    async function request(url, options = {}, timeout = 20000) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), timeout);
        try {
            const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', ...options, signal: controller.signal });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                const error = new Error(data.message || 'Request could not be completed.');
                error.status = response.status;
                throw error;
            }
            return data;
        } finally { clearTimeout(timer); }
    }
    async function refreshBalance() {
        try {
            const data = await request('account-balance.php');
            if (Number.isFinite(Number(data.credits))) {
                byId('creditCount').textContent = Number(data.credits);
                window.dispatchEvent(new CustomEvent('oldora:balance', { detail: data }));
            }
        } catch (_) {}
    }
    window.addEventListener('oldora:balance', event => {
        if (event.detail && Number.isFinite(Number(event.detail.credits))) byId('creditCount').textContent = Number(event.detail.credits);
    });
    function finishJob() { storage.remove(jobKey); activeJob = null; clearTimeout(pollTimer); setBusy(false); refreshBalance(); }
    function pausePolling(text) {
        setMessage(text, 'error');
        byId('previewStatus').textContent = 'Check status';
        byId('checkStatus').hidden = false;
        byId('downloadAsset').hidden = true;
        byId('startAnother').hidden = true;
        byId('resultActions').hidden = false;
        // Keep generation disabled until this known job is resolved to avoid duplicate charges.
    }
    async function poll(contentId) {
        if (!activeJob || activeJob.id !== contentId) return;
        try {
            const data = await request('content-status.php?id=' + encodeURIComponent(contentId));
            pollErrors = 0;
            const content = data.content;
            if (content.status === 'ready' && content.asset_url) {
                showAsset(content.media_type, content.asset_url);
                const jobs = Array.isArray(data.publish_jobs) ? data.publish_jobs : [];
                setMessage(jobs.length ? 'Content is ready. Follow publishing progress in Automation.' : 'Content is ready to preview and download.', 'success');
                finishJob();
                return;
            }
            if (content.status === 'failed') {
                setMessage(content.error_message || 'Generation failed. Check your updated credit balance.', 'error');
                byId('progressWrap').style.display = 'none';
                byId('placeholder').style.display = 'block';
                byId('previewStatus').textContent = 'Failed';
                finishJob();
                return;
            }
            pollCount++;
            if (pollCount >= 150) { pausePolling('This video is taking longer than expected. Check its status again; generation can continue in the background.'); return; }
            showProgress(content.progress || 0, 'Rendering video… ' + (content.progress || 0) + '%');
            pollTimer = setTimeout(() => poll(contentId), 12000);
        } catch (error) {
            pollErrors++;
            if ([401, 403, 404, 419].includes(error.status) || pollErrors >= 6) {
                pausePolling(error.status === 401 ? 'Your session expired. Sign in again to view this creation.' : 'Status is temporarily unavailable. Your request is saved; check again before creating another video.');
                return;
            }
            byId('progressText').textContent = 'Reconnecting to your generation…';
            pollTimer = setTimeout(() => poll(contentId), Math.min(60000, 10000 * pollErrors));
        }
    }
    byId('checkStatus').addEventListener('click', () => { if (activeJob) { pollErrors = 0; pollCount = 0; byId('resultActions').hidden = true; poll(activeJob.id); } });
    byId('startAnother').addEventListener('click', () => { prompt.focus(); form.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy) return;
        if (!form.reportValidity()) return;
        message.className = 'message';
        if (Array.from(prompt.value.trim()).length > 4000 || Array.from(caption.value).length > 2200) {
            setMessage('Your brief or caption is too long. Shorten it before creating.', 'error'); return;
        }
        if (selectedAccounts() && !byId('publishConsent').checked) {
            setMessage('Confirm publishing consent for the selected accounts.', 'error'); return;
        }
        if (mediaType() === 'image' && byId('imageAspect').value === 'portrait' && selectedAccounts()) {
            setMessage('Choose square or landscape for Instagram, or deselect publishing to download a portrait image.', 'error'); return;
        }
        if (byId('scheduledAt').value && new Date(byId('scheduledAt').value).getTime() <= Date.now()) {
            setMessage('Choose a future publishing time or leave it empty.', 'error'); return;
        }
        const submittedType = mediaType();
        const submittedAccounts = selectedAccounts();
        const formData = new FormData(form);
        saveDraft(); setBusy(true); showProgress(0, 'Sending your creative brief…');
        try {
            const result = await request('content-create.php', { method: 'POST', body: formData }, 260000);
            refreshBalance();
            if (result.status === 'ready') {
                showAsset(submittedType, result.asset_url);
                setMessage(result.message + (submittedAccounts ? ' Publishing progress is available in Automation.' : ''), 'success');
                setBusy(false);
            } else {
                activeJob = { id: Number(result.content_id), type: submittedType };
                storage.set(jobKey, JSON.stringify(activeJob));
                pollCount = 0; pollErrors = 0;
                setMessage(result.message, 'success');
                showProgress(0, 'Video queued. Rendering can take several minutes.');
                poll(activeJob.id);
            }
        } catch (error) {
            setMessage(error.name === 'AbortError' ? 'The request timed out. Check Recent creations before submitting again; generation may still finish.' : error.message, 'error');
            byId('progressWrap').style.display = 'none';
            byId('placeholder').style.display = 'block';
            byId('previewStatus').textContent = 'Check creations';
            setBusy(false); refreshBalance();
        }
    });
    syncSettings();
    try {
        const job = JSON.parse(storage.get(jobKey) || 'null');
        if (job && Number.isSafeInteger(job.id) && job.id > 0) {
            activeJob = job; setBusy(true); showProgress(0, 'Reconnecting to your saved video request…'); poll(job.id);
        }
    } catch (_) { storage.remove(jobKey); }
})();
