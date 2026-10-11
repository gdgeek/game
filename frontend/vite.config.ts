import { defineConfig, loadEnv, type Plugin, type ProxyOptions } from 'vite'
import vue from '@vitejs/plugin-vue'
import { demoApiPlugin } from './dev/demo-api'
import { readBackends } from './dev/backends'

export default defineConfig(({ mode, command }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const backends = command === 'serve' && !['demo', 'test'].includes(mode) ? readBackends(env) : []
  const backendProxy = (backend: string, target: string): ProxyOptions => ({
    target,
    changeOrigin: true,
    secure: true,
    rewrite: path => path.slice(`/backends/${backend}`.length),
    cookieDomainRewrite: '',
    cookiePathRewrite: { '*': `/backends/${backend}/` },
  })
  const proxy = Object.fromEntries(backends.map(backend => [
    `^/backends/${backend.id}/admin-api/`, backendProxy(backend.id, backend.origin),
  ]))
  const manifest = JSON.stringify({ backends: backends.map(({ origin: _origin, ...backend }) => backend) })
  const runtimeConfigPlugin: Plugin = {
    name: 'runtime-backend-config',
    configureServer(server) {
      server.middlewares.use((req, res, next) => {
        if (req.url?.split('?')[0] !== '/runtime-config.json') return next()
        res.setHeader('Content-Type', 'application/json; charset=utf-8')
        res.setHeader('Cache-Control', 'no-store')
        res.end(manifest)
      })
    },
    configurePreviewServer(server) {
      server.middlewares.use((req, res, next) => {
        if (req.url?.split('?')[0] !== '/runtime-config.json') return next()
        res.setHeader('Content-Type', 'application/json; charset=utf-8')
        res.setHeader('Cache-Control', 'no-store')
        res.end(manifest)
      })
    },
  }
  return {
    plugins: [vue(), ...(mode === 'demo' ? [demoApiPlugin()] : [runtimeConfigPlugin])],
    server: {
      port: 5173,
      strictPort: true,
      ...(backends.length ? { proxy } : {}),
    },
    preview: { proxy },
  }
})
