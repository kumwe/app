import { defineConfig } from 'vite';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const releaseRecord = JSON.parse(readFileSync(
  resolve(import.meta.dirname, 'resources/studio-contract/studio-release.json'),
  'utf8',
)) as { kind?: unknown; release?: unknown };
if (releaseRecord.kind !== 'studio-release' || typeof releaseRecord.release !== 'string') {
  throw new Error('The canonical Studio release record is malformed.');
}

export default defineConfig({
  publicDir: false,
  define: {
    __KUMWE_STUDIO_RELEASE__: JSON.stringify(releaseRecord.release),
  },
  build: {
    outDir: 'public/assets/build',
    emptyOutDir: true,
    manifest: true,
    minify: false,
    rollupOptions: {
      input: {
        administrator: resolve(import.meta.dirname, 'assets/administrator/main.ts'),
        portal: resolve(import.meta.dirname, 'assets/portal/main.ts'),
        site: resolve(import.meta.dirname, 'assets/site/main.ts'),
      },
      output: {
        entryFileNames: 'js/[name]-[hash].js',
        chunkFileNames: 'js/[name]-[hash].js',
        assetFileNames: 'css/[name]-[hash][extname]',
      },
    },
  },
});
