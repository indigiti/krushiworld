import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import { resolve } from 'node:path';

export default defineConfig({
  base: './',
  plugins: [tailwindcss()],
  build: {
    outDir: 'public/build-vite',
    emptyOutDir: true,
    cssCodeSplit: false,
    rollupOptions: {
      input: {
        app: resolve(process.cwd(), 'resources/js/vite-entry.js'),
        admin: resolve(process.cwd(), 'resources/js/admin.js'),
      },
      output: {
        entryFileNames: '[name].js',
        chunkFileNames: 'chunks/[name]-[hash].js',
        assetFileNames: (assetInfo) => assetInfo.name && assetInfo.name.endsWith('.css') ? 'app.css' : 'assets/[name]-[hash][extname]',
      },
    },
  },
});
