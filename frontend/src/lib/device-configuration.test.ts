import { describe, expect, it } from 'vitest'
import type { Device } from '../types'
import { configurationChanges, createConfigurationDraft } from './device-configuration'

const device: Device = {
  id: 1, uuid: 'device-a', name: '大厅', ip: '', status: 'unknown', updatedAt: '', gameId: 638,
  slogans: ['欢迎光临'], pictures: ['keep.png'], thumbs: ['keep.webp'],
}

describe('device configuration draft', () => {
  it('saves a trimmed device name independently and permits clearing it', () => {
    const original = { ...device, title: '原名称' }
    const draft = createConfigurationDraft(original)
    draft.title = '  大厅新名称  '
    expect(configurationChanges(original, draft)).toEqual({ title: '大厅新名称' })
    expect(original.title).toBe('原名称')
    draft.title = '  '
    expect(configurationChanges(original, draft)).toEqual({ title: '' })
  })

  it('uses the current display name with older responses without marking it changed', () => {
    const draft = createConfigurationDraft(device)
    expect(draft.title).toBe(device.name)
    expect(configurationChanges(device, draft)).toEqual({})
  })

  it('isolates list edits so cancelling leaves the original device unchanged', () => {
    const draft = createConfigurationDraft(device)
    draft.slogans[0] = '新欢迎词'
    draft.pictures.push('new.png')
    draft.thumbs.splice(0, 1)
    expect(device.slogans).toEqual(['欢迎光临'])
    expect(device.pictures).toEqual(['keep.png'])
    expect(device.thumbs).toEqual(['keep.webp'])
  })

  it('submits only edited fields with trimmed nonempty items in their original order', () => {
    const draft = createConfigurationDraft(device)
    draft.slogans = ['  你好  ', ' ', '你好', ' 欢迎 ']
    expect(configurationChanges(device, draft)).toEqual({ slogans: ['你好', '你好', '欢迎'] })
    draft.gameId = 700
    expect(configurationChanges(device, draft)).toEqual({ gameId: 700, slogans: ['你好', '你好', '欢迎'] })
  })

  it('allows clearing content without changing the current game', () => {
    const draft = createConfigurationDraft(device)
    draft.pictures = []
    draft.thumbs = ['  ']
    expect(configurationChanges(device, draft)).toEqual({ pictures: [], thumbs: [] })
  })

  it('allows content-only editing when no game is configured', () => {
    const unconfigured = { ...device, gameId: null, slogans: undefined, pictures: undefined, thumbs: undefined }
    const draft = createConfigurationDraft(unconfigured)
    expect(configurationChanges(unconfigured, draft)).toEqual({})
    draft.slogans = ['欢迎体验']
    expect(configurationChanges(unconfigured, draft)).toEqual({ slogans: ['欢迎体验'] })
  })

  it('does not send blank placeholder rows or unchanged stored content', () => {
    const draft = createConfigurationDraft(device)
    draft.slogans.push(' ')
    expect(configurationChanges(device, draft)).toEqual({})
  })
})
