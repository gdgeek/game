import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, type ComponentOptions } from 'vue'
import { renderToString } from '@vue/server-renderer'

const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status })
const configuration = { apiBase: '/api/admin-api', automatic: true, auth: 'jwt' }

describe('automatic API runtime configuration', () => {
  const fetchMock = vi.fn<typeof fetch>()
  const storage = { getItem: vi.fn(() => 'b1'), setItem: vi.fn() }
  beforeEach(() => {
    vi.resetModules(); vi.stubEnv('MODE', 'production')
    fetchMock.mockReset(); storage.getItem.mockClear(); storage.setItem.mockClear()
    vi.stubGlobal('fetch', fetchMock); vi.stubGlobal('localStorage', storage)
  })
  afterEach(() => { vi.unstubAllGlobals(); vi.unstubAllEnvs() })

  it('loads only the automatic same-origin route without consulting saved backend selection', async () => {
    fetchMock.mockResolvedValueOnce(response(configuration))
    const api = await import('./api')
    expect(await api.initializeApi()).toEqual(configuration)
    expect(storage.getItem).not.toHaveBeenCalled(); expect(storage.setItem).not.toHaveBeenCalled()
    expect(fetchMock.mock.calls[0]?.[0]).toBe('/runtime-config.json')
    expect(fetchMock.mock.calls[0]?.[1]).toMatchObject({ cache: 'no-store', credentials: 'same-origin', mode: 'same-origin' })
    expect(fetchMock.mock.calls[0]?.[1]?.signal).toBeInstanceOf(AbortSignal)
  })

  it('shares initialization and caches an immutable valid route', async () => {
    let finish!: (response: Response) => void
    fetchMock.mockReturnValueOnce(new Promise(resolve => { finish = resolve }))
    const api = await import('./api')
    const first = api.initializeApi(), second = api.initializeApi()
    finish(response(configuration))
    const value = await first
    expect(await second).toBe(value); expect(await api.initializeApi()).toBe(value)
    expect(Object.isFrozen(value)).toBe(true); expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it.each([null, {}, { backends: [{ id: 'b0' }] }, { ...configuration, automatic: false },
    ...['https://evil.example/admin-api', '//evil.example/admin-api', '/admin-api', '/api/backends/b0/admin-api', '/api/admin-api/', '/api/admin-api?target=evil', '/api/../admin-api'].map(apiBase => ({ ...configuration, apiBase })),
  ])('rejects unsafe or obsolete runtime configuration %j', async value => {
    fetchMock.mockResolvedValueOnce(response(value))
    const api = await import('./api')
    await expect(api.initializeApi()).rejects.toThrow(/配置/)
    await expect(api.login('admin', 'password')).rejects.toThrow(/配置/)
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it.each(['network', 'http', 'html', 'timeout'])('allows explicit initialization retry after %s failure', async failure => {
    if (failure === 'network') fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'))
    else if (failure === 'http') fetchMock.mockResolvedValueOnce(response({}, 503))
    else if (failure === 'html') fetchMock.mockResolvedValueOnce(new Response('<html>shell</html>'))
    else fetchMock.mockRejectedValueOnce(new DOMException('timeout', 'TimeoutError'))
    const api = await import('./api')
    await expect(api.initializeApi()).rejects.toThrow(/配置/)
    fetchMock.mockResolvedValueOnce(response(configuration))
    expect(await api.initializeApi()).toEqual(configuration)
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it('blocks all real operations before valid initialization', async () => {
    const api = await import('./api')
    for (const operation of [() => api.getSession(), () => api.login('admin', 'password'), () => api.logout(), () => api.getDevices(), () => api.getGames(), () => api.assignGame(1, 638), () => api.createScanLoginTicket()]) {
      await expect(operation()).rejects.toThrow(/配置/)
    }
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('keeps demo isolated without loading the real manifest', async () => {
    vi.stubEnv('MODE', 'demo')
    const api = await import('./api')
    expect(await api.initializeApi()).toEqual({ apiBase: '/admin-api', automatic: false, auth: 'session' })
    fetchMock.mockResolvedValueOnce(response({ user: null, csrfToken: 'demo' }))
    await api.getSession()
    expect(fetchMock.mock.calls.map(call => call[0])).toEqual(['/admin-api/session'])
  })
})

describe('application automatic startup', () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => { vi.resetModules(); vi.stubEnv('MODE', 'production'); fetchMock.mockReset(); vi.stubGlobal('fetch', fetchMock) })
  afterEach(() => { vi.unstubAllGlobals(); vi.unstubAllEnvs() })
  async function application() {
    const { default: App } = await import('../App.vue')
    const setup = (App as ComponentOptions).setup!
    let state: Record<string, unknown>
    const component = { ...App, setup: (props: Parameters<typeof setup>[0], context: Parameters<typeof setup>[1]) => {
      state ??= setup(props, context) as Record<string, unknown>; return state
    } }
    const render = () => renderToString(createSSRApp(component))
    await render()
    return { render, initialize: () => (state.initializeWorkspace as () => Promise<void>)(),
      submitLogin: (username: string, password: string) => (state.submitLogin as (u: string, p: string) => Promise<void>)(username, password),
      openPicker: (device: unknown) => (state.openPicker as (device: unknown) => void)(device),
      saveGame: (id: number) => (state.saveGame as (id: number) => Promise<void>)(id) }
  }

  it('connects the automatic route without rendering backend selection on login or workspace', async () => {
    fetchMock.mockResolvedValueOnce(response(configuration))
    fetchMock.mockResolvedValueOnce(response({ user: null, scanLoginEnabled: true, authMode: 'jwt' }))
    const app = await application(); await app.initialize()
    expect(fetchMock.mock.calls.map(call => call[0])).toEqual(['/runtime-config.json', '/api/admin-api/session'])
    const login = await app.render()
    expect(login).toContain('登录管理后台'); expect(login).not.toContain('正式后端'); expect(login).not.toContain('<select')
    fetchMock.mockResolvedValueOnce(response({ user: { username: 'admin' }, token: { accessToken: 'access', refreshToken: 'refresh', expires: '2099-01-01T00:00:00Z', refreshExpires: '2099-01-01T08:00:00Z' }, authMode: 'jwt' }))
    fetchMock.mockResolvedValueOnce(response([])); fetchMock.mockResolvedValueOnce(response([]))
    await app.submitLogin('admin', 'password')
    const workspace = await app.render()
    expect(workspace).toContain('admin'); expect(workspace).not.toContain('切换正式后端'); expect(workspace).not.toContain('backend-selector')
    expect(fetchMock.mock.calls[2]?.[0]).toBe('/api/admin-api/login')
  })

  it('disables login on failed configuration and retries only when requested', async () => {
    fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'))
    const app = await application(); await app.initialize()
    const html = await app.render()
    expect(html).toContain('重试加载配置'); expect(html).toMatch(/<button[^>]*type="submit"[^>]*disabled/)
    expect(html).toMatch(/<input[^>]*id="username"[^>]*disabled/)
    await app.submitLogin('admin', 'password'); expect(fetchMock).toHaveBeenCalledTimes(1)
    fetchMock.mockResolvedValueOnce(response(configuration)); fetchMock.mockResolvedValueOnce(response({ user: null, authMode: 'jwt' }))
    await app.initialize()
    expect(fetchMock.mock.calls.map(call => call[0])).toEqual(['/runtime-config.json', '/runtime-config.json', '/api/admin-api/session'])
    expect(await app.render()).not.toContain('重试加载配置')
  })

  it('leaves a failed save open for explicit retry and sends the write once', async () => {
    const device = { id: 1, uuid: 'device', name: '设备', ip: '', status: 'unknown', updatedAt: '', gameId: null }
    fetchMock.mockResolvedValueOnce(response(configuration)); fetchMock.mockResolvedValueOnce(response({ user: { username: 'admin' }, token: { accessToken: 'access', refreshToken: 'refresh', expires: '2099-01-01T00:00:00Z', refreshExpires: '2099-01-01T08:00:00Z' }, authMode: 'jwt' }))
    fetchMock.mockResolvedValueOnce(response([device])); fetchMock.mockResolvedValueOnce(response([{ id: 638, name: '游戏', description: '', category: '' }]))
    const app = await application(); await app.initialize(); app.openPicker(device)
    fetchMock.mockResolvedValueOnce(response({ message: '保存失败' }, 503)); await app.saveGame(638)
    expect(fetchMock).toHaveBeenCalledTimes(5); expect(fetchMock.mock.calls[4]?.[0]).toBe('/api/admin-api/devices/1/game')
    expect(await app.render()).toContain('保存失败')
  })
})
