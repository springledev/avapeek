<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { toast } from 'vue-sonner'
import { useChildStore } from '@/stores/child'
import { useUploadPhotos } from '@/composables/useUploadPhotos'
import { useSetupState, type SetupStep } from '@/composables/useSetupState'
import { useMotionPolicy } from '@/composables/useMotionPolicy'
import { MSG } from '@/lib/messages'
import type { Child } from '@/types/child'
import CreateChildForm from '@/components/CreateChildForm.vue'
import UploadPicker from '@/components/upload/UploadPicker.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiProgressOverlay from '@/components/ui/UiProgressOverlay.vue'

const router = useRouter()
const route = useRoute()
const childStore = useChildStore()
const { isComplete, firstIncompleteStep } = useSetupState()
const { reducedMotion } = useMotionPolicy()
const { isUploading, current, total, fileName, upload } = useUploadPhotos()

const isReady = ref(false)
const step = ref<SetupStep>(1)
const createdChild = ref<Child | null>(null)
const selectedChildId = ref<number | null>(null)
const skippedKid = ref(false)
const photosAdded = ref(0)
const uploadError = ref('')

const uploadableChildren = computed(() => childStore.uploadableChildren)
const hasUploadableChild = computed(() => uploadableChildren.value.length > 0)

const targetChild = computed(
  () =>
    uploadableChildren.value.find((c) => c.id === selectedChildId.value) ??
    uploadableChildren.value[0] ??
    null,
)

/** The kid the invite link points at: the one just added, else the first one. */
const inviteChild = computed(() => createdChild.value ?? childStore.children[0] ?? null)

function parseStepQuery(): SetupStep | null {
  const raw = Number(route.query.step)
  return raw === 1 || raw === 2 || raw === 3 ? raw : null
}

onMounted(async () => {
  if (!childStore.childrenLoaded) {
    try {
      await childStore.fetchChildren()
    } catch {
      // The wizard still works; kids just won't be pre-detected.
    }
  }

  if (isComplete.value) {
    await router.replace('/feed')
    return
  }

  step.value = parseStepQuery() ?? firstIncompleteStep.value
  selectedChildId.value = uploadableChildren.value[0]?.id ?? null
  isReady.value = true
})

function goToStep(next: SetupStep) {
  uploadError.value = ''
  step.value = next
}

function onChildCreated(child: Child) {
  createdChild.value = child
  selectedChildId.value = child.id
  skippedKid.value = false
  goToStep(2)
}

function skipKid() {
  skippedKid.value = !hasUploadableChild.value
  goToStep(3)
}

function skipPhoto() {
  goToStep(3)
}

async function onFilesSelected(files: File[]) {
  const child = targetChild.value
  if (!child || files.length === 0) return

  uploadError.value = ''
  const { succeeded, failed } = await upload(files, [child.id])
  photosAdded.value += succeeded

  if (failed > 0) {
    uploadError.value = MSG.uploadFailed
    toast.error(MSG.uploadFailed)
    return
  }

  toast.success(MSG.photosAdded(succeeded))
  goToStep(3)
}

function goToFeed() {
  router.push('/feed')
}

const stepNames = MSG.setupStepNames
</script>

<template>
  <div class="setup">
    <ol class="setup__steps" aria-label="Setup progress">
      <li
        v-for="(name, index) in stepNames"
        :key="name"
        class="setup__step"
        :class="{
          'setup__step--current': step === index + 1,
          'setup__step--done': step > index + 1,
        }"
        :aria-current="step === index + 1 ? 'step' : undefined"
      >
        <span class="setup__step-num">{{ index + 1 }}</span>
        <span class="setup__step-name">{{ name }}</span>
      </li>
    </ol>

    <Transition :name="reducedMotion ? '' : 'setup-fade'" :css="!reducedMotion" mode="out-in">
      <div v-if="!isReady" key="loading" class="setup__panel" aria-busy="true" />

      <section v-else-if="step === 1" key="kid" class="setup__panel" data-test="setup-step-kid">
        <h1 class="setup__title">{{ MSG.setupKidTitle }}</h1>
        <p class="setup__hint">{{ MSG.setupKidHint }}</p>
        <CreateChildForm @created="onChildCreated" />
        <UiButton variant="ghost" class="setup__skip" data-test="setup-skip" @click="skipKid">
          {{ MSG.setupSkip }}
        </UiButton>
      </section>

      <section v-else-if="step === 2" key="photo" class="setup__panel" data-test="setup-step-photo">
        <h1 class="setup__title">{{ MSG.setupPhotoTitle }}</h1>

        <template v-if="hasUploadableChild">
          <p class="setup__hint">{{ MSG.setupPhotoHint }}</p>

          <div v-if="uploadableChildren.length > 1" class="setup__field">
            <label class="setup__label" for="setup-child">{{ MSG.setupWhoFor }}</label>
            <select id="setup-child" v-model="selectedChildId" class="setup__select">
              <option v-for="c in uploadableChildren" :key="c.id" :value="c.id">
                {{ c.name }}
              </option>
            </select>
          </div>
          <p v-else-if="targetChild" class="setup__hint">For {{ targetChild.name }}</p>

          <div v-if="uploadError" class="setup__error" role="alert">{{ uploadError }}</div>
          <UploadPicker @select="onFilesSelected" />
        </template>

        <template v-else>
          <p class="setup__hint" data-test="setup-needs-kid">{{ MSG.setupPhotoNeedsKid }}</p>
          <div class="setup__picker-disabled" inert>
            <UploadPicker />
          </div>
          <UiButton variant="accent" data-test="setup-back-to-kid" @click="goToStep(1)">
            {{ MSG.setupAddKid }}
          </UiButton>
        </template>

        <UiButton variant="ghost" class="setup__skip" data-test="setup-skip" @click="skipPhoto">
          {{ MSG.setupSkip }}
        </UiButton>
      </section>

      <section v-else key="done" class="setup__panel" data-test="setup-step-done">
        <h1 class="setup__title">{{ MSG.setupDoneTitle }}</h1>

        <ul class="setup__summary">
          <li v-if="createdChild">{{ MSG.setupDoneKid(createdChild.name) }}</li>
          <li v-if="photosAdded > 0">{{ MSG.setupDonePhotos(photosAdded) }}</li>
        </ul>

        <p v-if="skippedKid" class="setup__hint" data-test="setup-skipped-kid-note">
          {{ MSG.setupSkippedKidNote }}
        </p>

        <div v-if="!hasUploadableChild || photosAdded === 0" class="setup__later">
          <h2 class="setup__later-title">{{ MSG.setupDoLater }}</h2>
          <button
            v-if="!hasUploadableChild"
            type="button"
            class="setup__link u-focus-ring"
            data-test="setup-later-kid"
            @click="goToStep(1)"
          >
            {{ MSG.setupDoLaterKid }}
          </button>
          <button
            v-else-if="photosAdded === 0"
            type="button"
            class="setup__link u-focus-ring"
            data-test="setup-later-photo"
            @click="goToStep(2)"
          >
            {{ MSG.setupDoLaterPhoto }}
          </button>
        </div>

        <div class="setup__actions">
          <UiButton variant="accent" size="lg" data-test="setup-feed" @click="goToFeed">
            {{ MSG.setupGoToFeed }}
          </UiButton>
          <router-link
            v-if="inviteChild"
            :to="`/settings/children/${inviteChild.id}`"
            class="setup__link u-focus-ring"
            data-test="setup-invite"
          >
            {{ MSG.setupInviteFamily }}
          </router-link>
        </div>
      </section>
    </Transition>

    <UiProgressOverlay
      :active="isUploading"
      :current="current"
      :total="total"
      :file-name="fileName"
    />
  </div>
</template>

<style scoped>
.setup {
  display: flex;
  flex-direction: column;
  gap: var(--space-6);
  max-width: 32rem;
  margin: 0 auto;
}

.setup__steps {
  display: flex;
  gap: var(--space-2);
  margin: 0;
  padding: 0;
  list-style: none;
}

.setup__step {
  display: flex;
  flex: 1;
  flex-direction: column;
  align-items: center;
  gap: var(--space-1);
  color: var(--color-ink-soft);
  font-size: var(--text-meta);
  text-align: center;
}

.setup__step-num {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 2rem;
  border-radius: var(--radius-pill);
  background: var(--color-surface-2);
  font-weight: 700;
}

.setup__step--current {
  color: var(--color-ink);
  font-weight: 700;
}

.setup__step--current .setup__step-num,
.setup__step--done .setup__step-num {
  background: var(--color-accent);
  color: var(--color-on-accent);
}

.setup__panel {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  min-height: 12rem;
}

.setup__title {
  margin: 0;
  font-size: var(--text-display);
  font-weight: 800;
  color: var(--color-ink);
}

.setup__hint {
  margin: 0;
  color: var(--color-ink-soft);
  font-size: var(--text-body);
  line-height: var(--lh-body);
}

.setup__field {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.setup__label {
  font-weight: 600;
  color: var(--color-ink);
}

.setup__select {
  min-height: var(--target-min);
  padding: var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-card);
  background: var(--color-surface);
  color: var(--color-ink);
  font-family: inherit;
  font-size: var(--text-body);
}

.setup__error {
  padding: var(--space-3);
  border-radius: var(--radius-card);
  background: var(--color-danger);
  color: var(--color-on-danger);
}

.setup__picker-disabled {
  opacity: 0.5;
  pointer-events: none;
}

.setup__skip {
  align-self: center;
  min-height: var(--target-min);
}

.setup__summary {
  margin: 0;
  padding-left: var(--space-4);
  color: var(--color-ink);
  font-size: var(--text-body);
}

.setup__later {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: var(--space-2);
}

.setup__later-title {
  margin: 0;
  font-size: var(--text-body);
  font-weight: 700;
  color: var(--color-ink-soft);
}

.setup__actions {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: var(--space-3);
}

.setup__link {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: var(--target-min);
  padding: 0 var(--space-3);
  border: 0;
  background: transparent;
  color: var(--color-accent-ink);
  font-family: inherit;
  font-size: var(--text-body);
  font-weight: 700;
  text-decoration: none;
  cursor: pointer;
}

.setup__link:hover {
  text-decoration: underline;
}

.setup-fade-enter-active,
.setup-fade-leave-active {
  transition: opacity var(--dur-micro) var(--ease-soft);
}

.setup-fade-enter-from,
.setup-fade-leave-to {
  opacity: 0;
}
</style>
