/**
 * Output side of an export: where finished zip bytes go. Feature-detected.
 * Browsers with the File System Access API stream straight to disk; others
 * assemble each part as a Blob and save it with an anchor download.
 */

export interface SinkFile {
  /** Consumes the whole zip stream. Rejects on abort or write failure. */
  write(stream: ReadableStream<Uint8Array>, signal: AbortSignal): Promise<void>
  /** Best-effort removal of a partial output after failure or cancel. */
  discard(): Promise<void>
}

export interface ExportSink {
  /** True when output is streamed to disk (single zip, bounded memory). */
  streaming: boolean
  /** Opens one output file. For a streaming sink the first call must run inside a user gesture. */
  open(filename: string): Promise<SinkFile>
}

interface WritableHandle {
  createWritable(): Promise<FileSystemWritableFileStream>
  remove?: () => Promise<void>
}

type SaveFilePicker = (options: {
  suggestedName: string
  types: Array<{ description: string; accept: Record<string, string[]> }>
}) => Promise<WritableHandle>

function getSaveFilePicker(): SaveFilePicker | null {
  const picker = (globalThis as { showSaveFilePicker?: SaveFilePicker }).showSaveFilePicker
  return typeof picker === 'function' ? picker.bind(globalThis) : null
}

export function supportsStreamingSave(): boolean {
  return getSaveFilePicker() !== null
}

/** Saves a Blob through a temporary anchor; the object URL is revoked right after the click. */
export function saveBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = filename
  document.body.appendChild(anchor)
  anchor.click()
  document.body.removeChild(anchor)
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

function createStreamingSink(picker: SaveFilePicker): ExportSink {
  return {
    streaming: true,
    async open(filename) {
      const handle = await picker({
        suggestedName: filename,
        types: [{ description: 'Zip archive', accept: { 'application/zip': ['.zip'] } }],
      })
      return {
        async write(stream, signal) {
          const writable = await handle.createWritable()
          try {
            await stream.pipeTo(writable, { signal })
          } catch (error) {
            await writable.abort().catch(() => {})
            throw error
          }
        },
        async discard() {
          try {
            await handle.remove?.()
          } catch {
            // The partial file stays on disk when the handle cannot remove it.
          }
        },
      }
    },
  }
}

function createBlobSink(): ExportSink {
  return {
    streaming: false,
    async open(filename) {
      return {
        async write(stream, signal) {
          const blob = await new Response(stream).blob()
          signal.throwIfAborted()
          saveBlob(new Blob([blob], { type: 'application/zip' }), filename)
        },
        async discard() {},
      }
    },
  }
}

export function createExportSink(): ExportSink {
  const picker = getSaveFilePicker()
  return picker ? createStreamingSink(picker) : createBlobSink()
}
