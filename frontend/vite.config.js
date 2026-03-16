import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  server: {
    host: '0.0.0.0',
    port: 5173,
    proxy: {
      '/api/gravity': {
        target: 'http://gravity-api:8080',
        changeOrigin: true,
        rewrite: (path) => path.replace(/^\/api\/gravity/, '/api')
      },
      '/api/logic': {
        target: 'http://logic-api:8000',
        changeOrigin: true,
        rewrite: (path) => path.replace(/^\/api\/logic/, '')
      }
    }
  }
})
