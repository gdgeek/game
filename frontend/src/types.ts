export type DeviceStatus = 'online' | 'offline' | 'unknown'

export type BackendId = 'b0' | 'b1'

export interface BackendOption {
  id: BackendId
  name: string
  host: string
}

export interface Device {
  id: number
  uuid: string
  name: string
  ip: string
  status: DeviceStatus
  updatedAt: string
  gameId: number | null
}

export interface Game {
  id: number
  name: string
  description: string
  category: string
}

export interface Session {
  user: { username: string } | null
  csrfToken: string
  demo?: boolean
}

export type DeviceFilterStatus = 'all' | DeviceStatus | 'configured' | 'unconfigured'

export interface DeviceFilters {
  search: string
  status: DeviceFilterStatus
  gameId: number | null
}

export interface DeviceSummary {
  total: number
  configured: number
  unconfigured: number
  online: number
  offline: number
  unknown: number
}
