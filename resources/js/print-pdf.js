/**
 * Prints a document's own PDF, so the printed page always matches the downloaded PDF.
 * The PDF is loaded inline in a hidden frame and the browser print dialog is opened for it.
 * Browsers name "Save as PDF" after the page title, so the title is switched to the
 * document name (party name then invoice number) while printing and restored afterwards.
 * If the browser cannot print the frame, the PDF opens in a new tab where it can be printed.
 */
function inlinePdfUrl(url) {
    return url + (url.includes('?') ? '&' : '?') + 'inline=1';
}

function titledDocuments() {
    const documents = [document];
    let current = window;
    try {
        while (current.parent && current.parent !== current) {
            current = current.parent;
            documents.push(current.document);
        }
    } catch (error) {
        // A parent from another origin keeps its own title.
    }

    return documents;
}

/** The desktop app's bridge, which lives on the top window even when this page runs inside a workspace tab. */
function desktopBridge() {
    try {
        return window.top?.waslaDesktop?.printPdf ? window.top.waslaDesktop : undefined;
    } catch (error) {
        return undefined;
    }
}

function printPdf(url, title = '') {
    if (!url) return;

    const source = inlinePdfUrl(url);
    const desktop = desktopBridge();
    if (desktop) {
        // The desktop window has no built-in PDF viewer, so the app opens the PDF in the Windows PDF viewer to print or save it.
        desktop.printPdf({ url: new URL(source, window.location.href).href, title })
            .catch((error) => window.alert(error?.message || 'تعذر فتح ملف PDF للطباعة.'));

        return;
    }
    document.querySelectorAll('iframe[data-print-pdf]').forEach((frame) => frame.remove());

    const frame = document.createElement('iframe');
    frame.setAttribute('data-print-pdf', 'true');
    frame.setAttribute('aria-hidden', 'true');
    frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:800px;height:600px;border:0;';
    frame.onload = () => {
        window.setTimeout(() => {
            // Pages may run inside workspace tabs (frames); the browser names the file after the top window title.
            const documents = titledDocuments();
            const pageTitles = documents.map((doc) => doc.title);
            let restored = false;
            const restoreTitle = () => {
                if (restored) return;
                restored = true;
                documents.forEach((doc, index) => { doc.title = pageTitles[index]; });
            };

            if (title) documents.forEach((doc) => { doc.title = title; });
            window.addEventListener('focus', () => window.setTimeout(restoreTitle, 500), { once: true });
            window.setTimeout(restoreTitle, 60000);

            try {
                frame.contentWindow.focus();
                frame.contentWindow.print();
            } catch (error) {
                restoreTitle();
                window.open(source, '_blank', 'noopener');
            }
        }, 400);
    };
    frame.src = source;
    document.body.appendChild(frame);
}

window.printPdf = printPdf;
window.addEventListener('print-pdf', (event) => printPdf(event.detail?.url, event.detail?.title));
