(function () {
    'use strict';

    // Module-level state — persists across PJAX navigations
    var state = {
        source:       null,
        running:      false,
        eventCount:   0,
        startedAt:    0,
        timer:        null,
        currentTitle: '',
        baseUrl:      '/admin/sync/',
        queue:        [],     // remaining steps for the current run
        section:      '',     // last section header written to the log
        totalSteps:   0,
        doneSteps:    0,
        paused:       false,
        stopped:      false,
        resumeFn:     null,   // what to run when the hold is lifted
        pausedAt:     0,
        pausedTotal:  0       // ms spent paused, so elapsed shows working time
    };

    // Live DOM lookups — always reflect the current PJAX-rendered content
    function el(id)     { return document.getElementById(id); }
    function container(){ return document.querySelector('.sync-admin'); }

    // ---- One-time document-level delegation (registered at module load) ----

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('.sync-admin__trigger');
        if (trigger) { start(trigger.dataset.mode, trigger.dataset.title); return; }
        if (e.target.closest('#sync-pause'))          { pauseRun(); return; }
        if (e.target.closest('#sync-resume'))         { resumeRun(); return; }
        if (e.target.closest('#sync-stop'))           { stopRun(); return; }
        if (e.target.closest('#sync-modal-minimize')) { hideModal(); return; }
        if (e.target.closest('#sync-modal-close'))    { hideModal(); stopStream(); setChipHidden(true); return; }
        if (e.target.closest('#sync-chip'))           { showModal(); return; }
    });

    document.addEventListener('keydown', function (e) {
        var overlay = el('sync-overlay');
        if (e.key === 'Escape' && overlay && !overlay.hidden) { hideModal(); }
    });

    // ---- Init — re-reads baseUrl whenever the panel is (re)loaded ----

    function init() {
        var c = container();
        if (!c) { return; }
        state.baseUrl = (c.dataset.baseUrl || '/admin/sync/').replace(/\/?$/, '/');
    }

    // ---- Stream ----
    //
    // Each sync step runs as its own short-lived SSE request. The client fetches
    // the ordered step list for the chosen mode, then walks it one step at a
    // time — so no single HTTP request runs long enough to hit a server-side
    // request timeout.

    function start(mode, title) {
        stopStream();
        resetView(title);
        showModal();

        state.running    = true;
        state.startedAt  = Date.now();
        state.eventCount = 0;
        state.queue      = [];
        state.section    = '';
        state.totalSteps = 0;
        state.doneSteps  = 0;
        state.paused      = false;
        state.stopped     = false;
        state.resumeFn    = null;
        state.pausedAt    = 0;
        state.pausedTotal = 0;
        updateControls();
        updateStats();
        startTimer();

        addLog('Starting…', 'info');

        fetch(state.baseUrl + 'steps?mode=' + encodeURIComponent(mode || 'all'), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                state.queue      = (data && data.steps) ? data.steps.slice() : [];
                state.totalSteps = state.queue.length;
                if (!state.totalSteps) {
                    addLog('✖ No sync steps returned', 'error');
                    finalize('error', 'An error occurred');
                    return;
                }
                runNextStep();
            })
            .catch(function () {
                addLog('✖ Could not load sync steps', 'error');
                finalize('error', 'An error occurred');
            });
    }

    // Every point where the client is about to start another unit of work goes
    // through here: between steps, and between the chunks of a chunked step.
    // Pausing mid-request is not possible — a step is one PHP request — so the
    // hold happens at the next boundary, which also means nothing is ever left
    // half-applied.
    function gate(next) {
        if (state.stopped) { return; }
        if (state.paused)  { state.resumeFn = next; enterPausedUI(); return; }
        next();
    }

    function pauseRun() {
        if (!state.running || state.paused) { return; }
        state.paused = true;
        addLog('Pausing — holding after the current step…', 'info');
        updateControls();
    }

    function resumeRun() {
        if (!state.running || !state.paused) { return; }
        state.paused = false;
        if (state.pausedAt) {
            state.pausedTotal += Date.now() - state.pausedAt;
            state.pausedAt = 0;
        }
        addLog('Resuming…', 'info');
        exitPausedUI();
        updateControls();
        var next = state.resumeFn;
        state.resumeFn = null;
        if (next) { next(); }
    }

    function stopRun() {
        if (!state.running) { return; }
        state.stopped  = true;
        state.paused   = false;
        state.resumeFn = null;
        // The request in flight keeps running server-side: the admin sets
        // ignore_user_abort, so closing the connection does not cancel the work
        // already started. Say so rather than implying it was undone.
        addLog('Stopped. The step already running will finish on the server; nothing further will start.', 'info');
        exitPausedUI();
        finalize('stopped', 'Stopped');
    }

    function runNextStep() {
        if (state.stopped) { return; }
        if (!state.queue.length) {
            addLog('✔ All steps complete', 'complete');
            finalize('success', 'Completed successfully');
            return;
        }

        var step = state.queue.shift();

        if (step.section && step.section !== state.section) {
            state.section = step.section;
            addLog(state.section, 'section');
        }

        state.doneSteps += 1;
        runStepRequest(step, 0);
    }

    // Run one slice of `step` starting at `cursor`. On a 'continue' event the
    // step re-runs from the returned cursor; on 'complete' we advance to the
    // next step. This keeps each HTTP request short.
    function runStepRequest(step, cursor) {
        updateSubtitle('Step ' + state.doneSteps + ' of ' + state.totalSteps + ': ' + step.label);

        var url = state.baseUrl + 'stream_step?step=' + encodeURIComponent(step.id);
        if (cursor) { url += '&cursor=' + encodeURIComponent(cursor); }

        var src = new EventSource(url);
        state.source = src;

        src.addEventListener('step',      function (e) { var d = parse(e); addLog('⮦ ' + d.message, 'step'); });
        src.addEventListener('step_done', function (e) { var d = parse(e); addLog('✔ ' + d.message, 'done'); });
        src.addEventListener('log',       function (e) { var d = parse(e); addLog(d.message, d.level || 'info'); });
        src.addEventListener('continue',  function (e) {
            src.close();
            state.source = null;
            var d = parse(e);
            var next = parseInt(d.message, 10);
            gate(function () { runStepRequest(step, isNaN(next) ? 0 : next); });
        });
        src.addEventListener('complete',  function () {
            src.close();
            state.source = null;
            gate(runNextStep);
        });
        src.addEventListener('error', function (e) {
            // 'continue'/'complete' already handled this stream and moved on —
            // ignore the trailing connection-closed error EventSource fires after.
            if (state.source !== src) { return; }
            // Stopping closes the stream on purpose; that is not an error.
            if (state.stopped) { return; }
            var msg = 'Connection error during "' + step.label + '"';
            try { if (e.data) { msg = JSON.parse(e.data).message; } } catch (_) {}
            src.close();
            state.source = null;
            addLog('✖ ' + msg, 'error');
            finalize('error', 'An error occurred');
        });
    }

    function stopStream() {
        if (state.source) { state.source.close(); state.source = null; }
        state.queue = [];
    }

    // ---- View helpers ----

    function finalize(kind, statusText) {
        stopStream();
        stopTimer();
        state.running = false;

        var spinner    = el('sync-spinner');
        var statusEl   = el('sync-status');
        var closeBtn   = el('sync-modal-close');
        var minimizeBtn = el('sync-modal-minimize');

        var note = el('sync-paused-note');

        if (note)        { note.hidden = true; }
        if (spinner)     { spinner.hidden = true; }
        if (statusEl)    { statusEl.hidden = false; statusEl.textContent = statusText; statusEl.className = 'sync-admin__status sync-admin__status--' + kind; }
        if (closeBtn)    { closeBtn.hidden = false; }
        if (minimizeBtn) { minimizeBtn.hidden = true; }
        updateControls();
        setChipHidden(true);
    }

    // Which of pause / resume / stop make sense right now. Offering a control
    // that does nothing is worse than not offering it.
    function updateControls() {
        var pause  = el('sync-pause');
        var resume = el('sync-resume');
        var stop   = el('sync-stop');
        var live   = state.running && !state.stopped;

        if (pause)  { pause.hidden  = !(live && !state.paused); }
        if (resume) { resume.hidden = !(live && state.paused); }
        if (stop)   { stop.hidden   = !live; }
    }

    function enterPausedUI() {
        state.pausedAt = Date.now();

        var spinner = el('sync-spinner');
        var note    = el('sync-paused-note');
        var chip    = el('sync-chip-label');

        if (spinner) { spinner.hidden = true; }
        if (note)    { note.hidden = false; }
        if (chip)    { chip.textContent = 'Paused'; }
        updateSubtitle('Paused — ' + state.queue.length + ' step(s) still to run');
        updateControls();
    }

    function exitPausedUI() {
        var spinner = el('sync-spinner');
        var note    = el('sync-paused-note');
        var chip    = el('sync-chip-label');

        if (spinner) { spinner.hidden = state.stopped; }
        if (note)    { note.hidden = true; }
        if (chip)    { chip.textContent = state.currentTitle + '…'; }
    }

    function parse(e) { try { return JSON.parse(e.data); } catch (_) { return { message: '' }; } }

    function addLog(msg, type) {
        var logEl = el('sync-log');
        if (!logEl) { return; }
        var div = document.createElement('div');
        div.className = 'sync-log-entry sync-log-entry--' + type;
        div.textContent = msg;
        logEl.appendChild(div);
        logEl.scrollTop = logEl.scrollHeight;
        state.eventCount += 1;
        updateStats();
    }

    function resetView(title) {
        var logEl      = el('sync-log');
        var modalTitle = el('sync-modal-title');
        var modalSub   = el('sync-modal-subtitle');
        var statusEl   = el('sync-status');
        var spinner    = el('sync-spinner');
        var closeBtn   = el('sync-modal-close');
        var minimizeBtn = el('sync-modal-minimize');
        var chipLabel  = el('sync-chip-label');

        if (logEl)       { logEl.innerHTML = ''; }
        if (modalTitle)  { modalTitle.textContent = title; }
        if (modalSub)    { modalSub.textContent = 'Live progress'; }
        if (statusEl)    { statusEl.hidden = true; statusEl.textContent = ''; statusEl.className = 'sync-admin__status'; }
        if (spinner)     { spinner.hidden = false; }
        if (closeBtn)    { closeBtn.hidden = true; }
        if (minimizeBtn) { minimizeBtn.hidden = false; }
        if (chipLabel)   { chipLabel.textContent = title + '…'; }
        var note = el('sync-paused-note');
        if (note)        { note.hidden = true; }
        state.currentTitle = title;
    }

    function updateSubtitle(text) {
        var modalSub = el('sync-modal-subtitle');
        if (modalSub) { modalSub.textContent = text; }
    }

    function showModal() {
        var overlay = el('sync-overlay');
        if (overlay) { overlay.hidden = false; }
        setChipHidden(true);
    }

    function hideModal() {
        var overlay = el('sync-overlay');
        if (overlay) { overlay.hidden = true; }
        if (state.running) { setChipHidden(false); }
    }

    function setChipHidden(hidden) {
        var chip = el('sync-chip');
        if (chip) { chip.hidden = hidden; }
    }

    // ---- Stats timer ----

    function startTimer() {
        stopTimer();
        state.timer = setInterval(updateStats, 500);
    }

    function stopTimer() {
        if (state.timer) { clearInterval(state.timer); state.timer = null; }
    }

    function updateStats() {
        var statEvents  = el('sync-stat-events');
        var statElapsed = el('sync-stat-elapsed');
        if (statEvents)  { statEvents.textContent = state.eventCount + (state.eventCount === 1 ? ' event' : ' events'); }
        if (statElapsed) {
            // Time spent paused is not time the sync was working, and counting
            // it makes the figure useless for judging how long a run takes.
            var pausedSoFar = state.pausedTotal + (state.pausedAt ? Date.now() - state.pausedAt : 0);
            var secs = Math.max(0, Math.floor((Date.now() - state.startedAt - pausedSoFar) / 1000));
            var mm = String(Math.floor(secs / 60)).padStart(2, '0');
            var ss = String(secs % 60).padStart(2, '0');
            statElapsed.textContent = mm + ':' + ss;
        }
    }

    // ---- Bootstrap ----

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    document.addEventListener('cms-panel-loaded', init);
    if (window.jQuery) {
        window.jQuery(document).on('redrawform', init);
    }
}());
