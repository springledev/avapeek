// Copies the public Markdown docs from the repository into the site source so
// the website never drifts from the code. Generated files are git-ignored.
import { mkdir, readFile, rm, writeFile } from 'node:fs/promises'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const websiteRoot = join(dirname(fileURLToPath(import.meta.url)), '..')
const repoRoot = join(websiteRoot, '..')
const srcDir = join(websiteRoot, 'src')

/**
 * Docs published on the site. Internal planning pages are left out on purpose,
 * and the long technical pages (security, architecture, development, why) are
 * replaced on the site by shorter pages in website/src/ that link to GitHub.
 */
const publishedDocs = [
  'installation.md',
  'configuration.md',
  'storage.md',
  'faq.md',
  'roadmap.md',
]

/** Links that point at pages not copied from docs/, mapped to site pages. */
const linkRewrites = [
  ['](../README.md#quickstart)', '](/#quickstart)'],
  ['](../SECURITY.md)', '](/security-policy)'],
  ['](security.md', '](/security'],
  ['](docs/security.md', '](/security'],
  ['](why-avapeek.md', '](/why'],
]

const rewriteLinks = (markdown) =>
  linkRewrites.reduce((text, [from, to]) => text.replaceAll(from, to), markdown)

const generatedNotice = (source) =>
  `<!-- Generated from ${source} by website/scripts/sync-docs.mjs. Edit the original. -->\n\n`

await rm(join(srcDir, 'docs'), { recursive: true, force: true })
await mkdir(join(srcDir, 'docs'), { recursive: true })

for (const file of publishedDocs) {
  const markdown = await readFile(join(repoRoot, 'docs', file), 'utf8')
  await writeFile(join(srcDir, 'docs', file), generatedNotice(`docs/${file}`) + rewriteLinks(markdown))
}

const docsIndex = await readFile(join(websiteRoot, 'docs-index.md'), 'utf8')
await writeFile(join(srcDir, 'docs', 'index.md'), docsIndex)

for (const [source, target] of [
  ['SECURITY.md', 'security-policy.md'],
  ['CHANGELOG.md', 'changelog.md'],
]) {
  const markdown = await readFile(join(repoRoot, source), 'utf8')
  await writeFile(join(srcDir, target), generatedNotice(source) + rewriteLinks(markdown))
}

console.log(`synced ${publishedDocs.length} docs, SECURITY.md and CHANGELOG.md`)
