import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeSignedDocument } from '../../resources/js/ata-signed-document.js';

class Element extends EventTarget {
    emit(type, properties = {}) {
        const event = Object.assign(new Event(type), properties);
        this.dispatchEvent(event);
    }
}

test('saves the marked ATA page coordinates without opening every page', t => exerciseSignedControl(t, false));
test('scopes signed PDF links and requests to the selected amendment', t => exerciseSignedControl(t, true));

async function exerciseSignedControl(t, amendment) {
    const root = new Element();
    root.dataset = { ataLoaded: '81', ataVersion: '6', csrfToken: 'test' };
    const control = new Element();
    control.closest = () => root;
    const template = amendment ? '/atas/0/amendments/0/signed-document' : '/atas/0/signed-document';
    const endpoint = amendment ? '/atas/81/amendments/15/signed-document' : '/atas/81/signed-document';
    control.dataset = { previewUrl: template + '/preview', storeUrl: template, removeUrl: template,
        originalUrl: template + '/original', redactedUrl: template + '/redacted',
        ...(amendment ? { signedAmendment: '' } : {}) };
    const elements = new Map();
    const find = selector => {
        if (!elements.has(selector)) elements.set(selector, new Element());
        return elements.get(selector);
    };
    control.querySelector = find;
    control.ownerDocument = { querySelector: find };
    const downloads = find(amendment ? '[data-amendment-signed-downloads]' : '[data-signed-downloads]');
    downloads.querySelector = find;
    const input = find('input[type="file"]');
    input.files = [new File(['synthetic'], 'assinada.pdf')];
    const canvas = find('canvas');
    canvas.getContext = () => ({ drawImage() {}, fillRect() {}, clearRect() {} });
    canvas.getBoundingClientRect = () => ({ left: 0, top: 0, width: 100, height: 100 });
    canvas.setPointerCapture = () => {};
    const dialog = find('dialog');
    dialog.showModal = () => { dialog.open = true; };
    dialog.close = () => { dialog.open = false; dialog.emit('close'); };
    const uploadChoice = find('[data-signed-upload-choice]');
    uploadChoice.showModal = () => { uploadChoice.open = true; };
    const removal = find('[data-signed-removal]');
    removal.showModal = () => { removal.open = true; };
    removal.close = () => { removal.open = false; };
    const closeRemoval = find('[data-signed-removal-close]');
    let closeFocused = false;
    closeRemoval.focus = () => { closeFocused = true; };
    for (const kind of ['original', 'redacted']) {
        find(`[data-signed-row="${kind}"]`).querySelector = selector => find(`${kind} ${selector}`);
    }
    let removals = 0;
    let saves = 0;
    let finishFirstSave;
    t.mock.method(globalThis, 'fetch', async (url, { body, method }) => {
        if (method === 'DELETE') {
            removals++;
            assert.equal(url, endpoint);
            assert.deepEqual(JSON.parse(body), { version: '7', copy: 'original' });
            return Response.json({ version: 8, signed_document_copies: ['redacted'] });
        }
        if (body.get('mode') === 'original') {
            assert.equal(body.get('version'), '8');
            assert.equal(body.get('pdf').name, 'assinada.pdf');
            assert.equal(body.has('reviewed'), false);
            return Response.json({ version: 9, signed_document_copies: ['original'], can_create_redacted_copy: true }, { status: 201 });
        }
        if (body.get('mode') === 'stored') {
            assert.equal(body.get('version'), '9');
            assert.equal(body.has('pdf'), false);
            assert.match(url, /\/preview$/);
            return Response.json({ sha256: 'stored-digest', pages: ['page-1'] });
        }
        if (url.endsWith('/preview')) {
            assert.equal(body.get('version'), '6');
            return Response.json({ sha256: 'digest', pages: ['page-1', 'page-2', 'page-3'] });
        }
        assert.equal(url, endpoint);
        assert.equal(body.get('pdf').name, 'assinada.pdf');
        assert.equal(body.get('sha256'), 'digest');
        assert.equal(body.get('pages'), '3');
        assert.equal(body.get('reviewed'), '1');
        assert.deepEqual([...body.entries()].filter(([key]) => key.startsWith('rectangles')), [
            ['rectangles[0][page]', '2'], ['rectangles[0][left]', '0.2'], ['rectangles[0][top]', '0.1'],
            ['rectangles[0][right]', '0.8'], ['rectangles[0][bottom]', '0.3'],
        ]);
        if (++saves === 1) return new Promise(resolve => { finishFirstSave = resolve; });
        return Response.json({ version: 7, signed_document_copies: ['original', 'redacted'] }, { status: 201 });
    });
    globalThis.Image = class {
        naturalWidth = 100;
        naturalHeight = 100;
        set src(value) { this.source = value; queueMicrotask(() => this.onload()); }
        getAttribute() { return this.source; }
        removeAttribute() { this.source = ''; }
    };
    t.after(() => { delete globalThis.Image; });
    const tick = () => new Promise(resolve => setImmediate(resolve));

    initializeSignedDocument(control);
    if (amendment) {
        input.emit('change');
        assert.equal(uploadChoice.open, undefined);
        control.emit('ata-signed-amendment-context', { detail: { id: 15, number: '2', copies: [] } });
    }
    input.emit('change');
    assert.equal(uploadChoice.open, true);
    uploadChoice.returnValue = 'pair';
    uploadChoice.open = false;
    uploadChoice.emit('close');
    await tick();
    const confirm = find('[data-signed-confirm]');
    const save = find('[data-signed-save]');
    assert.equal(dialog.open, true);
    assert.equal(dialog.dispatchEvent(new Event('cancel', { cancelable: true })), false);
    assert.equal(confirm.disabled, false);
    assert.equal(save.disabled, true);
    confirm.checked = true;
    confirm.emit('change');
    assert.equal(save.disabled, false);
    find('[data-signed-next]').emit('click');
    await tick();
    assert.equal(find('[data-signed-page]').textContent, 'Página 2 de 3');
    assert.equal(confirm.disabled, false);
    assert.equal(save.disabled, false);
    canvas.emit('pointerdown', { pointerId: 1, button: 0, clientX: 80, clientY: 30 });
    canvas.emit('pointerup', { pointerId: 1, clientX: 20, clientY: 10 });
    assert.equal(save.disabled, true);
    confirm.checked = true;
    confirm.emit('change');
    assert.equal(save.disabled, false);
    const progress = find('[data-signed-progress]');
    const progressBar = find('[data-signed-progress-bar]');
    progress.showModal = () => { progress.open = true; };
    progress.close = () => { progress.open = false; };
    const cancel = find('[data-signed-cancel]');
    let elapsed = 0;
    t.mock.method(performance, 'now', () => elapsed);
    t.mock.timers.enable({ apis: ['setInterval', 'setTimeout'] });
    save.emit('click');
    assert.equal(progress.open, true);
    assert.equal(progressBar.value, 0);
    assert.equal(progress.dispatchEvent(new Event('cancel', { cancelable: true })), false);
    elapsed = 4500;
    t.mock.timers.tick(200);
    assert.equal(progressBar.value, 50);
    assert.equal(find('[data-signed-percent]').textContent, '50%');
    for (const button of [save, cancel, confirm, ...['first', 'prev', 'next', 'last', 'undo'].map(name => find(`[data-signed-${name}]`))]) {
        assert.equal(button.disabled, true);
    }
    cancel.emit('click');
    save.emit('click');
    assert.equal(dialog.open, true);
    assert.equal(saves, 1);
    finishFirstSave(Response.json({}, { status: 500 }));
    await tick();
    assert.equal(progress.open, false);
    assert.equal(dialog.open, true);
    assert.equal(cancel.disabled, false);
    assert.equal(save.disabled, false);
    assert.match(find('[data-signed-error]').textContent, /Tente novamente/);
    save.emit('click');
    await tick();
    assert.equal(progressBar.value, 100);
    assert.equal(progress.open, true);
    t.mock.timers.tick(400);
    await tick();
    assert.equal(progress.open, false);
    assert.equal(dialog.open, false);
    assert.equal(find('[data-signed-original]').href, endpoint + '/original');
    assert.equal(find('[data-signed-redacted]').href, endpoint + '/redacted');
    assert.equal(find('[data-signed-status]').textContent, '');
    root.dataset.ataVersion = '7';
    find('[data-signed-remove]').emit('click');
    assert.equal(removal.open, true);
    closeRemoval.emit('click');
    assert.equal(removal.open, false);
    await tick();
    assert.equal(removals, 0);
    find('[data-signed-remove]').emit('click');
    find('original [data-signed-remove-copy]').emit('click');
    await tick();
    assert.equal(removals, 1);
    assert.equal(closeFocused, true);
    assert.equal(find('[data-signed-row="original"]').hidden, true);
    assert.equal(find('[data-signed-row="redacted"]').hidden, false);
    assert.equal(downloads.hidden, false);

    root.dataset.ataVersion = '8';
    removal.close();
    input.emit('change');
    assert.equal(uploadChoice.open, true);
    uploadChoice.returnValue = 'original';
    uploadChoice.open = false;
    uploadChoice.emit('close');
    await tick();
    assert.equal(dialog.open, false);
    assert.equal(find('[data-signed-redacted]').hidden, true);
    assert.equal(find('[data-signed-create-redacted]').hidden, false);
    root.dataset.ataVersion = '9';
    find('[data-signed-create-redacted]').emit('click');
    await tick();
    assert.equal(dialog.open, true);
    assert.equal(save.textContent, 'Gravar cópia tarjada');
    if (amendment) {
        control.emit('ata-signed-amendment-context', { detail: { id: 9, number: '1', copies: ['original'] } });
        assert.equal(dialog.open, false);
        assert.equal(find('[data-signed-original]').href, '/atas/81/amendments/9/signed-document/original');
        assert.equal(find('[data-signed-redacted]').hidden, true);
    }
}
