import { readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { gzipSync } from 'node:zlib';
import {
    defineConfig
} from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/passkeys.js',
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
        {
            name: 'precompress-static-assets',
            apply: 'build',
            async writeBundle(options, bundle) {
                for (const asset of Object.values(bundle)) {
                    if (!/\.(css|js)$/.test(asset.fileName)) continue;
                    const file = resolve(options.dir ?? 'public/build', asset.fileName);
                    const source = await readFile(file);
                    if (Buffer.byteLength(source) < 1024) continue;
                    await writeFile(file + '.gz', gzipSync(source, { level: 9 }));
                }
            },
        },
    ],
    server: {
        cors: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
