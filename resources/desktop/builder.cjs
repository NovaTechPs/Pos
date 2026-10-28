module.exports = {
    appId: 'app.waslapos.desktop', productName: 'WaslaPOS',
    directories: { output: 'storage/app/desktop-build/release', buildResources: 'public' },
    files: ['resources/desktop/main.cjs', 'resources/desktop/runtime.cjs', 'resources/desktop/preload.cjs',
        'resources/desktop/printing.cjs', 'resources/desktop/print-bridge.js', '!node_modules/**'],
    extraMetadata: { name: 'waslapos-desktop', version: require('../../package.json').version, main: 'resources/desktop/main.cjs',
        description: 'WaslaPOS offline desktop cashier', author: 'WaslaPOS' },
    extraResources: [
        { from: 'storage/app/desktop-build/stage/backend', to: 'backend' },
        { from: 'storage/app/desktop-build/stage/php', to: 'php' },
    ],
    win: { target: [{ target: 'nsis', arch: ['x64'] }], icon: 'public/favicon.ico', signExecutable: false },
    nsis: { oneClick: false, perMachine: false, allowToChangeInstallationDirectory: true,
        createDesktopShortcut: true, createStartMenuShortcut: true, deleteAppDataOnUninstall: false,
        artifactName: 'WaslaPOS-Setup-${version}-${arch}.${ext}' },
    npmRebuild: false,
};
