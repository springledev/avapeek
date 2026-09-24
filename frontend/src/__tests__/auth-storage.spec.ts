import { describe, it, expect, beforeEach } from 'vitest'
import {
  getStaySignedIn,
  setStaySignedIn,
  getSessionItem,
  setSessionItem,
  removeSessionItem,
  clearSession,
  getPendingInviteToken,
  setPendingInviteToken,
  removePendingInviteToken,
  hasAnySession,
} from '@/lib/auth-storage'

describe('auth-storage', () => {
  beforeEach(() => {
    localStorage.clear()
    sessionStorage.clear()
  })

  describe('stay signed in', () => {
    it('defaults to true', () => {
      expect(getStaySignedIn()).toBe(true)
    })

    it('can be set to false', () => {
      setStaySignedIn(false)
      expect(getStaySignedIn()).toBe(false)
      expect(localStorage.getItem('stay_signed_in')).toBe('0')
    })

    it('can be set back to true', () => {
      setStaySignedIn(false)
      setStaySignedIn(true)
      expect(getStaySignedIn()).toBe(true)
      expect(localStorage.getItem('stay_signed_in')).toBe('1')
    })

    it('migrates session data to sessionStorage when turning off', () => {
      setStaySignedIn(true)
      setSessionItem('auth_token', 'token-a')
      setSessionItem('user', 'user-a')

      setStaySignedIn(false)

      expect(localStorage.getItem('auth_token')).toBeNull()
      expect(localStorage.getItem('user')).toBeNull()
      expect(sessionStorage.getItem('auth_token')).toBe('token-a')
      expect(sessionStorage.getItem('user')).toBe('user-a')
    })

    it('migrates session data to localStorage when turning on', () => {
      setStaySignedIn(false)
      setSessionItem('auth_token', 'token-b')
      setSessionItem('user', 'user-b')

      setStaySignedIn(true)

      expect(sessionStorage.getItem('auth_token')).toBeNull()
      expect(sessionStorage.getItem('user')).toBeNull()
      expect(localStorage.getItem('auth_token')).toBe('token-b')
      expect(localStorage.getItem('user')).toBe('user-b')
    })
  })

  describe('session items', () => {
    it('writes to localStorage when stay signed in is true', () => {
      setStaySignedIn(true)
      setSessionItem('auth_token', 'token')
      expect(localStorage.getItem('auth_token')).toBe('token')
      expect(sessionStorage.getItem('auth_token')).toBeNull()
    })

    it('writes to sessionStorage when stay signed in is false', () => {
      setStaySignedIn(false)
      setSessionItem('auth_token', 'token')
      expect(sessionStorage.getItem('auth_token')).toBe('token')
      expect(localStorage.getItem('auth_token')).toBeNull()
    })

    it('reads from the active driver', () => {
      setStaySignedIn(false)
      sessionStorage.setItem('auth_token', 'session-token')
      expect(getSessionItem('auth_token')).toBe('session-token')

      setStaySignedIn(true)
      localStorage.setItem('auth_token', 'local-token')
      expect(getSessionItem('auth_token')).toBe('local-token')
    })

    it('removes from both storages', () => {
      localStorage.setItem('auth_token', 'local-token')
      sessionStorage.setItem('auth_token', 'session-token')
      removeSessionItem('auth_token')
      expect(localStorage.getItem('auth_token')).toBeNull()
      expect(sessionStorage.getItem('auth_token')).toBeNull()
    })

    it('clears all session keys from both storages', () => {
      localStorage.setItem('auth_token', 'a')
      localStorage.setItem('user', 'b')
      localStorage.setItem('encryption_keys', 'c')
      localStorage.setItem('kek', 'd')
      sessionStorage.setItem('auth_token', 'e')
      sessionStorage.setItem('user', 'f')
      sessionStorage.setItem('encryption_keys', 'g')
      sessionStorage.setItem('kek', 'h')

      clearSession()

      for (const key of ['auth_token', 'user', 'encryption_keys', 'kek']) {
        expect(localStorage.getItem(key)).toBeNull()
        expect(sessionStorage.getItem(key)).toBeNull()
      }
    })
  })

  describe('pending invite token', () => {
    it('reads from localStorage', () => {
      localStorage.setItem('pending_invite_token', 'invite-123')
      expect(getPendingInviteToken()).toBe('invite-123')
    })

    it('writes to localStorage', () => {
      setPendingInviteToken('invite-456')
      expect(localStorage.getItem('pending_invite_token')).toBe('invite-456')
    })

    it('removes from localStorage', () => {
      localStorage.setItem('pending_invite_token', 'invite-789')
      removePendingInviteToken()
      expect(localStorage.getItem('pending_invite_token')).toBeNull()
    })

    it('stays in localStorage when clearSession is called', () => {
      localStorage.setItem('pending_invite_token', 'invite-abc')
      clearSession()
      expect(getPendingInviteToken()).toBe('invite-abc')
    })
  })

  describe('hasAnySession', () => {
    it('returns true when auth_token is in localStorage', () => {
      localStorage.setItem('auth_token', 'token')
      expect(hasAnySession()).toBe(true)
    })

    it('returns true when auth_token is in sessionStorage', () => {
      sessionStorage.setItem('auth_token', 'token')
      expect(hasAnySession()).toBe(true)
    })

    it('returns false when auth_token is absent', () => {
      expect(hasAnySession()).toBe(false)
    })
  })
})
