import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status })
const token = (accessToken: string, expires = '2026-10-16T15:00:00Z') => ({ accessToken, refreshToken: `refresh-${accessToken}`, expires, refreshExpires: '2026-10-16T18:00:00Z' })
const session = (accessToken: string) => ({ user: { username: 'admin' }, token: token(accessToken), authMode: 'jwt' })
const device = { id: 1, uuid: 'device', name: '设备', ip: '', status: 'unknown', updatedAt: '', gameId: 638 }
function deferred<T>() { let resolve!: (value: T) => void; const promise = new Promise<T>(yes => { resolve = yes }); return { promise, resolve } }

describe('automatic JWT API', () => {
  const fetchMock = vi.fn<typeof fetch>()
  let storageEvents: EventTarget
  let stored: string | null
  const localStorage = { getItem: vi.fn<(key: string) => string | null>(() => stored), setItem: vi.fn((_key: string, value: string) => { stored = value }), removeItem: vi.fn(() => { stored = null }) }
  const sessionStorage = { getItem: vi.fn<(key: string) => string | null>(() => null), setItem: vi.fn() }
  beforeEach(async () => {
    vi.useFakeTimers(); vi.setSystemTime('2026-10-16T12:00:00Z'); storageEvents = new EventTarget()
    vi.resetModules(); vi.stubEnv('MODE', 'production'); fetchMock.mockReset(); vi.stubGlobal('fetch', fetchMock)
    localStorage.getItem.mockReset(); localStorage.getItem.mockReturnValue(null); localStorage.setItem.mockReset(); localStorage.removeItem.mockReset()
    stored = null
    localStorage.getItem.mockImplementation(() => stored)
    sessionStorage.getItem.mockReset(); sessionStorage.getItem.mockReturnValue(null); sessionStorage.setItem.mockReset()
    vi.stubGlobal('localStorage', localStorage); vi.stubGlobal('sessionStorage', sessionStorage)
    vi.stubGlobal('addEventListener', storageEvents.addEventListener.bind(storageEvents))
    fetchMock.mockResolvedValueOnce(response({ apiBase: '/api/admin-api', automatic: true, auth: 'jwt' }))
    const api = await import('./api'); await api.initializeApi(); fetchMock.mockClear()
  })
  afterEach(() => { vi.useRealTimers(); vi.unstubAllEnvs(); vi.unstubAllGlobals() })

  it('does not replay a business write after HTTP or network failure', async () => {
    const api = await import('./api')
    fetchMock.mockResolvedValueOnce(response(session('access')))
    await api.login('admin', 'password')
    fetchMock.mockResolvedValueOnce(response({ message: 'expired' }, 401))
    await expect(api.assignGame(1, 638)).rejects.toMatchObject({ status: 401 })
    expect(fetchMock).toHaveBeenCalledTimes(2)
    expect(localStorage.removeItem).toHaveBeenCalled()
  })

  it('singleflights token refresh before concurrent writes', async () => {
    const api = await import('./api')
    localStorage.getItem.mockReturnValue(JSON.stringify(token('old', '2026-10-16T12:00:20Z')))
    fetchMock.mockResolvedValueOnce(response({ user: { username: 'admin' }, token: token('new'), authMode: 'jwt' }))
    fetchMock.mockResolvedValueOnce(response(device)); fetchMock.mockResolvedValueOnce(response(device))
    await Promise.all([api.assignGame(1, 638), api.assignGame(1, 638)])
    expect(fetchMock.mock.calls.map(call => call[0])).toEqual(['/api/admin-api/refresh', '/api/admin-api/devices/1/game', '/api/admin-api/devices/1/game'])
    expect(new Headers(fetchMock.mock.calls[1]?.[1]?.headers).get('Authorization')).toBe('Bearer new')
    expect(new Headers(fetchMock.mock.calls[2]?.[1]?.headers).get('Authorization')).toBe('Bearer new')
  })

  it('serializes refresh across tabs and adopts the rotated token without changing identity', async () => {
    const oldPair = { accessToken: 'old-access', refreshToken: 'family.old-secret', expires: '2026-10-16T12:00:20Z', refreshExpires: '2026-10-16T18:00:00Z' }
    const newPair = { accessToken: 'new-access', refreshToken: 'family.new-secret', expires: '2026-10-16T15:00:00Z', refreshExpires: oldPair.refreshExpires }
    stored = JSON.stringify(oldPair)
    const firstModule = await import('./api')
    vi.resetModules()
    fetchMock.mockResolvedValueOnce(response({ apiBase: '/api/admin-api', automatic: true, auth: 'jwt' }))
    const secondModule = await import('./api')
    await secondModule.initializeApi()
    await secondModule.initializeApi()
    const lockQueue: Array<() => void> = []
    let lockHeld = false
    const locks = { request: vi.fn(async (_name: string, callback: () => Promise<void>) => {
      if (lockHeld) await new Promise<void>(resolve => lockQueue.push(resolve))
      lockHeld = true
      try { await callback() } finally { lockHeld = false; lockQueue.shift()?.() }
    }) }
    vi.stubGlobal('navigator', { locks })
    const firstIdentity = vi.fn(); const secondIdentity = vi.fn()
    firstModule.onAuthIdentityChange(firstIdentity); secondModule.onAuthIdentityChange(secondIdentity)
    const refresh = deferred<Response>()
    fetchMock.mockReturnValueOnce(refresh.promise)
    const firstRead = firstModule.getDevices(); const secondRead = secondModule.getDevices()
    await vi.waitFor(() => expect(fetchMock.mock.calls.some(([url]) => url === '/api/admin-api/refresh')).toBe(true))
    stored = JSON.stringify(newPair)
    refresh.resolve(response({ user: { username: 'admin' }, token: newPair, authMode: 'jwt' }))
    fetchMock.mockResolvedValueOnce(response([device])); fetchMock.mockResolvedValueOnce(response([device]))
    await Promise.all([firstRead, secondRead])
    expect(fetchMock.mock.calls.filter(([url]) => url === '/api/admin-api/refresh')).toHaveLength(1)
    expect(fetchMock.mock.calls.filter(([url]) => url === '/api/admin-api/devices')).toHaveLength(2)
    expect(firstIdentity).not.toHaveBeenCalled(); expect(secondIdentity).not.toHaveBeenCalled()
    expect(JSON.parse(stored!).refreshToken).toBe('family.new-secret')
  })

  it('uses a stable per-tab proof for login/logout and never sends cookies or CSRF', async () => {
    const api = await import('./api')
    const proof = 'a'.repeat(64); sessionStorage.getItem.mockReturnValue(proof)
    fetchMock.mockResolvedValueOnce(response(session('access'))); await api.login('admin', 'password')
    fetchMock.mockResolvedValueOnce(response({ user: null, authMode: 'jwt' })); await api.logout()
    for (const [, init] of fetchMock.mock.calls) {
      expect(init?.credentials).toBe('omit')
      const headers = new Headers(init?.headers)
      expect(headers.has('Cookie')).toBe(false); expect(headers.has('X-CSRF-Token')).toBe(false)
      expect(headers.get('X-Admin-Scan-Owner')).toBe(proof)
    }
  })

  it('rejects a late login and keeps the newer login token', async () => {
    const api = await import('./api')
    const old = deferred<Response>(); fetchMock.mockReturnValueOnce(old.promise)
    const oldLogin = api.login('old', 'password')
    fetchMock.mockResolvedValueOnce(response(session('new'))); await api.login('new', 'password')
    old.resolve(response(session('old')))
    await expect(oldLogin).rejects.toThrow(/登录状态已更新/)
    fetchMock.mockResolvedValueOnce(response([device])); await api.getDevices()
    expect(new Headers(fetchMock.mock.calls.at(-1)?.[1]?.headers).get('Authorization')).toBe('Bearer new')
  })

  it('does not let a late scan consume replace a newer login', async () => {
    const api = await import('./api')
    const old = deferred<Response>(); fetchMock.mockReturnValueOnce(old.promise)
    const consume = api.consumeScanLoginTicket('a'.repeat(32))
    fetchMock.mockResolvedValueOnce(response(session('new'))); await api.login('new', 'password')
    old.resolve(response({ ...session('scan-old'), user: { username: 'scan-user', role: 'device_manager' } }))
    await expect(consume).rejects.toThrow(/登录状态已更新/)
    fetchMock.mockResolvedValueOnce(response([device])); await api.getDevices()
    expect(new Headers(fetchMock.mock.calls.at(-1)?.[1]?.headers).get('Authorization')).toBe('Bearer new')
  })

  it('does not let a late logout clear a newer login', async () => {
    const api = await import('./api')
    fetchMock.mockResolvedValueOnce(response(session('before'))); await api.login('before', 'password')
    const pendingLogout = deferred<Response>(); fetchMock.mockReturnValueOnce(pendingLogout.promise)
    const logout = api.logout()
    fetchMock.mockResolvedValueOnce(response(session('new'))); await api.login('new', 'password')
    pendingLogout.resolve(response({ user: null, authMode: 'jwt' }))
    await expect(logout).rejects.toThrow(/登录状态已更新/)
    fetchMock.mockResolvedValueOnce(response([device])); await api.getDevices()
    expect(new Headers(fetchMock.mock.calls.at(-1)?.[1]?.headers).get('Authorization')).toBe('Bearer new')
  })

  it('aborts a write after its refresh crosses into a newer login', async () => {
    const api = await import('./api')
    localStorage.getItem.mockReturnValue(JSON.stringify(token('old', '2026-10-16T12:00:20Z')))
    const refresh = deferred<Response>(); fetchMock.mockReturnValueOnce(refresh.promise)
    const oldWrite = api.assignGame(1, 638)
    fetchMock.mockResolvedValueOnce(response(session('new'))); await api.login('new', 'password')
    refresh.resolve(response({ user: { username: 'old' }, token: token('refreshed-old'), authMode: 'jwt' }))
    await expect(oldWrite).rejects.toMatchObject({ status: 0, message: expect.stringContaining('登录状态已更新') })
    expect(fetchMock).toHaveBeenCalledTimes(2)
    fetchMock.mockResolvedValueOnce(response(device)); await api.assignGame(1, 638)
    expect(new Headers(fetchMock.mock.calls.at(-1)?.[1]?.headers).get('Authorization')).toBe('Bearer new')
  })

  it('rejects a late Session identity after a newer login', async () => {
    const api = await import('./api')
    const old = deferred<Response>(); fetchMock.mockReturnValueOnce(old.promise)
    const oldSession = api.getSession()
    fetchMock.mockResolvedValueOnce(response(session('new'))); await api.login('new', 'password')
    old.resolve(response({ user: { username: 'old' }, authMode: 'jwt' }))
    await expect(oldSession).rejects.toMatchObject({ status: 0, message: expect.stringContaining('登录状态已更新') })
  })

  it('does not clear a new token when an older request returns 401', async () => {
    const api = await import('./api')
    fetchMock.mockResolvedValueOnce(response(session('old'))); await api.login('old', 'password')
    const pendingRead = deferred<Response>(); fetchMock.mockReturnValueOnce(pendingRead.promise)
    const read = api.getDevices()
    fetchMock.mockResolvedValueOnce(response(session('new'))); await api.login('new', 'password')
    pendingRead.resolve(response({ message: 'expired' }, 401))
    await expect(read).rejects.toMatchObject({ status: 0, message: expect.stringContaining('登录状态已更新') })
    fetchMock.mockResolvedValueOnce(response([device])); await api.getDevices()
    expect(new Headers(fetchMock.mock.calls.at(-1)?.[1]?.headers).get('Authorization')).toBe('Bearer new')
  })

  it('syncs token replacement and logout from another browser tab', async () => {
    const api = await import('./api')
    fetchMock.mockResolvedValueOnce(response(session('initial'))); await api.login('admin', 'password')
    stored = JSON.stringify(token('other-tab'))
    storageEvents.dispatchEvent(Object.assign(new Event('storage'), { key: 'game-admin-jwt-v1', newValue: stored }))
    fetchMock.mockResolvedValueOnce(response([device])); await api.getDevices()
    expect(new Headers(fetchMock.mock.calls.at(-1)?.[1]?.headers).get('Authorization')).toBe('Bearer other-tab')
    stored = null
    storageEvents.dispatchEvent(Object.assign(new Event('storage'), { key: 'game-admin-jwt-v1', newValue: null }))
    fetchMock.mockResolvedValueOnce(response({ user: null, authMode: 'jwt' })); await api.getSession()
    expect(new Headers(fetchMock.mock.calls.at(-1)?.[1]?.headers).has('Authorization')).toBe(false)
  })

  it('ignores a delayed storage event payload and adopts the current persisted identity', async () => {
    const api = await import('./api')
    stored = JSON.stringify(token('new-login'))
    storageEvents.dispatchEvent(Object.assign(new Event('storage'), { key: 'game-admin-jwt-v1', newValue: JSON.stringify(token('old-external')) }))
    fetchMock.mockResolvedValueOnce(response([device])); await api.getDevices()
    expect(new Headers(fetchMock.mock.calls.at(-1)?.[1]?.headers).get('Authorization')).toBe('Bearer new-login')
  })

  it('removes stale persisted credentials when storage quota blocks a new login token', async () => {
    const api = await import('./api')
    localStorage.setItem.mockImplementation(() => { throw new DOMException('quota', 'QuotaExceededError') })
    fetchMock.mockResolvedValueOnce(response(session('new-memory'))); await api.login('admin', 'password')
    expect(localStorage.removeItem).toHaveBeenCalled()
    fetchMock.mockResolvedValueOnce(response([device])); await api.getDevices()
    expect(new Headers(fetchMock.mock.calls.at(-1)?.[1]?.headers).get('Authorization')).toBe('Bearer new-memory')
  })

  it('recovers an anonymous session and scan capability after a stored access token is revoked', async () => {
    const api = await import('./api')
    localStorage.getItem.mockReturnValue(JSON.stringify(token('revoked')))
    fetchMock.mockResolvedValueOnce(response({ message: 'revoked' }, 401))
    fetchMock.mockResolvedValueOnce(response({ user: null, scanLoginEnabled: true, authMode: 'jwt' }))
    expect(await api.getSession()).toMatchObject({ user: null, scanLoginEnabled: true, authMode: 'jwt' })
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })
})
