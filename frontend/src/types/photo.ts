/** A tag as returned by the API: the name is ciphertext (child DEK). */
export interface RawPhotoTag {
  id: number
  child_id: number
  name_encrypted: string
  iv_name: string
}

/** A photo tag after client-side decryption of its name. */
export interface PhotoTag extends RawPhotoTag {
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
  comment_count?: number
}

/** A photo as the API returns it, before its tag names are decrypted. */
export type ApiPhoto = Omit<Photo, 'tags'> & { tags: RawPhotoTag[] }

export interface PhotoComment {
  id: number
  user_id: number
  author_name: string
  body_encrypted: string
  iv_body: string
  created_at: string
}

export interface DecryptedComment {
  id: number
  user_id: number
  author_name: string
  text: string
  created_at: string
}

export interface CommentListMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface CommentListResponse {
  data: PhotoComment[]
  meta: CommentListMeta
}

export interface PhotoListMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface PhotoListResponse {
  data: ApiPhoto[]
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
