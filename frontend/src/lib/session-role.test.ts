import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h, type ComponentOptions } from 'vue'
import { renderToString } from '@vue/server-renderer'
import LoginView from '../components/LoginView.vue'
import ScanLogin from '../components/ScanLogin.vue'
import HelpDialog from '../components/HelpDialog.vue'

const response = (body: unknown) => new Response(JSON.stringify(body))

describe('device management permission presentation', () => {
  const fetchMock = vi.fn<typeof fetch>()
  beforeEach(() => {
    vi.resetModules()
    vi.stubEnv('MODE', 'production')
    vi.stubGlobal('localStorage', { getItem: () => null, setItem: () => {} })
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
  })
  afterEach(() => { vi.unstubAllGlobals(); vi.unstubAllEnvs() })

  async function renderWorkspace(role?: string) {
    const { default: App } = await import('../App.vue')
    const setup = (App as ComponentOptions).setup!
    let state: Record<string, unknown>
    const component = {
      ...App,
      setup: (props: Parameters<typeof setup>[0], context: Parameters<typeof setup>[1]) => {
        state ??= setup(props, context) as Record<string, unknown>
        return state
      },
    }
    const render = () => renderToString(createSSRApp(component))
    await render()
    fetchMock.mockResolvedValueOnce(response({ backends: [{ id: 'b0', name: '后端', host: 'example.test' }] }))
    fetchMock.mockResolvedValueOnce(response({ user: { username: '用户', ...(role ? { role } : {}) }, csrfToken: 'token' }))
    fetchMock.mockResolvedValueOnce(response([]))
    fetchMock.mockResolvedValueOnce(response([]))
    await (state!.initializeWorkspace as () => Promise<void>)()
    return render()
  }

  it('identifies the device manager and explains the empty associated device list', async () => {
    const html = await renderWorkspace('device_manager')
    expect(html).toContain('<small>关联设备管理</small>')
    expect(html).toContain('你关联的设备')
    expect(html).toContain('还没有关联设备')
    expect(html).toContain('请先在「不加班」小程序中关联设备')
    expect(html).not.toContain('当前工作空间全部设备')
    expect(html).not.toContain('<small>管理员</small>')
  })

  it.each([undefined, 'admin'])('preserves administrator presentation for the %s password Session', async role => {
    const html = await renderWorkspace(role)
    expect(html).toContain('<small>管理员</small>')
    expect(html).toContain('当前工作空间全部设备')
    expect(html).not.toContain('还没有关联设备')
  })

  it('explains that WeChat login manages associated devices without claiming administrator access', async () => {
    const html = await renderToString(createSSRApp({ render: () => h(LoginView, { busy: false, error: '', demo: false, backend: 'b0', scanLoginEnabled: true }) }))
    expect(html).toContain('微信扫码仅可管理自己关联的设备')
    expect(html).not.toContain('仅指定微信管理员')
    const qr = await renderToString(createSSRApp({ render: () => h(ScanLogin, { backend: 'b0' }) }))
    expect(qr).toContain('登录后仅可管理当前微信账号关联的设备')
    expect(qr).toContain('我的 → 扫码登录设备后台')
    expect(qr).not.toContain('指定微信管理员')
  })

  it('shows the same associated-device scope in the help dialog', async () => {
    const html = await renderToString(createSSRApp({ render: () => h(HelpDialog, { demo: false, deviceManager: true }) }))
    expect(html).toContain('统计仅包含你关联的设备')
    expect(html).toContain('仅可管理当前账号关联的设备')
    expect(html).not.toContain('仅管理员可以管理设备配置')
  })
})
