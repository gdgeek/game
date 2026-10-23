import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, nextTick, ssrContextKey, type ComponentOptions, type Ref } from 'vue'
import { renderToString } from '@vue/server-renderer'
import type { Device } from '../types'
import { createTestRenderer, testNode } from './test-renderer'

const response = (body: unknown) => new Response(JSON.stringify(body))
const devices: Device[] = Array.from({ length: 10 }, (_, index) => ({
  id: index + 1, uuid: `device-${index + 1}`, name: `设备 ${index + 1}`, ip: '', status: 'unknown',
  version: index + 1, updatedAt: `2026-10-${String(index + 1).padStart(2, '0')} 12:00:00`,
  gameId: index % 2 ? 638 : null,
}))

describe('device list sorting', () => {
  const fetchMock = vi.fn<typeof fetch>()
  const cleanup: Array<() => void> = []
  beforeEach(() => {
    vi.resetModules()
    vi.stubEnv('MODE', 'production')
    vi.stubGlobal('fetch', fetchMock)
    fetchMock.mockReset()
  })
  afterEach(() => {
    cleanup.splice(0).forEach(stop => stop())
    vi.unstubAllGlobals()
    vi.unstubAllEnvs()
    vi.restoreAllMocks()
  })

  async function workspace() {
    const { default: App } = await import('../App.vue')
    const setup = (App as ComponentOptions).setup!
    let state!: Record<string, unknown>
    fetchMock.mockResolvedValueOnce(response({ apiBase: '/api/admin-api', automatic: true, auth: 'jwt' }))
      .mockResolvedValueOnce(response({ user: { username: '管理员', role: 'admin' }, authMode: 'jwt' }))
      .mockResolvedValueOnce(response(devices))
      .mockResolvedValueOnce(response([{ id: 638, name: '游戏', description: '', category: '' }]))
    const app = createTestRenderer().createApp({
      setup(props, context) { state = setup(props, context) as Record<string, unknown>; return state },
      render: () => null,
    })
    app.provide(ssrContextKey, { modules: new Set() })
    app.mount(testNode('root'))
    cleanup.push(() => app.unmount())
    await vi.waitFor(() => expect((state.ready as Ref<boolean>).value).toBe(true))
    await nextTick()
    return {
      state,
      set: async (key: string, value: unknown) => { (state[key] as Ref<unknown>).value = value; await nextTick() },
      ids: () => (state.pageDevices as Ref<Device[]>).value.map(device => device.id),
      render: () => renderToString(createSSRApp({ ...App, setup: () => state })),
    }
  }

  it('offers version, name, update time and ID sorting while retaining the original default order', async () => {
    const view = await workspace()
    expect(view.ids()).toEqual([1, 2, 3, 4, 5, 6, 7, 8])
    const html = await view.render()
    expect(html).toContain('aria-label="设备排序字段"')
    for (const label of ['默认顺序', '版本号', '设备名称', '更新时间', '设备 ID']) expect(html).toContain(label)
    expect(html).toMatch(/<button[^>]*aria-label="切换排序方向"[^>]*disabled/)
  })

  it('sorts all matching devices before pagination and returns to page one when the order changes', async () => {
    const view = await workspace()
    await view.set('page', 2)
    expect(view.ids()).toEqual([9, 10])
    await view.set('sortKey', 'version')
    expect((view.state.page as Ref<number>).value).toBe(1)
    expect(view.ids()).toEqual([10, 9, 8, 7, 6, 5, 4, 3])
    await view.set('page', 2)
    await view.set('sortDirection', 'asc')
    expect((view.state.page as Ref<number>).value).toBe(1)
    expect(view.ids()).toEqual([1, 2, 3, 4, 5, 6, 7, 8])
  })

  it('combines name sorting with search and game filters without changing the source device order', async () => {
    const view = await workspace()
    await view.set('sortKey', 'name')
    expect((view.state.sortDirection as Ref<string>).value).toBe('asc')
    await view.set('sortDirection', 'desc')
    await view.set('gameFilter', 638)
    expect(view.ids()).toEqual([10, 8, 6, 4, 2])
    await view.set('search', '设备 1')
    expect(view.ids()).toEqual([10])
    expect((view.state.devices as Ref<Device[]>).value.map(device => device.id)).toEqual(devices.map(device => device.id))
  })

  it('defaults update time to newest first and retains sorting when data is refreshed', async () => {
    const view = await workspace()
    await view.set('sortKey', 'updatedAt')
    expect(view.ids()[0]).toBe(10)
    await view.set('sortDirection', 'asc')
    fetchMock.mockResolvedValueOnce(response([...devices].reverse())).mockResolvedValueOnce(response([]))
    await (view.state.loadData as () => Promise<void>)()
    expect(view.ids()[0]).toBe(1)
    await view.set('sortKey', 'default')
    expect(view.ids()).toEqual([10, 9, 8, 7, 6, 5, 4, 3])
  })

  it('exports every filtered device in the selected order across pages', async () => {
    const view = await workspace()
    await view.set('sortKey', 'version')
    const createObjectURL = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:test')
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {})
    vi.stubGlobal('document', { createElement: () => ({ click: vi.fn() }) })
    ;(view.state.exportDevices as () => void)()
    const csv = await (createObjectURL.mock.calls[0]![0] as Blob).text()
    expect(csv.split('\r\n').slice(1).map(row => Number(row.split(',')[0]!.replaceAll('"', ''))))
      .toEqual([10, 9, 8, 7, 6, 5, 4, 3, 2, 1])
  })
})
