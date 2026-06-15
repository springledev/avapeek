export interface ChildTag {
  id: number
  name: string
  photo_count: number
}

export interface Child {
  id: number
  slug: string
  name: string
  birth_date: string | null
  avatar_color: string | null
  age: string | null
  role: string
  relationship: string | null
  storage_used: number
  member_count?: number
  photo_count?: number
  created_at: string
  updated_at: string
}

/**
 * Request payload for creating a child.
 */
export interface CreateChildRequest {
  name: string
  birth_date?: string | null
  avatar_color?: string | null
}

/**
 * Response from creating an invite.
 */
export interface InviteResponse {
  id: number
  token: string
  url: string
  role: string
  relationship: string | null
  expires_at: string
  created_at: string
}

/**
 * Response from fetching invite preview.
 */
export interface InvitePreview {
  token: string
  child_name: string
  inviter_name: string
  role: string
  relationship: string | null
  expires_at: string
}

/**
 * A member of a child.
 */
export interface ChildMember {
  id: number
  display_name: string
  role: string
  relationship: string | null
  joined_at: string
}

/**
 * Child detail (with members, tags, and quota).
 */
export interface ChildDetail extends Child {
  created_by: number
  members: ChildMember[]
  role: string
  tags: ChildTag[]
  storage_limit_bytes: number
  storage_percentage: number
}
