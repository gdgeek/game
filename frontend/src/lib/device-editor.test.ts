import { describe, expect, it } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from '@vue/server-renderer'
import GamePicker from '../components/GamePicker.vue'

describe('device configuration editor', () => {
  it('shows saved welcome words, pictures and thumbnails even when games are unavailable', async () => {
    const html = await renderToString(createSSRApp({ render: () => h(GamePicker, {
      device: { id: 1, uuid: 'device-a', name: '大厅', ip: '', status: 'unknown', updatedAt: '', gameId: 638,
        slogans: ['欢迎光临'], pictures: ['https://example.test/a.png'], thumbs: ['https://example.test/a.webp'] },
      games: [], busy: false, error: '', gamesError: '目录暂不可用',
    }) }))
    expect(html).toContain('欢迎词（口号）')
    expect(html).toContain('设备名称')
    expect(html).toContain('id="device-title"')
    expect(html).toContain('欢迎光临')
    expect(html).toContain('https://example.test/a.png')
    expect(html).toContain('https://example.test/a.webp')
    expect(html).toContain('缩略图')
    expect(html).toContain('目录暂不可用')
    expect(html).toContain('场景 638')
  })

  it('explains the length limit and blocks an overlong device name', async () => {
    const html = await renderToString(createSSRApp({ render: () => h(GamePicker, {
      device: { id: 1, uuid: '', name: '大厅', title: '名'.repeat(256), ip: '', status: 'unknown', updatedAt: '', gameId: null },
      games: [], busy: false, error: '',
    }) }))
    expect(html).toContain('设备名称最多 255 个字符')
    expect(html).toContain('aria-invalid="true"')
    expect(html).toMatch(/<button[^>]*class="button primary"[^>]*disabled/)
  })
})
