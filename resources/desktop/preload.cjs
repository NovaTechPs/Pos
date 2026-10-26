const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('waslaDesktop', {
    print: () => ipcRenderer.invoke('wasla:print'),
});
