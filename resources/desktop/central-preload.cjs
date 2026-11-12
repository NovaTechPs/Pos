const { contextBridge, ipcRenderer } = require('electron');

// Pages from the central server may only ask the app to open a document PDF from that same server.
contextBridge.exposeInMainWorld('waslaDesktop', {
    printPdf: (request) => ipcRenderer.invoke('wasla:print-pdf', request),
});
