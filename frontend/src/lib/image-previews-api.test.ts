import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const source = 'https://bucket.cos.ap-test.myqcloud.com/images/original.png'
const signed = `${source}?q-signature=temporary-secret`
const expiresAt = Math.floor(Date.parse('2099-10-11T12:05:00Z') / 1000)
const response = (value: unknown, status = 200) => new Response(JSON.stringify(value), { status })
const token = { accessToken: 'access', refreshToken: 'refresh', expires: '2099-10-11T15:00:00Z', refreshExpires: '2099-10-11T20:00:00Z' }

describe('device image preview API', () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(async () => {
    vi.resetModules(); vi.stubEnv('MODE', 'production'); fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    vi.stubGlobal('localStorage', { getItem: () => JSON.stringify(token), setItem: vi.fn(), removeItem: vi.fn() })
    vi.stubGlobal('sessionStorage', { getItem: () => 'c'.repeat(64), setItem: vi.fn() })
    fetchMock.mockResolvedValueOnce(response({ apiBase: '/api/admin-api', automatic: true, auth: 'jwt' }))
    const api = await import('./api'); await api.initializeApi(); fetchMock.mockClear()
  })
  afterEach(() => { vi.unstubAllEnvs(); vi.unstubAllGlobals() })

  it('reads temporary previews with JWT and owner proof without cookies, writes or caching', async () => {
    const api = await import('./api')
    expect(api).toHaveProperty('getDeviceImagePreviews')
    fetchMock.mockResolvedValueOnce(response({ previews: [{ source, url: signed, expiresAt }] }))
    const controller = new AbortController()
    expect(await api.getDeviceImagePreviews(123, controller.signal)).toEqual([{ source, url: signed, expiresAt }])
    const [url, init] = fetchMock.mock.calls[0]!
    expect(url).toBe('/api/admin-api/devices/123/image-previews')
    expect(init?.method ?? 'GET').toBe('GET')
    expect(init?.body).toBeUndefined()
    expect(init?.credentials).toBe('omit')
    expect(init?.cache).toBe('no-store')
    expect(new Headers(init?.headers).get('Authorization')).toBe('Bearer access')
    expect(new Headers(init?.headers).get('X-Admin-Scan-Owner')).toBe('c'.repeat(64))
    expect(new Headers(init?.headers).has('X-CSRF-Token')).toBe(false)
    controller.abort()
    expect(init?.signal?.aborted).toBe(true)
  })

  it.each([
    { previews: null }, { previews: [{ source, url: 'javascript:alert(1)', expiresAt }] },
    { previews: [{ source, url: signed, expiresAt: String(expiresAt) }] },
    { previews: [{ source, url: signed, expiresAt: 0 }] },
    { previews: [{ source, url: signed, expiresAt: 1.5 }] },
    { previews: [{ source, url: 'https://different.test/object.png', expiresAt }] },
  ])('rejects malformed or unrelated preview responses', async invalid => {
    const api = await import('./api')
    expect(api).toHaveProperty('getDeviceImagePreviews')
    fetchMock.mockResolvedValueOnce(response(invalid))
    await expect(api.getDeviceImagePreviews(123)).rejects.toMatchObject({ status: 502 })
  })

  it('keeps signed preview data out of subsequent configuration writes', async () => {
    const api = await import('./api')
    expect(api).toHaveProperty('getDeviceImagePreviews')
    fetchMock.mockResolvedValueOnce(response({ previews: [{ source, url: signed, expiresAt }] }))
    await api.getDeviceImagePreviews(123)
    const patch = { pictures: [source], thumbs: [source] }
    fetchMock.mockResolvedValueOnce(response({ id: 123, uuid: '', name: '设备', ip: '', status: 'unknown', updatedAt: '', gameId: null, ...patch }))
    await api.updateDeviceConfiguration(123, patch)
    expect(JSON.parse(String(fetchMock.mock.calls[1]?.[1]?.body))).toEqual(patch)
    expect(String(fetchMock.mock.calls[1]?.[1]?.body)).not.toContain('q-signature')
  })

  it('accepts an original query or fragment and equivalent once-encoded object paths', async () => {
    const api = await import('./api')
    const original = 'https://bucket.cos.ap-test.myqcloud.com/images/%74%31.png?imageView2/2/w/300#photo'
    const preview = { source: original, url: 'https://bucket.cos.ap-test.myqcloud.com/images/t1.png?sign=encoded-signature', expiresAt }
    fetchMock.mockResolvedValueOnce(response({ previews: [preview] }))
    expect(await api.getDeviceImagePreviews(123)).toEqual([preview])
  })

  it('accepts equivalent percent escape casing and encoded object separators', async () => {
    const api = await import('./api')
    const original = 'https://bucket.cos.ap-test.myqcloud.com/images%2ft1%20%E4%B8%AD.png'
    const preview = { source: original, url: 'https://bucket.cos.ap-test.myqcloud.com/images/t1%20%e4%b8%ad.png?sign=encoded-signature', expiresAt }
    fetchMock.mockResolvedValueOnce(response({ previews: [preview] }))
    expect(await api.getDeviceImagePreviews(123)).toEqual([preview])
  })
})
