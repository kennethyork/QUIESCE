/* Quiesce — the whole front end.
 *
 * Plain DOM, no framework, no build. PHP pushes tokens through
 * `window.quiesce.onToken(...)`; everything else is read by polling `qState`
 * once a second, because a readout that is a second stale is honest and a
 * subscription system would be a second copy of the truth.
 */

(function () {
    'use strict';

    var el = function (id) { return document.getElementById(id); };

    var dom = {
        status: el('status'),
        panels: el('panels'),
        modeChat: el('mode-chat'),
        modeTask: el('mode-task'),
        modeHistory: el('mode-history'),
        viewChat: el('view-chat'),
        viewTask: el('view-task'),
        viewHistory: el('view-history'),
        sessions: el('sessions'),
        sessionsPath: el('sessions-path'),
        openSessions: el('open-sessions'),
        skillsPath: el('skills-path'),
        openSkills: el('open-skills'),
        documentsStat: el('documents-stat'),
        indexDocuments: el('index-documents'),
        visionStat: el('vision-stat'),
        fitStat: el('fit-stat'),
        imagesStat: el('images-stat'),
        imageServe: el('image-serve'),
        imageInstall: el('image-install'),
        taskToggle: el('task-toggle'),
        taskChev: el('task-chev'),
        taskSummary: el('task-summary'),
        loraStat: el('lora-stat'),
        imageStops: el('image-stops'),
        imageSteps: el('image-steps'),
        imageSize: el('image-size'),
        taskRoom: el('task-room'),
        taskSteps: el('task-steps'),
        roomNote: el('room-note'),
        undoStat: el('undo-stat'),
        undoWrite: el('undo-write'),
        openImages: el('open-images'),
        mcpStat: el('mcp-stat'),
        openMcp: el('open-mcp'),
        knowledgeNote: el('knowledge-note'),
        jobs: el('jobs'),
        chats: el('chats'),
        installedModels: el('installed-models'),
        modelFetch: el('model-fetch'),
        modelCheck: el('model-check'),
        modelPull: el('model-pull'),
        modelProgress: el('model-progress'),
        modelNote: el('model-note'),
        modelSuggestions: el('model-suggestions'),
        chatNew: el('chat-new'),
        chatSearch: el('chat-search'),
        again: el('again'),
        editq: el('editq'),
        openChats: el('open-chats'),
        chatsNote: el('chats-note'),
        jobsNote: el('jobs-note'),
        jobForm: el('job-form'),
        jobTask: el('job-task'),
        jobEvery: el('job-every'),
        jobAt: el('job-at'),
        workspacePath: el('workspace-path'),
        chooseWorkspace: el('choose-workspace'),
        clearWorkspace: el('clear-workspace'),
        webToggle: el('web-toggle'),
        webNote: el('web-note'),
        shellToggle: el('shell-toggle'),
        shellNote: el('shell-note'),
        steps: el('steps'),
        answer: el('answer'),
        taskForm: el('task-form'),
        taskInput: el('task-input'),
        taskRun: el('task-run'),
        taskStop: el('task-stop'),
        taskClear: el('task-clear'),
        taskNote: el('task-note'),
        model: el('model'),
        load: el('load'),
        unload: el('unload'),
        forget: el('forget'),
        transcript: el('transcript'),
        empty: el('empty'),
        composer: el('composer'),
        prompt: el('prompt'),
        send: el('send'),
        stop: el('stop'),
        composerNote: el('composer-note'),
        attach: el('attach'),
        detach: el('detach'),
        profiles: el('profiles'),
        profileNote: el('profile-note'),
        profileFacts: el('profile-facts'),
        hardware: el('hardware'),
        hardwareNote: el('hardware-note'),
        tempFill: el('temp-fill'),
        tempMark: el('temp-mark'),
        governor: el('governor'),
        dutyFill: el('duty-fill'),
        dutyMark: el('duty-mark'),
        governorReason: el('governor-reason'),
        endpointOllama: el('endpoint-ollama'),
        endpointOpenai: el('endpoint-openai'),
        endpointNote: el('endpoint-note'),
        log: el('log'),
        footLeft: el('foot-left'),
        footRight: el('foot-right'),
        footZoom: el('foot-zoom')
    };

    var state = null;
    var streaming = null;      // the assistant turn being written
    var taskAnswer = null;     // the answer being streamed by a task
    var lastModelList = '';
    var mode = 'chat';

    function facts(node, rows) {
        node.innerHTML = '';
        rows.forEach(function (row) {
            var dt = document.createElement('dt');
            dt.textContent = row[0];
            var dd = document.createElement('dd');
            dd.textContent = row[1];
            node.appendChild(dt);
            node.appendChild(dd);
        });
    }

    function num(value, suffix, digits) {
        if (value === null || value === undefined || isNaN(value)) { return '—'; }
        return Number(value).toFixed(digits === undefined || digits === null ? 0 : digits) + (suffix || '');
    }

    function megabytes(mb) {
        if (mb === null || mb === undefined) { return '—'; }
        return Number(mb) >= 1024 ? (Number(mb) / 1024).toFixed(1) + ' GB' : Math.round(Number(mb)) + ' MB';
    }

    function turn(who, text, kind) {
        if (dom.empty && dom.empty.parentNode) { dom.empty.remove(); }

        var node = document.createElement('article');
        node.className = 'turn ' + (kind || '');

        var label = document.createElement('div');
        label.className = 'who';
        label.textContent = who;

        var body = document.createElement('div');
        body.className = 'body';
        body.textContent = text || '';

        node.appendChild(label);
        node.appendChild(body);
        dom.transcript.appendChild(node);
        dom.transcript.scrollTop = dom.transcript.scrollHeight;

        return body;
    }

    /* ---------- task view ---------- */

    var lastSteps = '';
    var lastSessions = '';
    var lastJobs = '';
    var lastChats = '';
    var lastModels = '';
    var openSession = null;

    function showMode(name, remember) {
        mode = name;
        dom.viewChat.hidden = name !== 'chat';
        dom.viewTask.hidden = name !== 'task';
        dom.viewHistory.hidden = name !== 'history';
        dom.modeChat.setAttribute('aria-pressed', String(name === 'chat'));
        dom.modeTask.setAttribute('aria-pressed', String(name === 'task'));
        dom.modeHistory.setAttribute('aria-pressed', String(name === 'history'));

        if (remember && typeof window.qMode === 'function') {
            window.qMode(name);
        }
    }

    var initialModeApplied = false;
    var zoomApplied = false;

    function applyRememberedMode(s) {
        if (initialModeApplied) { return; }
        initialModeApplied = true;

        var remembered = s.settings && s.settings.mode;

        if (remembered === 'task' || remembered === 'history') {
            showMode(remembered, false);
        }
    }

    /** A diff: green arrivals, red departures, context left alone. */
    function diffNode(diff) {
        var block = document.createElement('pre');
        block.className = 'step-diff';

        String(diff).split('\n').forEach(function (line) {
            var span = document.createElement('span');
            var first = line.charAt(0);
            span.className = first === '+' ? 'add' : (first === '-' ? 'del' : (line.indexOf('@@') === 0 ? 'hunk' : 'ctx'));
            span.textContent = line + '\n';
            block.appendChild(span);
        });

        return block;
    }

    function renderSteps(steps) {
        var signature = (steps || []).map(function (step) {
            return step.step + ':' + (step.call || step.tool) + ':' + step.ms + ':'
                + (step.ok ? '1' : '0') + ':' + (step.revision || 0);
        }).join('|');

        if (signature === lastSteps) { return; }
        lastSteps = signature;

        dom.steps.innerHTML = '';

        if (!steps || steps.length === 0) {
            var empty = document.createElement('p');
            empty.className = 'empty';
            empty.textContent = 'No task yet. The agent works in steps you can watch: each tool it calls, '
                + 'what came back, and how long it took. Every step is one generation, so the governor '
                + 'paces the whole task the same way it paces a single answer.';
            dom.steps.appendChild(empty);

            return;
        }

        steps.forEach(function (step, index) {
            var node = document.createElement('article');
            node.className = 'step' + (step.ok ? '' : ' step-bad') + (step.machine ? ' step-web' : '')
                + (step.running ? ' step-running' : '');
            node.dataset.stepIndex = String(index);

            var head = document.createElement('div');
            head.className = 'step-head';

            var badge = document.createElement('span');
            badge.className = 'step-badge';
            badge.textContent = 'step ' + step.step;

            var name = document.createElement('span');
            name.className = 'step-tool';
            name.textContent = step.call || step.tool;

            var meta = document.createElement('span');
            meta.className = 'step-meta';
            meta.textContent = (step.machine ? 'left this machine · ' : '')
                + (step.running ? 'running…' : step.ms + ' ms');

            head.appendChild(badge);
            head.appendChild(name);
            head.appendChild(meta);

            var output = document.createElement('pre');
            output.className = 'step-output';
            output.textContent = step.output || '';

            node.appendChild(head);
            node.appendChild(output);

            if (step.verification) {
                var check = document.createElement('div');
                check.className = 'step-check' + (step.verified ? ' ok' : ' bad');
                check.textContent = step.verification;
                node.appendChild(check);
            }

            if (step.diff) {
                node.appendChild(diffNode(step.diff));
            }

            dom.steps.appendChild(node);
        });
    }

    function when(seconds) {
        if (!seconds) { return '—'; }
        var date = new Date(seconds * 1000);
        return date.toLocaleDateString() + ' ' + String(date.getHours()).padStart(2, '0') + ':'
            + String(date.getMinutes()).padStart(2, '0');
    }

    function renderSessions(s) {
        dom.sessionsPath.textContent = s.sessions_directory || '';

        if (openSession) { return; }

        var sessions = s.sessions || [];
        var signature = sessions.map(function (session) {
            return session.id + ':' + session.state + ':' + session.steps;
        }).join('|');

        if (signature === lastSessions) { return; }
        lastSessions = signature;
        dom.sessions.innerHTML = '';

        if (sessions.length === 0) {
            var empty = document.createElement('p');
            empty.className = 'empty';
            empty.textContent = 'Nothing recorded yet. Every task the agent runs is written here as one readable '
                + 'JSON file — the steps it took, the files it wrote, the answer, and the history needed to '
                + 'continue it later.';
            dom.sessions.appendChild(empty);

            return;
        }

        sessions.forEach(function (session) {
            var node = document.createElement('article');
            node.className = 'session';

            var head = document.createElement('div');
            head.className = 'session-head';

            var title = document.createElement('span');
            title.className = 'session-title';
            title.textContent = session.task || '(no task recorded)';

            var meta = document.createElement('span');
            meta.className = 'session-meta';
            meta.textContent = when(session.started) + ' · ' + session.model + ' · '
                + session.steps + ' step' + (session.steps === 1 ? '' : 's') + ' · '
                + (session.seconds || 0) + 's · ' + session.state;

            head.appendChild(title);
            head.appendChild(meta);
            node.appendChild(head);

            if (session.files && session.files.length) {
                var files = document.createElement('p');
                files.className = 'muted small';
                files.textContent = 'wrote ' + session.files.join(', ');
                node.appendChild(files);
            }

            var actions = document.createElement('div');
            actions.className = 'session-actions';

            var show = document.createElement('button');
            show.type = 'button';
            show.textContent = 'Read';
            show.onclick = function () { readSession(session.id); };

            actions.appendChild(show);

            if (session.resumable) {
                var resume = document.createElement('button');
                resume.type = 'button';
                resume.className = 'allow';
                resume.textContent = 'Continue this task';
                resume.onclick = function () {
                    window.qResume({ id: session.id, instruction: '' }).then(function (result) {
                        if (result && result.ok === false) {
                            dom.taskNote.textContent = result.error || 'could not continue';
                        }
                        showMode('task');
                        poll();
                    });
                };

                actions.appendChild(resume);
            }

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.textContent = 'Forget';
            remove.onclick = function () {
                window.qSessionDelete(session.id).then(function () {
                    lastSessions = '';
                    poll();
                });
            };

            actions.appendChild(remove);
            node.appendChild(actions);
            dom.sessions.appendChild(node);
        });
    }

    function readSession(id) {
        window.qSession(id).then(function (result) {
            if (!result || !result.session) { return; }

            openSession = result.session;
            dom.sessions.innerHTML = '';

            var back = document.createElement('button');
            back.type = 'button';
            back.textContent = '← back to the list';
            back.onclick = function () {
                openSession = null;
                lastSessions = '';
                poll();
            };
            dom.sessions.appendChild(back);

            var title = document.createElement('h3');
            title.className = 'session-title';
            title.textContent = openSession.task || '';
            dom.sessions.appendChild(title);

            var summary = document.createElement('p');
            summary.className = 'muted small';
            summary.textContent = when(openSession.started) + ' · ' + (openSession.model || '') + ' · '
                + (openSession.seconds || 0) + 's · ' + (openSession.steps || []).length + ' steps · '
                + (openSession.state || '');
            dom.sessions.appendChild(summary);

            (openSession.steps || []).forEach(function (step) {
                var node = document.createElement('article');
                node.className = 'step' + (step.ok ? '' : ' step-bad') + (step.machine ? ' step-web' : '');

                var head = document.createElement('div');
                head.className = 'step-head';

                var badge = document.createElement('span');
                badge.className = 'step-badge';
                badge.textContent = 'step ' + step.step;

                var name = document.createElement('span');
                name.className = 'step-tool';
                name.textContent = step.call || step.tool;

                var meta = document.createElement('span');
                meta.className = 'step-meta';
                meta.textContent = (step.machine ? 'left this machine · ' : '') + step.ms + ' ms';

                head.appendChild(badge);
                head.appendChild(name);
                head.appendChild(meta);

                var output = document.createElement('pre');
                output.className = 'step-output';
                output.textContent = step.output || '';

                node.appendChild(head);
                node.appendChild(output);
                dom.sessions.appendChild(node);
            });

            if (openSession.answer) {
                var answer = document.createElement('div');
                answer.className = 'answer';
                answer.textContent = openSession.answer;
                dom.sessions.appendChild(answer);
            }
        });
    }

    function renderModels(s) {
        var options = s.model_options || {};
        var installed = options.installed || [];
        var fits = {};

        ((s.advisor && s.advisor.models) || []).forEach(function (model) { fits[model.model] = model; });

        var signature = installed.map(function (model) { return model.name + model.megabytes; }).join('|')
            + '#' + (options.suggestions || []).map(function (guess) { return guess.name; }).join('|');

        if (signature !== lastModels) {
            lastModels = signature;

            dom.installedModels.innerHTML = '';
            installed.forEach(function (model) {
                var item = document.createElement('li');
                var fit = fits[model.name] || {};

                var name = document.createElement('span');
                name.className = 'job-title';
                name.textContent = model.name + '  ·  ' + (model.megabytes / 1024).toFixed(1) + ' GB'
                    + (model.parameters ? ' · ' + model.parameters : '');

                var verdict = document.createElement('span');
                verdict.className = 'job-meta';
                verdict.textContent = fit.verdict || '';
                verdict.style.color = fit.fits_profile === false ? 'var(--warn)' : 'var(--muted)';

                item.appendChild(name);
                item.appendChild(verdict);
                dom.installedModels.appendChild(item);
            });

            dom.modelSuggestions.innerHTML = '';
            (options.suggestions || []).forEach(function (guess) {
                var item = document.createElement('li');

                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'chat-title';
                button.textContent = guess.name;
                button.title = guess.why;
                button.onclick = function () {
                    dom.modelFetch.value = guess.name;
                    dom.modelNote.textContent = guess.why;
                };

                var why = document.createElement('span');
                why.className = 'job-meta';
                why.textContent = guess.why;

                item.appendChild(button);
                item.appendChild(why);
                dom.modelSuggestions.appendChild(item);
            });
        }

        var pulling = options.pulling;

        if (pulling) {
            dom.modelProgress.style.width = (pulling.percent || 0) + '%';
            dom.modelPull.disabled = true;
            dom.modelNote.textContent = pulling.name + ': ' + pulling.status
                + (pulling.total ? ' — ' + pulling.percent + '% of ' + (pulling.total / 1e9).toFixed(1) + ' GB' : '')
                + ' (' + pulling.seconds + 's)';
        } else {
            dom.modelPull.disabled = false;
        }
    }

    function renderChats(s) {
        var chats = s.matches || s.chats || [];
        var signature = (s.chat || '') + '|' + chats.map(function (chat) {
            return chat.id + ':' + chat.title + ':' + (chat.turns || '') + ':' + (chat.snippet || '');
        }).join('|');

        if (signature === lastChats) { return; }
        lastChats = signature;
        dom.chats.innerHTML = '';

        if (chats.length === 0) {
            var empty = document.createElement('li');
            empty.className = 'muted small';
            empty.textContent = 'No conversations yet.';
            dom.chats.appendChild(empty);

            return;
        }

        chats.forEach(function (chat) {
            var item = document.createElement('li');
            item.className = chat.id === s.chat ? 'current' : '';

            if (chat.snippet) {
                var found = document.createElement('span');
                found.className = 'chat-meta';
                found.textContent = chat.where + ': ' + chat.snippet;
                item.appendChild(found);
            }

            var title = document.createElement('button');
            title.type = 'button';
            title.className = 'chat-title';
            title.textContent = chat.title;
            title.title = chat.turns + ' messages · ' + when(chat.updated);
            title.onclick = function () {
                window.qChatUse(chat.id).then(function () { poll(); });
            };

            var meta = document.createElement('span');
            meta.className = 'chat-meta';
            meta.textContent = when(chat.updated);

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'chat-remove';
            remove.textContent = '×';
            remove.title = 'forget this conversation';
            remove.onclick = function () {
                window.qChatDelete(chat.id).then(function () { lastChats = ''; poll(); });
            };

            item.appendChild(title);
            item.appendChild(meta);
            item.appendChild(remove);
            dom.chats.appendChild(item);
        });
    }

    function renderJobs(s) {
        var jobs = s.jobs || [];
        var signature = jobs.map(function (job) {
            return job.id + ':' + job.enabled + ':' + job.last_run + ':' + job.last_state + ':' + job.requested;
        }).join('|');

        if (signature === lastJobs) { return; }
        lastJobs = signature;
        dom.jobs.innerHTML = '';

        if (jobs.length === 0) {
            var empty = document.createElement('li');
            empty.className = 'muted small';
            empty.textContent = 'No jobs yet.';
            dom.jobs.appendChild(empty);

            return;
        }

        jobs.forEach(function (job) {
            var item = document.createElement('li');

            var title = document.createElement('span');
            title.className = 'job-title';
            title.textContent = job.name || job.task;

            var meta = document.createElement('span');
            meta.className = 'job-meta';
            meta.textContent = (job.enabled ? 'next ' + when(job.next_run) : 'paused')
                + (job.last_state ? ' · last ' + job.last_state : '')
                + (job.requested ? ' · queued' : '');

            var actions = document.createElement('span');
            actions.className = 'job-actions';

            var run = document.createElement('button');
            run.type = 'button';
            run.textContent = 'Run now';
            run.onclick = function () {
                window.qJobRun(job.id).then(function (result) {
                    dom.jobsNote.textContent = (result && result.note) || 'queued';
                    lastJobs = '';
                    poll();
                });
            };

            var toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.textContent = job.enabled ? 'Pause' : 'Start';
            toggle.onclick = function () {
                window.qJobToggle({ id: job.id, enabled: !job.enabled }).then(function () {
                    lastJobs = '';
                    poll();
                });
            };

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.textContent = '×';
            remove.onclick = function () {
                window.qJobRemove(job.id).then(function () {
                    lastJobs = '';
                    poll();
                });
            };

            actions.appendChild(run);
            actions.appendChild(toggle);
            actions.appendChild(remove);

            item.appendChild(title);
            item.appendChild(meta);
            item.appendChild(actions);
            dom.jobs.appendChild(item);
        });
    }

    var imageNode = null;
    var attached = null;      // { path, name, note } chosen for the next message
    var editing = false;      // the composer holds a question being changed
    var zoomLevel = 1;        // how much the page is scaled; remembered in settings

    /** Whichever view the reader is looking at is the one that answers. */
    function activePane() {
        return mode === 'task' ? dom.steps : dom.transcript;
    }

    function say(who, text, kind) {
        var pane = activePane();

        if (pane === dom.transcript) {
            return turn(who, text, kind);
        }

        var node = document.createElement('article');
        node.className = 'turn ' + (kind || '');

        var label = document.createElement('div');
        label.className = 'who';
        label.textContent = who;

        var body = document.createElement('div');
        body.className = 'body';
        body.textContent = text || '';

        node.appendChild(label);
        node.appendChild(body);
        pane.appendChild(node);
        pane.scrollTop = pane.scrollHeight;

        return body;
    }

    function imagePlaceholder(prompt) {
        var node = document.createElement('div');
        node.className = 'image-card';

        var title = document.createElement('div');
        title.className = 'session-title';
        title.textContent = '/create_image ' + prompt;

        var status = document.createElement('p');
        status.className = 'muted small';
        status.textContent = 'asking the local image server…';

        node.appendChild(title);
        node.appendChild(status);
        activePane().appendChild(node);
        activePane().scrollTop = activePane().scrollHeight;

        return status;
    }

    function runImage(prompt) {
        if (!prompt) {
            say('error', 'give it something to draw: /create_image a red cube on a table', 'failed');
            return;
        }

        say('you', '/create_image ' + prompt, 'user');
        imageNode = imagePlaceholder(prompt);
        dom.composerNote.textContent = 'drawing…';

        window.qImage({ prompt: prompt }).then(function (result) {
            if (result && result.ok === false) {
                if (imageNode) { imageNode.textContent = result.error || 'could not start'; }
                imageNode = null;
                dom.composerNote.textContent = result.error || 'could not start';
                dom.taskNote.textContent = result.error || 'could not start';
            }
            poll();
        });
    }

    function runLook(rest) {
        var parts = rest.split(/\s+/);
        var path = parts.shift() || '';
        var question = parts.join(' ');

        if (!path) {
            say('error', 'name an image in the working folder: /look chart.png what does this show?', 'failed');
            return;
        }

        say('you', '/look ' + path, 'user');
        dom.composerNote.textContent = 'looking…';

        window.qLook({ path: path, question: question }).then(function (result) {
            say(result && result.ok ? 'vision' : 'error', (result && result.output) || 'nothing came back', result && result.ok ? '' : 'failed');
            dom.composerNote.textContent = '';
            poll();
        });
    }

    function runIndex(remaining) {
        var left = typeof remaining === 'number' ? remaining : null;

        window.qIndex(48).then(function (result) {
            if (!result || result.ok === false) {
                dom.composerNote.textContent = (result && result.error) || 'could not index';
                return;
            }

            dom.composerNote.textContent = 'indexing… ' + result.remaining + ' chunks left';

            if (result.remaining > 0) {
                runIndex(result.remaining);
            } else {
                dom.composerNote.textContent = 'indexed: semantic search is on';
                lastJobs = '';
                poll();
            }
        });
    }

    /** Returns true when the text was a command and has been handled. */
    function slashCommand(text) {
        var match = /^\/([a-z_]+)\s*([\s\S]*)$/i.exec(text.trim());

        if (!match) {
            return false;
        }

        var name = match[1].toLowerCase();
        var rest = match[2].trim();

        if (name === 'create_image' || name === 'image') {
            runImage(rest);

            return true;
        }

        if (name === 'look') {
            runLook(rest);

            return true;
        }

        if (name === 'index') {
            runIndex();

            return true;
        }

        return false;
    }

    /** Will the chosen model fit the card at the context the profile asks for? */
    function renderFit(s) {
        var advisor = s.advisor || {};
        var chosen = dom.model.value || (s.settings && s.settings.model) || '';
        var entry = null;

        (advisor.models || []).forEach(function (model) {
            if (model.model === chosen) { entry = model; }
        });

        dom.fitStat.title = '';
        dom.fitStat.style.color = '';

        if (!advisor.vram) {
            dom.fitStat.textContent = advisor.note || 'no card to size against';
            return;
        }

        if (!entry) {
            dom.fitStat.textContent = 'not measured yet';
            return;
        }

        var wanted = entry.profile_context || 0;

        if (entry.fits_profile === false) {
            dom.fitStat.style.color = 'var(--warn)';
            dom.fitStat.textContent = 'will not fit ' + wanted.toLocaleString() + ' tokens — '
                + (entry.max_context ? 'about ' + Number(entry.max_context).toLocaleString() + ' left after the weights'
                    : 'the weights alone fill the card');

            return;
        }

        dom.fitStat.textContent = entry.verdict
            + (advisor.recommendation && advisor.recommendation !== chosen
                ? '  ·  ' + advisor.recommendation + ' is the largest that still fits'
                : '');
    }

    function renderKnowledge(s) {
        var skills = s.skills || [];
        var documents = s.documents || {};
        var mcp = s.mcp || {};
        var servers = mcp.servers || [];
        var tools = 0;

        servers.forEach(function (server) { tools += (server.tools || []).length; });

        dom.skillsPath.title = s.skills_directory || '';
        dom.mcpStat.title = mcp.path || '';
        dom.skillsPath.textContent = skills.length === 0
            ? (s.skills_directory || '') + ' (empty)'
            : skills.length + ' skill' + (skills.length === 1 ? '' : 's') + ': ' + skills.map(function (skill) {
                return skill.name;
            }).join(', ');

        var index = documents.index || {};

        dom.documentsStat.textContent = documents.files
            ? documents.files + ' readable file' + (documents.files === 1 ? '' : 's') + ' · '
                + (index.vectors
                    ? 'semantic index: ' + index.vectors + ' chunks' + (index.model ? ' with ' + index.model : '')
                    : (index.semantic ? 'not indexed yet — press Index' : 'keyword search only'))
            : 'nothing readable in the folder yet';

        var vision = s.vision || {};
        var images = s.images || {};
        var index = documents.index || {};

        dom.visionStat.textContent = (vision.chosen || '') === ''
            ? 'no vision model installed (ollama pull moondream)'
            : vision.chosen + ((vision.models || []).length > 1 ? ' (+' + ((vision.models.length) - 1) + ' more)' : '');

        dom.imagesStat.title = images.directory || '';

        var serving = images.serving || {};

        if (document.activeElement !== dom.imageSteps) {
            dom.imageSteps.value = images.steps || 20;
        }

        if (document.activeElement !== dom.imageSize) {
            dom.imageSize.value = images.size || 512;
        }

        dom.imageServe.hidden = !!(images.backend && images.backend.available);
        dom.imageInstall.hidden = !!(images.backend && images.backend.available) || !!images.installing;

        if (images.loras && !images.installing) {
            var files = images.loras.files || [];
            dom.loraStat.textContent = files.length === 0
                ? 'none yet — drop .safetensors in ' + (images.lora_dir || '')
                : files.length + ' installed: ' + files.join(', ');
            dom.loraStat.title = images.lora_dir || '';
        }

        if (images.installing) {
            dom.imagesStat.textContent = 'installing: ' + (images.status || 'working…');
        }
        dom.imageStops.hidden = !(serving.available && serving.started_by_us);
        dom.imagesStat.textContent = (images.backend && images.backend.available)
            ? 'ready: ' + images.backend.kind + ' at ' + images.backend.url
            : 'no local image server (start AUTOMATIC1111 with --api on 7860)';

        dom.indexDocuments.textContent = index.vectors
            ? 'Re-index (' + index.vectors + ' chunks)'
            : 'Index';

        dom.mcpStat.textContent = servers.length === 0
            ? 'no servers configured'
            : servers.map(function (server) {
                return server.id + ' (' + server.stage + (server.tools.length ? ', ' + server.tools.length + ' tools' : '') + ')';
            }).join(', ');

        dom.knowledgeNote.textContent = (skills.length ? 'The model is told which skills exist and can load one. ' : '')
            + (vision.chosen ? 'It can look at images in the folder with ' + vision.chosen + '. ' : '')
            + ((images.backend && images.backend.available) ? 'Images can be drawn locally. ' : '')
            + (documents.files
                ? ((documents.index && documents.index.vectors)
                    ? 'It can search these documents semantically (' + documents.index.vectors + ' chunks indexed) or by keyword. '
                    : 'It can search these documents by keyword')
                  + ((documents.index && documents.index.semantic) ? ' — press Index for semantic search. ' : '. ')
                : '')
            + (tools ? 'It can call ' + tools + ' MCP tool' + (tools === 1 ? '' : 's') + ', which run in your own servers.' : '');
    }

    function renderTask(s) {
        var tools = s.tools || {};
        var agent = s.agent || {};

        dom.workspacePath.textContent = tools.workspace || 'none chosen';
        dom.workspacePath.title = tools.workspace || '';

        if (document.activeElement !== dom.webToggle) {
            dom.webToggle.checked = !!tools.web;
        }

        if (document.activeElement !== dom.shellToggle) {
            dom.shellToggle.checked = !!tools.shell;
        }

        dom.shellNote.textContent = tools.shell
            ? 'The agent may run commands in the working folder, as you. Read-only commands run straight away; '
              + 'anything that could change something waits for you to allow it; a few are refused outright. '
              + 'This is a gate, not a sandbox.'
            : 'Off: the agent can read and write files in the folder but cannot run anything.';

        dom.webNote.textContent = tools.web
            ? 'The agent may search and read pages: that traffic leaves this machine, and every call is '
              + 'logged. Model traffic stays on 127.0.0.1 either way.'
            : 'Off: the agent can only use the working folder. Model traffic is loopback either way.';

        // The bar is redrawn from the window every poll, not once on the first one:
        // the same question the resize listener asks, asked again in case the window
        // changed while nothing was listening.
        applyTaskOpen(taskOpenNow(), false);

        renderSteps(agent.steps || []);
        renderKnowledge(s);
        renderFit(s);
        renderLeash(s);

        var running = agent.state === 'thinking' || agent.state === 'tool'
            || agent.state === 'command' || agent.state === 'awaiting';
        dom.taskRun.disabled = running;
        dom.taskStop.disabled = !running;

        if (agent.state === 'command') {
            dom.taskNote.textContent = 'running a command · ' + (agent.seconds || 0) + 's';
        } else if (agent.state === 'awaiting') {
            dom.taskNote.textContent = 'waiting for you to allow a command';
        } else if (running) {
            dom.taskNote.textContent = 'step ' + (agent.step || 0) + ' of ' + (agent.max_steps || 6)
                + ' · ' + (agent.seconds || 0) + 's · ' + (agent.tokens || 0) + ' tokens';
        }
    }

    /* ---------- the push channel from PHP ---------- */

    window.quiesce = {
        onStarted: function (payload) {
            if (payload && (payload.again || payload.edited)) {
                // The old answer is not part of this one any more: drop the last
                // assistant turn before the new one starts streaming in.
                var turns = dom.transcript.querySelectorAll('.turn');
                var last = turns[turns.length - 1];

                if (last && /assistant|error/.test(last.className)) { last.remove(); }
            }

            if (payload && payload.attachment) {
                dom.composerNote.textContent = 'sent with ' + payload.attachment;
            }

            streaming = turn('assistant', '');
            streaming.classList.add('cursor');
            dom.stop.disabled = false;
            dom.send.disabled = true;
            dom.composerNote.textContent = (payload && payload.model) ? 'asking ' + payload.model : '';
        },
        onToken: function (payload) {
            if (!streaming) { streaming = turn('assistant', ''); }
            streaming.textContent += (payload && payload.text) || '';
            dom.transcript.scrollTop = dom.transcript.scrollHeight;
        },
        onFinished: function (payload) {
            if (streaming) { streaming.classList.remove('cursor'); }
            var usage = payload && payload.usage ? payload.usage : {};
            var detail = [];
            if (payload && payload.tokens) { detail.push(payload.tokens + ' tokens'); }
            if (usage.completion) { detail.push(usage.completion + ' generated'); }
            if (usage.seconds) { detail.push(usage.seconds + 's'); }
            dom.composerNote.textContent = detail.join(' · ');
            dom.stop.disabled = true;
            dom.send.disabled = false;
            streaming = null;
        },
        onFailed: function (payload) {
            if (streaming) { streaming.classList.remove('cursor'); }
            streaming = null;
            dom.stop.disabled = true;
            dom.send.disabled = false;
            turn('error', (payload && payload.error) || 'the model failed', 'failed');
        },
        onStopped: function () {
            if (streaming) { streaming.classList.remove('cursor'); }
            streaming = null;
            dom.stop.disabled = true;
            dom.send.disabled = false;
            dom.composerNote.textContent = 'stopped';
        },
        /* Loading an earlier conversation: draw it back into the window. */
        onHistory: function (payload) {
            dom.transcript.innerHTML = '';
            streaming = null;
            taskAnswer = null;

            (payload && payload.messages ? payload.messages : []).forEach(function (message) {
                var role = message.role || '';

                if (role === 'user') {
                    turn('you', message.content || '', 'user');
                } else if (role === 'assistant' && (message.content || '') !== '') {
                    turn('assistant', message.content || '');
                }
            });

            if (dom.transcript.children.length === 0) {
                var empty = document.createElement('p');
                empty.className = 'empty';
                empty.id = 'empty';
                empty.textContent = 'An empty conversation. Ask something.';
                dom.transcript.appendChild(empty);
            }

            dom.composerNote.textContent = 'continued';
        },
        onCleared: function () {
            streaming = null;
            dom.transcript.innerHTML = '';
            var p = document.createElement('p');
            p.className = 'empty';
            p.textContent = 'New conversation. Nothing was kept.';
            dom.transcript.appendChild(p);
            dom.composerNote.textContent = '';
        },

        /* ---------- the task loop ---------- */

        onTaskStarted: function (payload) {
            lastSteps = '';
            dom.steps.innerHTML = '';
            dom.answer.hidden = true;
            dom.answer.textContent = '';
            taskAnswer = null;
            dom.taskRun.disabled = true;
            dom.taskStop.disabled = false;
            dom.taskNote.textContent = (payload && payload.model ? 'running on ' + payload.model : 'running') + ' — one generation per step';
            showMode('task');
        },
        onTaskToken: function (payload) {
            dom.answer.hidden = false;
            dom.answer.textContent += ((payload && payload.text) || '');
        },
        onTaskStep: function (record) {
            taskAnswer = null;
            dom.answer.hidden = true;
            dom.answer.textContent = '';
            lastSteps = '';
            renderSteps(((state && state.agent ? state.agent.steps : []) || []).concat([record]));
            dom.steps.scrollTop = dom.steps.scrollHeight;
        },

        /* A command's output arrives while it runs, so the step is updated in
           place rather than rebuilt: rebuilding would fight the reader's scroll. */
        onTaskOutput: function (payload) {
            var node = dom.steps.querySelector('[data-step-index="' + payload.index + '"] .step-output');

            if (node) {
                node.textContent = payload.output || '';
                node.scrollTop = node.scrollHeight;
            }
        },

        /* The gate: nothing that could change the machine runs without this. */
        onTaskApproval: function (payload) {
            renderSteps(((state && state.agent ? state.agent.steps : []) || []));

            var card = document.createElement('article');
            card.className = 'approval';

            var title = document.createElement('div');
            title.className = 'approval-title';
            title.textContent = 'The agent wants to run a command';

            var command = document.createElement('code');
            command.className = 'approval-command';
            command.textContent = (payload && payload.command) || '';

            var why = document.createElement('p');
            why.className = 'muted small';
            why.textContent = ((payload && payload.reason) || 'this could change something')
                + ' — it would run in the working folder, as you.';

            var actions = document.createElement('div');
            actions.className = 'approval-actions';

            var allow = document.createElement('button');
            allow.type = 'button';
            allow.className = 'allow';
            allow.textContent = 'Allow once';
            allow.onclick = function () { answerApproval(true, card); };

            var deny = document.createElement('button');
            deny.type = 'button';
            deny.textContent = 'Refuse';
            deny.onclick = function () { answerApproval(false, card); };

            actions.appendChild(allow);
            actions.appendChild(deny);

            card.appendChild(title);
            card.appendChild(command);
            card.appendChild(why);
            card.appendChild(actions);

            dom.steps.appendChild(card);
            dom.steps.scrollTop = dom.steps.scrollHeight;
            dom.taskNote.textContent = 'waiting for you to allow a command';
        },
        onTaskFinished: function (payload) {
            dom.taskRun.disabled = false;
            dom.taskStop.disabled = true;
            dom.answer.hidden = false;
            dom.answer.textContent = (payload && payload.answer) || '';
            dom.taskNote.textContent = (payload && payload.state === 'failed')
                ? ('failed: ' + ((payload && payload.error) || 'unknown'))
                : ('finished in ' + ((payload && payload.seconds) || 0) + 's with ' + ((payload && payload.step) || 0) + ' steps');
            poll();
        },
        onModelPull: function (payload) {
            if (!payload || !payload.name) { return; }

            dom.modelProgress.style.width = (payload.percent || 0) + '%';
            dom.modelNote.textContent = payload.name + ': ' + (payload.status || '')
                + (payload.total ? ' — ' + payload.percent + '%' : '')
                + (payload.error ? ' — ' + payload.error : '');

            if (payload.done) {
                dom.modelPull.disabled = false;
                dom.modelProgress.style.width = '100%';
                dom.modelNote.textContent = payload.error
                    ? 'download failed: ' + payload.error
                    : payload.name + ' is installed';
            }

            poll();
        },
        onImageProgress: function (payload) {
            if (!imageNode) { return; }

            var percent = (payload && payload.progress) ? Math.round(payload.progress * 100) + '% — ' : '';

            imageNode.textContent = percent + 'still drawing' + (payload && payload.seconds ? ' (' + payload.seconds + 's)' : '');
        },
        onImageDone: function (payload) {
            if (!imageNode) { return; }

            var node = imageNode.parentNode;

            if (payload && payload.ok && payload.files && payload.files.length) {
                var shown = 0;

                payload.files.forEach(function (file) {
                    var img = document.createElement('img');
                    img.className = 'generated';
                    img.src = '/images/' + file;
                    img.alt = (payload.prompt || 'generated image');
                    img.onload = function () {
                        dom.transcript.scrollTop = dom.transcript.scrollHeight;
                    };
                    node.appendChild(img);
                    shown++;
                });

                imageNode.textContent = 'drawn in ' + (payload.seconds || 0) + 's — saved as ' + payload.files.join(', ');
                dom.composerNote.textContent = shown + ' image' + (shown === 1 ? '' : 's') + ' in the images folder';
            } else {
                imageNode.textContent = (payload && payload.error) || 'the image server did not answer';
                imageNode.classList.add('failed');
                dom.composerNote.textContent = '';
            }

            imageNode = null;
            dom.transcript.scrollTop = dom.transcript.scrollHeight;
        },
        onTaskCleared: function () {
            taskAnswer = null;
            dom.steps.innerHTML = '<p class="empty">No task yet.</p>';
            dom.answer.hidden = true;
            dom.answer.textContent = '';
            dom.taskNote.textContent = '';
        }
    };

    /* ---------- rendering ---------- */

    function renderStatus(s) {
        dom.status.innerHTML = '';

        var ollama = s.ollama || {};
        var governor = s.governor || {};
        var guard = s.guard || {};

        var engine = document.createElement('span');
        engine.className = 'pill';
        var engineDot = document.createElement('span');
        engineDot.className = 'dot ' + (ollama.up ? 'up' : 'down');
        engine.appendChild(engineDot);
        var engineText = document.createElement('span');
        // The long forms are wrapped so a narrow window can drop them and keep the
        // dot: a nowrap label is what decides how narrow this app can be.
        engineText.className = 'pill-text';
        engineText.innerHTML = ollama.up
            ? 'ollama <b>' + (ollama.version || 'up') + '</b> on 127.0.0.1:11434'
            : 'ollama is not running';
        engine.appendChild(engineText);
        dom.status.appendChild(engine);

        if (!ollama.up) {
            var start = document.createElement('button');
            start.textContent = 'Start Ollama';
            start.onclick = function () {
                start.disabled = true;
                start.textContent = 'starting…';
                window.qServe().then(poll).catch(poll);
            };
            dom.status.appendChild(start);
        }

        var local = document.createElement('span');
        local.className = 'pill pill-wide';
        local.innerHTML = '<b>local only</b> · nothing sent anywhere';
        local.title = 'every socket this application opens is loopback';
        dom.status.appendChild(local);

        var gov = document.createElement('span');
        gov.className = 'pill';
        var govDot = document.createElement('span');
        govDot.className = 'dot ' + (governor.holding ? 'hold' : (guard.inflight ? 'up' : ''));
        gov.appendChild(govDot);
        var govText = document.createElement('span');
        govText.className = 'pill-text';
        govText.innerHTML = (governor.profile ? governor.profile.label : 'steady') + ' · <b>' + (governor.reason || 'idle') + '</b>';
        gov.appendChild(govText);
        dom.status.appendChild(gov);

        var queue = document.createElement('span');
        queue.className = 'pill pill-wide';
        queue.innerHTML = 'queue <b>' + (guard.queued || 0) + '</b> · one at a time';
        queue.title = 'the governor runs one generation at a time';
        dom.status.appendChild(queue);
    }

    function renderModels(s) {
        var models = s.models || [];
        var signature = models.map(function (m) {
            return m.name + (m.loaded ? '*' : '') + m.size;
        }).join('|');

        if (signature !== lastModelList) {
            var selected = dom.model.value || (s.settings && s.settings.model) || '';
            dom.model.innerHTML = '';
            models.forEach(function (model) {
                var option = document.createElement('option');
                option.value = model.name;
                // A picker's own width comes from its longest label, so on a narrow
                // window the labels get shorter — the sizes are in the Models card
                // where there is room for them.
                option.textContent = window.innerWidth < 820
                    ? model.name
                    : model.name
                        + (model.loaded ? ' · loaded' : '')
                        + ' · ' + model.gigabytes + ' GB'
                        + (model.parameters ? ' · ' + model.parameters : '');
                dom.model.appendChild(option);
            });
            if (models.some(function (m) { return m.name === selected; })) {
                dom.model.value = selected;
            }
            lastModelList = signature;
        }

        dom.model.disabled = models.length === 0;
        dom.load.disabled = models.length === 0;
        dom.unload.disabled = models.length === 0;
    }

    function renderProfiles(s) {
        var profiles = s.profiles || [];
        var current = s.governor && s.governor.profile ? s.governor.profile.id : 'steady';

        dom.profiles.innerHTML = '';
        profiles.forEach(function (profile) {
            var button = document.createElement('button');
            button.type = 'button';
            button.textContent = profile.label;
            button.setAttribute('aria-pressed', String(profile.id === current));
            button.onclick = function () {
                window.qProfile(profile.id).then(poll);
            };
            dom.profiles.appendChild(button);
        });

        var active = profiles.filter(function (p) { return p.id === current; })[0];
        if (!active) { return; }

        dom.profileNote.textContent = active.note;
        facts(dom.profileFacts, [
            ['ceiling', active.ceiling + ' °C'],
            ['duty cycle', active.on_ms > 0 ? (active.on_ms / 1000) + 's on / ' + (active.off_ms / 1000) + 's off' : 'none'],
            ['token pace', active.max_tps > 0 ? '≤ ' + active.max_tps + ' /s' : 'unpaced'],
            ['busy budget', Math.round(active.max_duty * 100) + '% of 20 min'],
            ['context', active.num_ctx.toLocaleString()],
            ['threads', active.num_thread === 0 ? 'all' : active.num_thread]
        ]);
    }

    function renderHardware(s) {
        var gpu = s.gpu || {};
        var cpu = s.cpu || {};

        if (!gpu.available) {
            dom.hardwareNote.textContent = 'No NVIDIA card answered nvidia-smi, so the governor is working from time alone.';
            facts(dom.hardware, [['cpu load', num(cpu.load, '%')]]);
            return;
        }

        facts(dom.hardware, [
            ['card', gpu.name || '—'],
            ['temperature', num(gpu.temp, ' °C')],
            ['fan', gpu.fan === null || gpu.fan === undefined ? '—' : num(gpu.fan, '%')],
            ['power', num(gpu.power, ' W') + ' of ' + num(gpu.power_limit, ' W')],
            ['utilisation', num(gpu.util, '%')],
            ['vram', megabytes(gpu.vram_used) + ' of ' + megabytes(gpu.vram_total)],
            ['cpu load', num(cpu.load, '%')]
        ]);

        var ceiling = s.governor && s.governor.profile ? s.governor.profile.ceiling : 74;
        var share = gpu.temp === null || gpu.temp === undefined ? 0 : Math.max(0, Math.min(100, (gpu.temp / ceiling) * 100));

        dom.tempFill.style.width = share + '%';
        dom.tempFill.style.background = share > 96 ? 'var(--hot)' : (share > 80 ? 'var(--warn)' : 'var(--quiet)');
        dom.tempMark.style.left = '100%';

        var note = 'the card reports its own limits: ' + num(gpu.power_limit, ' W') + ' in force, '
            + num(gpu.power_default, ' W') + ' default.';
        if (gpu.power_default && gpu.power_limit < gpu.power_default) {
            note += ' A power cap is already applied below the default — this app did not set it and does not claim it.';
        }
        dom.hardwareNote.textContent = note;
    }

    function renderGovernor(s) {
        var governor = s.governor || {};
        var guard = s.guard || {};
        var profile = governor.profile || {};
        var inflight = guard.inflight;

        var context = (s.agent && s.agent.context) || {};

        facts(dom.governor, [
            ['state', governor.holding ? 'holding' : (inflight ? 'generating' : 'idle')],
            ['context', context.budget
                ? (context.used || 0).toLocaleString() + ' / ' + Number(context.budget).toLocaleString() + ' tokens'
                    + ((context.dropped || context.shortened || context.digested)
                        ? '  (shortened ' + (context.shortened || 0) + ' · digested ' + (context.digested || 0)
                            + ' · dropped ' + (context.dropped || 0) + ')'
                        : '')
                : '—'],
            ['now', inflight ? (inflight.model + ' · ' + inflight.seconds + 's · ' + inflight.tokens + ' tok') : '—'],
            ['at once', '1 generation'],
            ['busy, 20 min', Math.round((governor.duty || 0) * 100) + '%'],
            ['held back', String((guard.counters && guard.counters.held) || 0)],
            ['paced', String((guard.counters && guard.counters.paced) || 0)],
            ['answer pace', (guard.tokens_this_second || 0) + ' tok/s now']
        ]);

        dom.dutyFill.style.width = Math.round((governor.duty || 0) * 100) + '%';
        dom.dutyMark.style.left = Math.round((profile.max_duty || 1) * 100) + '%';
        dom.governorReason.textContent = governor.reason || 'idle';

        endpointLine(dom.endpointOllama, 'OLLAMA_HOST=', guard.url || 'http://127.0.0.1:11435');
        endpointLine(dom.endpointOpenai, 'OPENAI_BASE_URL=', guard.openai_url || 'http://127.0.0.1:11435/v1');
        dom.endpointNote.textContent = 'In force now: ' + ((guard.counters && guard.counters.generations) || 0)
            + ' generations governed, ' + ((guard.counters && guard.counters.held) || 0) + ' held for heat or budget.';
    }

    /*
     * One line of shell environment, as two block boxes rather than one string.
     *
     * 41 characters of monospace do not fit a 25-character panel and there is nothing
     * in `OPENAI_BASE_URL=http://127.0.0.1:11435/v1` to break at, so left to itself the
     * browser breaks it wherever it runs out of room — in the middle of the address,
     * which reads like a different address. Two spans are two block boxes and the break
     * lands on the `=` for free.
     *
     * `textContent` is unchanged by any of it (block display adds no characters), and
     * that is exactly what the click-to-copy binding reads.
     */
    function endpointLine(node, name, address) {
        if (!node) { return; }

        node.textContent = '';

        var label = document.createElement('span');
        label.className = 'endpoint-name';
        label.textContent = name;

        var value = document.createElement('span');
        value.className = 'endpoint-address';
        value.textContent = address;

        node.appendChild(label);
        node.appendChild(value);
    }

    function renderLog(s) {
        var entries = s.log || [];
        dom.log.innerHTML = '';

        entries.slice(0, 24).forEach(function (entry) {
            var li = document.createElement('li');
            li.dataset.level = entry.source || '';
            var time = document.createElement('time');
            var date = new Date(entry.t * 1000);
            time.textContent = String(date.getHours()).padStart(2, '0') + ':' + String(date.getMinutes()).padStart(2, '0')
                + ':' + String(date.getSeconds()).padStart(2, '0');
            var text = document.createElement('span');
            text.textContent = entry.message;
            li.appendChild(time);
            li.appendChild(text);
            dom.log.appendChild(li);
        });
    }

    function renderChat(s) {
        var chat = s.chat || {};
        var generating = chat.state === 'generating';

        dom.send.disabled = generating;
        dom.stop.disabled = !generating;
        if (!generating && chat.turns === 0) {
            dom.composerNote.textContent = '';
        }
    }

    function render(s) {
        /*
         * What the reader left the Task settings as is read *before* anything is
         * drawn. It used to be read after `renderTask` had already decided the fold
         * for itself — so the remembered value never applied at all, and the fold was
         * whatever the window happened to be when the first poll landed, for the rest
         * of the session.
         */
        if (!taskChoiceRead && s.settings && typeof s.settings.task_fold === 'boolean') {
            taskChoiceRead = true;
            taskChoice = s.settings.task_fold;
        }

        renderStatus(s);
        renderModels(s);
        renderProfiles(s);
        renderHardware(s);
        renderGovernor(s);
        renderLog(s);
        renderChat(s);
        renderTask(s);
        renderSessions(s);
        renderChats(s);
        renderModels(s);
        renderJobs(s);
        applyRememberedMode(s);

        if (!zoomApplied && s.settings && s.settings.zoom) {
            applyZoom(Number(s.settings.zoom) || 1, false);
        }

        zoomApplied = true;

        dom.footLeft.textContent = (s.app ? s.app.name + ' ' + s.app.version : '') + ' · '
            + (s.app ? s.app.endpoint : '') + ' → 127.0.0.1:11434';
        dom.footRight.textContent = 'settings: ' + (s.app ? s.app.configuration : '');
    }

    /* ---------- actions ---------- */

    function enterSends(field, form) {
        field.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                form.requestSubmit();
            }
        });
    }

    enterSends(dom.prompt, dom.composer);
    enterSends(dom.taskInput, dom.taskForm);

    dom.composer.addEventListener('submit', function (event) {
        event.preventDefault();
        var text = dom.prompt.value.trim();
        if (!text) { return; }

        if (slashCommand(text)) {
            dom.prompt.value = '';
            return;
        }

        turn('you', text, 'user');
        dom.prompt.value = '';
        dom.composerNote.textContent = 'queued…';

        var send = editing
            ? window.qChatEditQuestion(text)
            : window.qSend({ prompt: text, model: dom.model.value, attachment: attached ? attached.path : '' });

        editing = false;

        send.then(function (result) {
            if (attached && result && result.ok !== false) {
                attached = null;
                dom.detach.hidden = true;
                dom.attach.textContent = 'Attach…';
            }
            if (result && result.ok === false) {
                turn('error', result.error || 'could not start', 'failed');
                dom.composerNote.textContent = '';
            }
            poll();
        });
    });

    dom.stop.onclick = function () {
        window.qStop().then(poll);
    };

    dom.attach.onclick = function () {
        dom.composerNote.textContent = 'waiting for the file picker…';

        window.qAttach().then(function (result) {
            if (!result || result.ok === false) {
                dom.composerNote.textContent = (result && result.error) || 'nothing attached';
                return;
            }

            attached = result;
            dom.detach.hidden = false;
            dom.attach.textContent = 'Attached';
            dom.composerNote.textContent = result.note || result.name;
        });
    };

    dom.detach.onclick = function () {
        attached = null;
        dom.detach.hidden = true;
        dom.attach.textContent = 'Attach…';
        dom.composerNote.textContent = '';
    };

    /*
     * Zoom, with the keys people already use. It scales the page rather than the
     * window, so a small window becomes a usable one instead of a cropped one —
     * which is the point of having it at all.
     */
    function applyZoom(level, remember) {
        zoomLevel = Math.max(0.7, Math.min(1.6, Math.round(level * 100) / 100));
        document.body.style.zoom = zoomLevel;
        dom.footZoom.textContent = 'zoom ' + Math.round(zoomLevel * 100) + '%  (ctrl + / − / 0)';

        if (remember && typeof window.qZoom === 'function') {
            window.qZoom(zoomLevel);
        }
    }

    document.addEventListener('keydown', function (event) {
        if (!event.ctrlKey && !event.metaKey) { return; }

        if (event.key === '+' || event.key === '=') {
            event.preventDefault();
            applyZoom(zoomLevel + 0.1, true);
        } else if (event.key === '-' || event.key === '_') {
            event.preventDefault();
            applyZoom(zoomLevel - 0.1, true);
        } else if (event.key === '0') {
            event.preventDefault();
            applyZoom(1, true);
        }
    });

    /*
     * The task settings fold.
     *
     * They are part of the Task tab at every window size — a tab that is only
     * complete when the window is large is not complete. So the rows fold to one
     * line that says what is inside (the folder, the switches, the counts), and
     * open when you tap it; the conversation and the composer keep the space either
     * way, and the rows scroll inside themselves when the window is short (see
     * `#view-task > .task-bar` in the stylesheet — that half is the layout's job,
     * not this function's).
     *
     * Two rules, and nothing else:
     *
     *   while the reader has not said otherwise, the fold follows the window
     *   once they tap it, it follows them
     *
     * "Follows the window" is the part that was missing. The fold was worked out
     * once, on the first poll, and never revisited — so a window dragged to half
     * its height kept the arrangement it was drawn with, and the settings stayed
     * open over everything.
     */
    var taskChoice = null;       // true/false: the reader's decision. null: follow the window
    var taskChoiceRead = false;  // the remembered decision has been read out of settings
    var taskForced = false;      // tapped in *this* window: the window stops having a say

    function taskFitsOpen() {
        // Both dimensions decide. The rows wrap onto more lines when the window is
        // narrow and stack when it is short — and on a scaled display the page has
        // far fewer pixels than the window looks like it has.
        return window.innerWidth >= 900 && window.innerHeight >= 620;
    }

    function taskOpenNow() {
        if (taskForced) { return taskChoice === true; }
        if (!taskFitsOpen()) { return false; }

        return taskChoice === null ? true : taskChoice;
    }

    /* One line that says what is inside, for when the rows are folded away. */
    function taskFoldSummary(s) {
        if (!s) { return ''; }

        var tools = s.tools || {};
        var agent = s.agent || {};
        var skills = s.skills || [];
        var bits = [];

        bits.push(tools.workspace ? tools.workspace.split('/').pop() : 'no folder');
        bits.push('web ' + (tools.web ? 'on' : 'off'));
        bits.push('commands ' + (tools.shell ? 'on' : 'off'));
        if (skills.length) { bits.push(skills.length + ' skill' + (skills.length === 1 ? '' : 's')); }
        if (tools.loras && (tools.loras.files || []).length) { bits.push((tools.loras.files || []).length + ' lora'); }
        if (agent.max_steps) { bits.push('max ' + agent.max_steps + ' steps'); }

        return bits.join(' · ');
    }

    function applyTaskOpen(open, remember) {
        var bar = document.getElementById('task-bar');

        if (!bar) { return; }

        bar.dataset.open = open ? 'true' : 'false';

        if (dom.taskToggle) { dom.taskToggle.setAttribute('aria-expanded', String(open)); }
        if (dom.taskChev) { dom.taskChev.textContent = open ? '\u25be' : '\u25b8'; }
        if (dom.taskSummary) { dom.taskSummary.textContent = open ? '' : taskFoldSummary(state); }

        if (remember && typeof window.qTaskFold === 'function') {
            window.qTaskFold(open);
        }
    }

    /*
     * A window can be resized without being reloaded, and the fold is a question
     * about the window. Asking it again costs two property writes, so it is asked
     * on every resize event rather than on a timer.
     */
    window.addEventListener('resize', function () {
        applyTaskOpen(taskOpenNow(), false);
        applyPanels();
    });

    /*
     * The side panels, and the button that shows and hides them.
     *
     * The one button means two things, because the panels are two things:
     *
     *   over 1024px   they sit beside the chat, so the button closes them and the
     *                 chat takes the whole window
     *   under it      they are a drawer, so the button opens them
     *
     * It used to toggle a single class that only the narrow stylesheet read — so on
     * the windows it was most visible in, it did nothing at all.
     *
     * Same rule as the Task settings fold: the window decides until the reader does,
     * and then the reader does.
     */
    var panelChoice = null;      // true/false: the reader decided. null: follow the window

    function panelsNarrow() {
        return window.innerWidth <= 1024;
    }

    /* Is the panel showing right now, for the window this is? */
    function panelsShowing(narrow) {
        return panelChoice === null ? !narrow : panelChoice;
    }

    function applyPanels() {
        var narrow = panelsNarrow();
        var show = panelsShowing(narrow);

        document.body.classList.toggle('panels-open', narrow && show);
        document.body.classList.toggle('panels-off', !narrow && !show);

        if (dom.panels) {
            dom.panels.setAttribute('aria-pressed', String(show));
            dom.panels.title = show ? 'hide the side panels' : 'show the side panels';
        }
    }

    dom.panels.onclick = function () {
        panelChoice = !panelsShowing(panelsNarrow());
        applyPanels();
    };

    document.addEventListener('click', function (event) {
        if (!document.body.classList.contains('panels-open')) { return; }
        if (event.target.closest && (event.target.closest('.side') || event.target.closest('#panels'))) { return; }
        panelChoice = false;
        applyPanels();
    });

    document.addEventListener('keydown', function (event) {
        // Escape closes the drawer, and only the drawer: on a wide window there is
        // nothing open to close, and hiding the panels there is not an escape.
        if (event.key !== 'Escape' || !document.body.classList.contains('panels-open')) { return; }
        panelChoice = false;
        applyPanels();
    });

    applyPanels();

    dom.modeChat.onclick = function () { showMode('chat', true); };
    dom.modeTask.onclick = function () { showMode('task', true); };
    dom.modeHistory.onclick = function () { showMode('history', true); };

    dom.openSkills.onclick = function () { window.qOpen(dom.skillsPath.title || ''); };
    dom.openChats.onclick = function () { window.qOpen(dom.chatsNote.title || ''); };
    dom.again.onclick = function () {
        dom.composerNote.textContent = 'asking again…';
        window.qChatRegenerate().then(function (result) {
            if (result && result.ok === false) { dom.composerNote.textContent = result.error || 'could not ask again'; }
            poll();
        });
    };

    dom.editq.onclick = function () {
        // The last question comes back into the box to be changed; sending it then
        // replaces it and everything that followed, rather than adding to it.
        var turns = dom.transcript.querySelectorAll('.turn.user .body');
        var last = turns[turns.length - 1];

        if (!last) { return; }

        dom.prompt.value = last.textContent || '';
        dom.prompt.focus();
        dom.composerNote.textContent = 'editing your last question — sending replaces it and the answers after it';
        editing = true;
    };

    dom.chatSearch.oninput = function () {
        var query = dom.chatSearch.value.trim();

        if (query.length < 2) {
            lastChats = '';
            poll();
            return;
        }

        window.qChatSearch(query).then(function (result) {
            var matches = (result && result.matches) || [];
            state = state || {};
            state.matches = matches;
            lastChats = '';
            renderChats(state);
        });
    };

    dom.modelCheck.onclick = function () {
        var name = dom.modelFetch.value.trim();

        if (!name) { return; }

        dom.modelNote.textContent = 'asking the registry about ' + name + '…';

        window.qModelCheck(name).then(function (result) {
            if (!result || result.ok === false) {
                dom.modelNote.textContent = (result && result.error) || 'could not size that up';
                return;
            }

            dom.modelNote.textContent = result.gigabytes + ' GB of weights ('
                + result.megabytes.toLocaleString() + ' MB, ' + result.layers + ' layers)'
                + (result.fits_weights === false
                    ? ' — more than the ' + Math.round(result.card_free_megabytes / 1024) + ' GB free on the card right now'
                    : (result.fits_weights === true ? ' — that fits the card' : ''))
                + '. ' + result.note;
        });
    };

    dom.modelPull.onclick = function () {
        var name = dom.modelFetch.value.trim();

        if (!name) { return; }

        dom.modelPull.disabled = true;
        dom.modelNote.textContent = 'starting the download…';

        window.qModelPull(name).then(function (result) {
            dom.modelPull.disabled = false;

            if (result && result.ok === false) {
                dom.modelNote.textContent = result.error || 'could not start it';
            }

            poll();
        });
    };

    dom.chatNew.onclick = function () {
        window.qChatNew().then(function () { lastChats = ''; poll(); });
    };
    dom.openImages.onclick = function () { window.qOpen(dom.imagesStat.title || ''); };

    /*
     * The leash, as the reader's own numbers.
     *
     * The profile stays the ceiling — the governor clamps whatever is asked for — so
     * this does not offer a window the profile cannot hold. What it does is stop the app
     * being *silent* about the one in use, which is how an agent comes to look forgetful
     * for no reason the reader can see. A larger window costs VRAM and time, not watts:
     * what heats the card is sustained duty, and the duty cycle does not depend on this.
     */
    function renderLeash(s) {
        var settings = s.settings || {};
        var agent = s.agent || {};
        var context = agent.context || {};
        var profile = (s.governor && s.governor.profile) || {};
        var asked = Number(settings.task_num_ctx || 0);
        var ceiling = Number(profile.num_ctx || 0);
        var steps = agent.max_steps || Number(settings.max_steps || 0) || 6;

        // Not while the reader is typing in them: a field that rewrites itself under the
        // cursor is worse than one that is a second out of date.
        if (dom.taskRoom && document.activeElement !== dom.taskRoom) { dom.taskRoom.value = String(asked); }
        if (dom.taskSteps && document.activeElement !== dom.taskSteps) { dom.taskSteps.value = String(steps); }

        if (dom.undoStat && dom.undoStat.textContent === '\u2014') {
            dom.undoStat.textContent = 'writes are checkpointed as they happen';
        }

        if (!dom.roomNote) { return; }

        var bits = [];

        if (asked > 0 && ceiling > 0 && asked > ceiling) {
            bits.push(asked.toLocaleString() + ' asked for, ' + ceiling.toLocaleString()
                + ' is this profile\'s ceiling, so the ceiling is what it gets — Fast is how you ask for a larger window');
        } else if (asked > 0) {
            bits.push(asked.toLocaleString() + ' tokens for this task'
                + (ceiling ? ' (the profile allows ' + ceiling.toLocaleString() + ')' : ''));
        } else if (ceiling) {
            bits.push('following the profile: ' + ceiling.toLocaleString() + ' tokens');
        }

        if (context.budget) {
            var trimmed = (context.shortened || 0) + (context.digested || 0) + (context.dropped || 0);

            bits.push('last task: ' + (context.used || 0).toLocaleString() + ' of '
                + Number(context.budget).toLocaleString() + ' tokens'
                + (trimmed
                    ? ' · shortened ' + (context.shortened || 0) + ', digested ' + (context.digested || 0)
                        + ' older result(s) into a line each, dropped ' + (context.dropped || 0)
                    : ' · nothing trimmed'));
        }

        bits.push('a larger window costs VRAM and time, not watts');

        dom.roomNote.textContent = bits.join(' · ');
    }

    dom.taskRoom.onchange = function () {
        if (typeof window.qTaskRoom !== 'function') { return; }

        window.qTaskRoom(Number(dom.taskRoom.value) || 0, Number(dom.taskSteps.value) || 0).then(function () { poll(); });
    };

    dom.taskSteps.onchange = dom.taskRoom.onchange;

    /*
     * Undo, next to the thing it undoes.
     *
     * One write at a time, deliberately: a button that rewrites forty files at once is a
     * bigger risk than the mistake it is there to catch. What it puts back is said in
     * the row, so the reader knows which change they just reversed.
     */
    dom.undoWrite.onclick = function () {
        if (typeof window.qUndo !== 'function') { return; }

        dom.undoWrite.disabled = true;
        dom.undoStat.textContent = 'undoing…';

        window.qUndo().then(function (result) {
            dom.undoWrite.disabled = false;
            dom.undoStat.textContent = (result && result.output) || 'nothing came back';

            if (result && result.held !== undefined) {
                dom.undoStat.textContent += ' (' + result.held + ' held)';
            }
        });
    };

    dom.taskToggle.onclick = function () {
        // "Follow the window" means whatever the window is showing, so the first tap
        // folds it even if that happens before the first poll. After a tap the reader
        // has decided: the window stops having an opinion, and the decision is
        // remembered for the next one.
        taskForced = true;
        taskChoice = !taskOpenNow();
        applyTaskOpen(taskChoice, true);
        poll();
    };

    dom.imageInstall.onclick = function () {
        dom.imageInstall.disabled = true;
        dom.imagesStat.textContent = 'installing an engine (a few gigabytes, several minutes)…';
        dom.taskNote.textContent = 'installing an image engine — progress is in the governor log';

        window.qImageInstall('sd').then(function (result) {
            dom.imageInstall.disabled = false;

            if (result && result.ok === false) {
                dom.taskNote.textContent = (result.error) || 'could not start the install';
            }

            poll();
        });
    };

    dom.imageServe.onclick = function () {
        dom.imageServe.disabled = true;
        dom.imagesStat.textContent = 'starting the engine… (this can take a minute)';

        window.qImageServe().then(function (result) {
            dom.imageServe.disabled = false;
            dom.composerNote.textContent = (result && result.ok)
                ? 'image engine ready at ' + (result.url || '') + (result.output ? ' — ' + result.output : '')
                : ((result && result.error) || 'the engine did not start');
            poll();
        });
    };

    dom.imageStops.onclick = function () {
        window.qImageStopServing().then(function () {
            dom.composerNote.textContent = 'image engine stopped';
            poll();
        });
    };

    function saveImageConfig() {
        window.qImageConfig({
            steps: Number(dom.imageSteps.value) || 20,
            size: Number(dom.imageSize.value) || 512
        }).then(poll);
    }

    dom.imageSteps.onchange = saveImageConfig;
    dom.imageSize.onchange = saveImageConfig;
    dom.indexDocuments.onclick = function () {
        dom.indexDocuments.disabled = true;
        runIndex();
        setTimeout(function () { dom.indexDocuments.disabled = false; }, 1500);
    };
    dom.openSessions.onclick = function () { window.qOpen(dom.sessionsPath.textContent); };
    dom.openMcp.onclick = function () { window.qOpen(dom.mcpStat.title || ''); };

    dom.jobForm.addEventListener('submit', function (event) {
        event.preventDefault();

        var task = dom.jobTask.value.trim();

        if (!task) { return; }

        window.qJobSave({
            task: task,
            every_minutes: Number(dom.jobEvery.value) || 60,
            at: dom.jobAt.value.trim(),
            model: dom.model.value
        }).then(function (result) {
            dom.jobsNote.textContent = (result && result.ok === false)
                ? (result.error || 'could not save')
                : 'saved — it runs when the machine is idle';
            dom.jobTask.value = '';
            dom.jobAt.value = '';
            lastJobs = '';
            poll();
        });
    });

    dom.chooseWorkspace.onclick = function () {
        dom.taskNote.textContent = 'waiting for the folder picker…';
        window.qChooseWorkspace().then(function (result) {
            dom.taskNote.textContent = (result && result.ok)
                ? 'working folder set: ' + (result.workspace || '')
                : ((result && result.error) || 'no folder chosen');
            poll();
        });
    };

    dom.clearWorkspace.onclick = function () {
        window.qWorkspace(null).then(poll);
    };

    dom.webToggle.onchange = function () {
        window.qWeb(dom.webToggle.checked).then(poll);
    };

    dom.shellToggle.onchange = function () {
        window.qShell(dom.shellToggle.checked).then(poll);
    };

    function answerApproval(allowed, card) {
        card.querySelectorAll('button').forEach(function (button) { button.disabled = true; });
        dom.taskNote.textContent = allowed ? 'allowed — running' : 'refused';
        window.qApprove(allowed).then(poll);
    }

    dom.taskForm.addEventListener('submit', function (event) {
        event.preventDefault();

        var task = dom.taskInput.value.trim();

        if (!task) {
            return;
        }

        if (slashCommand(task)) {
            dom.taskInput.value = '';
            return;
        }

        dom.taskNote.textContent = 'starting…';

        window.qTask({ task: task, model: dom.model.value }).then(function (result) {
            if (result && result.ok === false) {
                dom.taskNote.textContent = result.error || 'the task could not start';
            }

            poll();
        });
    });

    dom.taskStop.onclick = function () {
        window.qTaskStop().then(poll);
    };

    dom.taskClear.onclick = function () {
        window.qTaskForget().then(poll);
    };

    dom.forget.onclick = function () {
        window.qForget().then(poll);
    };

    dom.model.onchange = function () {
        window.qModel(dom.model.value).then(poll);
    };

    dom.load.onclick = function () {
        var name = dom.model.value;
        if (!name) { return; }
        dom.composerNote.textContent = 'loading ' + name + ' into VRAM…';
        window.qLoad(name).then(function (result) {
            dom.composerNote.textContent = result && result.ok ? name + ' is resident' : 'load failed';
            poll();
        });
    };

    dom.unload.onclick = function () {
        var name = dom.model.value;
        if (!name) { return; }
        window.qUnload(name).then(function () {
            dom.composerNote.textContent = 'emptied ' + name;
            poll();
        });
    };

    [dom.endpointOllama, dom.endpointOpenai].forEach(function (node) {
        node.onclick = function () {
            var value = node.textContent.split('=').slice(1).join('=');
            if (!navigator.clipboard) { return; }
            navigator.clipboard.writeText(value).then(function () {
                node.classList.add('copied');
                setTimeout(function () { node.classList.remove('copied'); }, 900);
            });
        };
    });

    /* ---------- the poll ---------- */

    function poll() {
        return window.qState().then(function (snapshot) {
            state = snapshot;
            render(snapshot);
        }).catch(function (error) {
            dom.status.textContent = 'the window lost contact with the application: ' + error;
        });
    }

    /* ---------- start, whenever the binding surface exists ---------- */

    var waited = 0;
    var waiting = null;

    function start() {
        if (typeof window.qState !== 'function') {
            return false;
        }

        poll();
        setInterval(poll, 1000);

        return true;
    }

    window.addEventListener('error', function (event) {
        dom.status.textContent = 'the window failed: ' + (event.message || 'unknown error');
        document.title = 'Quiesce — ' + (event.message || 'error');
    });

    if (!start()) {
        // The bindings are injected when the DOM is ready, and this script can
        // beat them there. Waiting for them is cheap; calling a function that
        // does not exist yet is how a window renders nothing at all.
        dom.status.textContent = 'waiting for the application…';

        waiting = setInterval(function () {
            waited += 250;

            if (start()) {
                clearInterval(waiting);
                dom.status.textContent = '';

                return;
            }

            if (waited > 30_000) {
                clearInterval(waiting);
                dom.status.textContent = 'the window could not reach the application';
                document.title = 'Quiesce — no bindings after 30s';
            }
        }, 250);
    }
})();
