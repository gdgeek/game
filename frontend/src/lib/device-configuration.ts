import type { Device, DeviceConfiguration, DeviceConfigurationPatch } from '../types'

const listFields = ['slogans', 'pictures', 'thumbs'] as const
const clean = (items: readonly string[]) => items.map(item => item.trim()).filter(Boolean)

export function createConfigurationDraft(device: Device): DeviceConfiguration {
  return {
    gameId: device.gameId,
    title: device.title ?? device.name,
    slogans: [...(device.slogans ?? [])],
    pictures: [...(device.pictures ?? [])],
    thumbs: [...(device.thumbs ?? [])],
  }
}

export function configurationChanges(device: Device, draft: DeviceConfiguration): DeviceConfigurationPatch {
  const changes: DeviceConfigurationPatch = {}
  const title = draft.title.trim()
  if (title !== (device.title ?? device.name).trim()) changes.title = title
  if (draft.gameId !== null && draft.gameId !== device.gameId) changes.gameId = draft.gameId
  for (const field of listFields) {
    const items = clean(draft[field])
    if (JSON.stringify(items) !== JSON.stringify(clean(device[field] ?? []))) changes[field] = items
  }
  return changes
}
