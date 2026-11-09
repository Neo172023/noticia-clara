const form = document.querySelector('#analysis-form');
const submit = form.querySelector('button[type="submit"]');
const analysisStatus = document.querySelector('#analysis-status');

form.addEventListener('submit', () => {
    submit.disabled = true;
    analysisStatus.textContent = 'Estamos leyendo la noticia y buscando fuentes. Esto puede demorar unos minutos.';
});

window.addEventListener('pageshow', () => {
    submit.disabled = false;
    analysisStatus.textContent = '';
});

const destination = document.querySelector('#analysis-error, #result-title');
destination?.focus();

const copyButton = document.querySelector('#copy-draft');
if (copyButton) {
    copyButton.hidden = false;
    copyButton.addEventListener('click', async () => {
        const title = document.querySelector('#draft-title').textContent.trim();
        const draft = document.querySelector('#draft-text');
        const status = document.querySelector('#copy-status');

        try {
            await navigator.clipboard.writeText(title + '\n\n' + draft.textContent.trim());
            status.textContent = 'Redacción copiada.';
        } catch {
            const range = document.createRange();
            range.selectNodeContents(draft);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            status.textContent = 'El navegador bloqueó el portapapeles. Podés copiar el texto seleccionado manualmente.';
        }
    });
}
