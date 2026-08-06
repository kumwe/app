import { defineConfig } from 'vite';
import { resolve } from 'node:path';

export default defineConfig({
  publicDir: false,
  build: {
    outDir: 'public/assets/build',
    emptyOutDir: true,
    manifest: true,
    minify: false,
    rollupOptions: {
      input: {
        administrator: resolve(import.meta.dirname, 'assets/administrator/main.ts'),
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
