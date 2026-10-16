import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createServer, request as httpRequest, type Server, type RequestListener } from 'node:http'
import type { AddressInfo } from 'node:net'
import configFactory from '../vite.config'
const transport = vi.hoisted(() => ({ request: vi.fn() }))
vi.mock('node:https', async original => ({ ...await original<typeof import('node:https')>(), request: transport.request }))


describe('automatic Vite API routing', () => {
  afterEach(() => vi.unstubAllEnvs())
  it('exposes one automatic route and a manifest without node addresses', async () => {
    vi.stubEnv('APP_API_1_URL', 'https://x.b0.01xr.com'); vi.stubEnv('APP_API_2_URL', 'https://x.b1.01xr.com')
    const factory = configFactory as (env: { mode: string; command: 'serve' }) => any
    const config = await factory({ mode: 'api', command: 'serve' })
    const plugin = config.plugins.find((value: any) => value?.name === 'automatic-api-proxy')
    expect(plugin).toBeDefined(); expect(config.server.proxy ?? {}).toEqual({})
    const handlers: any[] = []
    plugin.configureServer({ middlewares: { use: (...args: any[]) => handlers.push(args.at(-1)) } })
    const res = { setHeader: vi.fn(), end: vi.fn() }
    for (const handler of handlers) handler({ url: '/runtime-config.json' }, res, () => {})
    expect(JSON.parse(res.end.mock.calls[0]?.[0])).toEqual({ apiBase: '/api/admin-api', automatic: true, auth: 'jwt' })
    expect(res.end.mock.calls[0]?.[0]).not.toContain('x.b0')
  })
})


describe('development automatic proxy transport', () => {
  const servers: Server[] = []
  beforeEach(() => {
    transport.request.mockImplementation((url: URL, options: any, callback: any) => {
      const local = new URL(url); local.protocol = 'http:'
      return httpRequest(local, options, callback)
    })
  })
  afterEach(async () => {
    vi.unstubAllEnvs()
    await Promise.all(servers.map(server => new Promise<void>(resolve => { server.closeAllConnections(); server.close(() => resolve()) })))
    servers.length = 0
  })
  async function listen(handler: RequestListener) {
    const server = createServer(handler); servers.push(server)
    await new Promise<void>(resolve => server.listen(0, '127.0.0.1', resolve))
    return `http://127.0.0.1:${(server.address() as AddressInfo).port}`
  }
  async function proxy(origins: string[]) {
    origins.forEach((origin, index) => vi.stubEnv(`APP_API_${index + 1}_URL`, origin.replace('http:', 'https:')))
    const config = await (configFactory as any)({ command: 'serve', mode: 'api' })
    const plugin = config.plugins.find((value: any) => value?.name === 'automatic-api-proxy')
    expect(plugin).toBeDefined()
    const handlers: any[] = []
    plugin.configureServer({ middlewares: { use: (handler: any) => handlers.push(handler) } })
    return listen((req, res) => {
      let index = 0
      const next = () => {
        const handler = handlers[index++]
        if (handler) handler(req, res, next)
        else { res.statusCode = 404; res.end('not found') }
      }
      next()
    })
  }

  it('distributes requests automatically with Bearer and owner proof while stripping cookies', async () => {
    const received: any[] = []
    const origins = await Promise.all(['b0', 'b1'].map(node => listen((req, res) => {
      let body = ''; req.on('data', chunk => { body += chunk }); req.on('end', () => {
        received.push({ node, url: req.url, cookie: req.headers.cookie, authorization: req.headers.authorization, owner: req.headers['x-admin-scan-owner'], csrf: req.headers['x-csrf-token'], body })
        res.setHeader('Set-Cookie', 'ADMIN_SESSION=shared; Domain=upstream.example; Path=/admin-api; HttpOnly; Secure')
        res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify({ node }))
      })
    })))
    const base = await proxy(origins)
    const results = []
    for (let index = 0; index < 3; index++) {
      const result = await fetch(`${base}/api/admin-api/devices/1/game?trace=${index}`, { method: 'PUT', headers: { Cookie: 'ADMIN_SESSION=shared', Authorization: 'Bearer access', 'X-Admin-Scan-Owner': 'c'.repeat(64), 'X-CSRF-Token': 'obsolete-token' }, body: '{"gameId":638}' })
      expect(result.headers.get('set-cookie')).toBeNull()
      results.push(await result.json())
    }
    expect(results).toEqual([{ node: 'b0' }, { node: 'b1' }, { node: 'b0' }])
    expect(received).toEqual([0, 1, 2].map((index) => ({ node: index === 1 ? 'b1' : 'b0', url: `/admin-api/devices/1/game?trace=${index}`, cookie: undefined, authorization: 'Bearer access', owner: 'c'.repeat(64), csrf: undefined, body: '{"gameId":638}' })))
  })

  it('returns a safe 502 for a failed write without contacting another node', async () => {
    let first = 0, second = 0
    const origins = [await listen((req) => { first++; req.socket.destroy() }), await listen((_req, res) => { second++; res.end('{}') })]
    const base = await proxy(origins)
    const result = await fetch(`${base}/api/admin-api/login`, { method: 'POST', body: '{"password":"private"}' })
    expect(result.status).toBe(502); expect(first).toBe(1); expect(second).toBe(0)
    expect(await result.text()).toBe('{"message":"开发代理暂时无法连接服务"}')
  })

  it('does not proxy unrelated paths or normalized paths outside admin-api', async () => {
    let count = 0
    const origin = await listen((_req, res) => { count++; res.end('{}') })
    const base = await proxy([origin])
    expect((await fetch(`${base}/api/admin-api-evil`)).status).toBe(404)
    // Double encoding remains an admin path; a percent-encoded slash is not decoded by the proxy.
    expect((await fetch(`${base}/api/admin-api/%2e%2e/private`)).status).toBe(404)
    expect(count).toBe(0)
  })
})
