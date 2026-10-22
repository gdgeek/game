import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, type ComponentOptions } from 'vue'
import { renderToString } from '@vue/server-renderer'

const response = (body: unknown) => new Response(JSON.stringify(body), { status: 200 })
const device = { id: 1, uuid: 'version-device', name: '大厅设备', ip: '', status: 'unknown', updatedAt: '', gameId: null }

describe('device version display', () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.resetModules()
    vi.stubGlobal('fetch', fetchMock)
    vi.stubEnv('MODE', 'production')
    fetchMock.mockReset()
  })
  afterEach(() => { vi.unstubAllGlobals(); vi.unstubAllEnvs() })

  it.each([0, 1, 12])('shows database version %i in the device list', async version => {
    expect(await renderDevice({ ...device, version })).toContain(`版本号 ${version}`)
  })

  it('shows an unknown version when an older backend omits the field', async () => {
    expect(await renderDevice(device)).toContain('版本号 未知')
  })

  it.each([-1, 1.5, '2', null, 2147483648])('rejects malformed version %j before rendering', async version => {
    vi.stubEnv('MODE', 'demo')
    fetchMock.mockResolvedValueOnce(response([{ ...device, version }]))
    const api = await import('./api')
    await expect(api.getDevices()).rejects.toMatchObject({ status: 502 })
  })

  async function renderDevice(value: Record<string, unknown>): Promise<string> {
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
      .mockResolvedValueOnce(response({ user: { username: '管理员', role: 'admin' }, authMode: 'jwt' }))
      .mockResolvedValueOnce(response([value]))
      .mockResolvedValueOnce(response([]))
    await (state!.initializeWorkspace as () => Promise<void>)()
    return (await render()).replace(/<[^>]*>/g, '')
  }
})
