/**
 * Password rules. The server never sees passwords, so these are enforced here only.
 */

/** Same as Laravel's `Password::defaults()` was before passwords stopped reaching the server. */
export const MIN_PASSWORD_LENGTH = 8

/**
 * Why a new password is not acceptable, or null when it is.
 * `currentPassword` is omitted when there is no old password to differ from (sign-up).
 */
export function newPasswordError(
  newPassword: string,
  confirmation: string,
  currentPassword?: string,
): string | null {
  if (newPassword.length < MIN_PASSWORD_LENGTH) {
    return `Your new password must be at least ${MIN_PASSWORD_LENGTH} characters long.`
  }
  if (newPassword !== confirmation) {
    return "The new passwords don't match."
  }
  if (currentPassword !== undefined && newPassword === currentPassword) {
    return 'Your new password must be different from your current one.'
  }
  return null
}
