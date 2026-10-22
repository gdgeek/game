import { mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises'
import type { IncomingMessage, ServerResponse } from 'node:http'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { Readable } from 'node:stream'
import type { ViteDevServer } from 'vite'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { demoApiPlugin } from '../../dev/demo-api'
import type { Device, Session } from '../types'

type DemoSession = Session & { csrfToken: string }

// Password hashing is outside these middleware tests; exercise the real login/session flow.
vi.mock('node:crypto', async (importOriginal) => ({
  ...await importOriginal<typeof import('node:crypto')>(),
  timingSafeEqual: () => true,
}))

interface ApiResponse<T> {
  status: number
  body: T
  headers: Record<string, string>
}

describe('demo API', () => {
  let root: string
  let middleware: (request: IncomingMessage, response: ServerResponse) => void
  let logError: ReturnType<typeof vi.fn>

  async function startServer() {
    const plugin = demoApiPlugin()
    logError = vi.fn()
    const server = {
      config: { root, logger: { error: logError } },
      middlewares: { use: (_path: string, handler: typeof middleware) => { middleware = handler } },
    }
    if (typeof plugin.configureServer !== 'function') throw new Error('Missing demo middleware hook')
    await plugin.configureServer.call({} as never, server as unknown as ViteDevServer)
  }

  beforeEach(async () => {
    root = await mkdtemp(join(tmpdir(), 'dirui-demo-api-test-'))
    await startServer()
  })

  afterEach(async () => rm(root, { recursive: true, force: true }))

  function request<T = Record<string, unknown>>(path: string, method: string, headers: Record<string, string> = {}, body?: unknown) {
    return new Promise<ApiResponse<T>>((resolve) => {
      const result = { status: 0, headers: {} as Record<string, string> }
      const response = {
        headersSent: false,
        setHeader(name: string, value: string) { result.headers[name] = value },
        writeHead(status: number) {
          result.status = status
          this.headersSent = true
        },
        end(data: string) { resolve({ ...result, body: JSON.parse(data) as T }) },
      }
      const incoming = Object.assign(Readable.from(body === undefined ? [] : [JSON.stringify(body)]), { url: path, method, headers })
      middleware(incoming as unknown as IncomingMessage, response as unknown as ServerResponse)
    })
  }

  async function login() {
    const session = await request<DemoSession>('/session', 'GET')
    const authenticated = await request<DemoSession>('/login', 'POST', {
      cookie: session.headers['Set-Cookie']!.split(';')[0]!,
      'x-csrf-token': session.body.csrfToken,
    }, { username: 'dirui', password: 'middleware-test-password' })
    expect(authenticated.status).toBe(200)
    return {
      cookie: authenticated.headers['Set-Cookie']!.split(';')[0]!,
      'x-csrf-token': authenticated.body.csrfToken,
    }
  }

  it('returns 401 when a write request has no valid server session', async () => {
    for (const path of ['/devices/1/game', '/devices/1/configuration']) {
      expect((await request(path, 'PUT')).status).toBe(401)
      expect((await request(path, 'PUT', {
        cookie: 'dirui_demo_session=expired-session',
        'x-csrf-token': 'expired-token',
      })).status).toBe(401)
    }
  })

  it('keeps image previews authenticated and empty without changing demo device content', async () => {
    expect((await request('/devices/1/image-previews', 'GET')).status).toBe(401)
    const headers = await login()
    const before = (await request<Device[]>('/devices', 'GET', headers)).body
    expect(await request('/devices/1/image-previews', 'GET', headers)).toMatchObject({ status: 200, body: { previews: [] } })
    expect((await request('/devices/99999/image-previews', 'GET', headers)).status).toBe(404)
    expect((await request<Device[]>('/devices', 'GET', headers)).body).toEqual(before)
  })

  it('keeps a mismatching CSRF token as 403 while the session exists', async () => {
    const session = await request('/session', 'GET')
    const cookie = session.headers['Set-Cookie']!.split(';')[0]!
    for (const path of ['/devices/1/game', '/devices/1/configuration']) {
      expect((await request(path, 'PUT', { cookie, 'x-csrf-token': 'wrong-token' })).status).toBe(403)
    }
  })

  it('requires authentication even when a valid anonymous session sends its CSRF token', async () => {
    const session = await request<DemoSession>('/session', 'GET')
    const headers = {
      cookie: session.headers['Set-Cookie']!.split(';')[0]!,
      'x-csrf-token': session.body.csrfToken,
    }
    expect((await request('/devices/1/configuration', 'PUT', headers, { slogans: ['hello'] })).status).toBe(401)
  })

  it('seeds device content and saves normalized configuration across server reloads', async () => {
    const headers = await login()
    const initial = await request<Device[]>('/devices', 'GET', headers)
    expect(initial.body[0]).toMatchObject({ slogans: ['欢迎体验'], pictures: [], thumbs: [] })
    const saved = await request<Device>('/devices/1/configuration', 'PUT', headers, {
      gameId: 639,
      slogans: ['  欢迎光临  ', ' ', '欢迎光临', '下一位'],
      pictures: ['  https://example.com/a.png  ', '', 'https://example.com/a.png'],
      thumbs: ['  缩略图  ', '\t'],
    })
    expect(saved.status).toBe(200)
    const expected = {
      gameId: 639,
      slogans: ['欢迎光临', '欢迎光临', '下一位'],
      pictures: ['https://example.com/a.png', 'https://example.com/a.png'],
      thumbs: ['缩略图'],
    }
    expect(saved.body).toMatchObject(expected)
    expect((await request<Device[]>('/devices', 'GET', headers)).body[0]).toMatchObject(expected)
    const persisted = JSON.parse(await readFile(join(root, '.local/demo-state.json'), 'utf8'))
    expect(persisted.devices[0]).toMatchObject(expected)

    await startServer()
    const reloadedHeaders = await login()
    expect((await request<Device[]>('/devices', 'GET', reloadedHeaders)).body[0]).toMatchObject(expected)
  })

  it('renames a device with a title-only patch and retains its content after reloading', async () => {
    const headers = await login()
    const initial = (await request<Device[]>('/devices', 'GET', headers)).body[0]!
    expect(initial.title).toBe(initial.name)
    const content = { gameId: 640, slogans: ['欢迎'], pictures: ['image'], thumbs: ['thumb'] }
    expect((await request('/devices/1/configuration', 'PUT', headers, content)).status).toBe(200)
    const renamed = await request<Device>('/devices/1/configuration', 'PUT', headers, { title: '  新设备名称  ' })
    expect(renamed.status).toBe(200)
    const expected = { ...content, title: '新设备名称', name: '新设备名称' }
    expect(renamed.body).toMatchObject(expected)
    expect((await request<Device[]>('/devices', 'GET', headers)).body[0]).toMatchObject(expected)
    const persisted = JSON.parse(await readFile(join(root, '.local/demo-state.json'), 'utf8'))
    expect(persisted.devices[0]).toMatchObject({ ...content, title: '新设备名称' })
    expect(persisted.devices[0]).not.toHaveProperty('name')
    await startServer()
    expect((await request<Device[]>('/devices', 'GET', await login())).body[0]).toMatchObject(expected)
  })

  it.each(['', '   ', ' 未知 '])('uses the original device name for a cleared or unknown title %j', async (title) => {
    const headers = await login()
    const initial = (await request<Device[]>('/devices', 'GET', headers)).body[0]!
    expect((await request('/devices/1/configuration', 'PUT', headers, { title: '自定义名称' })).status).toBe(200)
    const cleared = await request<Device>('/devices/1/configuration', 'PUT', headers, { title })
    expect(cleared.status).toBe(200)
    expect(cleared.body).toMatchObject({ title: title.trim(), name: initial.name, gameId: initial.gameId })
    await startServer()
    expect((await request<Device[]>('/devices', 'GET', await login())).body[0]).toMatchObject({ title: title.trim(), name: initial.name })
  })

  it('accepts a 255 character Unicode title after trimming and preserves it on scene changes', async () => {
    const headers = await login()
    const title = '🚀'.repeat(255)
    const renamed = await request<Device>('/devices/1/configuration', 'PUT', headers, { title: `  ${title}  ` })
    expect(renamed.status).toBe(200)
    expect(renamed.body).toMatchObject({ title, name: title })
    for (const path of ['/devices/1/configuration', '/devices/1/game']) {
      expect((await request<Device>(path, 'PUT', headers, { gameId: 640 })).body).toMatchObject({ title, name: title })
    }
  })

  it('allows content-only changes on an unconfigured device and clears arrays explicitly', async () => {
    const headers = await login()
    const saved = await request<Device>('/devices/8/configuration', 'PUT', headers, {
      slogans: ['欢迎'], pictures: ['image'], thumbs: ['thumb'],
    })
    expect(saved.status).toBe(200)
    expect(saved.body).toMatchObject({ gameId: null, slogans: ['欢迎'], pictures: ['image'], thumbs: ['thumb'] })
    const partiallyCleared = await request<Device>('/devices/8/configuration', 'PUT', headers, { slogans: [] })
    expect(partiallyCleared.status).toBe(200)
    expect(partiallyCleared.body).toMatchObject({ gameId: null, slogans: [], pictures: ['image'], thumbs: ['thumb'] })
    const cleared = await request<Device>('/devices/8/configuration', 'PUT', headers, { pictures: [], thumbs: [] })
    expect(cleared.status).toBe(200)
    expect(cleared.body).toMatchObject({ gameId: null, slogans: [], pictures: [], thumbs: [] })
  })

  it('preserves content when assigning a game through either endpoint', async () => {
    const headers = await login()
    expect((await request('/devices/1/configuration', 'PUT', headers, { slogans: ['保留'], pictures: ['image'], thumbs: ['thumb'] })).status).toBe(200)
    for (const path of ['/devices/1/configuration', '/devices/1/game']) {
      const saved = await request<Device>(path, 'PUT', headers, { gameId: 640 })
      expect(saved.status).toBe(200)
      expect(saved.body).toMatchObject({ gameId: 640, slogans: ['保留'], pictures: ['image'], thumbs: ['thumb'] })
    }
  })

  it.each([
    {}, { unexpected: [] }, { slogans: [], unexpected: true }, { name: 'Direct name' },
    { title: null }, { title: 1 }, { title: [] }, { title: {} }, { title: 'a'.repeat(256) }, { title: '🚀'.repeat(256) },
    { gameId: null }, { gameId: 0 }, { gameId: -1 }, { gameId: 638.5 }, { gameId: 999 }, { gameId: '638' },
    { slogans: null }, { slogans: 'hello' }, { slogans: {} }, { slogans: ['hello', 1] },
    { pictures: null }, { pictures: 'image' }, { pictures: [false] },
    { thumbs: null }, { thumbs: 'thumb' }, { thumbs: [{}] },
    [], null,
  ].map((patch) => [patch]))('rejects invalid configuration patch %j without changing devices', async (patch) => {
    const headers = await login()
    const before = await request<Device[]>('/devices', 'GET', headers)
    expect((await request('/devices/1/configuration', 'PUT', headers, patch)).status).toBe(422)
    expect((await request<Device[]>('/devices', 'GET', headers)).body).toEqual(before.body)
  })

  it('returns 404 for a missing device', async () => {
    const headers = await login()
    expect((await request('/devices/999/configuration', 'PUT', headers, { slogans: ['hello'] })).status).toBe(404)
  })

  it('loads old version 1 state without content arrays and preserves its assignments', async () => {
    const headers = await login()
    const devices = (await request<Device[]>('/devices', 'GET', headers)).body
    const legacy = devices.map(({ id, updatedAt }) => ({ id, gameId: 641, updatedAt }))
    await mkdir(join(root, '.local'), { recursive: true })
    await writeFile(join(root, '.local/demo-state.json'), JSON.stringify({ version: 1, devices: legacy }))
    await startServer()
    const reloadedHeaders = await login()
    const reloaded = await request<Device[]>('/devices', 'GET', reloadedHeaders)
    expect(reloaded.body.every((device) => device.gameId === 641)).toBe(true)
    expect(reloaded.body[0]).toMatchObject({ title: devices[0]!.name, name: devices[0]!.name, slogans: ['欢迎体验'], pictures: [], thumbs: [] })
    const saved = await request<Device>('/devices/1/configuration', 'PUT', reloadedHeaders, { slogans: ['新欢迎词'] })
    expect(saved.status).toBe(200)
    expect(saved.body).toMatchObject({ gameId: 641, slogans: ['新欢迎词'], pictures: [], thumbs: [] })
  })

  it('keeps memory unchanged after persistence fails and allows a later retry', async () => {
    const headers = await login()
    const before = await request<Device[]>('/devices', 'GET', headers)
    const statePath = join(root, '.local/demo-state.json')
    await mkdir(statePath, { recursive: true })
    expect((await request('/devices/1/configuration', 'PUT', headers, { slogans: ['失败的保存'] })).status).toBe(500)
    expect(logError).toHaveBeenCalledOnce()
    expect((await request<Device[]>('/devices', 'GET', headers)).body).toEqual(before.body)
    await rm(statePath, { recursive: true })
    const retried = await request<Device>('/devices/1/configuration', 'PUT', headers, { slogans: ['重试成功'] })
    expect(retried.status).toBe(200)
    expect(retried.body.slogans).toEqual(['重试成功'])
  })
})
