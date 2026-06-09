export interface PhotoTag {
  id: number
  name: string
}

export interface Photo {
  id: number
  child_id: number
  uploaded_by: number
  uploader_name?: string
  original_path: string
  thumbnail_sm_path: string
  thumbnail_md_path: string
  thumbnail_lg_path: string
  iv_original: string
  iv_sm: string
  iv_md: string
  iv_lg: string
  mime_type: string
  size_bytes: number
  width: number | null
  height: number | null
  exif_encrypted: string | null
  iv_exif: string | null
  taken_at: string | null
  created_at: string
  tags: PhotoTag[]
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
