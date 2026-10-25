import assert from 'node:assert/strict';
import test from 'node:test';
import { createConfirmationHandler } from '../../resources/js/app-dialog.js';

function form() {
    return {
        dataset: { confirm: 'Delete this entry?' },
        isConnected: true,
        matches: selector => selector === 'form[data-confirm]',
        submissions: [],
        requestSubmit(button) { this.submissions.push(button); },
    };
}

function submit(target, submitter) {
    return {
        target, submitter, defaultPrevented: false,
        preventDefault() { this.defaultPrevented = true; },
    };
}

test('cancelling a confirmation prevents submitting the form', async () => {
    const target = form();
    const event = submit(target);
    const handler = createConfirmationHandler(async message => {
        assert.equal(message, target.dataset.confirm);
        return false;
    });
    await handler(event);
    assert.equal(event.defaultPrevented, true);
    assert.equal(target.submissions.length, 0);
});

test('confirmation submits once with the original button and does not ask again during resubmission', async () => {
    const target = form();
    const button = {};
    let questions = 0;
    const handler = createConfirmationHandler(async () => { questions++; return true; });
    target.requestSubmit = actualButton => {
        assert.equal(actualButton, button);
        const resubmission = submit(target, actualButton);
        handler(resubmission);
        assert.equal(resubmission.defaultPrevented, false);
        target.submissions.push(actualButton);
    };
    await handler(submit(target, button));
    assert.equal(questions, 1);
    assert.equal(target.submissions.length, 1);
    await handler(submit(target, button));
    assert.equal(questions, 2);
    assert.equal(target.submissions.length, 2);
});

test('repeated clicks while a confirmation is open do not submit or open another confirmation', async () => {
    const target = form();
    let resolveQuestion;
    let questions = 0;
    const handler = createConfirmationHandler(() => {
        questions++;
        return new Promise(resolve => { resolveQuestion = resolve; });
    });
    const first = handler(submit(target));
    const secondEvent = submit(target);
    await handler(secondEvent);
    assert.equal(secondEvent.defaultPrevented, true);
    assert.equal(questions, 1);
    resolveQuestion(false);
    await first;
    assert.equal(target.submissions.length, 0);
});

test('a form removed while confirmation is pending cannot be submitted', async () => {
    const target = form();
    const handler = createConfirmationHandler(async () => { target.isConnected = false; return true; });
    await handler(submit(target));
    assert.equal(target.submissions.length, 0);
});

test('normal forms and submissions handled elsewhere keep their current behavior', async () => {
    let questions = 0;
    const handler = createConfirmationHandler(async () => { questions++; return true; });
    const normal = form();
    normal.matches = () => false;
    const normalEvent = submit(normal);
    await handler(normalEvent);
    assert.equal(normalEvent.defaultPrevented, false);
    const handledEvent = submit(form());
    handledEvent.preventDefault();
    await handler(handledEvent);
    assert.equal(questions, 0);
});
