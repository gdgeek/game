import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick, ref } from 'vue'
import type { Device, DeviceImagePreview } from '../types'
import { createConfigurationDraft, configurationChanges } from './device-configuration'
import { createTestRenderer, testNode } from './test-renderer'
import { useDeviceImagePreviews } from './useDeviceImagePreviews'

const mocks = vi.hoisted(() => ({ load: vi.fn(), listeners: new Set<() => void>() }))
vi.mock('./api', () => ({
  getDeviceImagePreviews: mocks.load,
  onAuthIdentityChange: (listener: () => void) => { mocks.listeners.add(listener); return () => mocks.listeners.delete(listener) },
}))
const source = 'https://bucket.cos.ap-test.myqcloud.com/picture.png'
const signed = `${source}?sign=temporary-secret`
const device: Device = { id: 123, uuid: '', name: '设备', ip: '', status: 'unknown', updatedAt: '', gameId: null, pictures: [source], thumbs: [] }
const entry = (url = signed, seconds = 300): DeviceImagePreview => ({ source, url, expiresAt: Math.floor(Date.now() / 1000) + seconds })
function deferred<T>() { let resolve!: (value: T) => void; const promise = new Promise<T>(yes => { resolve = yes }); return { promise, resolve } }
async function flush() { await Promise.resolve(); await nextTick(); await Promise.resolve() }

describe('device image preview lifecycle', () => {
  const unmount: Array<() => void> = []
  beforeEach(() => { vi.useFakeTimers(); vi.setSystemTime('2026-10-23T00:00:00Z'); mocks.load.mockReset(); mocks.listeners.clear() })
  afterEach(() => { unmount.splice(0).forEach(stop => stop()); vi.useRealTimers() })
  function mount(value = { ...device }) {
    const current = ref(value)
    let previews!: ReturnType<typeof useDeviceImagePreviews>
    const app = createTestRenderer().createApp({ setup() { previews = useDeviceImagePreviews(() => current.value); return () => null } })
    app.mount(testNode('root')); unmount.push(() => app.unmount())
    return { previews, current, close: () => app.unmount() }
  }

  it('resolves only saved image sources without mutating the device or saved configuration', async () => {
    mocks.load.mockResolvedValue([entry(), { ...entry(), source: 'https://unrelated.test/other.png' }])
    const { previews } = mount(); await flush()
    expect(previews.urls.value).toEqual({ [source]: signed })
    expect(device.pictures).toEqual([source])
    const draft = createConfigurationDraft(device)
    draft.thumbs = [source]
    expect(configurationChanges(device, draft)).toEqual({ thumbs: [source] })
  })

  it('refreshes before expiry and stops exposing an expired signature while a refresh is pending', async () => {
    const refreshed = deferred<DeviceImagePreview[]>()
    mocks.load.mockResolvedValueOnce([entry()]).mockReturnValueOnce(refreshed.promise)
    const { previews } = mount(); await flush()
    await vi.advanceTimersByTimeAsync(269_999)
    expect(mocks.load).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(1)
    expect(mocks.load).toHaveBeenCalledTimes(2)
    expect(previews.urls.value[source]).toBe(signed)
    await vi.advanceTimersByTimeAsync(30_000)
    expect(previews.urls.value[source]).toBeUndefined()
    refreshed.resolve([entry(`${source}?sign=renewed`)]); await flush()
    expect(previews.urls.value[source]).toBe(`${source}?sign=renewed`)
  })

  it('offers an explicit retry with a safe message after a failed load', async () => {
    mocks.load.mockRejectedValueOnce(new Error(`network error ${signed}`))
    const { previews } = mount(); await flush()
    expect(previews.error.value).toContain('重试')
    expect(previews.error.value).not.toContain('temporary-secret')
    mocks.load.mockResolvedValueOnce([entry()])
    await previews.reload()
    expect(previews.urls.value[source]).toBe(signed)
    expect(previews.error.value).toBe('')
  })

  it('aborts on close and ignores a late response without scheduling another request', async () => {
    const pending = deferred<DeviceImagePreview[]>()
    mocks.load.mockReturnValueOnce(pending.promise)
    const { previews, close } = mount()
    const signal = mocks.load.mock.calls[0]?.[1] as AbortSignal
    close(); pending.resolve([entry()]); await flush()
    expect(signal.aborted).toBe(true)
    expect(previews.urls.value).toEqual({})
    await vi.advanceTimersByTimeAsync(600_000)
    expect(mocks.load).toHaveBeenCalledTimes(1)
    expect(mocks.listeners.size).toBe(0)
  })

  it('immediately clears signatures on identity change and discards a pending refresh', async () => {
    const pending = deferred<DeviceImagePreview[]>()
    mocks.load.mockResolvedValueOnce([entry()]).mockReturnValueOnce(pending.promise)
    const { previews } = mount(); await flush()
    const refresh = previews.reload()
    mocks.listeners.forEach(listener => listener())
    expect(previews.urls.value).toEqual({})
    pending.resolve([entry(`${source}?sign=old-identity`)]); await refresh
    expect(previews.urls.value).toEqual({})
    await vi.advanceTimersByTimeAsync(600_000)
    expect(mocks.load).toHaveBeenCalledTimes(2)
  })

  it('ignores an earlier device response after switching devices', async () => {
    const pending = deferred<DeviceImagePreview[]>()
    const otherSource = 'https://bucket.cos.ap-test.myqcloud.com/other.png'
    mocks.load.mockReturnValueOnce(pending.promise).mockResolvedValueOnce([{ source: otherSource, url: `${otherSource}?sign=new`, expiresAt: entry().expiresAt }])
    const { previews, current } = mount()
    current.value = { ...device, id: 456, pictures: [otherSource] }; await flush()
    pending.resolve([entry()]); await flush()
    expect(previews.urls.value).toEqual({ [otherSource]: `${otherSource}?sign=new` })
    expect(mocks.load.mock.calls[1]?.[0]).toBe(456)
  })

  it('clears an existing signature if device access is revoked', async () => {
    mocks.load.mockResolvedValueOnce([entry()]).mockRejectedValueOnce({ status: 404 })
    const { previews } = mount(); await flush()
    expect(previews.urls.value[source]).toBe(signed)
    await previews.reload()
    expect(previews.urls.value).toEqual({})
    expect(previews.error.value).toContain('重试')
  })
})
