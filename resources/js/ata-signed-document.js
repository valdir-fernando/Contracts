const MAX_RECTANGLES = 120;

function redactionBox(page, start, end) {
    return {
        page,
        left: Math.min(start.x, end.x),
        top: Math.min(start.y, end.y),
        right: Math.max(start.x, end.x),
        bottom: Math.max(start.y, end.y),
    };
}

export function initializeSignedDocument(control) {
    const root = control.closest('.ata-editor');
    const find = selector => control.querySelector(selector);
    const input = find('input[type="file"]');
    const dialog = find('dialog');
    const canvas = find('canvas');
    const context = canvas.getContext('2d');
    if (!context) return;

    const status = find('[data-signed-status]');
    const error = find('[data-signed-error]');
    const first = find('[data-signed-first]');
    const previous = find('[data-signed-prev]');
    const next = find('[data-signed-next]');
    const last = find('[data-signed-last]');
    const undo = find('[data-signed-undo]');
    const confirm = find('[data-signed-confirm]');
    const save = find('[data-signed-save]');
    const cancel = find('[data-signed-cancel]');
    const progress = find('[data-signed-progress]');
    const progressBar = find('[data-signed-progress-bar]');
    const percent = find('[data-signed-percent]');
    const amendmentControl = control.dataset.signedAmendment !== undefined;
    const downloads = control.ownerDocument.querySelector(amendmentControl ? '[data-amendment-signed-downloads]' : '[data-signed-downloads]');
    const createRedacted = downloads.querySelector('[data-signed-create-redacted]');
    const remove = downloads.querySelector('[data-signed-remove]');
    const uploadChoice = find('[data-signed-upload-choice]');
    const removal = find('[data-signed-removal]');
    const removalStatus = find('[data-signed-removal-status]');
    const closeRemoval = find('[data-signed-removal-close]');
    const copies = ['original', 'redacted'].map(kind => {
        const row = find(`[data-signed-row="${kind}"]`);
        return {
            kind, row, file: row.querySelector('[data-signed-file]'),
            button: row.querySelector('[data-signed-remove-copy]'),
            link: downloads.querySelector(`[data-signed-${kind}]`),
        };
    });
    const image = new Image();
    let review;
    let page;
    let drag;
    let draft;
    let pending = false;
    let imageReady = false;
    let amendmentId = '';

    const urlFor = (key, id, amendment = amendmentId) => amendmentControl
        ? control.dataset[key].replace('/0/amendments/0/', `/${encodeURIComponent(id)}/amendments/${encodeURIComponent(amendment)}/`)
        : control.dataset[key].replace('/0/', `/${encodeURIComponent(id)}/`);
    const hasTarget = () => root.dataset.ataLoaded && (!amendmentControl || amendmentId);
    const current = candidate => candidate === review && candidate.id === root.dataset.ataLoaded
        && candidate.version === root.dataset.ataVersion && candidate.amendment === amendmentId;

    function setDownloads(id, available = [], canCreate = false) {
        downloads.hidden = available.length === 0;
        createRedacted.hidden = !canCreate;
        downloads.querySelector('[data-signed-link-divider]').hidden = available.length < 2 && !canCreate;
        find('[data-signed-removal-empty]').hidden = available.length > 0;
        for (const { kind, row, file, link } of copies) {
            link.hidden = row.hidden = !available.includes(kind);
            if (!row.hidden) link.href = file.href = urlFor(`${kind}Url`, id);
        }
    }

    function documentUpdated(id, result) {
        control.dispatchEvent(new CustomEvent('ata-signed-document-updated', { detail: result, bubbles: true }));
        setDownloads(id, result.signed_document_copies, result.can_create_redacted_copy);
    }

    function refreshButtons() {
        if (!review?.pages) return;
        first.disabled = previous.disabled = pending || page <= 1;
        last.disabled = next.disabled = pending || page >= review.pages.length;
        undo.disabled = pending || !review.boxes.some(box => box.page === page);
        confirm.disabled = pending;
        cancel.disabled = pending;
        save.disabled = pending || !confirm.checked || !imageReady;
    }

    function paint() {
        if (!imageReady) return;
        context.drawImage(image, 0, 0);
        context.fillStyle = '#000';
        for (const box of [...review.boxes, ...(draft ? [draft] : [])]) {
            if (box.page !== page) continue;
            context.fillRect(box.left * canvas.width, box.top * canvas.height,
                (box.right - box.left) * canvas.width, (box.bottom - box.top) * canvas.height);
        }
    }

    function showPage(number) {
        page = number;
        drag = draft = null;
        imageReady = false;
        context.clearRect(0, 0, canvas.width, canvas.height);
        find('[data-signed-page]').textContent = `Página ${page} de ${review.pages.length}`;
        image.src = review.pages[page - 1];
        refreshButtons();
    }

    image.onload = () => {
        if (!review || image.getAttribute('src') !== review.pages[page - 1]) return;
        canvas.width = image.naturalWidth;
        canvas.height = image.naturalHeight;
        imageReady = true;
        paint();
        refreshButtons();
    };

    function coordinates(event) {
        const area = canvas.getBoundingClientRect();
        const clamp = value => Math.max(0, Math.min(1, value));
        return { x: clamp((event.clientX - area.left) / area.width), y: clamp((event.clientY - area.top) / area.height) };
    }

    canvas.addEventListener('pointerdown', event => {
        if (!review || pending || drag || !imageReady || event.button !== 0) return;
        if (review.boxes.length === MAX_RECTANGLES) {
            error.textContent = 'O limite de 120 tarjas foi atingido.';
            return;
        }
        drag = { id: event.pointerId, start: coordinates(event) };
        canvas.setPointerCapture(event.pointerId);
    });
    canvas.addEventListener('pointermove', event => {
        if (drag?.id !== event.pointerId) return;
        draft = redactionBox(page, drag.start, coordinates(event));
        paint();
    });
    canvas.addEventListener('pointerup', event => {
        if (drag?.id !== event.pointerId) return;
        const box = redactionBox(page, drag.start, coordinates(event));
        if ((box.right - box.left) * canvas.width >= 3 && (box.bottom - box.top) * canvas.height >= 3) {
            review.boxes.push(box);
            confirm.checked = false;
        }
        drag = draft = null;
        paint();
        refreshButtons();
    });
    canvas.addEventListener('lostpointercapture', () => {
        if (!drag) return;
        drag = draft = null;
        paint();
    });

    first.addEventListener('click', () => showPage(1));
    previous.addEventListener('click', () => showPage(page - 1));
    next.addEventListener('click', () => showPage(page + 1));
    last.addEventListener('click', () => showPage(review.pages.length));
    undo.addEventListener('click', () => {
        review.boxes.splice(review.boxes.findLastIndex(box => box.page === page), 1);
        confirm.checked = false;
        error.textContent = '';
        paint();
        refreshButtons();
    });
    confirm.addEventListener('change', refreshButtons);
    cancel.addEventListener('click', () => { if (!pending) dialog.close(); });
    dialog.addEventListener('cancel', event => event.preventDefault());
    progress.addEventListener('cancel', event => event.preventDefault());

    function updateProgress(value) {
        progressBar.value = value;
        percent.textContent = `${value}%`;
    }

    function reset() {
        review = undefined;
        image.removeAttribute('src');
        context.clearRect(0, 0, canvas.width, canvas.height);
        input.value = '';
        confirm.checked = false;
        error.textContent = '';
    }
    dialog.addEventListener('close', () => { if (!dialog.open) reset(); });
    function resetContext() {
        if (dialog.open) dialog.close();
        if (removal.open) removal.close();
        if (uploadChoice.open) uploadChoice.close();
        reset();
        status.textContent = '';
    }
    control.addEventListener('ata-signed-amendment-context', ({ detail }) => {
        const id = String(detail.id ?? '');
        if (amendmentId !== id) resetContext();
        amendmentId = id;
        find('[data-signed-removal-number]').textContent = detail.number || '';
        const copies = id ? detail.copies ?? [] : [];
        setDownloads(root.dataset.ataLoaded, copies, copies.length === 1 && copies[0] === 'original');
    });
    root.addEventListener('ata-loaded', ({ detail }) => {
        resetContext();
        amendmentId = '';
        if (amendmentControl) {
            setDownloads(null);
        } else {
            find('[data-signed-removal-number]').textContent = detail.number;
            setDownloads(detail.id, detail.signed_document_copies, detail.can_create_redacted_copy);
        }
    });
    root.addEventListener('ata-cleared', () => {
        resetContext();
        amendmentId = '';
        setDownloads(null);
    });

    async function submit(key, candidate, destination) {
        pending = true;
        refreshButtons();
        root.dispatchEvent(new CustomEvent('ata-product-saving', { bubbles: true }));
        const body = new FormData();
        if (candidate.file) body.append('pdf', candidate.file);
        body.append('version', candidate.version);
        body.append('mode', candidate.mode);
        if (key === 'storeUrl' && candidate.mode !== 'original') {
            body.append('sha256', candidate.sha256);
            body.append('pages', candidate.pages.length);
            body.append('reviewed', '1');
            candidate.boxes.forEach((box, index) => {
                for (const [property, value] of Object.entries(box)) body.append(`rectangles[${index}][${property}]`, value);
            });
        }
        try {
            const response = await fetch(urlFor(key, candidate.id, candidate.amendment), {
                method: 'POST', body, cache: 'no-store',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': root.dataset.csrfToken },
            });
            const result = await response.json().catch(() => null);
            if (!current(candidate)) return null;
            if (response.ok && result) return result;
            destination.textContent = {
                403: 'Somente administradores autorizados podem acessar o PDF assinado.',
                404: amendmentControl ? 'O aditivo ou o PDF não está mais disponível.' : 'A ata não está mais disponível.',
                409: result?.message || 'Consulte novamente a ata antes de continuar.',
                422: result?.errors?.pdf?.[0] || 'Confira o PDF e as marcações antes de continuar.',
                429: 'Aguarde um momento antes de tentar novamente.',
            }[response.status] || 'Não foi possível processar o PDF. Tente novamente.';
            return null;
        } catch {
            if (current(candidate)) destination.textContent = 'Não foi possível comunicar com o sistema. Tente novamente.';
            return null;
        } finally {
            pending = false;
            root.dispatchEvent(new CustomEvent('ata-product-finished', { bubbles: true }));
            refreshButtons();
        }
    }

    function startCandidate(file, mode) {
        review = { file, mode, id: root.dataset.ataLoaded, version: root.dataset.ataVersion, amendment: amendmentId };
        return review;
    }

    input.addEventListener('change', () => {
        const file = input.files[0];
        if (!file || !hasTarget() || pending || root.dataset.editorBusy === 'true') return;
        if (!/\.pdf$/i.test(file.name) || file.size > 12 * 1024 * 1024) {
            status.textContent = 'Selecione um arquivo PDF de até 12 MB.';
            input.value = '';
            return;
        }
        startCandidate(file, 'pair');
        status.textContent = '';
        uploadChoice.returnValue = '';
        uploadChoice.showModal();
    });

    uploadChoice.addEventListener('close', async () => {
        if (!review || !current(review)) return;
        const candidate = review;
        if (uploadChoice.returnValue === 'pair') {
            await showPreview(candidate);
        } else if (uploadChoice.returnValue === 'original') {
            candidate.mode = 'original';
            status.textContent = 'Gravando o PDF original…';
            const result = await submit('storeUrl', candidate, status);
            if (!current(candidate)) return;
            if (result) {
                documentUpdated(candidate.id, result);
                status.textContent = '';
            }
            reset();
        } else {
            reset();
        }
    });

    async function showPreview(candidate) {
        status.textContent = 'Preparando a prévia do PDF…';
        const result = await submit('previewUrl', candidate, status);
        if (!current(candidate)) return;
        if (!result) {
            reset();
            return;
        }
        Object.assign(candidate, result, { boxes: [] });
        status.textContent = '';
        save.textContent = candidate.mode === 'stored' ? 'Gravar cópia tarjada' : 'Gravar original e cópia tarjada';
        showPage(1);
        dialog.showModal();
    }

    createRedacted.addEventListener('click', () => {
        if (!hasTarget() || createRedacted.hidden || pending || root.dataset.editorBusy === 'true') return;
        showPreview(startCandidate(null, 'stored'));
    });

    save.addEventListener('click', async () => {
        if (!review || pending || save.disabled || !current(review)) return;
        const candidate = review;
        error.textContent = '';
        drag = draft = null;
        paint();
        updateProgress(0);
        progress.showModal();
        const started = performance.now();
        const expectedDuration = Math.max(3000, candidate.pages.length * 1500);
        const timer = setInterval(() => {
            const elapsed = performance.now() - started;
            updateProgress(Math.min(98, Math.floor(100 * elapsed / (elapsed + expectedDuration))));
        }, 200);
        try {
            const result = await submit('storeUrl', candidate, error);
            if (!result || !current(candidate)) return;
            clearInterval(timer);
            updateProgress(100);
            await new Promise(resolve => setTimeout(resolve, 400));
            if (!current(candidate)) return;
            documentUpdated(candidate.id, result);
            dialog.close();
        } finally {
            clearInterval(timer);
            progress.close();
        }
    });

    remove.addEventListener('click', () => {
        if (downloads.hidden || pending || root.dataset.editorBusy === 'true') return;
        removalStatus.textContent = '';
        removal.showModal();
    });
    closeRemoval.addEventListener('click', () => { if (!pending) removal.close(); });
    removal.addEventListener('cancel', event => { if (pending) event.preventDefault(); });

    async function removeCopy(kind) {
        const id = root.dataset.ataLoaded;
        const version = root.dataset.ataVersion;
        const amendment = amendmentId;
        const isCurrent = () => root.dataset.ataLoaded === id && root.dataset.ataVersion === version && amendmentId === amendment;
        if (!hasTarget() || !removal.open || pending || root.dataset.editorBusy === 'true') return;
        const buttons = [closeRemoval, ...copies.map(({ button }) => button)];
        pending = true;
        buttons.forEach(button => { button.disabled = true; });
        removalStatus.textContent = 'Removendo o PDF…';
        root.dispatchEvent(new CustomEvent('ata-product-saving', { bubbles: true }));
        try {
            const response = await fetch(urlFor('removeUrl', id, amendment), {
                method: 'DELETE', cache: 'no-store',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': root.dataset.csrfToken },
                body: JSON.stringify({ version, copy: kind }),
            });
            const data = await response.json().catch(() => null);
            if (!isCurrent()) return;
            if (!response.ok || !data) {
                removalStatus.textContent = response.status === 409 ? 'A ata foi alterada. Consulte novamente antes de remover o PDF.'
                    : 'Não foi possível remover o PDF. Consulte novamente a ata.';
                return;
            }
            documentUpdated(id, data);
            removalStatus.textContent = '';
        } catch {
            if (isCurrent()) removalStatus.textContent = 'Não foi possível remover o PDF. Tente novamente.';
        } finally {
            pending = false;
            buttons.forEach(button => { button.disabled = false; });
            root.dispatchEvent(new CustomEvent('ata-product-finished', { bubbles: true }));
        }
        if (removal.open) closeRemoval.focus();
    }
    copies.forEach(({ kind, button }) => button.addEventListener('click', () => removeCopy(kind)));
}

if (typeof document !== 'undefined') document.querySelectorAll('[data-ata-signed-document]').forEach(initializeSignedDocument);
