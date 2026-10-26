const fs = require('node:fs/promises');
const path = require('node:path');
const crypto = require('node:crypto');
const net = require('node:net');
const { spawn } = require('node:child_process');

async function initializeData(dataRoot) {
    for (const directory of ['logs', 'backups', 'storage/app/private', 'storage/app/public',
        'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs']) {
        await fs.mkdir(path.join(dataRoot, directory), { recursive: true });
    }
    const keyFile = path.join(dataRoot, 'application-key');
    try {
        await fs.writeFile(keyFile, 'base64:' + crypto.randomBytes(32).toString('base64'), { flag: 'wx', mode: 0o600 });
    } catch (error) {
        if (error.code !== 'EEXIST') throw error;
    }
    const database = path.join(dataRoot, 'database.sqlite');
    try {
        await fs.writeFile(database, '', { flag: 'wx', mode: 0o600 });
    } catch (error) {
        if (error.code !== 'EEXIST') throw error;
    }
    return { key: (await fs.readFile(keyFile, 'utf8')).trim(), database };
}

function buildEnvironment(parent, dataRoot, backendRoot, port, key) {
    const environment = {};
    for (const [name, value] of Object.entries(parent)) {
        if (['systemroot', 'windir', 'path', 'pathext', 'temp', 'tmp', 'comspec', 'userprofile',
            'appdata', 'localappdata', 'programdata', 'programfiles', 'programfiles(x86)', 'processor_architecture'].includes(name.toLowerCase())) {
            environment[name] = value;
        }
    }
    return { ...environment, APP_NAME: 'WaslaPOS', APP_ENV: 'production', APP_DEBUG: 'false',
        APP_DESKTOP: 'true', APP_KEY: key, APP_URL: `http://127.0.0.1:${port}`,
        APP_STORAGE_PATH: path.join(dataRoot, 'storage'),
        DB_CONNECTION: 'sqlite', DB_DATABASE: path.join(dataRoot, 'database.sqlite'),
        DB_FOREIGN_KEYS: 'true', DB_BUSY_TIMEOUT: '10000', DB_JOURNAL_MODE: 'WAL', DB_SYNCHRONOUS: 'NORMAL',
        CACHE_STORE: 'file', SESSION_DRIVER: 'file', SESSION_SECURE_COOKIE: 'false', SESSION_DOMAIN: 'null',
        SESSION_LIFETIME: '720', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'log', LOG_CHANNEL: 'single',
        BRANCH_SYNC_MODE: 'edge', BRANCH_SYNC_SERVER_URL: 'https://waslapos.app',
        BCRYPT_ROUNDS: '12', LARAVEL_STORAGE_PATH: path.join(dataRoot, 'storage'),
        VIEW_COMPILED_PATH: path.join(dataRoot, 'storage/framework/views'),
        APP_CONFIG_CACHE: path.join(dataRoot, 'unused-config-cache.php'),
        APP_ROUTES_CACHE: path.join(dataRoot, 'unused-routes-cache.php'),
        APP_EVENTS_CACHE: path.join(dataRoot, 'unused-events-cache.php'),
    };
}

function reservePort() {
    return new Promise((resolve, reject) => {
        const server = net.createServer();
        server.on('error', reject);
        server.listen(0, '127.0.0.1', () => {
            const port = server.address().port;
            server.close(() => resolve(port));
        });
    });
}

function runPhp(php, ini, args, options) {
    return new Promise((resolve, reject) => {
        const { onSpawn, ...spawnOptions } = options;
        const child = spawn(php, ['-c', ini, ...args], { ...spawnOptions, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
        onSpawn?.(child);
        let output = '';
        child.stdout.on('data', chunk => { output += chunk.toString(); });
        child.stderr.on('data', chunk => { output += chunk.toString(); });
        child.on('error', reject);
        child.on('exit', code => code === 0 ? resolve(output) : reject(new Error(`PHP exited with ${code}: ${output.slice(-5000)}`)));
    });
}

function isLocalNavigation(target, origin) {
    try { return new URL(target).origin === origin; } catch { return false; }
}

module.exports = { initializeData, buildEnvironment, reservePort, runPhp, isLocalNavigation };
