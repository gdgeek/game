import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h, type ComponentOptions } from 'vue'
import { renderToString } from '@vue/server-renderer'
import BackendSwitcher from '../components/BackendSwitcher.vue'

const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status })
const backend = (id: string) => ({ id, name: `正式后端 ${Number(id.slice(1)) + 1}`, host: `${id}.example.test` })
const storageKey = 'bujiaban.checkin.backend'

describe('runtime backend configuration', () => {
  const fetchMock = vi.fn<typeof fetch>()
  let stored: Map<string, string>

  beforeEach(() => {
    vi.resetModules()
    vi.stubEnv('MODE', 'production')
    stored = new Map()
    vi.stubGlobal('localStorage', {
      getItem: (key: string) => stored.get(key) ?? null,
      setItem: (key: string, value: string) => stored.set(key, value),
    })
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
  })
  afterEach(() => { vi.unstubAllGlobals(); vi.unstubAllEnvs() })

  it.each([1, 3, 40])('loads all %i configured backends and chooses the first', async (count) => {
    const options = Array.from({ length: count }, (_, index) => backend(`b${index}`))
    fetchMock.mockResolvedValueOnce(response({ backends: options }))
    const api = await import('./api')
    expect(api.getSelectedBackend()).toBe('')
    expect(await api.initializeBackends()).toEqual(options)
    expect(api.getSelectedBackend()).toBe('b0')
    expect(fetchMock).toHaveBeenCalledTimes(1)
    const [url, init] = fetchMock.mock.calls[0]!
    expect(url).toBe('/runtime-config.json')
    expect(init).toMatchObject({ cache: 'no-store', credentials: 'same-origin', mode: 'same-origin' })
    expect(init?.signal).toBeInstanceOf(AbortSignal)
  })

  it('preserves gaps and selects a saved ID that is still configured', async () => {
    stored.set(storageKey, 'b12')
    const options = [backend('b2'), backend('b12'), backend('b35')]
    fetchMock.mockResolvedValueOnce(response({ backends: options }))
    const api = await import('./api')
    expect(await api.initializeBackends()).toEqual(options)
    expect(api.getSelectedBackend()).toBe('b12')
    api.setSelectedBackend('b35')
    expect(stored.get(storageKey)).toBe('b35')
    expect(() => api.setSelectedBackend('b0')).toThrow(/后端/)
    expect(api.getSelectedBackend()).toBe('b35')
  })

  it('replaces a removed saved ID with the first configured backend', async () => {
    stored.set(storageKey, 'b1')
    fetchMock.mockResolvedValueOnce(response({ backends: [backend('b7')] }))
    const api = await import('./api')
    await api.initializeBackends()
    expect(api.getSelectedBackend()).toBe('b7')
    expect(stored.get(storageKey)).toBe('b7')
  })

  it.each(['localhost', '127.0.0.1:8080', 'console.example.test:65535', `${['a'.repeat(63), 'a'.repeat(63), 'a'.repeat(63), 'a'.repeat(61)].join('.')}:65535`])('accepts a safe runtime host: %s', async (host) => {
    const option = { ...backend('b0'), host }
    fetchMock.mockResolvedValueOnce(response({ backends: [option] }))
    const api = await import('./api')
    expect(await api.initializeBackends()).toEqual([option])
  })

  it('shares concurrent initialization and caches a successful configuration', async () => {
    let finish!: (value: Response) => void
    fetchMock.mockReturnValueOnce(new Promise(resolve => { finish = resolve }))
    const api = await import('./api')
    const first = api.initializeBackends()
    const second = api.initializeBackends()
    finish(response({ backends: [backend('b3')] }))
    const options = await first
    expect(await second).toBe(options)
    expect(await api.initializeBackends()).toBe(options)
    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(Object.isFrozen(options)).toBe(true)
    expect(Object.isFrozen(options[0])).toBe(true)
  })

  it.each([
    null, {}, { backends: [] }, { backends: {} },
    { backends: [backend('b0'), backend('b0')] },
    ...['b01', 'b-1', '../b0', 'b1/admin-api', 'B1', 'b9007199254740992'].map(id => ({ backends: [backend(id)] })),
    ...['', '   ', '<script>', 'name\nvalue'].map(name => ({ backends: [{ ...backend('b0'), name }] })),
    ...['', ' ', 'https://example.test', 'example.test/path', 'user@example.test', 'example.test\n', 'example.test:65536', 'example.test:0', 'example..test', '-example.test'].map(host => ({ backends: [{ ...backend('b0'), host }] })),
  ])('rejects invalid configuration without guessing a backend: %j', async (configuration) => {
    fetchMock.mockResolvedValueOnce(response(configuration))
    const api = await import('./api')
    await expect(api.initializeBackends()).rejects.toThrow(/后端配置/)
    expect(api.getSelectedBackend()).toBe('')
    expect(() => api.setSelectedBackend('b0')).toThrow(/后端/)
  })

  it.each(['network', 'http', 'html', 'timeout'] as const)('permits an explicit retry after a %s failure', async (failure) => {
    if (failure === 'network') fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'))
    else if (failure === 'http') fetchMock.mockResolvedValueOnce(response({}, 503))
    else if (failure === 'html') fetchMock.mockResolvedValueOnce(new Response('<html>shell</html>'))
    else fetchMock.mockRejectedValueOnce(new DOMException('timed out', 'TimeoutError'))
    const api = await import('./api')
    await expect(api.initializeBackends()).rejects.toThrow(/后端配置/)
    fetchMock.mockResolvedValueOnce(response({ backends: [backend('b9')] }))
    expect(await api.initializeBackends()).toEqual([backend('b9')])
    expect(api.getSelectedBackend()).toBe('b9')
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it('locks all API requests until valid configuration has loaded', async () => {
    const api = await import('./api')
    for (const operation of [() => api.getSession(), () => api.login('admin', 'password'), () => api.getDevices(), () => api.getGames(), () => api.assignGame(1, 638), () => api.logout()]) {
      await expect(operation()).rejects.toThrow(/后端配置/)
    }
    expect(fetchMock).not.toHaveBeenCalled()
    fetchMock.mockResolvedValueOnce(response({ backends: [] }))
    await expect(api.initializeBackends()).rejects.toThrow(/后端配置/)
    await expect(api.login('admin', 'password')).rejects.toThrow(/后端配置/)
    await expect(api.assignGame(1, 638)).rejects.toThrow(/后端配置/)
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('does not fetch runtime configuration in demo mode', async () => {
    vi.stubEnv('MODE', 'demo')
    const api = await import('./api')
    expect(await api.initializeBackends()).toEqual([])
    expect(fetchMock).not.toHaveBeenCalled()
  })
})

describe('backend selector from runtime configuration', () => {
  const render = (count: number, compact = false, disabled = false) => renderToString(createSSRApp({
    render: () => h(BackendSwitcher, { backends: Array.from({ length: count }, (_, index) => backend(`b${index * 2}`)), modelValue: 'b0', compact, disabled }),
  }))

  it.each([0, 1])('hides the selector for %i backend', async (count) => {
    expect(await render(count)).not.toContain('<select')
    expect(await render(count, true)).not.toContain('<select')
  })

  it.each([3, 40])('renders all %i runtime backends without a growing card grid', async (count) => {
    const html = await render(count)
    expect(html.match(/<option /g)).toHaveLength(count)
    expect(html).toMatch(new RegExp(`<option value="b${(count - 1) * 2}"[^>]*>`))
    expect(html).not.toContain('两个后端')
  })

  it('disables backend selection while a write is active', async () => {
    expect(await render(3, true, true)).toMatch(/<select[^>]*disabled/)
  })
})
describe('application backend startup', () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => {
    vi.resetModules()
    vi.stubEnv('MODE', 'production')
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    vi.stubGlobal('localStorage', { getItem: () => null, setItem: () => undefined })
  })
  afterEach(() => { vi.unstubAllGlobals(); vi.unstubAllEnvs() })

  async function application() {
    const { default: App } = await import('../App.vue')
    const setup = (App as ComponentOptions).setup!
    let state: Record<string, unknown>
    // Reuse setup state across SSR renders to inspect async startup and real login markup.
    const component = {
      ...App,
      setup: (props: Parameters<typeof setup>[0], context: Parameters<typeof setup>[1]) => {
        state ??= setup(props, context) as Record<string, unknown>
        return state
      },
    }
    const render = () => renderToString(createSSRApp(component))
    await render()
    return {
      render,
      initialize: () => (state.initializeWorkspace as () => Promise<void>)(),
      submitLogin: (username: string, password: string) => (state.submitLogin as (username: string, password: string) => Promise<void>)(username, password),
      switchBackend: (id: string) => (state.switchBackend as (id: string) => Promise<void>)(id),
      openPicker: (device: unknown) => (state.openPicker as (device: unknown) => void)(device),
      saveGame: (id: number) => (state.saveGame as (id: number) => Promise<void>)(id),
    }
  }

  it('automatically connects a single runtime backend after configuration loads', async () => {
    fetchMock.mockResolvedValueOnce(response({ backends: [backend('b7')] }))
    fetchMock.mockResolvedValueOnce(response({ user: null, csrfToken: 'token' }))
    const app = await application()
    await app.initialize()
    expect(fetchMock.mock.calls.map(call => call[0])).toEqual(['/runtime-config.json', '/backends/b7/admin-api/session'])
    const html = await app.render()
    expect(html).toContain('登录管理后台')
    expect(html).not.toContain('<select')
  })

  it('shows configuration failure, disables login and retries configuration explicitly', async () => {
    fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'))
    const app = await application()
    await app.initialize()
    const html = await app.render()
    expect(html).toContain('后端配置')
    expect(html).toMatch(/<button[^>]*type="submit"[^>]*disabled/)
    expect(html).toMatch(/<input[^>]*id="username"[^>]*disabled/)
    expect(html).not.toContain('正在登录…')
    expect(html).toContain('重试加载配置')
    await app.submitLogin('admin', 'password')
    expect(fetchMock).toHaveBeenCalledTimes(1)
    fetchMock.mockResolvedValueOnce(response({ backends: [backend('b3')] }))
    fetchMock.mockResolvedValueOnce(response({ user: null, csrfToken: 'token' }))
    await app.initialize()
    expect(fetchMock.mock.calls.map(call => call[0])).toEqual(['/runtime-config.json', '/runtime-config.json', '/backends/b3/admin-api/session'])
    expect(await app.render()).not.toContain('重试加载配置')
  })

  it('keeps backend selection locked while saving a game', async () => {
    const device = { id: 1, uuid: 'device-1', name: '设备一', ip: '', status: 'unknown', updatedAt: '', gameId: null }
    fetchMock.mockResolvedValueOnce(response({ backends: [backend('b0'), backend('b2'), backend('b4')] }))
    fetchMock.mockResolvedValueOnce(response({ user: { username: 'admin' }, csrfToken: 'token' }))
    fetchMock.mockResolvedValueOnce(response([device]))
    fetchMock.mockResolvedValueOnce(response([{ id: 638, name: '游戏', description: '', category: '' }]))
    const app = await application()
    await app.initialize()
    app.openPicker(device)
    let finish!: (value: Response) => void
    fetchMock.mockReturnValueOnce(new Promise(resolve => { finish = resolve }))
    const pending = app.saveGame(638)
    await app.switchBackend('b2')
    const api = await import('./api')
    expect(api.getSelectedBackend()).toBe('b0')
    expect(await app.render()).toMatch(/<select[^>]*disabled[^>]*aria-label="切换正式后端"/)
    expect(fetchMock.mock.calls[4]?.[0]).toBe('/backends/b0/admin-api/devices/1/game')
    expect(fetchMock).toHaveBeenCalledTimes(5)
    finish(response({ message: '保存失败' }, 503))
    await pending
    expect(api.getSelectedBackend()).toBe('b0')
    expect(fetchMock).toHaveBeenCalledTimes(5)
  })

  it('ignores a late session from a backend that the user has left', async () => {
    fetchMock.mockResolvedValueOnce(response({ backends: [backend('b0'), backend('b2'), backend('b4')] }))
    fetchMock.mockResolvedValueOnce(response({ user: null, csrfToken: 'token' }))
    const app = await application()
    await app.initialize()
    let finishB2!: (value: Response) => void
    fetchMock.mockReturnValueOnce(new Promise(resolve => { finishB2 = resolve }))
    const pendingB2 = app.switchBackend('b2')
    fetchMock.mockResolvedValueOnce(response({ user: null, csrfToken: 'b4-token' }))
    await app.switchBackend('b4')
    finishB2(response({ user: { username: 'old-user' }, csrfToken: 'b2-token' }))
    await pendingB2
    expect((await import('./api')).getSelectedBackend()).toBe('b4')
    const html = await app.render()
    expect(html).toContain('登录管理后台')
    expect(html).not.toContain('old-user')
    expect(fetchMock.mock.calls.map(call => call[0])).toEqual(['/runtime-config.json', '/backends/b0/admin-api/session', '/backends/b2/admin-api/session', '/backends/b4/admin-api/session'])
  })
})
