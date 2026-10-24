export function barcodeCamera(loadReader = () => import('@zxing/browser'), environment = globalThis) {
    let controls, stream, timer;
    let generation = 0;
    const stop = () => {
        controls?.stop();
        controls = null;
        stream?.getTracks().forEach(track => track.stop());
        stream = null;
        environment.clearInterval(timer);
    };
    return {
        open: false, loading: false, error: '',
        init() {
            this.onHide = () => this.close();
            environment.addEventListener('pagehide', this.onHide);
        },
        close() {
            generation++;
            this.open = false;
            this.loading = false;
            stop();
        },
        destroy() {
            this.close();
            environment.removeEventListener('pagehide', this.onHide);
        },
        async start() {
            this.close();
            this.open = true;
            this.error = '';
            this.loading = true;
            const attempt = generation;
            try {
                if (!environment.isSecureContext || !environment.navigator.mediaDevices?.getUserMedia) {
                    throw new Error('secure-context');
                }
                const { BrowserMultiFormatReader } = await loadReader();
                if (attempt !== generation) return;
                const acquired = await environment.navigator.mediaDevices.getUserMedia({
                    video: { facingMode: { ideal: 'environment' } }, audio: false,
                });
                if (attempt !== generation) {
                    acquired.getTracks().forEach(track => track.stop());
                    return;
                }
                stream = acquired;
                await this.$nextTick();
                if (attempt !== generation) return;
                const reader = new BrowserMultiFormatReader();
                const started = await reader.decodeFromStream(stream, this.$refs.preview, (result) => {
                    if (!result || attempt !== generation) return;
                    const barcode = result.getText().trim();
                    if (!barcode) return;
                    this.close();
                    this.$wire.scanBarcode(barcode);
                });
                if (attempt !== generation) {
                    started.stop();
                    return;
                }
                controls = started;
                this.loading = false;
                timer = environment.setInterval(() => {
                    if (environment.document.hidden || environment.frameElement?.hidden) this.close();
                }, 300);
            } catch (error) {
                if (attempt !== generation) return;
                stop();
                this.loading = false;
                this.error = error.message === 'secure-context'
                    ? 'فتح الكاميرا يحتاج اتصال HTTPS أو localhost.'
                    : error.name === 'NotAllowedError'
                        ? 'يرجى السماح باستخدام الكاميرا من إعدادات المتصفح، ثم إعادة المحاولة.'
                        : error.name === 'NotFoundError'
                            ? 'لم يتم العثور على كاميرا في هذا الجهاز.'
                            : 'تعذر تشغيل الكاميرا. أغلق التطبيقات التي تستخدمها ثم أعد المحاولة.';
            }
        },
    };
}

if (typeof window !== 'undefined') window.posBarcodeCamera = barcodeCamera;
