/** Max length of a tag name in the UI. */
export const MAX_TAG_LENGTH = 50
/** Number of suggestions shown while typing a tag. */
export const MAX_TAG_SUGGESTIONS = 8

/**
 * Identity of a tag name on the client: NFKC, trimmed, collapsed whitespace, lower-cased.
 * The stored (encrypted) name keeps the user's original casing.
 */
export function normalizeTagName(name: string): string {
  return name.normalize('NFKC').trim().replace(/\s+/g, ' ').toLocaleLowerCase()
}

/** Normalised form that also ignores diacritics ("Zoë" and "zoe" match), used only for suggestions. */
export function foldTagName(name: string): string {
  return normalizeTagName(name).normalize('NFD').replace(/\p{M}/gu, '')
}

export interface SuggestableTag {
  name: string
  photo_count: number
}

/**
 * Up to `limit` tags matching `query`: diacritic/case-insensitive prefix matches first,
 * then substring matches, each group sorted by photo count (then name).
 */
export function rankTagSuggestions<T extends SuggestableTag>(
  tags: T[],
  query: string,
  limit = MAX_TAG_SUGGESTIONS,
): T[] {
  const needle = foldTagName(query)
  if (!needle) return []

  const prefix: T[] = []
  const substring: T[] = []
  for (const tag of tags) {
    const folded = foldTagName(tag.name)
    if (folded.startsWith(needle)) prefix.push(tag)
    else if (folded.includes(needle)) substring.push(tag)
  }

  const byCountThenName = (a: T, b: T) =>
    b.photo_count - a.photo_count || a.name.localeCompare(b.name)
  return [...prefix.sort(byCountThenName), ...substring.sort(byCountThenName)].slice(0, limit)
}

/** One entry per distinct normalised name (a photo shared by several children carries one tag per child). */
export function dedupeTagsByName<T extends { name: string }>(tags: T[]): T[] {
  const seen = new Set<string>()
  return tags.filter((tag) => {
    const key = normalizeTagName(tag.name)
    if (seen.has(key)) return false
    seen.add(key)
    return true
  })
}
