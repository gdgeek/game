import { mkdtemp, rm } from 'node:fs/promises'
import type { IncomingMessage, ServerResponse } from 'node:http'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { Readable } from 'node:stream'
import type { ViteDevServer } from 'vite'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { demoApiPlugin } from '../../dev/demo-api'

describe('demo API session expiry', () => {
  let root: string
  let middleware: (request: IncomingMessage, response: ServerResponse) => void

  beforeEach(async () => {
    root = await mkdtemp(join(tmpdir(), 'dirui-demo-api-test-'))
    const plugin = demoApiPlugin()
    const server = {
      config: { root, logger: { error: vi.fn() } },
      middlewares: { use: (_path: string, handler: typeof middleware) => { middleware = handler } },
    }
    if (typeof plugin.configureServer !== 'function') throw new Error('Missing demo middleware hook')
    await plugin.configureServer.call({} as never, server as unknown as ViteDevServer)
  })

  afterEach(async () => rm(root, { recursive: true, force: true }))

  function request(path: string, method: string, headers: Record<string, string> = {}) {
    return new Promise<{ status: number; body: Record<string, unknown>; headers: Record<string, string> }>((resolve) => {
      const result = { status: 0, body: {}, headers: {} as Record<string, string> }
      const response = {
        headersSent: false,
        setHeader(name: string, value: string) { result.headers[name] = value },
        writeHead(status: number) { result.status = status },
        end(data: string) { resolve({ ...result, body: JSON.parse(data) as Record<string, unknown> }) },
      }
      const incoming = Object.assign(Readable.from([]), { url: path, method, headers })
      middleware(incoming as unknown as IncomingMessage, response as unknown as ServerResponse)
    })
  }

  it('returns 401 when a write request has no valid server session', async () => {
    expect((await request('/devices/1/game', 'PUT')).status).toBe(401)
    expect((await request('/devices/1/game', 'PUT', {
      cookie: 'dirui_demo_session=expired-session',
      'x-csrf-token': 'expired-token',
    })).status).toBe(401)
  })

  it('keeps a mismatching CSRF token as 403 while the session exists', async () => {
    const session = await request('/session', 'GET')
    const cookie = session.headers['Set-Cookie']!.split(';')[0]!
    expect((await request('/devices/1/game', 'PUT', { cookie, 'x-csrf-token': 'wrong-token' })).status).toBe(403)
  })
})
