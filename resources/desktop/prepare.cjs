const fs = require('node:fs/promises');
const path = require('node:path');
const crypto = require('node:crypto');
const { spawnSync } = require('node:child_process');
const { initializeData, buildEnvironment, runPhp } = require('./runtime.cjs');

const project = path.resolve(__dirname, '../..');
const buildRoot = path.join(project, 'storage/app/desktop-build');
const stage = path.join(buildRoot, 'stage');

function command(executable, args, cwd = project) {
    const result = spawnSync(executable, args, { cwd, stdio: 'inherit', windowsHide: true });
    if (result.error) throw result.error;
    if (result.status !== 0) throw new Error(`${executable} failed with exit code ${result.status}`);
}

async function prepare() {
    if (process.platform !== 'win32') throw new Error('Build the bundled Windows PHP runtime on Windows.');
    const phpInfo = spawnSync('php', ['-r', 'echo PHP_BINARY;'], { encoding: 'utf8', windowsHide: true });
    if (phpInfo.status !== 0) throw new Error('PHP is required on the build machine.');
    const phpSource = process.env.WASLA_PHP_DIR || path.dirname(phpInfo.stdout.trim());
    if (path.dirname(stage) !== buildRoot || !buildRoot.startsWith(project + path.sep)) throw new Error('Unsafe staging path.');
    await fs.rm(stage, { recursive: true, force: true, maxRetries: 5, retryDelay: 1000 });
    const backend = path.join(stage, 'backend');
    const phpRoot = path.join(stage, 'php');
    await fs.mkdir(backend, { recursive: true });
    await fs.mkdir(path.join(phpRoot, 'ext'), { recursive: true });
    for (const directory of ['app', 'bootstrap', 'config', 'lang', 'routes', 'resources']) {
        await fs.cp(path.join(project, directory), path.join(backend, directory), {
            recursive: true, filter: source => !source.startsWith(path.join(project, 'bootstrap/cache') + path.sep),
        });
    }
    await fs.mkdir(path.join(backend, 'bootstrap/cache'), { recursive: true });
    await fs.cp(path.join(project, 'database/migrations'), path.join(backend, 'database/migrations'), { recursive: true });
    await fs.cp(path.join(project, 'public'), path.join(backend, 'public'), { recursive: true, filter: source => {
        const relative = path.relative(path.join(project, 'public'), source);
        return relative !== 'storage' && !relative.startsWith('storage' + path.sep)
            && !['hot', 'fonts-manifest.dev.json'].includes(relative);
    } });
    for (const file of ['artisan', 'composer.json', 'composer.lock']) await fs.copyFile(path.join(project, file), path.join(backend, file));
    const lock = JSON.parse(await fs.readFile(path.join(project, 'composer.lock'), 'utf8'));
    const installed = JSON.parse(await fs.readFile(path.join(project, 'vendor/composer/installed.json'), 'utf8'));
    const productionNames = new Set(lock.packages.map(item => item.name));
    const productionPackages = installed.packages.filter(item => productionNames.has(item.name));
    if (productionPackages.length !== productionNames.size) throw new Error('Install the locked Composer dependencies before building.');
    await fs.mkdir(path.join(backend, 'vendor/composer'), { recursive: true });
    for (const item of productionPackages) {
        await fs.cp(path.join(project, 'vendor', item.name), path.join(backend, 'vendor', item.name), { recursive: true });
    }
    await fs.writeFile(path.join(backend, 'vendor/composer/installed.json'), JSON.stringify({ packages: productionPackages, dev: false, 'dev-package-names': [] }));
    const composerPath = path.join(buildRoot, 'composer.phar');
    if (!(await fs.stat(composerPath).catch(() => null))) {
        const [archive, checksum] = await Promise.all([
            fetch('https://getcomposer.org/download/latest-stable/composer.phar'),
            fetch('https://getcomposer.org/download/latest-stable/composer.phar.sha256sum'),
        ]);
        if (!archive.ok || !checksum.ok) throw new Error('Unable to download Composer for the desktop build.');
        const contents = Buffer.from(await archive.arrayBuffer());
        const expected = (await checksum.text()).trim().split(/\s+/)[0];
        if (crypto.createHash('sha256').update(contents).digest('hex') !== expected) throw new Error('Composer checksum did not match.');
        await fs.writeFile(composerPath, contents);
    }
    command(phpInfo.stdout.trim(), [composerPath, 'install', '--no-dev', '--prefer-dist', '--no-scripts', '--optimize-autoloader', '--no-interaction'], backend);
    for (const entry of await fs.readdir(phpSource)) {
        if (entry === 'php.exe' || entry.toLowerCase().endsWith('.dll') || /^(license|news|readme|install|deplister)/i.test(entry)) {
            if ((await fs.stat(path.join(phpSource, entry))).isFile()) await fs.copyFile(path.join(phpSource, entry), path.join(phpRoot, entry));
        }
    }
    const extensions = [];
    for (const name of ['bcmath', 'curl', 'fileinfo', 'gd', 'intl', 'mbstring', 'openssl', 'pdo_sqlite', 'sqlite3', 'sodium', 'zip', 'exif']) {
        const file = `php_${name}.dll`;
        const source = path.join(phpSource, 'ext', file);
        if (await fs.stat(source).catch(() => null)) {
            await fs.copyFile(source, path.join(phpRoot, 'ext', file));
            extensions.push(`extension=${name}`);
        }
    }
    for (const dll of ['vcruntime140.dll', 'vcruntime140_1.dll', 'msvcp140.dll']) {
        const source = path.join(process.env.SystemRoot || 'C:/Windows', 'System32', dll);
        if (await fs.stat(source).catch(() => null)) await fs.copyFile(source, path.join(phpRoot, dll));
    }
    const certificate = await fetch('https://curl.se/ca/cacert.pem');
    if (!certificate.ok) throw new Error('Unable to download the HTTPS CA certificate bundle.');
    await fs.writeFile(path.join(phpRoot, 'cacert.pem'), Buffer.from(await certificate.arrayBuffer()));
    await fs.writeFile(path.join(phpRoot, 'php.ini'), [
        'extension_dir="../php/ext"', ...extensions,
        'curl.cainfo="../php/cacert.pem"', 'openssl.cafile="../php/cacert.pem"',
        'memory_limit=512M', 'max_execution_time=120', 'date.timezone=Asia/Hebron',
        'expose_php=Off', 'display_errors=stderr', 'log_errors=On', 'variables_order=EGPCS',
        'opcache.enable=1', 'opcache.enable_cli=1', 'opcache.jit=disable',
        'opcache.memory_consumption=128', 'opcache.max_accelerated_files=20000',
        'opcache.validate_timestamps=1', 'opcache.revalidate_freq=0',
        'opcache.file_cache="${APP_STORAGE_PATH}/framework/cache/php"', 'opcache.file_cache_fallback=1',
        'upload_max_filesize=20M', 'post_max_size=24M',
    ].join('\n'));
    const temporaryData = path.join(buildRoot, 'build-validation');
    const state = await initializeData(temporaryData);
    const environment = buildEnvironment(process.env, temporaryData, backend, 8765, state.key);
    const php = path.join(phpRoot, 'php.exe');
    const ini = path.join(phpRoot, 'php.ini');
    console.log(await runPhp(php, ini, ['artisan', 'package:discover', '--no-interaction'], { cwd: backend, env: environment }));
    console.log(await runPhp(php, ini, ['artisan', 'migrate', '--force', '--no-interaction'], { cwd: backend, env: environment }));
    const productionManifest = JSON.parse(await fs.readFile(path.join(backend, 'public/build/manifest.json'), 'utf8'));
    if (!Object.keys(productionManifest).length) throw new Error('Production frontend assets are missing.');
    if (await fs.stat(path.join(backend, '.env')).catch(() => null)) throw new Error('A private environment file was included.');
    console.log(`Desktop resources are ready: ${stage}`);
}

prepare().catch(error => { console.error(error); process.exitCode = 1; });
