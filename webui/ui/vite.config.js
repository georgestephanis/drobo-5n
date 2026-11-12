import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Builds straight into ../public/assets with fixed names; index.php adds a version query for caching.
// For development, `DROBO=http://drobo5n.local npm run dev` proxies the API to a real board.
export default defineConfig({
  plugins: [react()],
  base: './',
  build: {
    outDir: '../public/assets',
    emptyOutDir: true,
    assetsDir: '.',
    rollupOptions: {
      input: 'src/main.jsx',
      output: { entryFileNames: 'app.js', chunkFileNames: '[name].js', assetFileNames: 'app[extname]' },
    },
  },
  server: {
    proxy: { '/api.php': { target: process.env.DROBO || 'http://drobo5n.local', changeOrigin: true } },
  },
});
