import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  build: {
    outDir: 'build',
    emptyOutDir: true,
    cssCodeSplit: false,
    rollupOptions: {
      input: 'src/main.jsx',
      output: {
        entryFileNames: 'assets/app-0.4.2.js',
        assetFileNames: 'assets/app-0.4.2.[ext]'
      }
    }
  }
});
