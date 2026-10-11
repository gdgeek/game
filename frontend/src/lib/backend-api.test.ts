import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status })
const device = { id: 1, uuid: 'device-1', name: '设备一', ip: '', status: 'unknown', updatedAt: '', gameId: 638 }
const backends = ['b0', 'b1'].map(id => ({ id, name: `正式后端 ${id}`, host: `${id}.example.test` }))

describe('explicit production backend selection', () => {
  const fetchMock = vi.fn<typeof fetch>()
  let stored: Map<string, string>

  beforeEach(async () => {
    vi.resetModules()
    vi.stubEnv('MODE', 'production')
    stored = new Map()
    vi.stubGlobal('localStorage', {
      getItem: (key: string) => stored.get(key) ?? null,
      setItem: (key: string, value: string) => stored.set(key, value),
    })
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    fetchMock.mockResolvedValueOnce(response({ backends }))
    const api = await import('./api')
    await api.initializeBackends()
    fetchMock.mockClear()
  })

  afterEach(() => { vi.unstubAllGlobals(); vi.unstubAllEnvs() })

  it('defaults to b0 and preserves the explicitly selected production backend on reload', async () => {
    let api = await import('./api')
    expect(api.getSelectedBackend()).toBe('b0')
    api.setSelectedBackend('b1')
    vi.resetModules()
    api = await import('./api')
    fetchMock.mockResolvedValueOnce(response({ backends }))
    await api.initializeBackends()
    expect(api.getSelectedBackend()).toBe('b1')
    fetchMock.mockClear()
    fetchMock.mockResolvedValueOnce(response([]))
    await api.getDevices()
    expect(fetchMock.mock.calls[0]?.[0]).toBe('/backends/b1/admin-api/devices')
  })

  it('keeps CSRF tokens scoped to the selected backend', async () => {
    const api = await import('./api')
    fetchMock.mockResolvedValueOnce(response({ user: null, csrfToken: 'b0-token' }))
    await api.getSession()
    api.setSelectedBackend('b1')
    fetchMock.mockResolvedValueOnce(response({ user: null, csrfToken: 'b1-token' }))
    await api.getSession()
    fetchMock.mockResolvedValueOnce(response(device))
    await api.assignGame(1, 638)
    api.setSelectedBackend('b0')
    fetchMock.mockResolvedValueOnce(response(device))
    await api.assignGame(1, 638)
    expect(fetchMock.mock.calls[2]?.[0]).toBe('/backends/b1/admin-api/devices/1/game')
    expect(new Headers(fetchMock.mock.calls[2]?.[1]?.headers).get('X-CSRF-Token')).toBe('b1-token')
    expect(fetchMock.mock.calls[3]?.[0]).toBe('/backends/b0/admin-api/devices/1/game')
    expect(new Headers(fetchMock.mock.calls[3]?.[1]?.headers).get('X-CSRF-Token')).toBe('b0-token')
  })

  it('does not let a late b0 session response replace the active b1 token', async () => {
    const api = await import('./api')
    let finishB0!: (value: Response) => void
    fetchMock.mockReturnValueOnce(new Promise((resolve) => { finishB0 = resolve }))
    const pendingB0 = api.getSession()
    api.setSelectedBackend('b1')
    fetchMock.mockResolvedValueOnce(response({ user: null, csrfToken: 'b1-token' }))
    await api.getSession()
    finishB0(response({ user: null, csrfToken: 'late-b0-token' }))
    await pendingB0
    fetchMock.mockResolvedValueOnce(response(device))
    await api.assignGame(1, 638)
    expect(fetchMock.mock.calls[2]?.[0]).toBe('/backends/b1/admin-api/devices/1/game')
    expect(new Headers(fetchMock.mock.calls[2]?.[1]?.headers).get('X-CSRF-Token')).toBe('b1-token')
  })

  it('keeps a pending login bootstrap and login on the backend where the operation began', async () => {
    const api = await import('./api')
    let finishSession!: (value: Response) => void
    fetchMock.mockReturnValueOnce(new Promise((resolve) => { finishSession = resolve }))
    const pendingLogin = api.login('dirui', 'provided-password')
    api.setSelectedBackend('b1')
    fetchMock.mockResolvedValueOnce(response({ user: { username: 'dirui' }, csrfToken: 'b0-logged-in' }))
    finishSession(response({ user: null, csrfToken: 'b0-login-token' }))
    await pendingLogin
    expect(fetchMock.mock.calls[1]?.[0]).toBe('/backends/b0/admin-api/login')
    expect(new Headers(fetchMock.mock.calls[1]?.[1]?.headers).get('X-CSRF-Token')).toBe('b0-login-token')
  })

  it('clearly reports an undeployed admin API instead of using demo data', async () => {
    const api = await import('./api')
    fetchMock.mockResolvedValueOnce(response({ message: 'Page not found.' }, 404))
    await expect(api.getSession()).rejects.toMatchObject({ status: 404, message: '该后端尚未部署管理接口' })
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('never fails over a failed write to the other production backend', async () => {
    const api = await import('./api')
    fetchMock.mockResolvedValueOnce(response({ user: null, csrfToken: 'b0-token' }))
    await api.getSession()
    fetchMock.mockResolvedValueOnce(response({ message: '保存失败' }, 503))
    await expect(api.assignGame(1, 638)).rejects.toMatchObject({ status: 503 })
    expect(fetchMock).toHaveBeenCalledTimes(2)
    expect(fetchMock.mock.calls[1]?.[0]).toBe('/backends/b0/admin-api/devices/1/game')
  })
})
