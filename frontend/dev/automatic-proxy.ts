import { request as httpsRequest } from 'node:https'
import type { IncomingHttpHeaders, IncomingMessage, ServerResponse } from 'node:http'
import type { Connect, Plugin } from 'vite'
import type { ConfiguredBackend } from './backends'

const publicBase = '/api/admin-api'
const hopHeaders = new Set(['connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization', 'te', 'trailer', 'transfer-encoding', 'upgrade'])

function endToEndHeaders(headers: IncomingHttpHeaders): IncomingHttpHeaders {
  const excluded = new Set([...hopHeaders, ...(headers.connection ?? '').split(',').map(value => value.trim().toLowerCase())])
  return Object.fromEntries(Object.entries(headers).filter(([name]) => !excluded.has(name.toLowerCase())))
}

export function automaticApiPlugin(backends: readonly ConfiguredBackend[]): Plugin {
  let cursor = 0
  const middleware: Connect.NextHandleFunction = (req: IncomingMessage, res: ServerResponse, next) => {
    const path = req.url ?? '/'
    if (path.split('?')[0] === '/runtime-config.json') {
      res.setHeader('Content-Type', 'application/json; charset=utf-8')
      res.setHeader('Cache-Control', 'no-store')
      res.end(JSON.stringify({ apiBase: publicBase, automatic: true, auth: 'jwt' }))
      return
    }
    if (!new RegExp(`^${publicBase}(?:/|\\?|$)`).test(path)) return next()
    if (!backends.length) { res.statusCode = 503; res.end(); return }
    // A request goes to exactly one upstream. In particular, ambiguous writes
    // are never replayed; JWT authentication is independent of backend cookies.
    const backend = backends[cursor++ % backends.length]!
    const target = new URL(path.slice('/api'.length), backend.origin)
    if (target.pathname !== '/admin-api' && !target.pathname.startsWith('/admin-api/')) {
      res.statusCode = 400; res.end(); return
    }
    const headers = endToEndHeaders(req.headers)
    delete headers.cookie
    delete headers['x-csrf-token']
    headers.host = target.host
    headers['x-forwarded-host'] = req.headers.host ?? 'localhost'
    headers['x-forwarded-proto'] = 'http'
    const upstream = httpsRequest(target, { method: req.method, headers }, result => {
      res.statusCode = result.statusCode ?? 502
      const responseHeaders = endToEndHeaders(result.headers)
      for (const [name, value] of Object.entries(responseHeaders)) {
        if (value === undefined) continue
        if (name === 'set-cookie') continue
        else if (name === 'location' && typeof value === 'string') {
          const redirect = new URL(value, backend.origin)
          res.setHeader(name, redirect.origin === target.origin && /^\/admin-api(?:\/|$)/.test(redirect.pathname)
            ? `/api${redirect.pathname}${redirect.search}${redirect.hash}` : value)
        } else res.setHeader(name, value)
      }
      result.on('error', () => res.destroy())
      result.pipe(res)
    })
    upstream.setTimeout(40_000, () => upstream.destroy(new Error('upstream timeout')))
    upstream.on('error', () => {
      if (res.destroyed) return
      if (res.headersSent) { res.destroy(); return }
      res.statusCode = 502
      res.setHeader('Content-Type', 'application/json; charset=utf-8')
      res.end(JSON.stringify({ message: '开发代理暂时无法连接服务' }))
    })
    req.on('aborted', () => upstream.destroy())
    res.on('close', () => { if (!res.writableEnded) upstream.destroy() })
    req.pipe(upstream)
  }
  const configure = (server: { middlewares: Connect.Server }) => { server.middlewares.use(middleware) }
  return { name: 'automatic-api-proxy', configureServer: configure, configurePreviewServer: configure }
}
