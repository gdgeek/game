import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), {
  status,
  headers: { 'Content-Type': 'application/json' },
})

describe('admin API client', () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.resetModules()
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    vi.stubEnv('MODE', 'demo')
  })

  afterEach(() => { vi.unstubAllGlobals(); vi.unstubAllEnvs() })

  it('sends session cookies and the session CSRF token when assigning a game', async () => {
    const device = { id: 1, uuid: 'VR-A01', name: '一号设备', ip: '192.168.1.101', status: 'online', updatedAt: '', gameId: 639 }
    fetchMock
      .mockResolvedValueOnce(response({ user: { username: 'dirui' }, csrfToken: 'token-from-server' }))
      .mockResolvedValueOnce(response(device))
    const api = await import('./api')
    await api.getSession()
    expect(await api.assignGame(1, 639)).toEqual(device)
    const [url, init] = fetchMock.mock.calls[1]!
    expect(url).toBe('/admin-api/devices/1/game')
    expect(init?.method).toBe('PUT')
    expect(init?.credentials).toBe('same-origin')
    expect(new Headers(init?.headers).get('X-CSRF-Token')).toBe('token-from-server')
    expect(JSON.parse(String(init?.body))).toEqual({ gameId: 639 })
  })

  it('obtains a CSRF token before login when no session has been loaded', async () => {
    fetchMock
      .mockResolvedValueOnce(response({ user: null, csrfToken: 'before-login' }))
      .mockResolvedValueOnce(response({ user: { username: 'dirui' }, csrfToken: 'after-login' }))
    const api = await import('./api')
    const session = await api.login('dirui', 'provided-password')
    expect(session.user?.username).toBe('dirui')
    expect(fetchMock.mock.calls[0]?.[0]).toBe('/admin-api/session')
    expect(new Headers(fetchMock.mock.calls[1]?.[1]?.headers).get('X-CSRF-Token')).toBe('before-login')
  })

  it('preserves HTTP status and the actionable server error message', async () => {
    fetchMock.mockResolvedValue(response({ message: '登录已过期，请重新登录' }, 401))
    const api = await import('./api')
    await expect(api.getDevices()).rejects.toMatchObject({ status: 401, message: '登录已过期，请重新登录' })
  })

  it('returns a useful error for a non-JSON server failure', async () => {
    fetchMock.mockResolvedValue(new Response('<html>Bad gateway</html>', { status: 502 }))
    const api = await import('./api')
    await expect(api.getGames()).rejects.toMatchObject({ status: 502, message: '服务暂时不可用，请稍后重试' })
  })

  it('converts network failures into ApiError with status zero', async () => {
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))
    const api = await import('./api')
    await expect(api.getSession()).rejects.toBeInstanceOf(api.ApiError)
    await expect(api.getSession()).rejects.toMatchObject({ status: 0, message: '无法连接服务器，请检查网络连接' })
  })

  it('loads a fresh CSRF token when logging in after a session expires', async () => {
    fetchMock
      .mockResolvedValueOnce(response({ user: { username: 'dirui' }, csrfToken: 'expired-token' }))
      .mockResolvedValueOnce(response({ message: '登录已过期，请重新登录' }, 401))
      .mockResolvedValueOnce(response({ user: null, csrfToken: 'fresh-token' }))
      .mockResolvedValueOnce(response({ user: { username: 'dirui' }, csrfToken: 'logged-in-token' }))
    const api = await import('./api')
    await api.getSession()
    await expect(api.getDevices()).rejects.toMatchObject({ status: 401 })
    await api.login('dirui', 'provided-password')
    expect(fetchMock.mock.calls[2]?.[0]).toBe('/admin-api/session')
    expect(new Headers(fetchMock.mock.calls[3]?.[1]?.headers).get('X-CSRF-Token')).toBe('fresh-token')
  })

  it('rejects a successful HTML response instead of passing null to Vue', async () => {
    fetchMock.mockResolvedValue(new Response('<html>Application shell</html>', { status: 200 }))
    const api = await import('./api')
    await expect(api.getDevices()).rejects.toMatchObject({ status: 502, message: '服务返回的数据格式不正确，请重试' })
  })

  it.each(['session', 'login', 'devices', 'games', 'assignment'] as const)('validates the %s response shape', async (endpoint) => {
    const api = await import('./api')
    if (endpoint === 'login' || endpoint === 'assignment') {
      fetchMock.mockResolvedValueOnce(response({ user: null, csrfToken: 'valid-token' }))
    }
    fetchMock.mockResolvedValueOnce(response(endpoint === 'devices' || endpoint === 'games' ? [{ id: 1 }] : null))
    const operation = endpoint === 'session' ? api.getSession()
      : endpoint === 'login' ? api.login('dirui', 'provided-password')
      : endpoint === 'devices' ? api.getDevices()
      : endpoint === 'games' ? api.getGames()
      : api.assignGame(1, 638)
    await expect(operation).rejects.toMatchObject({ status: 502, message: '服务返回的数据格式不正确，请重试' })
  })

  it('rejects an invalid device status before rendering device statistics', async () => {
    fetchMock.mockResolvedValue(response([{ id: 1, uuid: '', name: '', ip: '', status: 'broken', updatedAt: '', gameId: null }]))
    const api = await import('./api')
    await expect(api.getDevices()).rejects.toMatchObject({ status: 502 })
  })
})
