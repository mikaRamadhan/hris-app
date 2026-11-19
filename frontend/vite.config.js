import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// Build produksi dilayani Laravel dari public/spa (base '/spa/'). Saat dev, API di-proxy ke php artisan serve.
export default defineConfig(({ command }) => ({
  base: command === 'build' ? '/spa/' : '/',
  plugins: [react()],
  server: { port: 5173, proxy: { '/api': 'http://127.0.0.1:8000' } },
  build: { chunkSizeWarningLimit: 2500 },
}))
