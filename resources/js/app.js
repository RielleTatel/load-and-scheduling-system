

import Alpine from 'alpinejs';
import { initializeBrandedSelects } from './branded-select';

window.Alpine = Alpine;

window.timetableEditor = (config) => ({
    sequence: config.sequence,
    subjects: config.subjects,
    updateTemplate: config.updateTemplate,
    undoUrl: config.undoUrl,
    open: false,
    busy: false,
    error: '',
    entryId: null,
    sectionId: null,
    sourceDay: 1,
    sourcePeriod: 1,
    currentSubject: '',
    action: 'replace',
    subjectCode: '',
    targetDay: 1,
    targetPeriod: 1,
    dragged: null,
    dropTarget: null,

    startDrag(event, entryId, sectionId, day) {
        if (this.busy) { event.preventDefault(); return; }
        this.dragged = { entryId, sectionId, day };
        event.dataTransfer.setData('application/json', JSON.stringify(this.dragged));
        event.dataTransfer.effectAllowed = 'move';
    },

    endDrag() { this.dragged = null; this.dropTarget = null; },

    hoverDrop(event, entryId, sectionId, day) {
        if (this.dragged && !this.busy && this.dragged.sectionId === sectionId && this.dragged.day === day && this.dragged.entryId !== entryId) {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            this.dropTarget = entryId;
        }
    },

    edit(entryId, sectionId, day, period, subjectCode) {
        this.entryId = entryId;
        this.sectionId = sectionId;
        this.sourceDay = day;
        this.sourcePeriod = period;
        this.currentSubject = subjectCode;
        this.subjectCode = subjectCode;
        this.targetDay = day;
        this.targetPeriod = period;
        this.action = 'replace';
        this.error = '';
        this.open = true;
        this.$nextTick(() => document.dispatchEvent(new Event('branded-select-sync')));
    },

    async dropSwap(event, targetEntryId, targetSectionId, targetDay, targetPeriod) {
        event.preventDefault();
        let dragged;
        try {
            dragged = JSON.parse(event.dataTransfer.getData('application/json'));
        } catch {
            this.endDrag();
            return;
        }
        this.endDrag();
        if (this.busy) return;
        if (!dragged || dragged.sectionId !== targetSectionId || dragged.day !== targetDay || dragged.entryId === targetEntryId) {
            return;
        }
        await this.send(this.updateTemplate.replace('ENTRY_ID', dragged.entryId), 'PUT', {
            action: 'swap', expected_sequence: this.sequence, target_day: targetDay, target_period: targetPeriod,
        });
    },

    async save() {
        this.error = '';
        const body = { action: this.action, expected_sequence: this.sequence };
        if (this.action === 'replace') {
            body.subject_code = this.subjectCode;
        } else {
            body.target_day = Number(this.targetDay);
            body.target_period = Number(this.targetPeriod);
        }
        await this.send(this.updateTemplate.replace('ENTRY_ID', this.entryId), 'PUT', body);
    },

    async undo() {
        this.error = '';
        await this.send(this.undoUrl, 'POST', { expected_sequence: this.sequence });
    },

    async send(url, method, body) {
        if (this.busy) return;
        this.busy = true;
        try {
            const response = await fetch(url, {
                method,
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify(body),
            });
            const result = await response.json();
            if (!response.ok) {
                this.error = result.message || 'The change could not be saved. Refresh the page and try again.';
                return;
            }
            this.sequence = result.sequence;
            this.open = false;
            const pageResponse = await fetch(window.location.href, { headers: { 'Accept': 'text/html' } });
            if (!pageResponse.ok) throw new Error('Refresh failed');
            const page = new DOMParser().parseFromString(await pageResponse.text(), 'text/html');
            for (const selector of ['[data-timetable-grid]', '[data-schedule-validation]']) {
                const current = document.querySelector(selector);
                const updated = page.querySelector(selector);
                if (!current || !updated) throw new Error('Missing schedule');
                current.replaceWith(updated);
            }
            const approval = document.querySelector('[data-schedule-approve]');
            const newApproval = page.querySelector('[data-schedule-approve]');
            if (approval && newApproval) {
                approval.disabled = newApproval.disabled;
                approval.title = newApproval.title;
            }
        } catch {
            this.error = 'The server could not be reached. Refresh the page to check the current timetable.';
        } finally {
            this.busy = false;
        }
    },
});

window.timetableGeneration = (url) => ({
    stage: 'Getting ready to start', elapsed: 0, percent: 0, connectionError: false, stopped: false, timer: null, clock: null,
    get elapsedLabel() {
        const seconds = Math.max(0, Math.floor(this.elapsed));
        return Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
    },
    init() { this.poll(); this.clock = setInterval(() => this.elapsed++, 1000); },
    destroy() { this.stopped = true; clearTimeout(this.timer); clearInterval(this.clock); },
    async poll() {
        try {
            const response = await fetch(url, { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
            if (!response.ok) throw new Error('Status unavailable');
            const result = await response.json();
            if (this.stopped) return;
            this.connectionError = false;
            if (result.status !== 'generating') { window.location.reload(); return; }
            this.stage = result.stage;
            this.elapsed = result.elapsed_seconds;
            this.percent = result.check_percent ?? 0;
        } catch { this.connectionError = true; }
        if (!this.stopped) this.timer = setTimeout(() => this.poll(), 2500);
    },
});

const confirmationDialog = document.getElementById('app-confirm-dialog');
if (confirmationDialog) {
    const title = confirmationDialog.querySelector('#app-confirm-title');
    const message = confirmationDialog.querySelector('#app-confirm-message');
    const accept = confirmationDialog.querySelector('[data-confirm-accept]');
    const cancel = confirmationDialog.querySelector('[data-confirm-cancel]');
    const icons = confirmationDialog.querySelectorAll('[data-confirm-icon]');
    const confirmedForms = new WeakSet();
    let pendingForm = null;
    let pendingSubmitter = null;

    document.addEventListener('submit', (event) => {
        const form = event.target.closest('form[data-confirm]');
        if (!form) return;
        if (confirmedForms.has(form)) {
            confirmedForms.delete(form);
            return;
        }

        event.preventDefault();
        pendingForm = form;
        pendingSubmitter = event.submitter;
        title.textContent = form.dataset.confirmTitle || 'Please confirm';
        message.textContent = form.dataset.confirm;
        accept.textContent = form.dataset.confirmLabel || 'Continue';
        accept.classList.toggle('is-danger', form.dataset.confirmTone === 'danger');
        confirmationDialog.dataset.tone = form.dataset.confirmTone || 'primary';
        const iconName = form.dataset.confirmIcon || (form.dataset.confirmTone === 'danger' ? 'warning' : 'publish');
        icons.forEach((icon) => { icon.toggleAttribute('hidden', icon.dataset.confirmIcon !== iconName); });
        if (!confirmationDialog.open) confirmationDialog.showModal();
        (form.dataset.confirmTone === 'danger' ? cancel : accept).focus();
    });

    const closeDialog = () => {
        pendingForm = null;
        pendingSubmitter = null;
        if (confirmationDialog.open) confirmationDialog.close();
    };

    cancel.addEventListener('click', closeDialog);
    confirmationDialog.querySelector('[data-confirm-dismiss]').addEventListener('click', closeDialog);
    confirmationDialog.addEventListener('cancel', () => {
        pendingForm = null;
        pendingSubmitter = null;
    });
    accept.addEventListener('click', () => {
        const form = pendingForm;
        const submitter = pendingSubmitter;
        closeDialog();
        if (!form || !form.isConnected) return;
        confirmedForms.add(form);
        if (submitter && submitter.isConnected) form.requestSubmit(submitter);
        else form.requestSubmit();
        // requestSubmit dispatches synchronously when the form is valid.
        confirmedForms.delete(form);
    });
}

Alpine.start();
initializeBrandedSelects();
