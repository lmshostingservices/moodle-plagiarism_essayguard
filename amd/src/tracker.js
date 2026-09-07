// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Essay Guard  -  keystroke/paste/behaviour telemetry tracker.
 *
 * Loaded via js_call_amd('plagiarism_essayguard/tracker', 'init', [...]).
 * Requires Moodle AMD / RequireJS  -  file MUST be wrapped in define().
 *
 * @module     plagiarism_essayguard/tracker
 * @copyright  2025 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax'], function(Ajax) {

    var state = {
        cmid: 0,
        attemptkey: '',
        flushinterval: 5000,
        maxburstchars: 150,
        queue: [],
        timer: null,
        lastLengths: new WeakMap(),
        sessionStart: Date.now(),
        burstWordCount: 0,
        burstStart: null,
        inBurst: false,
        pauseThreshold: 2000,
        flushing: false,      // FIX-EG-FLUSH-LOSS: guards against concurrent flushes
        flushPromise: null,   // FIX-EG-FLUSH-LOSS: shared promise for in-flight flush
        // v1.2.219: init() re-entry guard + timer handles so the three setInterval
        // timers can actually be stopped. See init().
        initialised: false,
        timers: [],
        flushFailures: 0,
        queueDropped: 0,
        nextSeq: 1,
        // FIX-EG-TINYMCE-FINALIZE (v1.2.90): Registry of bound TinyMCE iframes.
        // The submit-time querySelectorAll('[data-essayguard-bound="1"]') runs on the
        // outer document and cannot see elements inside iframe contentDocuments.
        // Each entry: { outerNode: <iframe>, body: <iframe body>, qslot: <int> }.
        boundFrames: [],
    };

    var BADGE_STORAGE_KEY = 'essayguard_pending_badge';
    // FIX-EG-TOAST-PERQ (v1.2.69): separate key holding a {qslot: data} map so the
    // post-submit toast can list each question's risk, not just the aggregate.
    var BADGE_PERQ_STORAGE_KEY = 'essayguard_pending_badges_perq';

    var nowMs = function() {
        return Date.now();
    };

    // v1.2.219: Hard ceilings on the client queue.
    //
    // MAX_QUEUE: the queue was unbounded. Every failed flush rolled its batch back into
    // the queue and the next flush retried the whole thing, so a student who lost
    // connectivity for ten minutes of an exam accumulated tens of thousands of events in
    // memory — and then the beforeunload sendBeacon() silently refused the payload (the
    // Beacon spec caps the body at roughly 64KB), losing every one of them. Bounded now:
    // past the ceiling the OLDEST events are dropped, because the recent ones are the
    // ones nearest the submission and the ones the scoring signals care about.
    //
    // MAX_BATCH: matches log_event::MAX_EVENTS on the server. A flush larger than this is
    // now split into chunks rather than being rejected wholesale.
    var MAX_QUEUE = 5000;
    var MAX_BATCH = 500;

    // v1.2.219: The flush .catch used to be an empty block — a failing flush was
    // completely invisible, in the browser and in support. Failures are now reported to
    // the console (once every 10 failures after the first, so a long offline stretch does
    // not spam it) and counted on state so the count is inspectable.
    var reportFlushFailure = function(err) {
        state.flushFailures++;
        if (state.flushFailures === 1 || state.flushFailures % 10 === 0) {
            if (typeof window !== 'undefined' && window.console && window.console.warn) {
                window.console.warn(
                    '[Essay Guard] telemetry flush failed (' + state.flushFailures +
                    ' failure(s), ' + state.queue.length + ' event(s) queued):',
                    err
                );
            }
        }
    };

    var enqueue = function(eventname, payload) {
        payload = payload || {};
        state.queue.push({
            // v1.2.220: monotonic sequence number. The flush commit used to splice by
            // INDEX, which assumed nothing else could touch the front of the queue - an
            // assumption the v1.2.219 drop-oldest broke. Identity lets the commit remove
            // exactly what was sent.
            seq: (state.nextSeq++),
            eventname: eventname,
            eventtime: nowMs(),
            payloadjson: JSON.stringify(payload),
        });
        // v1.2.219: Bound the queue. Drop from the front (oldest first).
        if (state.queue.length > MAX_QUEUE) {
            var overflow = state.queue.length - MAX_QUEUE;
            state.queue.splice(0, overflow);
            state.queueDropped += overflow;
            if (typeof window !== 'undefined' && window.console && window.console.warn) {
                window.console.warn(
                    '[Essay Guard] telemetry queue full — dropped ' + state.queueDropped +
                    ' oldest event(s). The server may be unreachable.'
                );
            }
        }
    };

    var flush = function() {
        if (!state.queue.length) {
            return Promise.resolve();
        }

        // FIX-EG-FLUSH-LOSS (v1.2.43): guard concurrent flushes with flushing flag;
        // only splice queue inside .then() so events survive network failures.
        if (state.flushing) {
            return state.flushPromise;
        }
        state.flushing = true;

        // v1.2.219: Chunk the flush. Previously the whole queue went in one call, which
        // the server (log_event::MAX_EVENTS) now rejects outright above 500 events —
        // a backlog would have wedged permanently, retrying a payload that could never
        // be accepted. Each flush sends at most MAX_BATCH; the timer picks up the rest.
        var batchLen = Math.min(state.queue.length, MAX_BATCH);
        var batch = state.queue.slice(0, batchLen); // snapshot without removing

        // v1.2.220: `seq` is a CLIENT-ONLY field used to commit by identity. It is not in
        // log_event::execute_parameters(), and Moodle's external API rejects a structure
        // carrying an undeclared key - sending it would fail every single flush. Strip it
        // for the wire, keep it on the queued copies.
        var wire = batch.map(function(e) {
            return {
                eventname:   e.eventname,
                eventtime:   e.eventtime,
                payloadjson: e.payloadjson,
            };
        });

        state.flushPromise = Ajax.call([{
            methodname: 'plagiarism_essayguard_log_event',
            args: {
                cmid: state.cmid,
                attemptkey: state.attemptkey,
                events: wire,
            }
        }])[0].then(function(result) {
            // Commit by IDENTITY, not by index.
            //
            // v1.2.220: this was `state.queue.splice(0, batchLen)`, which assumed index 0
            // was still the first event sent. While a flush is in flight, enqueue() can now
            // drop events off the FRONT when the queue hits MAX_QUEUE - so a 500-event
            // batch that had 100 dropped underneath it committed 500 slots and destroyed
            // 100 events that were never transmitted. Silent telemetry loss, at exactly the
            // moment the server is unreachable and the data matters most.
            var sentSeq = {};
            for (var i = 0; i < batch.length; i++) { sentSeq[batch[i].seq] = true; }
            state.queue = state.queue.filter(function(e) { return !sentSeq[e.seq]; });
            state.flushFailures = 0;
            return result;
        }).catch(function(err) {
            // Rollback: events remain in the queue; next flush interval will retry.
            reportFlushFailure(err);
        }).finally(function() {
            state.flushing = false;
            state.flushPromise = null;
        });

        return state.flushPromise;
    };

    var countWords = function(text) {
        if (!text) {
            return 0;
        }
        var words = text.match(/\b\w+\b/g);
        return words ? words.length : 0;
    };

    /**
     * FIX-EG-QUE-QSLOT (v1.2.74): Extract the question slot number from the
     * Moodle quiz question container's id attribute.
     *
     * Moodle renders each quiz question inside a div with id="q{slot}" where
     * slot is the 1-based question slot number (e.g. id="q1", id="q2").
     * This is the most reliable qslot source — it works for ALL editor types
     * (plain textarea, Atto, TinyMCE) and doesn't depend on textarea name
     * conventions or CSS class names that may vary across Moodle versions.
     *
     * Returns 0 for non-quiz contexts (assign, forum, etc.) where there is
     * no enclosing .que container.
     *
     * @param {Element} outerNode  Any element in the outer (main) document.
     * @returns {number}  Slot number (>= 1) or 0 if not determinable.
     */
    var extractQslot = function(outerNode) {
        if (!outerNode || typeof outerNode.closest !== 'function') {
            return 0;
        }
        var que = outerNode.closest('.que');
        if (que && que.id) {
            // Moodle quiz standard: id="q{slot}" e.g. "q1", "q2", "q3"
            var m = que.id.match(/^q(\d+)$/);
            if (m) {
                return parseInt(m[1], 10);
            }
            // FIX-EG-QSLOT-ALTIDFMT (v1.2.77): Some Moodle versions / themes render
            // the .que container with id="question-{attemptid}-{slot}" or similar.
            // Extract the LAST numeric group, which is the slot number.
            var mAlt = que.id.match(/[^0-9](\d+)$/);
            if (mAlt) {
                return parseInt(mAlt[1], 10);
            }
        }
        // FIX-EG-QSLOT-QUESTION-ID (v1.2.77): Some Moodle/plugin configs use a
        // container with id^="question-" instead of (or in addition to) .que.
        var qCont = outerNode.closest('[id^="question-"]');
        if (qCont && qCont.id) {
            var mQ = qCont.id.match(/[^0-9](\d+)$/);
            if (mQ) {
                return parseInt(mQ[1], 10);
            }
        }
        // FIX-EG-QSLOT-DATA (v1.2.77): Some Moodle plugins/themes set a data-slot
        // attribute on the question container (e.g. data-slot="2").
        var slotCont = outerNode.closest('[data-slot]');
        if (slotCont) {
            var ds = parseInt(slotCont.dataset.slot, 10);
            if (ds > 0) {
                return ds;
            }
        }
        return 0;
    };

    /**
     * FIX-EG-QUE-QSLOT (v1.2.74): Extract and cache the qslot for a field node
     * in the outer document, storing it in data-essayguard-qslot.
     *
     * Priority:
     *   1. .que#q{N} container id (most reliable, works for all editor types)
     *   2. node.name regex (plain textareas: "q{attemptid}:{slot}_answer")
     *   3. node.dataset.essayguardName regex (Atto/TinyMCE after scan() sets it)
     *
     * Does NOT overwrite an already-cached qslot so repeated scan() calls are cheap.
     *
     * @param {Element} node  Outer document element (textarea, Atto div, TinyMCE iframe).
     */
    var cacheQslot = function(node) {
        if (!node) {
            return;
        }
        if (node.dataset.essayguardQslot) {
            return; // already cached
        }
        // Priority 1: .que container / alternative container ids / data-slot
        var qs = extractQslot(node);
        if (qs > 0) {
            node.dataset.essayguardQslot = String(qs);
            return;
        }
        // Priority 2: field.name (plain textarea: "q{attemptid}:{slot}_answer")
        if (node.name) {
            var m2 = node.name.match(/:(\d+)[_:]/);
            if (m2) {
                node.dataset.essayguardQslot = m2[1];
                return;
            }
        }
        // Priority 3: essayguardName data attribute (set by Atto/TinyMCE scan)
        if (node.dataset.essayguardName) {
            var m3 = node.dataset.essayguardName.match(/:(\d+)[_:]/);
            if (m3) {
                node.dataset.essayguardQslot = m3[1];
                return;
            }
        }
    };



    var bindField = function(field) {
        if (!field || field.dataset.essayguardBound === '1') {
            return;
        }
        field.dataset.essayguardBound = '1';
        // DIAG-EG-BIND (v1.2.145): Confirm which field is being monitored.
        // FIX-EG-PREVLEN-CONTENTEDITABLE (v1.2.87): use field.value != null so
        // contenteditable elements fall through to textContent for initial length.
        state.lastLengths.set(field,
            field.value != null ? field.value.length : (field.textContent ? field.textContent.length : 0));

        // FIX-EG-QSLOT-LOCKTIME (v1.2.89): capture qslot once at bind time so events
        // fired before dataset resolves are not tagged qslot=0. Priority: data-essayguard-qslot,
        // then name/data-essayguard-name regex /:(\d+)[_:]/ .
        var _bindQslot = parseInt(field.dataset.essayguardQslot || '0', 10);
        if (!_bindQslot) {
            var _bindName = field.name || field.dataset.essayguardName || '';
            var _bindM = _bindName.match(/:(\d+)[_:]/);
            _bindQslot = _bindM ? parseInt(_bindM[1], 10) : 0;
        }

        var eq = function(eventname, payload) {
            payload = payload || {};
            if (_bindQslot > 0) {
                payload = Object.assign({qslot: _bindQslot}, payload);
            }
            enqueue(eventname, payload);
        };

        // v1.2.36: Per-field telemetry state — prevents inter-question contamination.
        var _fieldLastKeyTime     = null;    // replaces shared state.lastKeyTime
        var _fieldCursorMoves     = 0;       // replaces shared state.cursorMoves
        var _fieldWindowWordCount = 0;       // replaces shared state.windowWordCount
        var _fieldWindowStartTime = nowMs(); // replaces shared state.windowStartTime

        field.addEventListener('focus', function() {
            eq('focus', {sessionms: nowMs() - state.sessionStart});
            // Reset per-field last-key-time on focus so the first inter-key delay
            // in this field is not contaminated by the previous field's last keystroke.
            _fieldLastKeyTime = null;
            state.burstStart = nowMs();
            state.burstWordCount = 0;
            state.inBurst = true;
        });

        field.addEventListener('keydown', function(e) {
            var now = nowMs();
            var ikd = _fieldLastKeyTime !== null ? now - _fieldLastKeyTime : null;

            if (e.key === 'Backspace') {
                eq('backspace', {key: 'Backspace', ikd: ikd});
            } else if (e.key === 'Delete') {
                eq('delete', {key: 'Delete', ikd: ikd});
            } else if (e.key.length === 1 || e.key === 'Enter' || e.key === 'Space') {
                if (ikd !== null && state.inBurst) {
                    if (ikd > state.pauseThreshold) {
                        if (state.burstStart !== null) {
                            var burstDurationMs = now - state.burstStart;
                            eq('burst_end', {
                                wordcount: state.burstWordCount,
                                durationms: burstDurationMs,
                            });
                        }
                        state.burstStart = now;
                        state.burstWordCount = 0;
                    }
                }
                eq('keydown', {key: e.key, code: e.code, ikd: ikd});
            }

            _fieldLastKeyTime = now;
        });

        field.addEventListener('paste', function(e) {
            var cd = e.clipboardData || window.clipboardData;
            var text = cd ? cd.getData('text') : '';
            var words = countWords(text);

            // FIX-EG-PASTE-ALLOW (v1.2.64): Students are always permitted to paste.
            // Plagiarism detection runs after the quiz is fully submitted  -  students
            // must never be interrupted or blocked during the quiz itself.
            // Paste events are still recorded silently for post-submission analysis.
            eq('paste', {
                insertlen: text.length,
                insertwords: words,
                blocked: 0,
            });
            state.burstWordCount += words;
            // FIX-EG-PASTE-IMMEDIATE-FLUSH (v1.2.126): Trigger an immediate server flush
            // the moment a paste event is captured. Previously only the 5-second periodic
            // flush sent events; if the student pasted and then submitted within < 5 s the
            // paste event sat in the queue and was never sent (paste_events stayed 0 in
            // the DB, scoring fell back to linguistics, copy-paste scored MEDIUM not HIGH).
            flush();
        });

        field.addEventListener('drop', function(e) {
            var dt = e.dataTransfer;
            var text = dt ? dt.getData('text') : '';
            if (text.length > 0) {
                // FIX-EG-PASTE-ALLOW (v1.2.64): Text drops are always permitted.
                // Plagiarism detection runs after submission  -  no interruption during the quiz.
                var words = countWords(text);
                eq('drop_paste', {
                    insertlen: text.length,
                    insertwords: words,
                    blocked: 0,
                });
                // FIX-EG-PASTE-IMMEDIATE-FLUSH (v1.2.126): flush immediately for drops too.
                flush();
            }
        });

        field.addEventListener('input', function(e) {
            var previous = state.lastLengths.get(field) || 0;
            var current = field.value
                ? field.value.length
                : (field.textContent ? field.textContent.length : 0);
            var delta = current - previous;
            state.lastLengths.set(field, current);

            var totalWords = countWords(field.value || field.textContent || '');
            var now = nowMs();
            var windowElapsedMin = (now - _fieldWindowStartTime) / 60000;
            if (windowElapsedMin >= 1) {
                var wpm = Math.round(_fieldWindowWordCount / windowElapsedMin);
                eq('wpm_snapshot', {
                    wpm: wpm,
                    totalwords: totalWords,
                    windowms: now - _fieldWindowStartTime,
                });
                _fieldWindowStartTime = now;
                _fieldWindowWordCount = 0;
            }
            if (delta > 0) {
                var addedWords = Math.round(delta / 5);
                _fieldWindowWordCount += addedWords;
                state.burstWordCount += addedWords;
            }

            // FIX-EG-LARGE-INSERT (v1.2.73): Emit separate large_insert event for any
            // character addition > 20 chars. This catches medium-sized pastes/AI completions
            // that don't meet the maxburstchars (150) threshold — small copy-pastes previously
            // triggered no burst signal and could score LOW even when paste was detected.
            if (delta > 20) {
                eq('large_insert', {delta: delta});
            }

            eq('input', {
                inputType: e.inputType || '',
                addedchars: delta > 0 ? delta : 0,
                removedchars: delta < 0 ? Math.abs(delta) : 0,
                insertlen: delta > 0 ? delta : 0,
                totalchars: current,
                totalwords: totalWords,
                suspiciousburst: delta >= state.maxburstchars ? 1 : 0,
            });
        });

        field.addEventListener('mouseup', function() {
            var sel = window.getSelection ? window.getSelection() : null;
            if (sel && sel.toString().length > 0) {
                _fieldCursorMoves++;
                eq('selection', {
                    selectionlen: sel.toString().length,
                    cursormoves: _fieldCursorMoves,
                });
            }
        });

        field.addEventListener('blur', function() {
            var now = nowMs();
            if (state.inBurst && state.burstStart !== null) {
                eq('burst_end', {
                    wordcount: state.burstWordCount,
                    durationms: now - state.burstStart,
                });
            }
            state.inBurst = false;
            state.burstStart = null;
            state.burstWordCount = 0;

            // BUG-EG-WPM-ZERO (v1.2.53): Emit a final WPM snapshot on blur so short
            // typing sessions (< 60 seconds) still record a WPM reading.
            // The 60-second window condition in the input handler means most students
            // never trigger a wpm_snapshot  -  their average_wpm stays 0 in the report.
            // Fix: if the student typed any words during this focus period, emit a
            // snapshot now regardless of elapsed time.
            var windowElapsedMin = (now - _fieldWindowStartTime) / 60000;
            if (_fieldWindowWordCount > 0 && windowElapsedMin > 0) {
                var blurWpm = Math.round(_fieldWindowWordCount / windowElapsedMin);
                if (blurWpm > 0) {
                    var totalWords = countWords(field.value || field.textContent || '');
                    eq('wpm_snapshot', {
                        wpm: blurWpm,
                        totalwords: totalWords,
                        windowms: now - _fieldWindowStartTime,
                        source: 'blur',
                    });
                }
                _fieldWindowStartTime = now;
                _fieldWindowWordCount = 0;
            }

            eq('blur', {sessionms: now - state.sessionStart});
            flush();
        });
    };

    /**
     * FIX-EG-QTYPE-FILTER (v1.2.63): Return true only when this DOM node should be
     * monitored by Essay Guard.
     *
     * Inside a Moodle quiz question (.que container): only question types where the
     * student must TYPE a free-text response are eligible  -  specifically qtype_essay
     * (and all qtype_essay* community variants such as qtype_essayautograde) and
     * qtype_shortanswer. All click/select-based types (multichoice, truefalse,
     * match, gapselect, ddwtos, ddimageortext, multianswer/cloze, etc.) are excluded.
     * This prevents paste-warning banners and telemetry from activating on MCQ,
     * True/False, Matching, Gap-Select and similar questions.
     *
     * Outside a quiz question (assign, forum, etc.): always true  -  the entire
     * submission area is typed by the student.
     *
     * FIX-EG-QTYPE-ESSAY-VARIANT (v1.2.125): Extend the class check to match any
     * CSS class that starts with 'qtype_essay' so that community essay plugins
     * (e.g. qtype_essayautograde) are treated identically to the core qtype_essay.
     * Previously these variants caused isTypedAnswerField() to return false, so
     * bindTinyMCENode() and scan() both silently returned without binding any
     * keystroke/paste listeners  -  resulting in zero events for every attempt and
     * the linguistic-fallback path being used for all scoring.
     *
     * @param {Element} node  Any DOM element (textarea, contenteditable, iframe, ...).
     * @returns {boolean}
     */
    var isTypedAnswerField = function(node) {
        if (!node || typeof node.closest !== 'function') {
            return true; // iframe body or element without .closest  -  allow
        }
        var que = node.closest('.que');
        if (!que) {
            // Not inside a Moodle quiz question  -  assign, forum, etc.
            return true;
        }
        // FIX-EG-QTYPE-ESSAY-VARIANT (v1.2.125): match qtype_essay plus any variant
        // whose CSS class starts with qtype_essay (e.g. qtype_essayautograde).
        // FIX-EG-QTYPE-UNPREFIXED (v1.2.146): also match unprefixed 'essay' and
        // 'shortanswer' classes. Moodle 4.3+ Boost/Moove themes emit class="que essay ..."
        // instead of class="que qtype_essay ..." — the qtype_ prefix is absent.
        var hasEssayClass = Array.from(que.classList).some(function(c) {
            return c === 'qtype_essay' || c.indexOf('qtype_essay') === 0
                || c === 'essay'       || c.indexOf('essay') === 0;
        });
        var result = hasEssayClass || que.classList.contains('qtype_shortanswer')
            || que.classList.contains('shortanswer');
        if (!result) {
            // DIAG-EG-QTYPE (v1.2.145): Log the rejection so devs can see why a field was skipped.
        }
        return result;
    };

    /**
     * FIX-EG-TINYMCE-BIND (v1.2.88): Bind a single TinyMCE <iframe> element.
     *
     * Extracted from scan() so both the initial scan pass and the MutationObserver
     * (observeTinyMCEIframes) can call it without duplicating 120 lines of code.
     *
     * Sets essayguardName and essayguardQslot on the iframe body, attaches
     * focus / blur / paste capture listeners, and marks the outer node with
     * _egTmBound so duplicate calls are cheap no-ops.
     *
     * FIX-EG-NAME-OUTERNODE-SYNC (v1.2.88): After resolving essayguardName on the
     * iframe body, the same value is mirrored to node.dataset.essayguardName on
     * the outer <iframe> element. This allows the paste-time qslot retry to read
     * the name directly from outerNode without crossing the iframe boundary (where
     * the body may be inaccessible inside the event handler closure on some browsers).
     *
     * FIX-EG-PASTE-QSLOT-NAME-RETRY (v1.2.88): The paste handler now has a third
     * qslot detection path: if extractQslot(outerNode) returns 0 (no .que / question-N
     * container) but outerNode.dataset.essayguardName was mirrored above, the name
     * regex /:(\d+)[_:]/ extracts the slot directly from the textarea name. This
     * covers Moodle themes where the .que wrapper is absent or has a non-standard id
     * but the hidden textarea still uses the standard q{attempt}:{slot}_answer format.
     *
     * @param {HTMLIFrameElement} node Outer TinyMCE <iframe> in the main document.
     */
    var bindTinyMCENode = function(node) {
        if (!node) {
            return; // Invalid node.
        }
        // DIAG-EG-TMCE-ENTRY (v1.2.145): Log every attempt to bind a TinyMCE iframe.
        // FIX-EG-TINYMCE-REBIND (v1.2.121): Check the CURRENT contentDocument's _egDocBound
        // flag instead of relying solely on node._egTmBound. TinyMCE can reinitialize its
        // editor by keeping the same <iframe> DOM node but replacing its contentDocument
        // (e.g. after a theme's JS re-renders the editor, or after TinyMCE's own re-init
        // on slow Moodle instances). When this happens, node._egTmBound=true blocks
        // re-binding, but the new document has no paste listener — every subsequent paste
        // in that editor is silently lost (paste_events stays 0 even when the student
        // copies and pastes their entire answer).
        //
        // Fix: when node._egTmBound is set, ALSO verify that the current contentDocument
        // still has _egDocBound. If TinyMCE swapped the document out, the new document
        // will not have _egDocBound → we clear node._egTmBound and fall through to rebind.
        if (node._egTmBound) {
            try {
                var currDoc = node.contentDocument || (node.contentWindow && node.contentWindow.document);
                if (currDoc && currDoc._egDocBound) {
                    return; // Same document — already bound, nothing to do.
                }
                // Current document is different (TinyMCE reinitialized). Clear the stale
                // flag and fall through to rebind the new document.
                node._egTmBound = false;
            } catch (_) {
                return; // Cross-origin — cannot access contentDocument, skip.
            }
        }
        try {
            var doc  = node.contentDocument;
            var body = doc && doc.body;
            // DIAG-EG-TMCE-BODY (v1.2.145): Show whether the iframe body is accessible.
            if (body) {
                // v1.2.32: Resolve the quiz question slot from the TinyMCE iframe id.
                if (!body.dataset.essayguardName) {
                    var iframeId = node.id || '';
                    var textareaId = iframeId.replace(/_ifr$/, '');
                    if (textareaId) {
                        var textarea = document.getElementById(textareaId);
                        if (textarea && textarea.name) {
                            body.dataset.essayguardName = textarea.name;
                        }
                    }
                }
                // FIX-EG-TINYMCE-QSLOT-SCOPE (v1.2.62): If the id-based lookup above
                // failed (e.g. TinyMCE uses a different id naming scheme), try to find
                // the associated textarea within the nearest Moodle quiz question
                // container (.que). This avoids a document-wide fallback that would
                // return Q1's textarea for every editor on a multi-question page.
                if (!body.dataset.essayguardName) {
                    try {
                        var tmQueContainer = node.closest('.que')
                            || node.closest('[id^="question-"]')
                            || node.closest('.formulation');
                        if (tmQueContainer) {
                            var tmTextarea = tmQueContainer.querySelector('textarea[name*=":"]');
                            if (tmTextarea && tmTextarea.name) {
                                body.dataset.essayguardName = tmTextarea.name;
                            }
                        }
                    } catch (_) {}
                }
                // FIX-EG-NAME-OUTERNODE-SYNC (v1.2.88): Mirror essayguardName to the
                // outer <iframe> node so the paste-time retry can access it from
                // outerNode without needing to reach into the iframe body.
                if (body.dataset.essayguardName && !node.dataset.essayguardName) {
                    node.dataset.essayguardName = body.dataset.essayguardName;
                }
                // FIX-EG-QUE-QSLOT (v1.2.74): Cache qslot on the iframe body.
                // node (the <iframe>) is in the outer document where .closest('.que')
                // works. The body element is inside the iframe and cannot use closest()
                // across the frame boundary, so we derive the qslot here using the
                // outer iframe node and store it on the body for use in event handlers.
                if (!body.dataset.essayguardQslot) {
                    var tmQslot = extractQslot(node); // node = outer <iframe>
                    if (tmQslot > 0) {
                        body.dataset.essayguardQslot = String(tmQslot);
                    } else if (body.dataset.essayguardName) {
                        var tmNameM = body.dataset.essayguardName.match(/:(\d+)[_:]/);
                        if (tmNameM) {
                            body.dataset.essayguardQslot = tmNameM[1];
                        }
                    }
                }
                // FIX-EG-QTYPE-FILTER: node is the <iframe> in the outer DOM  -
                // check its .que ancestor to skip non-typing question types.
                if (!isTypedAnswerField(node)) {
                    return;
                }
                // DIAG-EG-TMCE-BOUND (v1.2.145): Confirm successful TinyMCE iframe bind.
                bindField(body);
                // Mark as bound AFTER bindField so repeated scan() / MutationObserver
                // calls don't re-bind the body or re-add doc-level listeners.
                node._egTmBound = true;
                // FIX-EG-TINYMCE-FINALIZE (v1.2.90): Register this iframe in
                // state.boundFrames so the submit handler can call finalizeAttempt()
                // with the correct per-question qslot.  The outer-document
                // querySelectorAll('[data-essayguard-bound="1"]') cannot find elements
                // inside iframe contentDocuments, so TinyMCE bodies were invisible to
                // the finalize loop — no qslot > 0 records were ever created and every
                // question fell back to the same aggregate score.
                state.boundFrames.push({
                    outerNode: node,
                    body:      body,
                    qslot:     parseInt(body.dataset.essayguardQslot || '0', 10),
                });
                if (!doc._egDocBound) {
                    doc._egDocBound = true;
                    doc.addEventListener('focus', function() {
                        body.dispatchEvent(new Event('focus'));
                    }, true);
                    doc.addEventListener('blur', function() {
                        body.dispatchEvent(new Event('blur'));
                    }, true);
                    // FIX-EG-PASTE-TINYMCE v1.2.45: TinyMCE 6 intercepts paste at the
                    // document level with stopPropagation, so body.addEventListener('paste')
                    // in bindField() never fires. Add a doc-level capture handler that fires
                    // BEFORE TinyMCE's paste plugin, logs the event, and shows the banner
                    // anchored to the outer iframe element (not inside the invisible iframe).
                    var outerNode = node; // closure over the .tox iframe DOM element
                    var pasteBody = body;  // closure over the iframe body
                    doc.addEventListener('paste', function(e) {
                        var cd = e.clipboardData || window.clipboardData;
                        var text = cd ? cd.getData('text/plain') : '';
                        var words = countWords(text);
                        // FIX-EG-PASTE-ALLOW (v1.2.64): Students are always permitted
                        // to paste. Plagiarism detection runs after submission.
                        // Paste events are recorded silently for post-submission analysis.
                        //
                        // FIX-EG-TINYMCE-PASTE-SCOPE (v1.2.69): enqueue() directly and
                        // derive qslot from the iframe body's cached attributes.
                        //
                        // FIX-EG-QUE-QSLOT (v1.2.74): prefer data-essayguard-qslot
                        // (from .que container id) over the name-regex fallback.
                        var pasteSlot = parseInt(
                            (pasteBody && pasteBody.dataset.essayguardQslot) || '0', 10);
                        if (!pasteSlot) {
                            var pasteName = (pasteBody && pasteBody.dataset.essayguardName) || '';
                            var slotM2 = pasteName.match(/:(\d+)[_:]/);
                            pasteSlot = slotM2 ? parseInt(slotM2[1], 10) : 0;
                        }
                        // FIX-EG-PASTE-QSLOT-RETRY (v1.2.86): if qslot is still 0,
                        // re-run extractQslot on the outer iframe node. scan() caches
                        // qslot once at DOM-ready, but on Moodle 4.x/TinyMCE 6 pages
                        // the .que container or question-{id} element may be rendered
                        // after the initial scan (AMD deferred, lazy grids). Retrying
                        // here captures qslot for the paste even if the cache missed it.
                        if (!pasteSlot) {
                            try {
                                var retrySlot = extractQslot(outerNode);
                                if (retrySlot > 0) {
                                    pasteSlot = retrySlot;
                                    if (pasteBody) {
                                        pasteBody.dataset.essayguardQslot = String(retrySlot);
                                    }
                                }
                            } catch (_) {}
                        }
                        // FIX-EG-PASTE-QSLOT-NAME-RETRY (v1.2.88): if .que container
                        // detection failed but essayguardName was mirrored to the outer
                        // node by bindTinyMCENode, extract the slot from the name regex.
                        // This covers themes where no .que / question-N container exists
                        // but the hidden textarea still uses the standard
                        // q{attempt}:{slot}_answer naming convention.
                        if (!pasteSlot) {
                            try {
                                var outerName = (outerNode && outerNode.dataset.essayguardName) || '';
                                if (outerName) {
                                    var outerNameM = outerName.match(/:(\d+)[_:]/);
                                    if (outerNameM) {
                                        pasteSlot = parseInt(outerNameM[1], 10);
                                        if (pasteBody) {
                                            pasteBody.dataset.essayguardQslot = outerNameM[1];
                                        }
                                    }
                                }
                            } catch (_) {}
                        }
                        var pastePayload = {
                            insertlen: text.length,
                            insertwords: words,
                            blocked: 0,
                        };
                        if (pasteSlot > 0) {
                            pastePayload.qslot = pasteSlot;
                        }
                        enqueue('paste', pastePayload);
                        // FIX-EG-TINYMCE-PASTE-LARGE-INSERT (v1.2.99): TinyMCE does not
                        // reliably fire DOM input events on the body element after paste
                        // (behaviour varies by TinyMCE version and browser), so the
                        // bindField input handler may never emit a large_insert event for
                        // TinyMCE pastes. Emit it here from the capture-phase paste handler
                        // where we already have the clipboard text length. This ensures
                        // Signal 2 (+8) and Signals 5/7 (paste bonuses) can fire for
                        // per-question scores even when the DOM input event is absent.
                        if (text.length > 20) {
                            var liPayload = {delta: text.length};
                            if (pasteSlot > 0) {
                                liPayload.qslot = pasteSlot;
                            }
                            enqueue('large_insert', liPayload);
                        }
                        // FIX-EG-PASTE-IMMEDIATE-FLUSH (v1.2.126): flush immediately so
                        // TinyMCE paste events reach the server even when the student
                        // submits within seconds of pasting (before the 5-second periodic
                        // flush fires). Without this, TinyMCE copy-paste attempts where the
                        // student pastes and immediately submits produce paste_events=0 in
                        // the DB and are wrongly scored MEDIUM instead of HIGH.
                        flush();
                    }, true); // capture  -  fires before TinyMCE's paste intercept
                }
            }
        } catch (e) {
            // Cross-origin iframe  -  silently ignore.
        }
    };

    var scan = function() {
        // DIAG-EG-SCAN (v1.2.145): Log every scan() call so we can confirm the tracker is alive.
        var nTextareas = document.querySelectorAll('textarea').length;
        var nAtto     = document.querySelectorAll('[contenteditable="true"], .editor_atto_content').length;
        var nToxIframes = document.querySelectorAll('.tox-edit-area iframe, .tox-tinymce iframe').length;

        var selectors = [
            'textarea',
            '[contenteditable="true"]',
            '.editor_atto_content',
        ];

        selectors.forEach(function(selector) {
            document.querySelectorAll(selector).forEach(function(node) {
                // FIX-EG-AITUTOR-EXCLUDE (v1.2.77): Skip textareas whose id matches
                // known non-essay UI elements.  The AI Course Format injects an AI Tutor
                // chat textarea (id="aicourse-ai-input") into every quiz page.  It sits
                // outside any .que container so isTypedAnswerField() returns true for it
                // — but it is an AI assistant input, NOT a student essay.  Without this
                // guard its keystrokes corrupt the qslot=0 aggregate and paste events on
                // AI suggestion clicks fire false paste-warning banners.
                var nodeId = node.id || '';
                if (nodeId && /^aicourse-/i.test(nodeId)) {
                    return;
                }
                // v1.2.32: For Atto contenteditable divs, detect the quiz question slot
                // from the hidden textarea inside the same .editor_atto_wrap container.
                //
                // FIX-EG-ATTO-QSLOT-SCOPE (v1.2.62): The previous implementation fell back
                // to document.querySelector('textarea[name*=":"]') when .editor_atto_wrap
                // was not an ancestor. On multi-essay-question pages this returned the FIRST
                // quiz textarea in the entire document (always Q1) for every Atto editor  - 
                // so every event from Q2, Q3, etc. was tagged with Q1's qslot, making all
                // per-question behavioral metrics identical. Fix: scope the textarea search
                // to .editor_atto_wrap first, then to the enclosing Moodle quiz question
                // container (.que / [id^="question-"]), then to the id-based Atto lookup.
                // Never fall back to a document-wide search.
                if (node.classList && node.classList.contains('editor_atto_content')
                        && !node.dataset.essayguardName) {
                    var attoWrap = node.closest('.editor_atto_wrap');
                    var closestTextarea = attoWrap
                        ? attoWrap.querySelector('textarea[name*=":"]')
                        : null;
                    if (!closestTextarea) {
                        var queContainer = node.closest('.que')
                            || node.closest('[id^="question-"]')
                            || node.closest('.formulation');
                        if (queContainer) {
                            closestTextarea = queContainer.querySelector('textarea[name*=":"]');
                        }
                    }
                    if (closestTextarea && closestTextarea.name) {
                        node.dataset.essayguardName = closestTextarea.name;
                    } else {
                        var parentId = (node.closest('[id*="editoreditable"]') || {}).id || '';
                        if (parentId) {
                            var textareaId = parentId.replace('_editoreditable', '');
                            var ta = document.getElementById(textareaId);
                            if (ta && ta.name) {
                                node.dataset.essayguardName = ta.name;
                            }
                        }
                    }
                }
                // FIX-EG-QUE-QSLOT (v1.2.74): Cache the qslot from the .que container id
                // after essayguardName is set (so Priority 3 in cacheQslot also works).
                // This handles all field types — plain textareas, Atto contenteditables,
                // and any other element bound by Essay Guard.
                cacheQslot(node);
                // FIX-EG-QTYPE-FILTER: Skip any field that is inside a quiz question
                // container (.que) of a non-typing question type.
                if (!isTypedAnswerField(node)) {
                    return;
                }
                bindField(node);
            });
        });

        // FIX-EG-TINYMCE-BIND (v1.2.88): Delegate to bindTinyMCENode() which is
        // defined above scan(). That function is idempotent (node._egTmBound guard)
        // so repeated scan() calls from setInterval are cheap no-ops for already-
        // bound iframes, while newly-added iframes are bound on the next tick.
        document.querySelectorAll('.tox-edit-area iframe, .tox-tinymce iframe').forEach(function(node) {
            bindTinyMCENode(node);
        });
    };

    /**
     * FIX-EG-TINYMCE-MUTOBS (v1.2.88): Watch for TinyMCE iframes added to the DOM
     * after the initial scan() run. Handles two key scenarios:
     *
     *   A. Lazy-initialised TinyMCE editors: Moodle 4.4+ initialises TinyMCE via
     *      AMD deferred load. The iframe element is injected into the DOM after
     *      the first scan() tick. Without this observer, a paste in that ~0-2s
     *      window before setInterval(scan, 2000) catches it would be qslot=0.
     *
     *   B. Below-the-fold quiz questions: Moodle 4.4+ uses an IntersectionObserver
     *      to lazy-init TinyMCE editors only when the question scrolls into view.
     *      setInterval(scan, 2000) catches these within 2 s of scroll — but a
     *      student who pastes immediately after scrolling could still miss the window.
     *
     * The observer fires bindTinyMCENode() with a 300 ms delay (to let the iframe
     * body finish initialising) and only on nodes not already marked _egTmBound.
     */
    var observeTinyMCEIframes = function() {
        if (typeof MutationObserver === 'undefined') {
            return;
        }
        var mo = new MutationObserver(function(mutations) {
            var needsBind = false;
            for (var i = 0; i < mutations.length; i++) {
                var added = mutations[i].addedNodes;
                for (var j = 0; j < added.length; j++) {
                    var n = added[j];
                    if (!n || n.nodeType !== 1) {
                        continue; // Element nodes only.
                    }
                    // Is the added node itself a TinyMCE iframe?
                    if (n.tagName === 'IFRAME' && typeof n.closest === 'function'
                            && (n.closest('.tox-edit-area') || n.closest('.tox-tinymce'))) {
                        needsBind = true;
                        break;
                    }
                    // Does the added subtree contain TinyMCE iframes?
                    if (n.querySelectorAll
                            && n.querySelectorAll('.tox-edit-area iframe, .tox-tinymce iframe').length > 0) {
                        needsBind = true;
                        break;
                    }
                }
                if (needsBind) {
                    break;
                }
            }
            if (needsBind) {
                // FIX-EG-TMCE-LOADBIND (v1.2.128): Replace the 6-retry setTimeout chain
                // with deterministic per-iframe 'load' event binding.
                //
                // Root cause of the old approach: all retries scanned ALL unbound iframes
                // at a fixed delay after any mutation — on slow Moodle hosts (shared
                // hosting, Boost/Moove themes) every retry could fire before the iframe
                // body was accessible, leaving editors permanently unbound with no further
                // trigger.  The 5000 ms and 8000 ms safety-net retries (v1.2.121) helped
                // on very slow hosts but introduced unnecessary timer load on fast ones.
                //
                // New strategy — applied independently per newly-detected iframe:
                //   A. contentDocument.readyState === 'complete' AND body present
                //      → bind immediately (common case: Moodle prepopulated editor).
                //   B. Otherwise → attach a one-shot 'load' listener on the <iframe>
                //      element; fires deterministically when the document is fully
                //      initialised, regardless of host speed.
                //   C. Single 300 ms fallback setTimeout covers the narrow race where
                //      'load' already fired before we attached the listener (the
                //      MutationObserver callback is async, so this race is real).
                //
                // The tinymce.on('AddEditor') hook (hookTinyMCEApi below) is the PRIMARY
                // binding path and handles the common case.  This MutationObserver fires
                // bindTinyMCENode as a per-iframe safety net for themes that inject
                // TinyMCE without using the standard TinyMCE event API.
                document.querySelectorAll('.tox-edit-area iframe, .tox-tinymce iframe').forEach(function(ifrNode) {
                    if (ifrNode._egTmBound) {
                        return; // Already bound — skip.
                    }
                    var tryBindNode = function() {
                        if (!ifrNode._egTmBound) {
                            bindTinyMCENode(ifrNode);
                        }
                    };
                    try {
                        var d = ifrNode.contentDocument;
                        if (d && d.readyState === 'complete' && d.body) {
                            tryBindNode(); // Body already accessible — bind now (path A).
                            return;
                        }
                    } catch (_) {
                        return; // Cross-origin iframe — skip safely.
                    }
                    // Body not yet ready. Bind deterministically on 'load' (path B).
                    ifrNode.addEventListener('load', function onIfrLoad() {
                        ifrNode.removeEventListener('load', onIfrLoad);
                        tryBindNode();
                    });
                    // Single fallback: in case 'load' fired before we attached (path C).
                    setTimeout(tryBindNode, 300);
                });
            }
        });
        if (document.body) {
            mo.observe(document.body, {childList: true, subtree: true});
        }
    };

    /**
     * Build and return a risk badge DOM element from score data.
     * @param {{risklevel: string, score100: number}} data
     * @returns {HTMLElement}
     */
    var buildBadgeEl = function(data, slot) {
        // v1.2.66: Labels match Essay Guard docs: Low / Medium / High.
        // v1.2.65: score100 is authenticity % (100 - risk).
        var palette = {
            low:    {bg: '#f0fdf4', border: '#86efac', text: '#166534'},
            medium: {bg: '#fff7ed', border: '#fdba74', text: '#7c2d12'},
            high:   {bg: '#fef2f2', border: '#fca5a5', text: '#991b1b'},
        };
        var labelMap = {
            low:    'Low',
            medium: 'Medium',
            high:   'High',
        };
        var level  = (data.risklevel in palette) ? data.risklevel : 'low';
        var c      = palette[level];
        var label  = labelMap[level] || 'Low';
        var pct    = typeof data.score100 === 'number' ? data.score100 : 0;

        var badge = document.createElement('div');
        // v1.2.38 FIX-EG-BADGE-MULTI: Use per-slot badge ID so multiple essay
        // questions on the same page each get their own independent badge.
        // Previously the hardcoded ID meant Q2's badge would remove Q1's badge
        // (getElementById returns first match, remove() deleted it), and the
        // badge always anchored to the first bound field regardless of which
        // question triggered the finalisation.
        badge.id = 'essayguard-risk-badge-' + (slot !== undefined ? slot : 'main');
        badge.setAttribute('role', 'status');
        badge.setAttribute('aria-live', 'polite');
        badge.style.cssText = [
            'display:inline-flex',
            'align-items:center',
            'gap:7px',
            'padding:4px 12px',
            'border-radius:12px',
            'border:1px solid ' + c.border,
            'background:' + c.bg,
            'color:' + c.text,
            'font-size:0.82rem',
            'font-weight:600',
            'margin-top:8px',
            'line-height:1.4',
        ].join(';');

        // FIX-EG-TOAST-LABEL (v1.2.61): slot===0 is the aggregate (whole-attempt) result
        // stored in sessionStorage and shown as the fixed toast on the next page. Label it
        // "Overall:" so it is clearly distinct from per-question inline "Risk:" badges.
        var riskLabel = (slot === 0) ? 'Overall' : 'Risk';

        badge.innerHTML =
            '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"' +
            ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"' +
            ' stroke-linejoin="round" style="flex-shrink:0;">' +
            '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>' +
            '<span>Essay Guard</span>' +
            '<span style="opacity:0.5;margin:0 2px;">|</span>' +
            '<span>' + riskLabel + ': <strong>' + label + '</strong> (' + pct + '%)</span>';

        return badge;
    };

    /**
     * Inject a risk badge into the DOM next to the submitted field.
     * BUG-BADGE-NAV (v1.2.9 fix): persists badge data in sessionStorage so it
     * can be restored as a fixed toast on the NEXT page load.
     * @param {{risklevel: string, score100: number}} data
     */
    var injectRiskBadge = function(data, fieldOverride) {
        // v1.2.219: This is now the student-facing degradation path, and it is load-bearing.
        // finalize_attempt and log_event return risklevel ONLY to a caller holding
        // plagiarism/essayguard:viewreport; a student gets risklevel: ''. An empty
        // risklevel therefore means "you are not entitled to see this", and the correct
        // behaviour is to render nothing at all — no badge, no toast, no sessionStorage
        // entry — rather than to fall back to a default LOW badge, which would both be a
        // lie and re-open the live-score oracle this change exists to close.
        if (!data || !data.risklevel) {
            return;
        }

        // v1.2.38 FIX-EG-BADGE-MULTI: Derive slot from server result (qslot) so
        // each question's badge has a unique DOM id. If no qslot in response, fall
        // back to 'main' (single-question context).
        var slot = data.qslot !== undefined ? data.qslot : 'main';
        var badgeId = 'essayguard-risk-badge-' + slot;

        var existing = document.getElementById(badgeId);
        if (existing) {
            existing.remove();
        }

        // FIX-EG-BADGE-RACE (v1.2.61): Only persist aggregate (qslot=0) result to
        // sessionStorage. Per-question finalizeAttempt calls run in parallel via
        // Promise.allSettled  -  whichever resolves last previously overwrote all others,
        // making the toast badge show a non-deterministic single-question result instead
        // of the overall attempt risk. Per-question badges are still injected inline
        // next to each field on the current page; only the sessionStorage toast is guarded.
        if (!data.qslot || data.qslot === 0) {
            try {
                sessionStorage.setItem(BADGE_STORAGE_KEY, JSON.stringify(data));
            } catch (_) {
                // sessionStorage unavailable (private browsing, etc.) - degrade silently.
            }
            // FIX-EG-BADGE-DEDUP (v1.2.64): Do NOT inject the aggregate badge inline.
            // Injecting qslot=0 results inline anchors to the first bound field (same
            // location as the per-question badge), causing a duplicate badge for Q1 with
            // a different percentage (aggregate vs per-question). Aggregate results are
            // shown as the post-submit sessionStorage toast only; per-question results
            // from qslot > 0 are injected inline next to their respective fields.
            return;
        }

        // FIX-EG-TOAST-PERQ (v1.2.69): merge per-question result into the toast map so
        // the post-submit toast can list every question's risk. Per-question
        // finalizeAttempt calls run in parallel via Promise.allSettled  -  using a
        // {qslot: data} MAP (not an overwrite) means race ordering no longer matters.
        try {
            var rawMap = sessionStorage.getItem(BADGE_PERQ_STORAGE_KEY);
            var perqMap = rawMap ? JSON.parse(rawMap) : {};
            if (typeof perqMap !== 'object' || perqMap === null) {
                perqMap = {};
            }
            perqMap[String(data.qslot)] = {
                qslot: data.qslot,
                risklevel: data.risklevel,
                score100: data.score100,
            };
            sessionStorage.setItem(BADGE_PERQ_STORAGE_KEY, JSON.stringify(perqMap));
        } catch (_) {
            // sessionStorage unavailable - degrade silently; inline badge still injects below.
        }

        var badge = buildBadgeEl(data, slot);

        // Anchor badge next to the field that matches this slot (if known), the
        // explicitly supplied field, or fall back to the first bound field.
        var anchor = fieldOverride || null;
        if (!anchor && slot !== 'main') {
            // Try to find the textarea whose name contains ":{slot}_" (quiz essay convention).
            anchor = document.querySelector('[name*=":' + slot + '_answer"]') ||
                     document.querySelector('[data-essayguard-name*=":' + slot + '_answer"]');
        }
        if (!anchor) {
            anchor = document.querySelector('[data-essayguard-bound="1"]');
        }
        if (!anchor) {
            anchor = document.querySelector(
                'form#responseform, form[action*="processattempt"], form[action*="/mod/assign"]'
            );
        }

        if (anchor) {
            var parent = anchor.parentNode;
            if (parent) {
                parent.insertBefore(badge, anchor.nextSibling || null);
            }
        }
    };

    /**
     * On page load, check sessionStorage for a badge written during the previous
     * form submission and display it as a fixed toast at the top of the page.
     */
    var restorePendingBadge = function() {
        try {
            var raw = sessionStorage.getItem(BADGE_STORAGE_KEY);
            if (!raw) {
                return;
            }
            sessionStorage.removeItem(BADGE_STORAGE_KEY);
            var data = JSON.parse(raw);
            if (!data || !data.risklevel) {
                return;
            }

            // FIX-EG-TOAST-LABEL (v1.2.61): pass slot=0 explicitly so buildBadgeEl
            // renders the "Overall:" label for this whole-attempt toast badge.
            var badge = buildBadgeEl(data, 0);

            // FIX-EG-TOAST-PERQ (v1.2.69): If we also captured per-question results,
            // append a breakdown line beneath the aggregate so the toast actually
            // summarises EACH question rather than just one generic figure.
            var perqMap = null;
            try {
                var rawPerq = sessionStorage.getItem(BADGE_PERQ_STORAGE_KEY);
                if (rawPerq) {
                    perqMap = JSON.parse(rawPerq);
                    sessionStorage.removeItem(BADGE_PERQ_STORAGE_KEY);
                }
            } catch (_) {
                perqMap = null;
            }

            // Wrap aggregate badge + per-question breakdown into a single fixed-position
            // toast container so they stack and dismiss together.
            var toast = document.createElement('div');
            toast.style.cssText = [
                'position:fixed',
                'top:16px',
                'right:16px',
                'z-index:99999',
                'background:#ffffff',
                'border:1px solid #e5e7eb',
                'border-radius:10px',
                'box-shadow:0 4px 14px rgba(0,0,0,0.12)',
                'padding:10px 12px',
                'max-width:340px',
                'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif',
            ].join(';');

            // Aggregate badge inside the toast (override fixed positioning from buildBadgeEl).
            badge.style.cssText = badge.style.cssText
                .replace(/position:fixed;?/g, '')
                .replace(/top:[^;]+;?/g, '')
                .replace(/right:[^;]+;?/g, '')
                .replace(/z-index:[^;]+;?/g, '')
                .replace(/box-shadow:[^;]+;?/g, '');
            toast.appendChild(badge);

            if (perqMap && typeof perqMap === 'object') {
                var slots = Object.keys(perqMap)
                    .map(function(s) { return parseInt(s, 10); })
                    .filter(function(s) { return s > 0; })
                    .sort(function(a, b) { return a - b; });
                if (slots.length > 0) {
                    var labelMap = {
                        low: 'Low', medium: 'Medium', high: 'High',
                    };
                    var colourMap = {
                        low: '#166534', medium: '#7c2d12', high: '#991b1b',
                    };
                    var listEl = document.createElement('div');
                    listEl.style.cssText = [
                        'margin-top:8px',
                        'padding-top:8px',
                        'border-top:1px solid #e5e7eb',
                        'font-size:0.78rem',
                        'color:#374151',
                        'line-height:1.55',
                    ].join(';');
                    var heading = document.createElement('div');
                    heading.style.cssText = 'font-weight:600;margin-bottom:4px;color:#6b7280;';
                    heading.textContent = 'Per question';
                    listEl.appendChild(heading);
                    slots.forEach(function(slot) {
                        var d = perqMap[String(slot)];
                        var lvl = (d && d.risklevel) || 'low';
                        var lab = labelMap[lvl] || 'Low';
                        var col = colourMap[lvl] || '#166534';
                        var pct = (d && typeof d.score100 === 'number') ? d.score100 : 0;
                        var row = document.createElement('div');
                        row.style.cssText = 'display:flex;justify-content:space-between;gap:12px;';
                        // FIX-EG-TOAST-SEPARATOR (v1.2.91): Use middle-dot separator
                        // (U+00B7) to match reporter.js badge style. Asterisk looked
                        // like a CSS operator / garbled markup in the toast UI.
                        row.innerHTML =
                            '<span>Q' + slot + '</span>' +
                            '<span style="color:' + col + ';font-weight:600;">' +
                                lab + ' \u00b7 ' + pct + '%</span>';
                        listEl.appendChild(row);
                    });
                    toast.appendChild(listEl);
                }
            }

            document.body.appendChild(toast);
            setTimeout(function() {
                if (toast.parentNode) {
                    toast.style.transition = 'opacity 0.5s';
                    toast.style.opacity = '0';
                    setTimeout(function() {
                        if (toast.parentNode) {
                            toast.parentNode.removeChild(toast);
                        }
                    }, 600);
                }
            }, 15000);
        } catch (_) {
            // Ignore JSON parse errors or storage errors.
        }
    };

    /**
     * Call plagiarism_essayguard_finalize_attempt via Moodle AJAX.
     * BUG-BADGE-DISPLAY (v1.2.7 fix): response is now captured and passed to
     * injectRiskBadge() so the badge is displayed immediately on the current page.
     * FIX-EG-QSLOT-FINALIZE (v1.2.41): qslot is now passed per-field so each
     * quiz question is scored independently rather than in aggregate mode.
     * @param {string} text   The final essay text at submission time.
     * @param {number} qslot  Question slot (0 = aggregate / non-quiz).
     * @returns {Promise}
     */
    var finalizeAttempt = function(text, qslot) {
        text = text || '';
        qslot = parseInt(qslot, 10) || 0;
        if (!state.cmid || !state.attemptkey) {
            return Promise.resolve(null);
        }
        return Ajax.call([{
            methodname: 'plagiarism_essayguard_finalize_attempt',
            args: {
                cmid:       state.cmid,
                attemptkey: state.attemptkey,
                finaltext:  text.slice(0, 50000),
                qslot:      qslot,
            },
        }])[0].then(function(result) {
            if (result && result.ok) {
                injectRiskBadge(result);
            }
            return result || null;
        }).catch(function(err) {
            return null;
        });
    };

    /**
     * Collect the current text from all monitored fields.
     * @returns {string}
     */
    /**
     * v1.2.221: is this bound field the hidden backing textarea of a rich editor we have
     * ALSO bound as an iframe?
     *
     * scan() binds every <textarea>, including the hidden one Atto and TinyMCE keep in sync
     * with the visible editor. collectFieldText() and the submit handler then unioned those
     * textareas with state.boundFrames, so every rich-editor answer was submitted TWICE.
     *
     * That is not cosmetic. linguistic.php computes vocab_diversity as unique/total words,
     * so doubling the text roughly halves it - a real 300-word essay drops from ~0.5 to
     * ~0.25, straight into the band the analyser scores +15 for. Honest students were being
     * pushed toward MEDIUM by a duplication bug. It also fired two finalizeAttempt calls for
     * one answer and, when qslot detection failed, made the positional fallback invent a
     * second question out of the same editor's content.
     *
     * @param {Element} field A bound field in the outer document.
     * @returns {boolean} true when its content is already collected from an editor iframe.
     */
    var isEditorBackingField = function(field) {
        if (!field || field.tagName !== 'TEXTAREA') {
            return false;
        }
        // A textarea the browser is not showing is a backing store, not what the student
        // typed into. Covers Atto (contenteditable + hidden textarea) as well as TinyMCE.
        if (field.offsetParent === null && field.type !== 'hidden') {
            return true;
        }
        // Explicit match against the editors we bound: TinyMCE names its iframe
        // "<textareaid>_ifr".
        for (var i = 0; i < state.boundFrames.length; i++) {
            var outer = state.boundFrames[i].outerNode;
            if (outer && outer.id && field.id && outer.id === field.id + '_ifr') {
                return true;
            }
        }
        return false;
    };

    var collectFieldText = function() {
        var parts = [];
        document.querySelectorAll('[data-essayguard-bound="1"]').forEach(function(field) {
            if (isEditorBackingField(field)) {
                return; // v1.2.221: already collected from its editor iframe below.
            }
            var text = (field.value || field.textContent || '').trim();
            if (text.length > 0) {
                parts.push(text);
            }
        });
        // FIX-EG-TINYMCE-FINALIZE (v1.2.90): Also collect text from TinyMCE iframes
        // registered in state.boundFrames — their body elements live inside iframe
        // contentDocuments and are invisible to outer-document querySelectorAll.
        state.boundFrames.forEach(function(frame) {
            var text = (frame.body && (frame.body.innerText || frame.body.textContent) || '').trim();
            if (text.length > 0) {
                parts.push(text);
            }
        });
        return parts.join('\n\n');
    };

    /**
     * Intercept submission form submits to flush buffered telemetry events and
     * call finalizeAttempt() before the page unloads.
     */
    var interceptSubmitForms = function() {
        // FIX-EG-QUIZ-NAVDELAY (v1.2.51): 'form#responseform' was included here, but
        // that selector matches the Moodle quiz per-question answer form which is
        // submitted on EVERY "Next page" and "Previous" button click  -  not just on
        // final submission. Intercepting it added a 7-second delay per page turn,
        // making timed quizzes unusable. Removed it from the intercept list:
        //   * Intermediate quiz page events are still flushed by the 5-second timer
        //     and by the window.beforeunload handler.
        //   * Final quiz submission goes through form[action*="processattempt"]
        //     (the "Submit all and finish" confirmation), which IS still intercepted.
        //   * The PHP event observer (on_quiz_attempt_submitted) is the authoritative
        //     scoring path for quizzes and fires after the attempt is locked.
        var selectors = [
            'form[action*="processattempt"]',
            'form[action*="/mod/assign"]',
            'form#mform1[action*="assign"]',
        ];

        selectors.forEach(function(selector) {
            document.querySelectorAll(selector).forEach(function(form) {
                // FIX-EG-NEXTPAGE (v1.2.59): form#responseform is the Moodle quiz
                // per-question navigation form.  It matches form[action*="processattempt"]
                // but is submitted on EVERY "Next page" and "Previous" button click.
                // When we intercept it and later call form.requestSubmit() (with no button
                // context), Moodle receives the POST without a navigation value (e.g. no
                // "next=1"), cannot determine the intended direction, and keeps the student
                // on the same page  -  the button appears "blocked".
                // Exclude it entirely; intermediate events are captured by the 5-second
                // periodic flush timer and the window.beforeunload Beacon handler.
                if (form.id === 'responseform') {
                    return;
                }

                if (form.dataset.egIntercepted === '1' || form.dataset.egIntercepted === 'done') {
                    return;
                }
                form.dataset.egIntercepted = '1';

                // Track the last submit button clicked so we can pass it to
                // requestSubmit(submitter)  -  without this, Moodle cannot tell which
                // button triggered the form (e.g. "Submit all and finish") and may
                // ignore the submission or redirect to the wrong page.
                var egLastBtn = null;
                form.querySelectorAll('[type="submit"]').forEach(function(btn) {
                    btn.addEventListener('click', function() { egLastBtn = btn; }, true);
                });

                form.addEventListener('submit', function(e) {
                    if (form.dataset.egIntercepted === 'done') {
                        return;
                    }
                    if (!state.cmid || !state.attemptkey) {
                        return;
                    }

                    e.preventDefault();

                    // FIX-EG-FLUSH-BEFORE-FINALIZE (v1.2.92): flush() and finalizeAttempt()
                    // previously raced each other — perFieldPromises was populated (launching
                    // AJAX calls immediately) BEFORE flush() resolved, so when PHP ran
                    // analyser::score_attempt() some queued events had not yet been written to
                    // the DB. The analyser saw a partial event set and scored LOW instead of
                    // MEDIUM/HIGH. Fix: capture field text snapshots eagerly (DOM read is
                    // synchronous and must happen before navigation), but defer all
                    // finalizeAttempt AJAX calls until AFTER flush() resolves. Events are
                    // guaranteed in DB before any scoring begins.
                    //
                    // Text snapshots captured now (sync, before any async work):
                    var fieldSnapshots = [];
                    document.querySelectorAll('[data-essayguard-bound="1"]').forEach(function(field) {
                        // v1.2.221: skip a rich editor's hidden backing textarea - its
                        // content is collected from the editor iframe below. Submitting both
                        // halved vocab_diversity and pushed honest students toward MEDIUM.
                        if (isEditorBackingField(field)) {
                            return;
                        }
                        var fieldText = (field.value || field.textContent || '').trim();
                        if (!fieldText) {
                            return;
                        }
                        // FIX-EG-QUE-QSLOT (v1.2.74): prefer cached qslot from .que id,
                        // fall back to name-regex for backward compatibility.
                        var fieldSlot = parseInt(field.dataset.essayguardQslot || '0', 10);
                        if (!fieldSlot) {
                            var nameM = (field.name || field.dataset.essayguardName || '').match(/q\d+:(\d+)_answer/);
                            fieldSlot = nameM ? parseInt(nameM[1], 10) : 0;
                        }
                        fieldSnapshots.push({text: fieldText, slot: fieldSlot});
                    });

                    // FIX-EG-TINYMCE-FINALIZE (v1.2.90): Also collect TinyMCE iframe text.
                    var frameSnapshots = [];
                    state.boundFrames.forEach(function(frame) {
                        var frameText = (
                            frame.body && (frame.body.innerText || frame.body.textContent) || ''
                        ).trim();
                        if (!frameText) {
                            return;
                        }
                        var frameSlot = parseInt(
                            (frame.body && frame.body.dataset.essayguardQslot) ||
                            (frame.outerNode && frame.outerNode.dataset.essayguardQslot) || '0',
                            10
                        ) || frame.qslot || 0;
                        frameSnapshots.push({text: frameText, slot: frameSlot});
                    });

                    // Aggregate text snapshot also captured now while DOM is still live:
                    var aggregateText = collectFieldText();

                    // FIX-EG-TOAST-PERQ-SLOTS (v1.2.93): When qslot detection fails for all
                    // fields in a multi-question quiz (all slots = 0), every finalizeAttempt
                    // call uses qslot=0 so injectRiskBadge() writes the result to
                    // BADGE_STORAGE_KEY (overall) rather than the per-question perqMap.
                    // The post-submit toast then shows only "Overall" with no per-question
                    // breakdown — the report is incomplete and does not distinguish pasted
                    // answers (Q1) from typed answers (Q2).
                    //
                    // Fix: if ALL snapshots across both fieldSnapshots and frameSnapshots have
                    // slot=0 AND there is more than one field, assign sequential 1-based
                    // position slots. score_attempt(qslot=N) will then use the paste-detection
                    // fallback (matching finaltext length against paste insertlen events), which
                    // correctly distinguishes a pasted Q1 from a typed Q2 even when tracker.js
                    // failed to tag events with the question slot number.
                    var allSnapshots = fieldSnapshots.concat(frameSnapshots);
                    if (allSnapshots.length > 1 &&
                            allSnapshots.every(function(s) { return s.slot === 0; })) {
                        var posSlot = 1;
                        fieldSnapshots = fieldSnapshots.map(function(s) {
                            return {text: s.text, slot: posSlot++};
                        });
                        frameSnapshots = frameSnapshots.map(function(s) {
                            return {text: s.text, slot: posSlot++};
                        });
                    }

                    // FIX-EG-SUBMIT-FLUSH-TIMEOUT (v1.2.96): Two-phase flush / finalize gate.
                    //
                    // PROBLEM (v1.2.45 → v1.2.95): the single 7 s Promise.race covered BOTH
                    // the flush AJAX call AND all finalizeAttempt calls. On a slow Moodle
                    // server the combined flush + finalize time could easily exceed 7 s —
                    // the timeout fired before flush() resolved, the form was submitted while
                    // events were still in-flight, and the PHP observer ran against an empty
                    // plagiarism_essayguard_ev table, writing riskscore=0 (LOW) to the DB.
                    // Events eventually arrived via the sendBeacon fallback but no re-score
                    // was triggered, so the LOW badge was permanent.
                    //
                    // FIX: two sequential phases.
                    //   Phase 1 — flush (CRITICAL, up to 10 s):
                    //     Events MUST be in the DB before the form posts. The PHP observer
                    //     fires synchronously during form processing and reads from
                    //     plagiarism_essayguard_ev immediately — events that arrive even
                    //     1 ms later are invisible to it.
                    //   Phase 2 — finalizeAttempt (NICE-TO-HAVE, up to 5 s):
                    //     Writes the inline risk badge. If the 5 s deadline fires, the form
                    //     still submits; the observer uses the events stored in Phase 1 to
                    //     compute the authoritative score.
                    //
                    // Total max wait: 15 s. Acceptable for an exam submission — students
                    // already expect a short delay before the "attempt submitted" page loads.
                    Promise.race([
                        flush(),
                        new Promise(function(resolve) { setTimeout(resolve, 10000); }),
                    ]).then(function() {
                        // Phase 2 — events are guaranteed in DB (or flush deadline elapsed).
                        // Run finalizeAttempt for the inline badge with its own sub-deadline.
                        var perFieldPromises = [];
                        fieldSnapshots.forEach(function(snap) {
                            perFieldPromises.push(finalizeAttempt(snap.text, snap.slot));
                        });
                        frameSnapshots.forEach(function(snap) {
                            perFieldPromises.push(finalizeAttempt(snap.text, snap.slot));
                        });
                        // FIX-EG-AGGREGATE-BADGE (v1.2.61): Always include aggregate (qslot=0)
                        // call so the sessionStorage toast reflects overall attempt risk.
                        perFieldPromises.push(finalizeAttempt(aggregateText, 0));
                        return Promise.race([
                            Promise.allSettled(perFieldPromises),
                            new Promise(function(resolve) { setTimeout(resolve, 5000); }),
                        ]);
                    }).finally(function() {
                        form.dataset.egIntercepted = 'done';
                        if (typeof form.requestSubmit === 'function') {
                            // Pass the captured button as the submitter so Moodle receives
                            // the button's name/value (e.g. "finishattempt=1") and can
                            // determine the correct action.
                            form.requestSubmit(egLastBtn || undefined);
                        } else {
                            form.submit();
                        }
                        egLastBtn = null;
                    });
                });
            });
        });
    };

    /**
     * Initialise Essay Guard telemetry for this page.
     * Called via js_call_amd('plagiarism_essayguard/tracker', 'init', [config]).
     *
     * @param {Object} config  {cmid, attemptkey, flushinterval, maxburstchars}
     */
    var init = function(config) {
        // v1.2.219: RE-ENTRY GUARD.
        // init() created three setInterval timers and registered a beforeunload handler,
        // none of which were ever tracked or cleared. A second init() call — which Moodle
        // does whenever js_call_amd runs twice on a page (a fragment reload, a second
        // tracked field being initialised, an AJAX-loaded quiz page) — created three MORE
        // timers, re-bound every field, and made every keystroke enqueue TWICE. Doubled
        // keystroke counts feed straight into the WPM and keystroke-ratio signals, so a
        // double init actively corrupted the score. Now the second call is a no-op.
        if (state.initialised) {
            return;
        }
        state.initialised = true;

        state = Object.assign(state, config);
        state.sessionStart = Date.now();

        // DIAG-EG-INIT (v1.2.145): First thing logged — confirms AMD module loaded and init() was called.

        // BUG-BADGE-NAV fix: restore any badge saved before the previous form
        // navigation so the student sees their risk result on this (next) page.
        restorePendingBadge();

        scan();
        interceptSubmitForms();
        observeTinyMCEIframes(); // FIX-EG-TINYMCE-MUTOBS (v1.2.88): detect late-injected iframes.

        // FIX-EG-TINYMCE-API-HOOK (v1.2.121): Hook into TinyMCE's native AddEditor event as a
        // second detection path alongside the MutationObserver.
        //
        // Problem: on Moodle instances where TinyMCE initialises more than 2500 ms after page
        // load, ALL four MutationObserver retries (300/600/1200/2500 ms) fire before the editor
        // is ready. bindTinyMCENode finds an unready body and returns without attaching the
        // paste listener. No subsequent trigger re-runs bindTinyMCENode for that editor.
        // Result: paste_events=0 for every question regardless of how much the student pasted.
        //
        // Fix: also hook window.tinymce.on('AddEditor') which fires exactly when TinyMCE
        // creates and initialises each editor — guaranteed to be after the iframe body is
        // ready. This gives us a reliable bind point that is completely independent of timing.
        //
        // hookTinyMCEApi() is called immediately (catches editors already loaded), again at
        // 1 s and 3 s (catches editors that load after tracker.init but before tinymce is
        // available as a global). A state flag prevents duplicate registration.
        var hookTinyMCEApi = function() {
            if (typeof window.tinymce !== 'undefined'
                    && typeof window.tinymce.on === 'function'
                    && !state.tinymceApiHooked) {
                state.tinymceApiHooked = true;
                // DIAG-EG-TMCE-API (v1.2.145): Confirm TinyMCE global API was found and hooked.
                window.tinymce.on('AddEditor', function(addEvent) {
                    if (addEvent && addEvent.editor) {
                        // DIAG-EG-ADDEDITOR (v1.2.145): Log each editor that TinyMCE reports.
                        addEvent.editor.on('init', function() {
                            var editorId = addEvent.editor.id;
                            var editorIframe = document.getElementById(editorId + '_ifr');
                            // DIAG-EG-EDITOR-INIT (v1.2.145): Log editor init and whether iframe was found.
                            if (editorIframe) {
                                bindTinyMCENode(editorIframe);
                            }
                        });
                    }
                });
                // Also bind any editors that were already initialised before the hook ran.
                if (window.tinymce.editors && window.tinymce.editors.length) {
                    window.tinymce.editors.forEach(function(editor) {
                        if (editor && editor.initialized) {
                            var alreadyIframe = document.getElementById(editor.id + '_ifr');
                            if (alreadyIframe) {
                                bindTinyMCENode(alreadyIframe);
                            }
                        }
                    });
                }
            }
        };
        hookTinyMCEApi();
        setTimeout(hookTinyMCEApi, 1000);
        setTimeout(hookTinyMCEApi, 3000);

        // v1.2.219: Keep every timer handle so they can be cleared. Previously all three
        // were fire-and-forget setInterval() calls that ran for the lifetime of the
        // document — including after the student had submitted and moved on, and
        // including any duplicate set created by a second init().
        state.timers.push(setInterval(scan, 2000));
        state.timers.push(setInterval(interceptSubmitForms, 3000));

        state.timer = setInterval(function() {
            flush();
        }, state.flushinterval);
        state.timers.push(state.timer);

        // v1.2.219: Stop the timers when the page goes away. Without this, a bfcache
        // restore or a long-lived SPA-style page kept polling the DOM every 2 seconds
        // forever.
        var stopTimers = function() {
            state.timers.forEach(function(id) {
                clearInterval(id);
            });
            state.timers = [];
            state.timer = null;
        };
        window.addEventListener('pagehide', stopTimers);

        window.addEventListener('beforeunload', function() {
            if (!state.queue.length) {
                return;
            }

            // FIX-EG-BEFOREUNLOAD-BEACON (v1.2.58):
            //
            // PROBLEM: The previous implementation called flush() which uses Moodle's
            // Ajax.call() (XHR-based). When the student navigates away by clicking a
            // link or closing the tab, the browser may abort in-flight XHR requests
            // immediately after the unload event fires  -  before the server receives
            // or processes the payload. This silently discards any queued events that
            // had not yet been sent by the periodic timer.
            //
            // The interceptSubmitForms() handler covers the normal submit path by
            // waiting up to 7 s for flush() to complete before allowing the form to
            // post  -  but that only works for form submissions. Navigating away via a
            // link, the browser back button, or closing the tab bypasses that guard.
            //
            // FIX: Use navigator.sendBeacon() when available. The Beacon API sends a
            // small POST in the background and the browser GUARANTEES delivery even
            // after the page has unloaded. We post directly to Moodle's AJAX service
            // endpoint (/lib/ajax/service.php) using the same JSON array format that
            // Ajax.call() uses, with the sesskey in the URL for CSRF validation.
            //
            // Fallback: if Beacon is unavailable (very old browser) or rejects the
            // payload (size limit exceeded), we fall back to the async XHR flush.
            // Events may still be lost in that case, but it is no worse than before.
            if (typeof navigator !== 'undefined' && typeof navigator.sendBeacon === 'function' &&
                    typeof M !== 'undefined' && M.cfg && M.cfg.sesskey && M.cfg.wwwroot) {
                // v1.2.219: Send at most MAX_BATCH events, and only the most recent ones.
                // sendBeacon() silently returns false once the body exceeds the UA's limit
                // (~64KB), which previously meant a large backlog lost EVERYTHING rather
                // than the tail. Sending the newest slice guarantees the payload stays
                // small enough to be accepted, and the newest events are the ones the
                // scoring signals actually need.
                // v1.2.220: strip the client-only `seq` here too - the beacon posts to the
                // same web service, which rejects an undeclared key.
                var beaconEvents = state.queue.slice(-MAX_BATCH).map(function(e) {
                    return {
                        eventname:   e.eventname,
                        eventtime:   e.eventtime,
                        payloadjson: e.payloadjson,
                    };
                });
                var payload = JSON.stringify([{
                    methodname: 'plagiarism_essayguard_log_event',
                    args: {
                        cmid:       state.cmid,
                        attemptkey: state.attemptkey,
                        events:     beaconEvents,
                    },
                }]);
                var beaconUrl = M.cfg.wwwroot + '/lib/ajax/service.php?sesskey=' + M.cfg.sesskey;
                // sendBeacon() returns true when the user agent accepted the request.
                // It returns false if the payload is too large or the API is unavailable.
                if (navigator.sendBeacon(beaconUrl, new Blob([payload], {type: 'application/json'}))) {
                    return; // Beacon accepted  -  delivery is guaranteed by the browser.
                }
            }

            // Fallback: async XHR flush (events may not survive fast navigation).
            flush();
        });
    };

    // v1.2.219: Exposed for completeness so a host page can stop the tracker
    // deterministically rather than relying on navigation.
    var destroy = function() {
        state.timers.forEach(function(id) {
            clearInterval(id);
        });
        state.timers = [];
        state.timer = null;
        state.initialised = false;
    };

    return {
        init: init,
        destroy: destroy,
    };
});
