<?php
// EssayGuard — JS Event Capture Tester
// URL: /plagiarism/essayguard/capture_test.php
// Tests: paste detection, large inserts, keystroke ratio, pauses, CPS, backspace ratio.
// Mirrors analyser.php signal logic in JS so you can see live what score a session would get.

require_once(__DIR__ . '/../../config.php');
// FIX-EG-CAPTURE-TEST-AUTH (v1.2.182): Restrict to site administrators only.
// Previously only require_login() was checked, meaning any authenticated user
// could access this diagnostic tool. Added require_capability() to match the
// access control pattern used in diag.php and badge_diag.php.
require_login();
require_capability('moodle/site:config', context_system::instance());
$PAGE->set_url(new moodle_url('/plagiarism/essayguard/capture_test.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('EssayGuard — Event Capture Test');
$PAGE->set_heading('EssayGuard — Event Capture Test');
echo $OUTPUT->header();
?>
<style>
.eg-wrap          { max-width:960px; margin:0 auto; font-family:sans-serif; }
.eg-row           { display:flex; gap:16px; margin-top:16px; }
.eg-col           { flex:1; }
.eg-textarea      { width:100%; height:180px; font-size:15px; padding:10px;
                    border:2px solid #ccc; border-radius:6px; box-sizing:border-box;
                    resize:vertical; }
.eg-textarea.paste-flash { border-color:#e67e22; background:#fff8f0; transition:none; }
.eg-panel         { background:#f8f9fa; border:1px solid #dee2e6; border-radius:6px;
                    padding:14px; }
.eg-panel h3      { margin:0 0 10px; font-size:13px; text-transform:uppercase;
                    letter-spacing:.05em; color:#555; }
.eg-stat          { display:flex; justify-content:space-between; align-items:center;
                    padding:3px 0; border-bottom:1px solid #eee; font-size:13px; }
.eg-stat:last-child { border-bottom:none; }
.eg-val           { font-weight:700; font-family:monospace; font-size:14px; }
.eg-val.warn      { color:#e67e22; }
.eg-val.danger    { color:#c0392b; }
.eg-val.ok        { color:#27ae60; }
.sig-row          { display:flex; align-items:center; gap:8px; padding:4px 0;
                    border-bottom:1px solid #eee; font-size:13px; }
.sig-row:last-child { border-bottom:none; }
.sig-badge        { display:inline-block; min-width:58px; text-align:center;
                    padding:2px 6px; border-radius:4px; font-size:11px; font-weight:700; }
.sig-badge.fired  { background:#2ecc71; color:#fff; }
.sig-badge.silent { background:#bdc3c7; color:#555; }
.sig-badge.warn   { background:#e67e22; color:#fff; }
.badge-score      { display:inline-block; padding:6px 18px; border-radius:20px;
                    font-weight:700; font-size:16px; color:#fff; }
.badge-low        { background:#27ae60; }
.badge-medium     { background:#e67e22; }
.badge-high       { background:#c0392b; }
.ev-log           { font-size:11px; font-family:monospace; max-height:180px;
                    overflow-y:auto; background:#fff; border:1px solid #dee2e6;
                    border-radius:4px; padding:6px; }
.ev-log .ev       { padding:1px 0; border-bottom:1px solid #f0f0f0; }
.ev-log .ev.paste { color:#c0392b; font-weight:700; }
.ev-log .ev.key   { color:#2980b9; }
.ev-log .ev.pause { color:#e67e22; }
.ev-log .ev.large { color:#8e44ad; font-weight:700; }
.eg-btn           { padding:7px 16px; border:none; border-radius:5px; cursor:pointer;
                    font-size:13px; font-weight:600; }
.eg-btn.primary   { background:#3498db; color:#fff; }
.eg-btn.danger    { background:#e74c3c; color:#fff; }
.eg-btn.secondary { background:#95a5a6; color:#fff; }
.eg-notice        { background:#fff3cd; border:1px solid #ffc107; border-radius:5px;
                    padding:10px 14px; font-size:13px; margin-bottom:12px; }
.cap-note         { font-size:11px; color:#777; font-style:italic; margin-top:4px; }
</style>

<div class="eg-wrap">
  <div class="eg-notice">
    <strong>EssayGuard Event Capture Test</strong> — Type, paste, backspace, and pause in the box below.
    All metrics update live. The signal panel shows exactly what EssayGuard's analyser would detect.
    <br>Use the <strong>Copy test text</strong> button then paste into the box to verify paste capture.
  </div>

  <div style="margin-bottom:8px; display:flex; gap:8px; flex-wrap:wrap;">
    <button class="eg-btn primary" id="btn-copy">Copy test text</button>
    <button class="eg-btn secondary" id="btn-copylarge">Copy large block (500 chars)</button>
    <button class="eg-btn danger" id="btn-reset">Reset</button>
  </div>

  <textarea class="eg-textarea" id="eg-area"
    placeholder="Start typing here, or paste text to test event capture..."></textarea>

  <div class="eg-row">
    <!-- Live stats -->
    <div class="eg-col" style="max-width:280px;">
      <div class="eg-panel">
        <h3>Live Metrics</h3>
        <div class="eg-stat"><span>Total chars</span><span class="eg-val" id="s-chars">0</span></div>
        <div class="eg-stat"><span>Keystrokes</span><span class="eg-val" id="s-keys">0</span></div>
        <div class="eg-stat"><span>Backspaces</span><span class="eg-val" id="s-bs">0</span></div>
        <div class="eg-stat"><span>Backspace ratio</span><span class="eg-val" id="s-bsratio">—</span></div>
        <div class="eg-stat"><span>Paste events</span><span class="eg-val" id="s-paste">0</span></div>
        <div class="eg-stat"><span>Paste chars</span><span class="eg-val" id="s-pastechars">0</span></div>
        <div class="eg-stat"><span>Paste fraction</span><span class="eg-val" id="s-pastefrac">—</span></div>
        <div class="eg-stat"><span>Large inserts (&gt;50)</span><span class="eg-val" id="s-large">0</span></div>
        <div class="eg-stat"><span>Pauses (&gt;3s)</span><span class="eg-val" id="s-pauses">0</span></div>
        <div class="eg-stat"><span>Elapsed time</span><span class="eg-val" id="s-elapsed">0s</span></div>
        <div class="eg-stat"><span>CPS (chars/sec)</span><span class="eg-val" id="s-cps">—</span></div>
        <div class="eg-stat"><span>Keystroke ratio</span><span class="eg-val" id="s-ksr">—</span></div>
        <div class="eg-stat"><span>S13 no-paste flag</span><span class="eg-val" id="s-s13flag">—</span></div>
      </div>
    </div>

    <!-- Signals + score -->
    <div class="eg-col">
      <div class="eg-panel">
        <h3>Signal Breakdown (analyser.php simulation)</h3>
        <div id="sig-list"></div>
        <div style="margin-top:12px; display:flex; align-items:center; gap:12px;">
          <span style="font-size:13px; font-weight:600;">Predicted score:</span>
          <span class="badge-score badge-low" id="score-badge">—</span>
          <span style="font-size:13px; color:#555;" id="score-pts"></span>
        </div>
        <div class="cap-note" id="cap-note"></div>
      </div>

      <!-- Event log -->
      <div class="eg-panel" style="margin-top:12px;">
        <h3>Event Log <span style="font-weight:400;font-size:11px;color:#888;">(last 40)</span></h3>
        <div class="ev-log" id="ev-log"></div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  'use strict';

  // ── State ──────────────────────────────────────────────────────────────────
  var state = {
    keys: 0, backspaces: 0, pasteEvents: 0, pasteChars: 0, largeInserts: 0,
    pauses: 0, startTime: 0, lastKeyTime: 0, events: []
  };

  var area      = document.getElementById('eg-area');
  var evLog     = document.getElementById('ev-log');
  var PAUSE_MS  = 3000;   // 3 s = long pause threshold
  var LARGE_THR = 50;     // chars inserted at once = "large insert"

  // ── Helpers ────────────────────────────────────────────────────────────────
  function now() { return Date.now(); }
  function elapsed() {
    return state.startTime ? ((now() - state.startTime) / 1000).toFixed(1) : 0;
  }
  function pct(n, d) { return d ? ((n / d) * 100).toFixed(1) + '%' : '—'; }
  function logEv(cls, msg) {
    var d = document.createElement('div');
    d.className = 'ev ' + cls;
    d.textContent = '[' + elapsed() + 's] ' + msg;
    evLog.insertBefore(d, evLog.firstChild);
    while (evLog.childElementCount > 40) evLog.removeChild(evLog.lastChild);
  }

  // ── Pause detection ────────────────────────────────────────────────────────
  function checkPause(ts) {
    if (state.lastKeyTime && (ts - state.lastKeyTime) > PAUSE_MS) {
      state.pauses++;
      logEv('pause', 'PAUSE ' + ((ts - state.lastKeyTime) / 1000).toFixed(1) + 's gap');
    }
    state.lastKeyTime = ts;
  }

  // ── Keydown ────────────────────────────────────────────────────────────────
  area.addEventListener('keydown', function (e) {
    var ts = now();
    if (!state.startTime) state.startTime = ts;
    checkPause(ts);
    if (e.key === 'Backspace' || e.key === 'Delete') {
      state.backspaces++;
      logEv('key', 'BACKSPACE');
    } else if (!e.ctrlKey && !e.metaKey && !e.altKey && e.key.length === 1) {
      state.keys++;
      // don't log every key — just count
    } else {
      // modifier / special key — still a keystroke for ratio purposes
      if (e.key.length > 1 && e.key !== 'Shift' && e.key !== 'CapsLock') {
        // navigation / modifier — don't count as typing keystroke
      }
    }
    refresh();
  });

  // ── Paste ──────────────────────────────────────────────────────────────────
  area.addEventListener('paste', function (e) {
    var ts = now();
    if (!state.startTime) state.startTime = ts;
    var text = '';
    if (e.clipboardData) text = e.clipboardData.getData('text/plain');
    var len = text.length;
    state.pasteEvents++;
    state.pasteChars += len;
    if (len > LARGE_THR) {
      state.largeInserts++;
      logEv('large', 'LARGE INSERT via paste: ' + len + ' chars — "' + text.slice(0, 40).replace(/\n/g,' ') + (len>40?'…':'') + '"');
    } else {
      logEv('paste', 'PASTE: ' + len + ' chars — "' + text.slice(0, 60).replace(/\n/g,' ') + '"');
    }
    // flash border
    area.classList.add('paste-flash');
    setTimeout(function(){ area.classList.remove('paste-flash'); }, 600);
    state.lastKeyTime = ts;
    setTimeout(refresh, 50);
  });

  // ── Drop (drag-and-drop text) ──────────────────────────────────────────────
  area.addEventListener('drop', function (e) {
    var ts = now();
    if (!state.startTime) state.startTime = ts;
    var text = e.dataTransfer ? e.dataTransfer.getData('text/plain') : '';
    var len = text.length;
    if (len > 0) {
      state.pasteEvents++;
      state.pasteChars += len;
      if (len > LARGE_THR) state.largeInserts++;
      logEv('paste', 'DROP: ' + len + ' chars');
    }
    setTimeout(refresh, 50);
  });

  // ── Input (catches IME, autocomplete, voice, etc.) ─────────────────────────
  area.addEventListener('input', function (e) {
    var ts = now();
    if (!state.startTime) state.startTime = ts;
    // Detect large inserts not caught by paste (e.g. autocomplete, voice)
    if (e.inputType === 'insertFromPaste' || e.inputType === 'insertFromDrop') {
      // already handled by paste/drop — skip to avoid double-count
    } else if (e.inputType === 'insertText' && typeof e.data === 'string' && e.data.length > LARGE_THR) {
      state.largeInserts++;
      logEv('large', 'LARGE AUTO-INSERT: ' + e.data.length + ' chars (inputType=' + e.inputType + ')');
    }
    refresh();
  });

  // ── Signal simulation (mirrors analyser.php) ───────────────────────────────
  function computeSignals() {
    var textChars   = area.value.length;
    var keystrokes  = state.keys + state.backspaces;  // total recorded keystrokes
    var pasteCount  = state.pasteEvents;
    var pasteChars  = state.pasteChars;
    var largeInserts= state.largeInserts;
    var bsCount     = state.backspaces;
    var pauseCount  = state.pauses;
    var elapsedSec  = state.startTime ? (now() - state.startTime) / 1000 : 0;

    var pastefrac   = textChars > 0 ? pasteChars / textChars : 0;
    var bsRatio     = keystrokes > 0 ? bsCount / keystrokes : 0;
    var ksRatio     = textChars > 0 ? keystrokes / textChars : 0;
    var cps         = elapsedSec > 2 && textChars > 0 ? textChars / elapsedSec : 0;

    // events_empty_for_scoring analog: no keys AND no paste events
    var eventsEmpty  = (keystrokes === 0 && pasteCount === 0);

    // FIX-EG-S13-NO-PASTE flag (v1.2.143)
    var s13NoPaste  = (!eventsEmpty && pasteCount === 0 && largeInserts === 0
                      && keystrokes > 0 && textChars > 100 && ksRatio < 0.25);

    var signals = {};
    var score   = 0;

    // S1 — paste fraction
    if (pasteCount > 0 || largeInserts > 0) {
      var s1pts = 0;
      if (pastefrac >= 0.95)      s1pts = 60;
      else if (pastefrac >= 0.50) s1pts = 30 + Math.round((pastefrac - 0.50) / 0.45 * 30);
      else if (pastefrac >= 0.05) s1pts = 10;
      signals[1] = { label: 'S1 Paste fraction (' + (pastefrac*100).toFixed(0) + '%)', pts: s1pts, fired: s1pts > 0 };
      score += s1pts;
    } else {
      signals[1] = { label: 'S1 Paste fraction (no paste events)', pts: 0, fired: false };
    }

    // S2 — large insert ratio (simplified)
    var s2pts = 0;
    if (largeInserts > 0 && pastefrac < 0.05) {
      s2pts = 20;
      signals[2] = { label: 'S2 Large insert (>50 chars, low paste_frac)', pts: s2pts, fired: true };
      score += s2pts;
    } else {
      signals[2] = { label: 'S2 Large insert', pts: 0, fired: false };
    }

    // S4 — no long pauses (>3s = risky in a timed test)
    if (!eventsEmpty) {
      var s4pts = (pauseCount === 0) ? 20 : 0;
      signals[4] = { label: 'S4 No long pauses (pauses=' + pauseCount + ')', pts: s4pts, fired: s4pts > 0 };
      score += s4pts;
    } else {
      signals[4] = { label: 'S4 No long pauses (no events)', pts: 0, fired: false };
    }

    // S5 — low backspace ratio
    if (!eventsEmpty) {
      var s5pts = (bsRatio < 0.10) ? 15 : 0;
      signals[5] = { label: 'S5 Low backspace ratio (' + (bsRatio*100).toFixed(1) + '%)', pts: s5pts, fired: s5pts > 0 };
      score += s5pts;
    } else {
      signals[5] = { label: 'S5 Low backspace ratio (no events)', pts: 0, fired: false };
    }

    // S8 — sentence uniformity (can't compute in JS; shown as unknown)
    signals[8] = { label: 'S8 Sentence uniformity (server-side NLP only)', pts: '?', fired: null };

    // S12 — keystroke ratio < 0.5
    if (!eventsEmpty) {
      var s12pts = (ksRatio > 0 && ksRatio < 0.5) ? 10 : 0;
      signals[12] = { label: 'S12 Keystroke ratio ' + ksRatio.toFixed(3) + ' (< 0.5)', pts: s12pts, fired: s12pts > 0 };
      score += s12pts;
    } else {
      signals[12] = { label: 'S12 Keystroke ratio (no events)', pts: 0, fired: false };
    }

    // S13 — server-side CPS
    var s13pts = 0;
    if ((eventsEmpty || s13NoPaste) && elapsedSec > 2 && textChars > 50) {
      if (cps > 10)      s13pts = 50;
      else if (cps > 4)  s13pts = 25;
      signals[13] = { label: 'S13 Server CPS ' + cps.toFixed(2) + (s13NoPaste?' [s13_no_paste_evidence]':'') + (eventsEmpty?' [events_empty]':''), pts: s13pts, fired: s13pts > 0 };
      score += s13pts;
    } else if (!eventsEmpty && !s13NoPaste && elapsedSec > 2) {
      signals[13] = { label: 'S13 Server CPS — suppressed (events present, paste captured)', pts: 0, fired: false };
    } else {
      signals[13] = { label: 'S13 Server CPS (waiting: need >50 chars, >2s)', pts: 0, fired: false };
    }

    // False-positive cap: if no paste AND no large_insert, cap at 45
    var capped = false;
    if (pasteCount === 0 && largeInserts === 0 && score > 45) {
      score = 45; capped = true;
    }
    score = Math.min(100, score);

    return { signals: signals, score: score, capped: capped,
             pastefrac: pastefrac, bsRatio: bsRatio, ksRatio: ksRatio,
             cps: cps, eventsEmpty: eventsEmpty, s13NoPaste: s13NoPaste,
             textChars: textChars, keystrokes: keystrokes, elapsedSec: elapsedSec };
  }

  // ── Render ─────────────────────────────────────────────────────────────────
  function refresh() {
    var r = computeSignals();
    var textChars = r.textChars;

    // Stat panel
    setText('s-chars',    textChars);
    setText('s-keys',     state.keys);
    setText('s-bs',       state.backspaces);
    setVal('s-bsratio',   pct(state.backspaces, r.keystrokes), r.bsRatio >= 0.10 ? 'warn' : 'ok');
    setVal('s-paste',     state.pasteEvents, state.pasteEvents > 0 ? 'danger' : '');
    setVal('s-pastechars',state.pasteChars,  state.pasteChars > 0 ? 'warn' : '');
    setVal('s-pastefrac', pct(state.pasteChars, textChars), r.pastefrac >= 0.05 ? 'danger' : 'ok');
    setVal('s-large',     state.largeInserts, state.largeInserts > 0 ? 'danger' : '');
    setVal('s-pauses',    state.pauses, state.pauses > 0 ? 'warn' : 'ok');
    setText('s-elapsed',  elapsed() + 's');
    setVal('s-cps',       r.elapsedSec > 1 ? r.cps.toFixed(2) : '—',
           r.cps > 10 ? 'danger' : r.cps > 4 ? 'warn' : '');
    setVal('s-ksr',       textChars > 0 ? r.ksRatio.toFixed(3) : '—',
           r.ksRatio < 0.25 && textChars > 100 ? 'danger' : r.ksRatio < 0.5 && textChars > 0 ? 'warn' : 'ok');
    setVal('s-s13flag',   r.s13NoPaste ? 'TRUE ⚠' : 'false',
           r.s13NoPaste ? 'danger' : '');

    // Signal list
    var html = '';
    [1, 2, 4, 5, 8, 12, 13].forEach(function(k) {
      var sig = r.signals[k];
      if (!sig) return;
      var cls = sig.fired === null ? 'warn' : sig.fired ? 'fired' : 'silent';
      var label = sig.fired === null ? '?' : sig.fired ? '+' + sig.pts + ' pts' : 'silent';
      html += '<div class="sig-row">'
        + '<span class="sig-badge ' + cls + '">' + label + '</span>'
        + '<span>' + sig.label + '</span></div>';
    });
    document.getElementById('sig-list').innerHTML = html;

    // Score badge
    var badge = document.getElementById('score-badge');
    var ptsEl = document.getElementById('score-pts');
    var capEl = document.getElementById('cap-note');
    if (textChars < 10) {
      badge.textContent = '—';
      badge.className = 'badge-score badge-low';
      ptsEl.textContent = '';
      capEl.textContent = '';
    } else {
      var lbl = r.score >= 66 ? 'HIGH' : r.score >= 30 ? 'MEDIUM' : 'LOW';
      var cls = r.score >= 66 ? 'badge-high' : r.score >= 30 ? 'badge-medium' : 'badge-low';
      badge.textContent = lbl + ' ' + r.score + '%';
      badge.className = 'badge-score ' + cls;
      ptsEl.textContent = '(raw score ' + r.score + '/100)';
      capEl.textContent = r.capped
        ? '⚠ False-positive cap applied: no paste/large_insert detected, so score capped at 45% even if secondary signals fired.'
        : '';
    }
  }

  function setText(id, v) {
    var el = document.getElementById(id);
    if (el) { el.textContent = v; el.className = 'eg-val'; }
  }
  function setVal(id, v, cls) {
    var el = document.getElementById(id);
    if (el) { el.textContent = v; el.className = 'eg-val ' + (cls || ''); }
  }

  // ── Buttons ────────────────────────────────────────────────────────────────
  var SHORT_TEXT = 'The principles of workplace health and safety require all participants to demonstrate understanding of risk assessment methodologies. Students are expected to apply these concepts independently.';
  var LARGE_TEXT = 'Workplace health and safety (WHS) legislation in Australia requires registered training organisations to embed WHS principles throughout their training programs. RTOs must ensure that all training and assessment activities comply with the model WHS Act and relevant codes of practice. Trainers and assessors are responsible for identifying hazards, assessing risks, and implementing control measures in accordance with the hierarchy of controls. Students undertaking vocational education and training must be able to apply WHS knowledge in their specific industry context. The risk management process involves hazard identification, risk assessment, control implementation, and ongoing monitoring and review. Documentation of these processes is essential for compliance with regulatory requirements and for demonstrating continuous improvement in safety management systems.';

  document.getElementById('btn-copy').addEventListener('click', function () {
    navigator.clipboard.writeText(SHORT_TEXT).then(function () {
      this.textContent = 'Copied! Now paste into the box ↑';
      setTimeout(function(){ document.getElementById('btn-copy').textContent = 'Copy test text'; }, 2500);
    }.bind(this)).catch(function() {
      prompt('Copy this text:', SHORT_TEXT);
    });
  });

  document.getElementById('btn-copylarge').addEventListener('click', function () {
    navigator.clipboard.writeText(LARGE_TEXT).then(function () {
      this.textContent = 'Copied large block! Now paste ↑';
      setTimeout(function(){ document.getElementById('btn-copylarge').textContent = 'Copy large block (500 chars)'; }, 2500);
    }.bind(this)).catch(function() {
      prompt('Copy this text:', LARGE_TEXT);
    });
  });

  document.getElementById('btn-reset').addEventListener('click', function () {
    area.value = '';
    state = { keys: 0, backspaces: 0, pasteEvents: 0, pasteChars: 0, largeInserts: 0,
              pauses: 0, startTime: 0, lastKeyTime: 0, events: [] };
    evLog.innerHTML = '';
    refresh();
  });

  // Initial render
  refresh();
})();
</script>

<?php echo $OUTPUT->footer(); ?>
