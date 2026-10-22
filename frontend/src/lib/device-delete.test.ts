import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, type ComponentOptions, type Ref } from 'vue'
import { renderToString } from '@vue/server-renderer'
import type { Device } from '../types'

const device: Device = { id: 7, uuid: 'device-delete-uuid', name: '大厅设备', ip: '', status: 'unknown', updatedAt: '', gameId: 638 }
const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status })

describe('administrator device deletion', () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => {
    vi.resetModules()
    vi.stubEnv('MODE', 'production')
    vi.stubGlobal('fetch', fetchMock)
    fetchMock.mockReset()
  })
  afterEach(() => { vi.unstubAllGlobals(); vi.unstubAllEnvs() })

  async function workspace(role: 'admin' | 'device_manager' | null = 'admin') {
    const { default: App } = await import('../App.vue')
    const setup = (App as ComponentOptions).setup!
    let state: Record<string, unknown>
    const component = { ...App, setup: (props: Parameters<typeof setup>[0], context: Parameters<typeof setup>[1]) => {
      state ??= setup(props, context) as Record<string, unknown>
      return state
    } }
    const render = () => renderToString(createSSRApp(component))
    await render()
    fetchMock.mockResolvedValueOnce(response({ apiBase: '/api/admin-api', automatic: true, auth: 'jwt' }))
      .mockResolvedValueOnce(response({ user: { username: '用户', role: role ?? undefined }, authMode: 'jwt' }))
      .mockResolvedValueOnce(response([device]))
      .mockResolvedValueOnce(response([]))
    await (state!.initializeWorkspace as () => Promise<void>)()
    return {
      render,
      open: () => (state!.openDeletion as (device: Device) => void)(device),
      close: () => (state!.closeDeletion as () => void)(),
      confirm: () => (state!.confirmDeletion as () => Promise<void>)(),
      devices: () => (state!.devices as Ref<Device[]>).value,
      expire: async () => {
        const { ApiError } = await import('./api')
        ;(state!.expireSession as (error: unknown) => void)(new ApiError('登录已过期', 401))
      },
    }
  }

  it('requires an explicit administrator role for the deletion entry and action', async () => {
    for (const role of ['device_manager', null] as const) {
      const view = await workspace(role)
      expect(await view.render()).not.toContain('class="delete-device-button"')
      const calls = fetchMock.mock.calls.length
      view.open()
      await view.confirm()
      expect(await view.render()).not.toContain('role="alertdialog"')
      expect(fetchMock).toHaveBeenCalledTimes(calls)
      vi.resetModules()
    }
  })

  it('shows the target and consequences before sending any deletion request, and cancels without writing', async () => {
    const view = await workspace()
    expect(await view.render()).toContain('class="delete-device-button"')
    const calls = fetchMock.mock.calls.length
    view.open()
    const html = await view.render()
    expect(html).toContain('role="alertdialog"')
    expect(html).toContain('确认删除设备')
    expect(html).toContain('device-delete-uuid')
    expect(html).toContain('大厅设备')
    expect(html).toContain('无法撤销')
    expect(fetchMock).toHaveBeenCalledTimes(calls)
    view.close()
    expect(await view.render()).not.toContain('role="alertdialog"')
    expect(view.devices()).toEqual([device])
    expect(fetchMock).toHaveBeenCalledTimes(calls)
  })

  it('deletes only after confirmation and updates the list and counts on success', async () => {
    const view = await workspace()
    view.open()
    fetchMock.mockResolvedValueOnce(response({ id: device.id, deleted: true }))
    await view.confirm()
    expect(fetchMock.mock.calls.at(-1)?.[0]).toBe('/api/admin-api/devices/7')
    expect(fetchMock.mock.calls.at(-1)?.[1]?.method).toBe('DELETE')
    expect(view.devices()).toEqual([])
    const html = await view.render()
    expect(html).not.toContain('role="alertdialog"')
    expect(html).toContain('已删除')
    expect(html).toContain('还没有接入设备')
  })

  it('prevents duplicate submission and closing while deletion is pending', async () => {
    const view = await workspace()
    view.open()
    let finish!: (response: Response) => void
    fetchMock.mockReturnValueOnce(new Promise(resolve => { finish = resolve }))
    const calls = fetchMock.mock.calls.length
    const pending = view.confirm()
    await view.confirm()
    view.close()
    expect(fetchMock).toHaveBeenCalledTimes(calls + 1)
    const html = await view.render()
    expect(html).toContain('role="alertdialog"')
    expect(html).toContain('正在删除')
    expect(html).toMatch(/<button[^>]*class="button danger"[^>]*disabled/)
    finish(response({ id: device.id, deleted: true }))
    await pending
  })

  it('keeps the device and confirmation open on failure and permits retry', async () => {
    const view = await workspace()
    view.open()
    fetchMock.mockResolvedValueOnce(response({ message: '设备暂时无法删除，请稍后重试' }, 503))
    await view.confirm()
    expect(view.devices()).toEqual([device])
    expect(await view.render()).toContain('设备暂时无法删除，请稍后重试')
    fetchMock.mockResolvedValueOnce(response({ id: device.id, deleted: true }))
    await view.confirm()
    expect(view.devices()).toEqual([])
  })

  it('closes the confirmation and returns to login when the session expires', async () => {
    const view = await workspace()
    view.open()
    fetchMock.mockResolvedValueOnce(response({ message: '登录已过期' }, 401))
    await view.confirm()
    const html = await view.render()
    expect(html).not.toContain('role="alertdialog"')
    expect(html).toContain('登录已过期，请重新登录')
  })

  it('discards a late successful deletion response after identity invalidation', async () => {
    const view = await workspace()
    view.open()
    let finish!: (response: Response) => void
    fetchMock.mockReturnValueOnce(new Promise(resolve => { finish = resolve }))
    const pending = view.confirm()
    await view.expire()
    finish(response({ id: device.id, deleted: true }))
    await pending
    const html = await view.render()
    expect(html).not.toContain('已删除')
    expect(html).not.toContain('role="alertdialog"')
  })
})
