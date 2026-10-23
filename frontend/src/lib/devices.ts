import type { Device, DeviceFilters, DeviceSortDirection, DeviceSortKey, DeviceSummary } from '../types'

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

export function sortDevices(devices: readonly Device[], key: DeviceSortKey, direction: DeviceSortDirection): Device[] {
  const sorted = [...devices]
  if (key === 'default') return sorted
  const order = direction === 'asc' ? 1 : -1
  return sorted.sort((left, right) => {
    if (key === 'name') {
      return order * nameCollator.compare(left.name || `设备 #${left.id}`, right.name || `设备 #${right.id}`)
    }
    const value = (device: Device): number | undefined => {
      if (key === 'version') return device.version
      if (key === 'id') return device.id
      return Date.parse(device.updatedAt.includes('T') ? device.updatedAt : device.updatedAt.replace(' ', 'T'))
    }
    const a = value(left)
    const b = value(right)
    const aKnown = a !== undefined && Number.isFinite(a)
    const bKnown = b !== undefined && Number.isFinite(b)
    // Unknown values stay at the end even when the sort direction is reversed.
    if (!aKnown || !bKnown) return aKnown ? -1 : bKnown ? 1 : 0
    return order * (a - b)
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
