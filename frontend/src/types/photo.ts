export interface PhotoTag {
  id: number
  name: string
}

export interface PhotoChild {
  id: number
  name: string
  avatar_color: string | null
  encrypted_pek: string
  iv_pek: string
}

export interface Photo {
  id: number
  uuid: string
  uploaded_by: number
  uploader_name?: string
  original_path: string
  thumbnail_path: string
  thumbnail_url?: string
  presigned_url?: string
  lqip?: string | null
  iv_lqip?: string | null
  iv_original: string
  iv_thumbnail: string
  original_mime: string
  size_bytes: number
  width: number | null
  height: number | null
  exif_encrypted: string | null
  iv_exif: string | null
  taken_at: string | null
  created_at: string
  tags: PhotoTag[]
  children: PhotoChild[]
}

export interface PhotoListMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface PhotoListResponse {
  data: Photo[]
  meta: PhotoListMeta
}

export interface UnseenCountResponse {
  data: {
    unseen_count: number
  }
}

export interface MarkSeenResponse {
  data: {
    last_seen_at: string
  }
}
