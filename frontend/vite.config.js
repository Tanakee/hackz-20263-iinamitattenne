import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import basicSsl from '@vitejs/plugin-basic-ssl'

export default defineConfig({
  plugins: [vue(), basicSsl()],
  server: {
    host: '0.0.0.0',
    port: 5173,
    https: true,
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
      },
      '/ws': {
        target: 'ws://gravity-api:8080',
        ws: true,
        changeOrigin: true,
      }
    }
  }
})
