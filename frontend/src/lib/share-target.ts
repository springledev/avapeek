import { SHARE_REDIRECT_PATH } from './sw-routes'
import { stageSharedFiles } from './share-inbox'

/**
 * Handles a Web Share Target POST: stages the shared image files in the share
 * inbox and answers with a 303 to the upload page. Never throws, so a failed
 * staging still lands the user on the upload page.
 */
export async function handleShareTarget(
  request: { formData: () => Promise<FormData> },
  origin: string,
  stage: (files: File[]) => Promise<void> = stageSharedFiles,
): Promise<Response> {
  try {
    const formData = await request.formData()
    const files = formData
      .getAll('photos')
      .filter((entry): entry is File => entry instanceof File && entry.type.startsWith('image/'))
    if (files.length > 0) {
      await stage(files)
    }
  } catch (error) {
    console.warn('Share target staging failed', error)
  }
  return Response.redirect(new URL(SHARE_REDIRECT_PATH, origin).href, 303)
}
