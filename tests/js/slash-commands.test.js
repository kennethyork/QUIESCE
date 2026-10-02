/**
 * The window's slash commands, tested without a window.
 *
 * Driving the real WebView with synthetic clicks proved unreliable — the layout
 * shifts between runs, so a click that lands on the composer in one run lands on
 * the steps list in the next. This runs `app.js` against a stub DOM instead, and
 * checks the thing that actually matters: that typing a command calls the right
 * binding, and that what comes back is written where the reader is looking.
 *
 *     node tests/js/slash-commands.test.js
 */

'use strict';

const fs = require('fs');
const path = require('path');
const assert = require('assert');

const source = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'private', 'js', 'app.js'), 'utf8');

/* ---------- a DOM just real enough ---------- */

function element(tag) {
    const node = {
        tagName: tag,
        id: '',
        textContent: '',
        innerHTML: '',
        value: '',
        checked: false,
        hidden: false,
        disabled: false,
        title: '',
        dataset: {},
        style: {},
        children: [],
        parentNode: null,
        listeners: {},
        classes: new Set(),
        classList: {
            add: (name) => node.classes.add(name),
            remove: (name) => node.classes.delete(name),
            contains: (name) => node.classes.has(name),
            // The second argument is `force`, and it is not optional in the DOM: with
            // `force` false, `toggle` *removes* the class. This stub ignored it, which
            // made every `toggle(name, false)` add the class instead — and accused the
            // application of a bug it did not have, in the one place the two widths are
            // told apart.
            toggle(name, force) {
                const wanted = force === undefined ? !node.classes.has(name) : Boolean(force);

                if (wanted) { node.classes.add(name); } else { node.classes.delete(name); }

                return wanted;
            },
        },
        appendChild(child) {
            child.parentNode = node;
            node.children.push(child);
            return child;
        },
        insertBefore(child, before) {
            child.parentNode = node;
            node.children.unshift(child);
            return child;
        },
        get firstChild() { return node.children[0] || null; },
        remove() {},
        querySelector: () => null,
        querySelectorAll: () => [],
        addEventListener(name, handler) { (node.listeners[name] = node.listeners[name] || []).push(handler); },
        removeEventListener() {},
        setAttribute(name, value) { node[name] = value; },
        getAttribute(name) { return node[name]; },
        requestSubmit() { node.fire('submit'); },
        fire(name, event) {
            const fake = event || { preventDefault() {} };

            // A browser fires the `on<event>` property as well as the listeners.
            // This stub got that wrong twice — first by skipping the property
            // entirely, then by looking for `node.click` instead of `node.onclick`
            // — and both times it accused the application of a bug it did not have.
            const property = 'on' + name;

            if (typeof node[property] === 'function') {
                node[property](fake);
            }

            (node.listeners[name] || []).forEach((handler) => handler(fake));
        },
        focus() {},
        scrollTop: 0,
        scrollHeight: 0,
    };

    return node;
}

const byId = {};
const bySelector = {};
const documentListeners = {};
const queries = {};
const document = {
    body: element('body'),
    addEventListener(name, handler) { (documentListeners[name] = documentListeners[name] || []).push(handler); },
    fire(name, event) { (documentListeners[name] || []).forEach((handler) => handler(event || { preventDefault() {} })); },
    getElementById(id) {
        if (!byId[id]) {
            byId[id] = element('div');
            byId[id].id = id;
        }

        return byId[id];
    },
    querySelector(selector) {
        if (!bySelector[selector]) { bySelector[selector] = element('div'); }
        return bySelector[selector];
    },
    querySelectorAll() { return []; },
    createElement: element,
    activeElement: null,
};

const calls = [];
const mediaQuery = (matches) => ({
    matches,
    addEventListener() {},
    removeEventListener() {},
});

const resizeHandlers = [];

const window = {
    document,
    // One object per query, kept: a real MediaQueryList is a live object whose
    // `matches` changes as the window does, so the app holds a reference to it.
    matchMedia(query) {
        if (!queries[query]) { queries[query] = mediaQuery(false); }
        return queries[query];
    },
    dispatchResize() { resizeHandlers.forEach((handler) => handler({})); },
    addEventListener(name, handler) { if (name === 'resize') { resizeHandlers.push(handler); } },
    setInterval() { return 1; },
    clearInterval() {},
    setTimeout() { return 1; },
    navigator: {},
    boson: { rpc: { call() {} } },
};

const binding = (name) => (...args) => {
    calls.push({ name, args });
    return Promise.resolve({ ok: true, output: 'stub' });
};

[
    'qState', 'qHardware', 'qModels', 'qSend', 'qStop', 'qForget', 'qProfile', 'qModel',
    'qLoad', 'qUnload', 'qServe', 'qLog', 'qTask', 'qTaskStop', 'qTaskForget', 'qApprove',
    'qShell', 'qMode', 'qChooseWorkspace', 'qWorkspace', 'qWeb', 'qSearchUrl', 'qSessions',
    'qSession', 'qSessionDelete', 'qResume', 'qSkills', 'qDocuments', 'qSearch', 'qJobs',
    'qJobSave', 'qJobToggle', 'qJobRemove', 'qJobRun', 'qMcp', 'qOpen', 'qImage', 'qImageStop',
    'qImageConfig', 'qLook', 'qIndex', 'qForgetIndex', 'qZoom', 'qTaskFold',
].forEach((name) => { window[name] = binding(name); });

/* ---------- load the window's script ---------- */

const run = new Function('window', 'document', 'navigator', 'setInterval', 'clearInterval', 'setTimeout', source);
run(window, document, window.navigator, window.setInterval, window.clearInterval, window.setTimeout);

const last = (name) => [...calls].reverse().find((call) => call.name === name);

/* ---------- the tests ---------- */

let failures = 0;

function test(name, body) {
    try {
        body();
        console.log('  ok  ' + name);
    } catch (error) {
        failures++;
        console.log('  FAIL ' + name + '\n       ' + error.message);
    }
}

console.log('slash commands, against a stub DOM:');

test('/create_image calls the image binding with the prompt', () => {
    calls.length = 0;
    document.getElementById('task-input').value = '/create_image a red cube on a wooden table';
    document.getElementById('task-form').fire('submit');

    const call = last('qImage');

    assert.ok(call, 'qImage was never called');
    assert.strictEqual(call.args[0].prompt, 'a red cube on a wooden table');
});

test('/look calls the vision binding with a path and a question', () => {
    calls.length = 0;
    document.getElementById('task-input').value = '/look pictures/chart.png what does this show?';
    document.getElementById('task-form').fire('submit');

    const call = last('qLook');

    assert.ok(call, 'qLook was never called');
    assert.strictEqual(call.args[0].path, 'pictures/chart.png');
    assert.strictEqual(call.args[0].question, 'what does this show?');
});

test('/index calls the indexing binding', () => {
    calls.length = 0;
    document.getElementById('task-input').value = '/index';
    document.getElementById('task-form').fire('submit');

    assert.ok(last('qIndex'), 'qIndex was never called');
});

test('an ordinary task is sent to the agent, not to a command', () => {
    calls.length = 0;
    document.getElementById('task-input').value = 'summarise notes.md into summary.md';
    document.getElementById('task-form').fire('submit');

    const call = last('qTask');

    assert.ok(call, 'qTask was never called');
    assert.strictEqual(call.args[0].task, 'summarise notes.md into summary.md');
    assert.strictEqual(last('qImage'), undefined, 'a plain task must not reach the image path');
});

test('an unknown slash command is passed to the model rather than swallowed', () => {
    calls.length = 0;
    document.getElementById('task-input').value = '/summarise the folder';
    document.getElementById('task-form').fire('submit');

    assert.ok(last('qTask'), 'an unknown command should not be treated as a command');
});

test('what comes back lands in the view the reader is looking at', () => {
    calls.length = 0;
    const steps = document.getElementById('steps');

    // The window is in Task mode; a command's output belongs in the steps pane.
    document.getElementById('mode-task').fire('click');

    const before = steps.children.length;
    const transcript = document.getElementById('transcript');
    const beforeTranscript = transcript.children.length;

    document.getElementById('task-input').value = '/look pictures/chart.png';
    document.getElementById('task-form').fire('submit');

    assert.strictEqual(document.getElementById('view-chat').hidden, true, 'the chat pane should be out of the way');
    assert.strictEqual(document.getElementById('view-task').hidden, false, 'the task pane should be the one showing');
    assert.strictEqual(transcript.children.length, beforeTranscript, 'and nothing should go to the hidden pane');

    assert.ok(steps.children.length > before, 'the command said nothing in the task pane');
});

test('the panels button opens and closes the drawer on a narrow window', () => {
    const panels = document.getElementById('panels');
    const body = document.body;

    // Narrow enough to be a drawer. The window size is not incidental: the same
    // button does the opposite on a wide window, on purpose.
    window.innerWidth = 760;
    body.classes.clear();
    panels.fire('click');
    assert.ok(body.classes.has('panels-open'), 'the drawer should open on a narrow window');
    assert.strictEqual(panels['aria-pressed'], 'true');

    panels.fire('click');
    assert.ok(!body.classes.has('panels-open'), 'and close again');
    assert.strictEqual(panels['aria-pressed'], 'false');
});

test('the panels button hides the panels on a wide window', () => {
    // This is what "it does nothing" was: the button toggled `panels-open`, a class
    // only the narrow stylesheet reads, so on a window wide enough to show the panels
    // beside the chat, pressing it changed nothing anyone could see.
    //
    // Which state the window is in to begin with is not this test's business — the
    // reader may have left the panels closed. That a click changes it, and changes it
    // back, is.
    const panels = document.getElementById('panels');
    const body = document.body;

    window.innerWidth = 1400;
    body.classes.clear();

    // A window that changed size says so, and that is what puts the classes in sync:
    // the reader's last decision was made about a different window. So this line is the
    // assertion that a resize re-applies it, not just tidying.
    window.dispatchResize();

    const before = body.classes.has('panels-off');

    panels.fire('click');
    assert.notStrictEqual(body.classes.has('panels-off'), before, 'a click should change it on a wide window');
    assert.ok(!body.classes.has('panels-open'), 'a wide window has no drawer for it to open');
    assert.strictEqual(panels['aria-pressed'], String(!body.classes.has('panels-off')), 'the pressed state follows the panels');

    panels.fire('click');
    assert.strictEqual(body.classes.has('panels-off'), before, 'and change it back');
});

test('escape closes the drawer, and only the drawer', () => {
    document.body.classes.add('panels-open');
    document.fire('keydown', { key: 'Escape' });
    assert.ok(!document.body.classes.has('panels-open'), 'escape should close it');

    // On a wide window there is nothing open to close: escape must not hide a panel
    // the reader is looking at.
    window.innerWidth = 1400;
    document.body.classes.clear();
    document.fire('keydown', { key: 'Escape' });
    assert.ok(!document.body.classes.has('panels-off'), 'escape must not close the panels on a wide window');
});

/*
 * These two come before the fold test on purpose: a tap is a decision, and a
 * decision outranks the window (see the fold rules in app.js). The
 * window-following rule therefore has to be checked before anything taps it.
 */
test('the task settings follow a window that is resized', () => {
    const bar = document.getElementById('task-bar');

    window.innerWidth = 1400;
    window.innerHeight = 900;
    window.dispatchResize();
    assert.strictEqual(bar.dataset.open, 'true', 'the rows should be open on a window that can hold them');

    // The reported bug: the fold was worked out once and never revisited, so a
    // window dragged to half its height kept the arrangement it was drawn with.
    window.innerWidth = 760;
    window.innerHeight = 480;
    window.dispatchResize();
    assert.strictEqual(bar.dataset.open, 'false', 'and folded when the window is dragged small');

    window.innerWidth = 1400;
    window.innerHeight = 900;
    window.dispatchResize();
    assert.strictEqual(bar.dataset.open, 'true', 'and open again when it is dragged back');
});

test('a tap on the settings outranks the window', () => {
    const bar = document.getElementById('task-bar');
    const toggle = document.getElementById('task-toggle');

    window.innerWidth = 760;
    window.innerHeight = 480;
    window.dispatchResize();
    assert.strictEqual(bar.dataset.open, 'false', 'a small window folds the rows');

    calls.length = 0;
    toggle.fire('click');
    assert.strictEqual(bar.dataset.open, 'true', 'a tap opens them anyway');

    window.dispatchResize();
    assert.strictEqual(bar.dataset.open, 'true', 'and the window does not overrule the reader');

    const remembered = last('qTaskFold');
    assert.ok(remembered, 'the decision was never written down');
    assert.strictEqual(remembered.args[0], true, 'and what was written down is what was tapped');
});

test('the task settings fold and unfold in place', () => {
    // The full Task tab has to be complete at any window size, so the settings fold
    // to a line that says what is inside — they do not leave the tab.
    const bar = document.getElementById('task-bar');
    const toggle = document.getElementById('task-toggle');

    // What the window is showing to begin with is not the test's business; that a
    // click changes it, and changes it back, is.
    const before = bar.dataset.open === 'true';

    toggle.fire('click');
    assert.notStrictEqual(bar.dataset.open === 'true', before, 'a click should change it');
    assert.strictEqual(toggle['aria-expanded'], bar.dataset.open);

    toggle.fire('click');
    assert.strictEqual(bar.dataset.open === 'true', before, 'and change it back');
});

test('the composer is cleared after a command', () => {
    const input = document.getElementById('task-input');
    input.value = '/create_image a cat';
    document.getElementById('task-form').fire('submit');

    assert.strictEqual(input.value, '', 'the command text should not stay in the box');
});

console.log(failures === 0 ? '\nall good' : '\n' + failures + ' failed');
process.exit(failures === 0 ? 0 : 1);
