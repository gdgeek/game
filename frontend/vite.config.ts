import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import { demoApiPlugin } from './dev/demo-api'
import { readBackends } from './dev/backends'
import { automaticApiPlugin } from './dev/automatic-proxy'

export default defineConfig(({ mode, command }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const backends = command === 'serve' && !['demo', 'test'].includes(mode) ? readBackends(env) : []
  return {
    plugins: [vue(), mode === 'demo' ? demoApiPlugin() : automaticApiPlugin(backends)],
    server: { port: 5173, strictPort: true },
  }
})
