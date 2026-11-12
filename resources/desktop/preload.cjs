const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('waslaDesktop', {
    print: (request) => ipcRenderer.invoke('wasla:print', request),
    printPdf: (request) => ipcRenderer.invoke('wasla:print-pdf', request),
    openCentral: (path) => ipcRenderer.invoke('wasla:open-central', path),
});
