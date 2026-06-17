<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, watch, computed } from 'vue'
import { fetchOriginal, cleanupComponent, getPEKForPhoto } from '@/services/storage'
import { decryptBlob, revokeObjectURL } from '@/crypto/encrypt'
import { ageAt } from '@/utils/childHelpers'
import TagChip from '@/components/TagChip.vue'
import type { Photo } from '@/types/photo'
import type { TagItem } from '@/components/TagSidebar.vue'

const props = defineProps<{
  photo: Photo
  hasPrev: boolean
  hasNext: boolean
  canDelete: boolean
  childBirthDate: string | null
  allTags: TagItem[]
}>()

const emit = defineEmits<{
  close: []
  prev: []
  next: []
  delete: [photoId: number]
  tagAdded: [photoId: number, tagName: string]
  tagRemoved: [photoId: number, tagId: number]
}>()

const componentKey = 'photoviewer'

const imageUrl = ref<string | null>(null)
const isLoading = ref(true)
const hasError = ref(false)
const showInfo = ref(false)
const zoom = ref(1)
const exifData = ref<Record<string, unknown> | null>(null)
const showDeleteConfirm = ref(false)
const isDeleting = ref(false)
const showAddTagDropdown = ref(false)
const showNewTagInput = ref(false)
const newTagName = ref('')

const photoAge = computed(() => {
  if (!props.childBirthDate || !props.photo.taken_at) return null
  return ageAt(props.childBirthDate, new Date(props.photo.taken_at))
})

const existingTagIds = computed(() => new Set(props.photo.tags?.map((t) => t.id) ?? []))

const availableTagsToAdd = computed(() =>
  props.allTags.filter((t) => !existingTagIds.value.has(t.id)),
)

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

function formatDate(dateStr: string | null): string {
  if (!dateStr) return 'Unknown'
  return new Date(dateStr).toLocaleDateString('default', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  })
}

function formatShutterSpeed(et: unknown): string {
  if (typeof et === 'number' && et < 1) return `1/${Math.round(1 / et)}s`
  return `${et}s`
}

function zoomIn() {
  zoom.value = Math.min(zoom.value + 0.5, 4)
}

function zoomOut() {
  zoom.value = Math.max(zoom.value - 0.5, 0.5)
}

function resetZoom() {
  zoom.value = 1
}

function onWheel(event: WheelEvent) {
  event.preventDefault()
  if (event.deltaY < 0) zoomIn()
  else zoomOut()
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    if (showDeleteConfirm.value) { showDeleteConfirm.value = false; return }
    if (showAddTagDropdown.value) { showAddTagDropdown.value = false; return }
    emit('close')
  }
  if (event.key === 'ArrowLeft' && props.hasPrev) emit('prev')
  if (event.key === 'ArrowRight' && props.hasNext) emit('next')
  if (event.key === 'i') showInfo.value = !showInfo.value
}

async function decryptExif() {
  if (!props.photo.exif_encrypted || !props.photo.iv_exif) return
  try {
    const pek = await getPEKForPhoto(props.photo.children)
    if (!pek) return
    const binary = atob(props.photo.exif_encrypted)
    const bytes = new Uint8Array(binary.length)
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i)
    const decrypted = await decryptBlob(bytes.buffer, pek, props.photo.iv_exif)
    const text = new TextDecoder().decode(decrypted)
    exifData.value = JSON.parse(text)
  } catch {
    // EXIF decryption is best-effort
  }
}

async function loadImage() {
  isLoading.value = true
  hasError.value = false
  try {
    const pek = await getPEKForPhoto(props.photo.children)
    if (!pek) {
      hasError.value = true
      return
    }
    imageUrl.value = await fetchOriginal(
      props.photo.id,
      pek,
      props.photo.iv_original,
      props.photo.original_mime,
    )
  } catch {
    hasError.value = true
  } finally {
    isLoading.value = false
  }
}

async function handleDelete() {
  isDeleting.value = true
  try {
    emit('delete', props.photo.id)
  } finally {
    isDeleting.value = false
    showDeleteConfirm.value = false
  }
}

function handleAddTag(tagName: string) {
  emit('tagAdded', props.photo.id, tagName)
  showAddTagDropdown.value = false
  showNewTagInput.value = false
  newTagName.value = ''
}

function handleShowNewTagInput() {
  showNewTagInput.value = true
}

function submitNewTag() {
  const name = newTagName.value.trim()
  if (name) handleAddTag(name)
}

function handleRemoveTag(tagId: number) {
  emit('tagRemoved', props.photo.id, tagId)
}

function toggleAddTag() {
  showAddTagDropdown.value = !showAddTagDropdown.value
  showNewTagInput.value = false
  newTagName.value = ''
}

watch(() => props.photo.id, () => {
  if (imageUrl.value) revokeObjectURL(imageUrl.value)
  imageUrl.value = null
  zoom.value = 1
  exifData.value = null
  showInfo.value = false
  showDeleteConfirm.value = false
  showAddTagDropdown.value = false
  loadImage()
  decryptExif()
})

onMounted(() => {
  loadImage()
  decryptExif()
  window.addEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  if (imageUrl.value) revokeObjectURL(imageUrl.value)
  cleanupComponent(componentKey)
})
</script>
