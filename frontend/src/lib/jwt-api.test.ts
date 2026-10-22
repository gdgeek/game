import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status })
const config = { apiBase: '/api/admin-api', automatic: true, auth: 'jwt' }
const token = { accessToken: 'access-one', refreshToken: 'refresh-one', expires: '2026-10-16T13:00:00Z', refreshExpires: '2026-10-16T18:00:00Z' }
const auth = { user: { username: 'admin' }, token, authMode: 'jwt' }

describe('JWT admin API', () => {
  const fetchMock = vi.fn<typeof fetch>()
  const local = { getItem: vi.fn<(key: string) => string | null>(() => null), setItem: vi.fn(), removeItem: vi.fn() }
  const session = { getItem: vi.fn<(key: string) => string | null>(() => null), setItem: vi.fn(), removeItem: vi.fn() }
  beforeEach(() => {
    vi.useFakeTimers(); vi.setSystemTime('2026-10-16T12:00:00Z')
    vi.resetModules(); fetchMock.mockReset(); local.getItem.mockReset(); local.getItem.mockReturnValue(null); local.setItem.mockReset(); local.removeItem.mockReset()
    session.getItem.mockReset(); session.getItem.mockReturnValue(null); session.setItem.mockReset(); session.removeItem.mockReset()
    vi.stubEnv('MODE', 'production'); vi.stubGlobal('fetch', fetchMock); vi.stubGlobal('localStorage', local); vi.stubGlobal('sessionStorage', session)
    fetchMock.mockResolvedValueOnce(response(config))
  })
  afterEach(() => { vi.useRealTimers(); vi.unstubAllGlobals(); vi.unstubAllEnvs() })

  it('loads jwt mode and sends anonymous session without cookies or CSRF', async () => {
    fetchMock.mockResolvedValueOnce(response({ user: null, scanLoginEnabled: true, authMode: 'jwt' }))
    const api = await import('./api'); await api.initializeApi(); await api.getSession()
    expect(fetchMock.mock.calls[0]?.[0]).toBe('/runtime-config.json')
    expect(fetchMock.mock.calls[1]?.[1]).toMatchObject({ credentials: 'omit' })
    expect(new Headers(fetchMock.mock.calls[1]?.[1]?.headers).has('X-CSRF-Token')).toBe(false)
  })

  it('stores login tokens and sends Bearer Authorization on subsequent calls', async () => {
    fetchMock.mockResolvedValueOnce(response({ user: null, scanLoginEnabled: false, authMode: 'jwt' }))
    fetchMock.mockResolvedValueOnce(response(auth))
    fetchMock.mockResolvedValueOnce(response([]))
    const api = await import('./api'); await api.initializeApi(); await api.getSession(); await api.login('admin', 'secret'); await api.getDevices()
    expect(fetchMock.mock.calls[2]?.[0]).toBe('/api/admin-api/login')
    expect(fetchMock.mock.calls[3]?.[1]).toMatchObject({ credentials: 'omit' })
    expect(new Headers(fetchMock.mock.calls[3]?.[1]?.headers).get('Authorization')).toBe('Bearer access-one')
    expect(local.setItem).toHaveBeenCalled()
  })

  it('refreshes before an expiring write and never retries the business write', async () => {
    const expiredSoon = { ...token, expires: '2026-10-16T12:00:30Z' }
    local.getItem.mockReturnValue(JSON.stringify(expiredSoon))
    fetchMock.mockResolvedValueOnce(response({ user: { username: 'admin' }, token: { ...token, accessToken: 'access-two' }, authMode: 'jwt' }))
    fetchMock.mockResolvedValueOnce(response({ message: 'denied' }, 401))
    const api = await import('./api'); await api.initializeApi()
    await expect(api.assignGame(2, 3)).rejects.toMatchObject({ status: 401 })
    expect(fetchMock.mock.calls.map(call => call[0])).toEqual(['/runtime-config.json', '/api/admin-api/refresh', '/api/admin-api/devices/2/game'])
    expect(fetchMock).toHaveBeenCalledTimes(3)
  })

  it('deletes a device using the admin Bearer without cookies or CSRF', async () => {
    local.getItem.mockReturnValue(JSON.stringify(token))
    fetchMock.mockResolvedValueOnce(response({ id: 7, deleted: true }))
    const api = await import('./api'); await api.initializeApi()
    await api.deleteDevice(7)
    const [url, init] = fetchMock.mock.calls[1]!
    expect(url).toBe('/api/admin-api/devices/7')
    expect(init).toMatchObject({ method: 'DELETE', credentials: 'omit' })
    expect(new Headers(init?.headers).get('Authorization')).toBe('Bearer access-one')
    expect(new Headers(init?.headers).has('X-CSRF-Token')).toBe(false)
  })

  it.each([{ id: 8, deleted: true }, { id: 7, deleted: false }, null])('rejects invalid deletion acknowledgement %j', async body => {
    fetchMock.mockResolvedValueOnce(response(body))
    const api = await import('./api'); await api.initializeApi()
    await expect(api.deleteDevice(7)).rejects.toMatchObject({ status: 502 })
  })
})
