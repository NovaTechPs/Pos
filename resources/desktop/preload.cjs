const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('waslaDesktop', {
    print: (request) => ipcRenderer.invoke('wasla:print', request),
});
