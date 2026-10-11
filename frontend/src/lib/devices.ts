import type { Device, DeviceFilters, DeviceSummary } from '../types'

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

export function summarizeDevices(devices: readonly Device[]): DeviceSummary {
  return devices.reduce<DeviceSummary>((summary, device) => {
    summary.total += 1
    summary[device.status] += 1
    summary[device.gameId === null ? 'unconfigured' : 'configured'] += 1
    return summary
  }, { total: 0, configured: 0, unconfigured: 0, online: 0, offline: 0, unknown: 0 })
}
