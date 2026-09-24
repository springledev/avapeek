const SESSION_KEYS = ['auth_token', 'user', 'encryption_keys', 'kek'] as const

export type SessionKey = (typeof SESSION_KEYS)[number]

const STAY_SIGNED_IN_KEY = 'stay_signed_in'
const PENDING_INVITE_TOKEN_KEY = 'pending_invite_token'

function getActiveStorage(): Storage {
  return getStaySignedIn() ? localStorage : sessionStorage
}

export function getStaySignedIn(): boolean {
  const value = localStorage.getItem(STAY_SIGNED_IN_KEY)
  return value !== '0'
}

export function setStaySignedIn(value: boolean): void {
  const current = getStaySignedIn()
  localStorage.setItem(STAY_SIGNED_IN_KEY, value ? '1' : '0')

  if (current === value) {
    return
  }

  const source = value ? sessionStorage : localStorage
  const target = value ? localStorage : sessionStorage

  for (const key of SESSION_KEYS) {
    const item = source.getItem(key)
    if (item !== null) {
      target.setItem(key, item)
    }
    source.removeItem(key)
  }
}

export function getSessionItem(key: SessionKey): string | null {
  return getActiveStorage().getItem(key)
}

export function setSessionItem(key: SessionKey, value: string): void {
  getActiveStorage().setItem(key, value)
}

export function removeSessionItem(key: SessionKey): void {
  localStorage.removeItem(key)
  sessionStorage.removeItem(key)
}

export function clearSession(): void {
  for (const key of SESSION_KEYS) {
    localStorage.removeItem(key)
    sessionStorage.removeItem(key)
  }
}

export function getPendingInviteToken(): string | null {
  return localStorage.getItem(PENDING_INVITE_TOKEN_KEY)
}

export function setPendingInviteToken(token: string): void {
  localStorage.setItem(PENDING_INVITE_TOKEN_KEY, token)
}

export function removePendingInviteToken(): void {
  localStorage.removeItem(PENDING_INVITE_TOKEN_KEY)
}

export function hasAnySession(): boolean {
  return localStorage.getItem('auth_token') !== null || sessionStorage.getItem('auth_token') !== null
}
