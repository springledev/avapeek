/**
 * Child data from the API.
 */
export interface Child {
  id: number
  name: string
  birth_date: string | null
  avatar_color: string | null
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
 * Request payload for inviting a user to a child.
 */
export interface InviteUserRequest {
  email: string
  role: 'owner' | 'admin' | 'member'
  relationship?: string | null
}
