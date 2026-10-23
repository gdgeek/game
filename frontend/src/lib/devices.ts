import type { Device, DeviceFilters, DeviceSortDirection, DeviceSortKey, DeviceSummary, Game } from '../types'

const nameCollator = new Intl.Collator('zh-CN', { numeric: true, sensitivity: 'base' })

export function filterDevices(devices: readonly Device[], filters: DeviceFilters): Device[] {
  const query = filters.search.trim().toLocaleLowerCase()
  return devices.filter((device) => {
    const matchesSearch = !query || [device.name, device.uuid, device.ip, String(device.id)]
      .some((value) => value.toLocaleLowerCase().includes(query))
    const matchesStatus = filters.status === 'all'
      || (filters.status === 'configured' && device.gameId !== null)
      || (filters.status === 'unconfigured' && device.gameId === null)
      || filters.status === device.status
    const matchesGame = filters.gameId === null || device.gameId === filters.gameId
    return matchesSearch && matchesStatus && matchesGame
  })
}

export function sortDevices(devices: readonly Device[], key: DeviceSortKey, direction: DeviceSortDirection, games: readonly Game[] = []): Device[] {
  const order = direction === 'asc' ? 1 : -1
  const gameNames = new Map(games.map(game => [game.id, game.name]))
  const value = (device: Device): string | number | undefined => {
    if (key === 'name') return device.name || `设备 #${device.id}`
    if (key === 'status') return { online: 0, offline: 1, unknown: 2 }[device.status]
    if (key === 'game') return device.gameId === null ? undefined : gameNames.get(device.gameId) || `场景 ${device.gameId}`
    if (key === 'ip') return device.ip.trim() || undefined
    if (key === 'version') return device.version
    if (key === 'id') return device.id
    const timestamp = (key === 'createdAt' ? device.createdAt : device.updatedAt) || ''
    const time = Date.parse(timestamp.includes('T') ? timestamp : timestamp.replace(' ', 'T'))
    return Number.isFinite(time) ? time : undefined
  }
  return [...devices].sort((left, right) => {
    const a = value(left)
    const b = value(right)
    const aKnown = a !== undefined && (typeof a === 'string' || Number.isFinite(a))
    const bKnown = b !== undefined && (typeof b === 'string' || Number.isFinite(b))
    // Unknown values stay at the end even when the sort direction is reversed.
    if (!aKnown || !bKnown) return aKnown ? -1 : bKnown ? 1 : 0
    return order * (typeof a === 'number' && typeof b === 'number' ? a - b : nameCollator.compare(String(a), String(b)))
  })
}

export function summarizeDevices(devices: readonly Device[]): DeviceSummary {
  return devices.reduce<DeviceSummary>((summary, device) => {
    summary.total += 1
    summary[device.status] += 1
    summary[device.gameId === null ? 'unconfigured' : 'configured'] += 1
    return summary
  }, { total: 0, configured: 0, unconfigured: 0, online: 0, offline: 0, unknown: 0 })
}
