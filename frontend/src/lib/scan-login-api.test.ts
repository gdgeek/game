import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status })
const ticketId = 'a'.repeat(32)
const ticket = { ticketId, qrText: `bujiaban-admin-login://v1/${ticketId}?secret=${'b'.repeat(64)}&origin=https%3A%2F%2Fb0.example.test`, expiresAt: '2099-10-11T12:05:00Z' }
const token = { accessToken: 'access', refreshToken: 'refresh', expires: '2099-10-11T15:00:00Z', refreshExpires: '2099-10-11T20:00:00Z' }
const config = { apiBase: '/api/admin-api', automatic: true, auth: 'jwt' }
const proof = 'c'.repeat(64)

describe('JWT scan login API contract', () => {
  const fetchMock = vi.fn<typeof fetch>()
  const sessionStorage = { getItem: vi.fn(() => proof), setItem: vi.fn() }
  beforeEach(async () => {
    vi.resetModules(); vi.stubEnv('MODE', 'production'); fetchMock.mockReset()
    sessionStorage.getItem.mockReset(); sessionStorage.getItem.mockReturnValue(proof)
    vi.stubGlobal('fetch', fetchMock); vi.stubGlobal('sessionStorage', sessionStorage)
    fetchMock.mockResolvedValueOnce(response(config)); const api = await import('./api'); await api.initializeApi(); fetchMock.mockClear()
  })
  afterEach(() => { vi.unstubAllEnvs(); vi.unstubAllGlobals() })

  it('reads anonymous and authenticated JWT sessions without cookies or CSRF', async () => {
    const api = await import('./api')
    fetchMock.mockResolvedValueOnce(response({ user: null, scanLoginEnabled: true, authMode: 'jwt' }))
    expect(await api.getSession()).toMatchObject({ user: null, authMode: 'jwt', scanLoginEnabled: true })
    fetchMock.mockResolvedValueOnce(response({ user: { username: 'admin' }, authMode: 'jwt' }))
    expect(await api.getSession()).toMatchObject({ user: { username: 'admin' }, authMode: 'jwt' })
    for (const [, init] of fetchMock.mock.calls) {
      expect(init?.credentials).toBe('omit'); expect(new Headers(init?.headers).has('X-CSRF-Token')).toBe(false)
    }
  })

  it('uses the tab proof on password login and persists the returned access/refresh pair', async () => {
    const api = await import('./api')
    fetchMock.mockResolvedValueOnce(response({ user: { username: 'admin' }, token, authMode: 'jwt' }))
    expect(await api.login('admin', 'secret')).toMatchObject({ user: { username: 'admin' } })
    const [, init] = fetchMock.mock.calls[0]!
    expect(new Headers(init?.headers).get('X-Admin-Scan-Owner')).toBe(proof)
    expect(init?.credentials).toBe('omit')
  })

  it('keeps a generated owner proof stable when sessionStorage is unavailable', async () => {
    const api = await import('./api')
    sessionStorage.getItem.mockImplementation(() => { throw new DOMException('blocked', 'SecurityError') })
    sessionStorage.setItem.mockImplementation(() => { throw new DOMException('blocked', 'SecurityError') })
    fetchMock.mockResolvedValueOnce(response({ user: { username: 'admin' }, token, authMode: 'jwt' })); await api.login('admin', 'secret')
    fetchMock.mockResolvedValueOnce(response(ticket)); await api.createScanLoginTicket()
    const loginProof = new Headers(fetchMock.mock.calls[0]?.[1]?.headers).get('X-Admin-Scan-Owner')
    const scanProof = new Headers(fetchMock.mock.calls[1]?.[1]?.headers).get('X-Admin-Scan-Owner')
    expect(loginProof).toMatch(/^[a-f0-9]{64}$/)
    expect(scanProof).toBe(loginProof)
  })

  it('carries the per-tab proof through QR operations and installs the token from consume', async () => {
    const api = await import('./api')
    fetchMock.mockResolvedValueOnce(response(ticket)); await api.createScanLoginTicket()
    fetchMock.mockResolvedValueOnce(response({ status: 'approved', expiresAt: ticket.expiresAt })); await api.getScanLoginTicket(ticketId)
    fetchMock.mockResolvedValueOnce(response({ user: { username: 'wechat-user', role: 'device_manager' }, token, authMode: 'jwt' }))
    await api.consumeScanLoginTicket(ticketId)
    fetchMock.mockResolvedValueOnce(response({ status: 'consumed', expiresAt: ticket.expiresAt })); await api.cancelScanLoginTicket(ticketId)
    fetchMock.mockResolvedValueOnce(response([])); await api.getDevices()
    expect(fetchMock.mock.calls.map(call => call[0])).toEqual([
      '/api/admin-api/scan-login/tickets', `/api/admin-api/scan-login/tickets/${ticketId}`,
      `/api/admin-api/scan-login/tickets/${ticketId}/consume`, `/api/admin-api/scan-login/tickets/${ticketId}/cancel`, '/api/admin-api/devices',
    ])
    for (const [, init] of fetchMock.mock.calls.slice(0, 4)) expect(new Headers(init?.headers).get('X-Admin-Scan-Owner')).toBe(proof)
    expect(new Headers(fetchMock.mock.calls[4]?.[1]?.headers).get('Authorization')).toBe('Bearer access')
    for (const [, init] of fetchMock.mock.calls) expect(init?.credentials).toBe('omit')
  })

  it('rejects consume responses that omit JWT credentials', async () => {
    const api = await import('./api')
    fetchMock.mockResolvedValueOnce(response({ user: { username: 'admin' }, authMode: 'jwt' }))
    await expect(api.consumeScanLoginTicket(ticketId)).rejects.toMatchObject({ status: 502 })
  })

  it('does not retry a consume or restore authentication from a later anonymous session', async () => {
    const api = await import('./api')
    fetchMock.mockRejectedValueOnce(new TypeError('response lost'))
    await expect(api.consumeScanLoginTicket(ticketId)).rejects.toMatchObject({ status: 0 })
    expect(fetchMock).toHaveBeenCalledTimes(1)
    fetchMock.mockResolvedValueOnce(response({ user: null, authMode: 'jwt' }))
    expect(await api.getSession()).toMatchObject({ user: null })
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it.each([
    { ...ticket, ticketId: 'invalid' }, { ...ticket, qrText: ticket.qrText.replace('b'.repeat(64), 'bad') },
    { ...ticket, qrText: ticket.qrText.replace('https%3A', 'http%3A') },
  ])('rejects invalid QR ticket data', async invalid => {
    const api = await import('./api'); fetchMock.mockResolvedValueOnce(response(invalid))
    await expect(api.createScanLoginTicket()).rejects.toMatchObject({ status: 502 })
  })
})
