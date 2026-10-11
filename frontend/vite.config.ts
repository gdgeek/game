import { defineConfig, loadEnv, type ProxyOptions } from 'vite'
import vue from '@vitejs/plugin-vue'
import { demoApiPlugin } from './dev/demo-api'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const backendProxy = (backend: 'b0' | 'b1', target: string): ProxyOptions => ({
    target,
    changeOrigin: true,
    secure: true,
    rewrite: path => path.replace(`/backends/${backend}`, ''),
    cookieDomainRewrite: '',
    cookiePathRewrite: { '*': `/backends/${backend}/` },
  })
  return {
    plugins: [vue(), ...(mode === 'demo' ? [demoApiPlugin()] : [])],
    server: {
      port: 5173,
      strictPort: true,
      ...(mode !== 'demo' ? {
        proxy: {
          '/backends/b0/admin-api': backendProxy('b0', env.APP_API_1_URL || 'https://x.b0.01xr.com'),
          '/backends/b1/admin-api': backendProxy('b1', env.APP_API_2_URL || 'https://x.b1.01xr.com'),
        },
      } : {}),
    },
  }
})
