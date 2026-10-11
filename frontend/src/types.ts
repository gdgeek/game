export type DeviceStatus = 'online' | 'offline' | 'unknown'

export type BackendId = string

export interface BackendOption {
  readonly id: BackendId
  readonly name: string
  readonly host: string
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
  user: { username: string; role?: 'admin' | 'device_manager' } | null
  csrfToken: string
  demo?: boolean
  scanLoginEnabled?: boolean
}

export interface ScanLoginTicket {
  ticketId: string
  qrText: string
  expiresAt: string
}

export type ScanLoginStatus = 'pending' | 'approved' | 'consumed' | 'cancelled' | 'expired'

export interface ScanLoginTicketStatus {
  status: ScanLoginStatus
  expiresAt: string
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
