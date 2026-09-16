/**
 * Kid color normalization.
 *
 * The API hands us `avatar_color` (any hex an admin picked). We never trust
 * raw values for text-adjacent UI: each child is assigned one of 6 verified
 * pastel-bg / deep-ink pairs (see tokens.css). Deterministic per child id,
 * with avatar_color used only as a seed tiebreaker.
 */

export interface KidPair {
  name: string
  bg: string
  ink: string
}

export const KID_PAIRS: readonly KidPair[] = [
  { name: 'peach', bg: '#fbe3d3', ink: '#8a4110' },
  { name: 'butter', bg: '#f8edcb', ink: '#75600b' },
  { name: 'mint', bg: '#dff0e2', ink: '#2e5a34' },
  { name: 'sky', bg: '#dce9f5', ink: '#1e4e79' },
  { name: 'lilac', bg: '#e8e1f4', ink: '#4a3672' },
  { name: 'rose', bg: '#f7dce0', ink: '#7e2f3e' },
]

function hashSeed(input: string): number {
  let hash = 0
  for (let i = 0; i < input.length; i++) {
    hash = (hash * 31 + input.charCodeAt(i)) | 0
  }
  return Math.abs(hash)
}

export function kidPairFor(id: number, avatarColor?: string | null): KidPair {
  const seed = hashSeed(avatarColor ?? String(id))
  const pair = KID_PAIRS[seed % KID_PAIRS.length]
  if (!pair) {
    throw new Error(`kidColors: no pair found at index ${seed % KID_PAIRS.length}`)
  }
  return pair
}

/** Inline style for chips/avatars: verified pastel background + deep ink. */
export function kidPairStyle(id: number, avatarColor?: string | null): Record<string, string> {
  const pair = kidPairFor(id, avatarColor)
  return {
    backgroundColor: pair.bg,
    color: pair.ink,
  }
}
