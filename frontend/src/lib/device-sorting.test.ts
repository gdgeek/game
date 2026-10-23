import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, nextTick, ssrContextKey, type ComponentOptions, type Ref } from 'vue'
import { renderToString } from '@vue/server-renderer'
import type { Device, DeviceSortKey } from '../types'
import { createTestRenderer, testNode } from './test-renderer'

const response = (body: unknown) => new Response(JSON.stringify(body))
const devices: Device[] = Array.from({ length: 10 }, (_, index) => ({
  id: index + 1, uuid: `device-${index + 1}`, name: `设备 ${index + 1}`, ip: '', status: 'unknown',
  version: index + 1, createdAt: `2026-10-${String(10 - index).padStart(2, '0')} 12:00:00`,
  updatedAt: `2026-10-${String(index + 1).padStart(2, '0')} 12:00:00`,
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
      sort: async (key: DeviceSortKey) => { (state.sortByColumn as (key: DeviceSortKey) => void)(key); await nextTick() },
      ids: () => (state.pageDevices as Ref<Device[]>).value.map(device => device.id),
      render: () => renderToString(createSSRApp({ ...App, setup: () => state })),
    }
  }

  it('defaults to newest creation time and renders a sort button on every data column', async () => {
    const view = await workspace()
    expect((view.state.sortKey as Ref<string>).value).toBe('createdAt')
    expect((view.state.sortDirection as Ref<string>).value).toBe('desc')
    expect(view.ids()).toEqual([1, 2, 3, 4, 5, 6, 7, 8])
    const html = await view.render()
    expect(html).toContain('aria-label="设备排序字段"')
    const headers = html.match(/<thead>.*?<\/thead>/s)![0]
    for (const label of ['设备名称', '状态', '当前游戏', 'IP 地址', '添加时间', '更新时间']) {
      expect(headers).toContain(`aria-label="按${label}排序"`)
    }
    expect(headers).toMatch(/<th[^>]*aria-sort="descending"[^>]*>.*?按添加时间排序/s)
    expect(headers.match(/aria-sort="none"/g)).toHaveLength(5)
    expect(headers).not.toContain('按操作排序')
    for (const key of ['version', 'name', 'status', 'game', 'ip', 'createdAt', 'updatedAt', 'id']) {
      expect(html).toContain(`value="${key}"`)
    }
    expect(html).not.toContain('默认顺序')
    expect(html).not.toMatch(/<button[^>]*aria-label="切换排序方向"[^>]*disabled/)
  })

  it.each(['name', 'status', 'game', 'ip', 'updatedAt'] as const)('starts a newly selected %s column ascending and toggles on repeat clicks', async key => {
    const view = await workspace()
    await view.sort(key)
    expect((view.state.sortKey as Ref<string>).value).toBe(key)
    expect((view.state.sortDirection as Ref<string>).value).toBe('asc')
    expect(await view.render()).toContain('aria-sort="ascending"')
    await view.sort(key)
    expect((view.state.sortDirection as Ref<string>).value).toBe('desc')
    await view.sort(key)
    expect((view.state.sortDirection as Ref<string>).value).toBe('asc')
  })

  it('toggles the default creation column to ascending first and restarts a reselected column ascending', async () => {
    const view = await workspace()
    await view.sort('createdAt')
    expect(view.ids()).toEqual([10, 9, 8, 7, 6, 5, 4, 3])
    await view.sort('createdAt')
    expect(view.ids()).toEqual([1, 2, 3, 4, 5, 6, 7, 8])
    await view.sort('updatedAt')
    await view.sort('updatedAt')
    await view.sort('createdAt')
    expect((view.state.sortDirection as Ref<string>).value).toBe('asc')
  })

  it('sorts default creation times independently of the API response and keeps missing times last', async () => {
    const view = await workspace()
    await view.set('devices', [{ ...devices[0]!, id: 11, createdAt: undefined }, ...devices].reverse())
    expect(view.ids()).toEqual([1, 2, 3, 4, 5, 6, 7, 8])
    await view.set('page', 2)
    expect(view.ids()).toEqual([9, 10, 11])
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
    await view.sort('name')
    expect((view.state.sortDirection as Ref<string>).value).toBe('asc')
    await view.set('sortDirection', 'desc')
    await view.set('gameFilter', 638)
    expect(view.ids()).toEqual([10, 8, 6, 4, 2])
    await view.set('search', '设备 1')
    expect(view.ids()).toEqual([10])
    expect((view.state.devices as Ref<Device[]>).value.map(device => device.id)).toEqual(devices.map(device => device.id))
  })

  it('retains the selected sorting when data is refreshed and restores creation order for a new session', async () => {
    const view = await workspace()
    await view.set('sortKey', 'updatedAt')
    expect(view.ids()[0]).toBe(10)
    await view.set('sortDirection', 'asc')
    fetchMock.mockResolvedValueOnce(response([...devices].reverse())).mockResolvedValueOnce(response([]))
    await (view.state.loadData as () => Promise<void>)()
    expect(view.ids()[0]).toBe(1)
    ;(view.state.resetWorkspace as () => void)()
    await nextTick()
    expect((view.state.sortKey as Ref<string>).value).toBe('createdAt')
    expect((view.state.sortDirection as Ref<string>).value).toBe('desc')
  })

  it('orders the game column by the displayed catalog names and follows catalog refreshes', async () => {
    const view = await workspace()
    await view.set('devices', [{ ...devices[0]!, gameId: 1 }, { ...devices[1]!, gameId: 2 }])
    await view.set('games', [{ id: 1, name: '游戏 10' }, { id: 2, name: '游戏 2' }])
    await view.sort('game')
    expect(view.ids()).toEqual([2, 1])
    await view.set('games', [{ id: 1, name: '游戏 1' }, { id: 2, name: '游戏 2' }])
    expect(view.ids()).toEqual([1, 2])
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
