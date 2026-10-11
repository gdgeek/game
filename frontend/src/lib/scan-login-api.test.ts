import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const response = (body: unknown) => new Response(JSON.stringify(body))
const ticketId = 'a'.repeat(32)
const ticket = {
  ticketId,
  qrText: `bujiaban-admin-login://v1/${ticketId}?secret=${'b'.repeat(64)}&origin=https%3A%2F%2Fb0.example.test`,
  expiresAt: '2026-10-11T12:05:00Z',
}
const backends = ['b0', 'b1'].map(id => ({ id, name: id, host: `${id}.example.test` }))

describe('scan login API contract', () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(async () => {
    vi.resetModules()
    vi.stubEnv('MODE', 'production')
    vi.stubGlobal('localStorage', { getItem: () => null, setItem: () => {} })
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    fetchMock.mockResolvedValueOnce(response({ backends }))
    const api = await import('./api')
    await api.initializeBackends()
    fetchMock.mockClear()
  })
  afterEach(() => { vi.unstubAllEnvs(); vi.unstubAllGlobals() })

  it('accepts legacy sessions and advertises scan login only through a boolean capability', async () => {
    const api = await import('./api')
    fetchMock.mockResolvedValueOnce(response({ user: null, csrfToken: 'token' }))
    expect(await api.getSession()).not.toHaveProperty('scanLoginEnabled')
    fetchMock.mockResolvedValueOnce(response({ user: null, csrfToken: 'token', scanLoginEnabled: true }))
    expect(await api.getSession()).toHaveProperty('scanLoginEnabled', true)
    fetchMock.mockResolvedValueOnce(response({ user: null, csrfToken: 'token', scanLoginEnabled: 'yes' }))
    await expect(api.getSession()).rejects.toMatchObject({ status: 502 })
  })

  it.each(['admin', 'device_manager'] as const)('preserves the validated %s role on Session', async role => {
    const api = await import('./api')
    fetchMock.mockResolvedValueOnce(response({ user: { username: '用户', role }, csrfToken: 'token' }))
    expect(await api.getSession()).toHaveProperty('user.role', role)
  })

  it.each(['root', '', null, 1, {}])('rejects an unknown Session role instead of presenting it as admin: %j', async role => {
    const api = await import('./api')
    fetchMock.mockResolvedValueOnce(response({ user: { username: '用户', role }, csrfToken: 'token' }))
    await expect(api.getSession()).rejects.toMatchObject({ status: 502 })
  })

  it('accepts a device manager consume response and rejects an unknown role', async () => {
    const api = await import('./api')
    fetchMock.mockResolvedValueOnce(response({ user: null, csrfToken: 'token' }))
    await api.getSession()
    fetchMock.mockResolvedValueOnce(response({ user: { username: '微信用户', role: 'device_manager' }, csrfToken: 'manager-token' }))
    expect(await api.consumeScanLoginTicket(ticketId)).toHaveProperty('user.role', 'device_manager')
    fetchMock.mockResolvedValueOnce(response({ user: { username: '用户', role: 'root' }, csrfToken: 'unknown-token' }))
    await expect(api.consumeScanLoginTicket(ticketId)).rejects.toMatchObject({ status: 502 })
  })

  it('keeps ticket creation and its CSRF bootstrap on the original backend', async () => {
    const api = await import('./api')
    let finish!: (value: Response) => void
    fetchMock.mockReturnValueOnce(new Promise(resolve => { finish = resolve }))
    const creating = api.createScanLoginTicket('b0')
    api.setSelectedBackend('b1')
    fetchMock.mockResolvedValueOnce(response(ticket))
    finish(response({ user: null, csrfToken: 'b0-token' }))
    expect(await creating).toEqual(ticket)
    const [url, init] = fetchMock.mock.calls[1]!
    expect(url).toBe('/api/backends/b0/admin-api/scan-login/tickets')
    expect(init?.method).toBe('POST')
    expect(init?.credentials).toBe('same-origin')
    expect(new Headers(init?.headers).get('X-CSRF-Token')).toBe('b0-token')
    expect(init?.cache).toBe('no-store')
  })

  it('polls the captured backend without posting secret or establishing a session', async () => {
    const api = await import('./api')
    api.setSelectedBackend('b1')
    fetchMock.mockResolvedValueOnce(response({ status: 'approved', expiresAt: ticket.expiresAt }))
    expect(await api.getScanLoginTicket(ticketId, 'b0')).toEqual({ status: 'approved', expiresAt: ticket.expiresAt })
    expect(fetchMock).toHaveBeenCalledTimes(1)
    const [url, init] = fetchMock.mock.calls[0]!
    expect(url).toBe(`/api/backends/b0/admin-api/scan-login/tickets/${ticketId}`)
    expect(init?.method ?? 'GET').toBe('GET')
    expect(init?.body).toBeUndefined()
    expect(init?.cache).toBe('no-store')
  })

  it('reconciles the Session on the consume backend after selection changes', async () => {
    const api = await import('./api')
    api.setSelectedBackend('b1')
    fetchMock.mockResolvedValueOnce(response({ user: { username: '管理员' }, csrfToken: 'b0-recovered' }))
    expect(await api.getSession('b0')).toHaveProperty('csrfToken', 'b0-recovered')
    expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/backends/b0/admin-api/session')
  })

  it('explicitly consumes with CSRF and scopes the rotated token to its backend', async () => {
    const api = await import('./api')
    fetchMock.mockResolvedValueOnce(response({ user: null, csrfToken: 'b0-token' }))
    await api.getSession()
    api.setSelectedBackend('b1')
    fetchMock.mockResolvedValueOnce(response({ user: null, csrfToken: 'b1-token' }))
    await api.getSession()
    fetchMock.mockResolvedValueOnce(response({ user: { username: '微信管理员' }, csrfToken: 'b0-rotated' }))
    const session = await api.consumeScanLoginTicket(ticketId, 'b0')
    expect(session.csrfToken).toBe('b0-rotated')
    expect(fetchMock.mock.calls[2]?.[0]).toBe(`/api/backends/b0/admin-api/scan-login/tickets/${ticketId}/consume`)
    expect(fetchMock.mock.calls[2]?.[1]?.method).toBe('POST')
    expect(new Headers(fetchMock.mock.calls[2]?.[1]?.headers).get('X-CSRF-Token')).toBe('b0-token')
    fetchMock.mockResolvedValueOnce(response({ status: 'cancelled' }))
    await api.cancelScanLoginTicket(ticketId, 'b1')
    expect(new Headers(fetchMock.mock.calls[3]?.[1]?.headers).get('X-CSRF-Token')).toBe('b1-token')
    fetchMock.mockResolvedValueOnce(response({ status: 'cancelled' }))
    await api.cancelScanLoginTicket(ticketId, 'b0')
    expect(new Headers(fetchMock.mock.calls[4]?.[1]?.headers).get('X-CSRF-Token')).toBe('b0-rotated')
    expect(fetchMock.mock.calls[4]?.[1]?.method).toBe('POST')
  })

  it.each([
    { ...ticket, ticketId: 'invalid' },
    { ...ticket, expiresAt: 'tomorrow' },
    { ...ticket, qrText: ticket.qrText.replace('b'.repeat(64), 'secret') },
    { ...ticket, qrText: ticket.qrText.replace(ticketId, 'c'.repeat(32)) },
    { ...ticket, qrText: ticket.qrText.replace('https%3A', 'http%3A') },
  ])('rejects malformed ticket creation data before drawing a QR code', async invalid => {
    const api = await import('./api')
    fetchMock.mockResolvedValueOnce(response({ user: null, csrfToken: 'token' }))
    fetchMock.mockResolvedValueOnce(response(invalid))
    await expect(api.createScanLoginTicket()).rejects.toMatchObject({ status: 502 })
  })

  it('rejects invalid poll states, cancel responses and unauthenticated consumption', async () => {
    const api = await import('./api')
    fetchMock.mockResolvedValueOnce(response({ status: 'logged-in', expiresAt: ticket.expiresAt }))
    await expect(api.getScanLoginTicket(ticketId)).rejects.toMatchObject({ status: 502 })
    fetchMock.mockResolvedValueOnce(response({ user: null, csrfToken: 'token' }))
    fetchMock.mockResolvedValueOnce(response({ status: 'approved' }))
    await expect(api.cancelScanLoginTicket(ticketId)).rejects.toMatchObject({ status: 502 })
    fetchMock.mockResolvedValueOnce(response({ user: null, csrfToken: 'token' }))
    await expect(api.consumeScanLoginTicket(ticketId)).rejects.toMatchObject({ status: 502 })
  })

  it.each(['expired', 'consumed'] as const)('accepts an idempotent cancellation of an already %s ticket', async status => {
    const api = await import('./api')
    fetchMock.mockResolvedValueOnce(response({ user: null, csrfToken: 'token' }))
    fetchMock.mockResolvedValueOnce(response({ status, expiresAt: ticket.expiresAt }))
    expect(await api.cancelScanLoginTicket(ticketId)).toHaveProperty('status', status)
  })
})
