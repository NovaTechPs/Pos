(() => {
    function install(target, sourceUrl) {
        try {
            if (target.__waslaPrintBridge) return;
            target.__waslaPrintBridge = true;
            target.print = async () => {
                target.dispatchEvent(new target.Event('beforeprint'));
                try {
                    const snapshot = target.document.documentElement.cloneNode(true);
                    snapshot.querySelectorAll('script, base, iframe, object, embed').forEach(node => node.remove());
                    snapshot.querySelectorAll('*').forEach(node => {
                        for (const attr of Array.from(node.attributes)) {
                            if (attr.name.startsWith('on')) node.removeAttribute(attr.name);
                        }
                    });
                    return await window.waslaDesktop.print({
                        pageUrl: sourceUrl || target.location.href,
                        html: '<!DOCTYPE html>' + snapshot.outerHTML,
                    });
                } catch (error) {
                    target.alert(error.message || 'تعذرت الطباعة.');
                } finally { target.dispatchEvent(new target.Event('afterprint')); }
            };
            const originalOpen = target.open.bind(target);
            target.open = (...args) => {
                const popup = originalOpen(...args);
                if (popup) install(popup, sourceUrl || target.location.href);
                return popup;
            };
            const watchFrames = () => {
                for (const frame of target.document.querySelectorAll('iframe')) {
                    const bind = () => { try { install(frame.contentWindow); } catch {} };
                    if (!frame.__waslaPrintWatched) {
                        frame.__waslaPrintWatched = true;
                        frame.addEventListener('load', bind);
                    }
                    bind();
                }
            };
            new target.MutationObserver(watchFrames).observe(target.document, { childList: true, subtree: true });
            watchFrames();
        } catch { /* Cross-origin frames retain their normal browser behavior. */ }
    }
    install(window);
})();
