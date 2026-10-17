export function initializeProfilePhoto(root, urls = URL) {
    const input = root.querySelector('[data-profile-photo-input]');
    const preview = root.querySelector('[data-profile-photo-preview]');
    const status = root.querySelector('[data-profile-photo-status]');
    const original = [...preview.childNodes].map((node) => node.cloneNode(true));
    let objectUrl;
    const release = () => {
        if (objectUrl) urls.revokeObjectURL(objectUrl);
        objectUrl = null;
    };
    input.addEventListener('change', () => {
        release();
        preview.replaceChildren(...original.map((node) => node.cloneNode(true)));
        status.textContent = '';
        input.setCustomValidity('');
        const file = input.files[0];
        if (!file) return;
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) {
            const message = 'Escolha uma foto JPEG, PNG ou WebP de até 2 MB.';
            input.setCustomValidity(message);
            status.textContent = message;
            return;
        }
        objectUrl = urls.createObjectURL(file);
        const image = input.ownerDocument.createElement('img');
        image.alt = '';
        image.src = objectUrl;
        preview.replaceChildren(image);
        status.textContent = 'Prévia da foto. Clique em Salvar foto para aplicar.';
    });
    input.ownerDocument.defaultView.addEventListener('pagehide', release);
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-profile-photo]').forEach((root) => initializeProfilePhoto(root));
}
