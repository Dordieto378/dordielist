export function createConfirmationHandler(ask) {
    const approved = new WeakSet();
    const pending = new WeakSet();

    return async event => {
        const form = event.target;
        if (!form?.matches?.('form[data-confirm]') || approved.has(form) || event.defaultPrevented) return;
        event.preventDefault();
        if (pending.has(form)) return;
        pending.add(form);

        try {
            if (await ask(form.dataset.confirm) && form.isConnected) {
                approved.add(form);
                try {
                    form.requestSubmit(event.submitter ?? undefined);
                } finally {
                    approved.delete(form);
                }
            }
        } finally {
            pending.delete(form);
        }
    };
}

export function initAppDialogs() {
    const modal = document.getElementById('appDialogModal');
    if (!modal) return;
    const title = document.getElementById('appDialogTitle');
    const message = document.getElementById('appDialogMessage');
    const cancel = document.getElementById('appDialogCancel');
    const confirm = document.getElementById('appDialogConfirm');
    const queue = [];
    let active = null;
    let previousFocus;
    let wasLocked;

    const openNext = () => {
        if (active || !queue.length) return;
        active = queue.shift();
        previousFocus = document.activeElement;
        wasLocked = document.body.classList.contains('overflow-hidden');
        title.textContent = active.confirmation ? 'Confirm action' : 'Error';
        message.textContent = active.message;
        cancel.classList.toggle('hidden', !active.confirmation);
        confirm.textContent = active.confirmation ? 'Continue' : 'OK';
        modal.classList.remove('hidden');
        modal.scrollTop = 0;
        document.body.classList.add('overflow-hidden');
        (active.confirmation ? cancel : confirm).focus();
    };
    const show = (text, confirmation) => new Promise(resolve => {
        queue.push({ message: String(text), confirmation, resolve });
        openNext();
    });
    const close = result => {
        if (!active) return;
        const current = active;
        active = null;
        modal.classList.add('hidden');
        document.body.classList.toggle('overflow-hidden', wasLocked);
        if (previousFocus?.isConnected) previousFocus.focus();
        current.resolve(result);
        openNext();
    };

    cancel.addEventListener('click', () => close(false));
    confirm.addEventListener('click', () => close(true));
    modal.addEventListener('click', event => {
        if (event.target === modal) close(false);
    });
    modal.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            event.stopPropagation();
            close(false);
        }
        if (event.key === 'Tab' && active) {
            const first = active.confirmation ? cancel : confirm;
            if ((event.shiftKey && document.activeElement === first) || (!event.shiftKey && document.activeElement === confirm)) {
                event.preventDefault();
                (event.shiftKey ? confirm : first).focus();
            }
        }
    });
    window.showAppAlert = text => show(text, false);
    document.addEventListener('submit', createConfirmationHandler(text => show(text, true)));
}
