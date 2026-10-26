/** A per-child tag as returned by the API: the name is ciphertext (child DEK). */
export interface ChildTag {
  id: number
  name_encrypted: string
  iv_name: string
  photo_count: number
  created_at: string
}

/** A child tag after client-side decryption of its name. */
export interface DecryptedChildTag extends ChildTag {
  child_id: number
  name: string
  normalized: string
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
  /** Owners and admins always can; members only when granted. Absent means allowed. */
  can_upload?: boolean
  storage_used: number
  storage_limit_bytes: number | null
  storage_percentage: number
  member_count?: number
  photo_count?: number
  created_at: string
}

export interface CreateChildRequest {
  name: string
  birth_date?: string | null
  avatar_color?: string | null
}

export interface InviteResponse {
  id: number
  token: string
  url: string
  role: string
  relationship: string | null
  can_upload?: boolean
  expires_at: string
  created_at: string
}

export interface InvitePreview {
  token: string
  child_name: string
  inviter_name: string
  role: string
  relationship: string | null
  can_upload?: boolean
  expires_at: string
}

export interface ChildMember {
  id: number
  display_name: string
  role: string
  relationship: string | null
  can_upload?: boolean
  joined_at: string
  last_active?: string | null
}

export interface ChildDetail extends Child {
  created_by: number
  members: ChildMember[]
  role: string
}
