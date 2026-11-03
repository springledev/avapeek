<script setup lang="ts">
import { computed } from 'vue'

/** Full installation guide, TLS section. */
const DOCS_URL = 'https://avapeek.com/docs/installation#tls-and-reverse-proxies'

/**
 * Encryption needs the Web Crypto API, which browsers only expose in a secure
 * context (HTTPS or localhost). Treat only an explicit `false` as insecure so
 * environments without the flag are not blocked.
 */
const isInsecure = computed(() => window.isSecureContext === false)
</script>

<template>
  <div v-if="isInsecure" class="insecure" role="alert" data-test="insecure-context">
    <p class="insecure__title">This needs HTTPS</p>
    <p class="insecure__body">
      Avapeek encrypts your photos in the browser, and browsers only allow that on a secure
      connection. Open this server over HTTPS (or on localhost) to sign in.
      <a :href="DOCS_URL" target="_blank" rel="noopener" class="insecure__link"
        >How to set up HTTPS</a
      >
    </p>
  </div>
</template>

<style scoped>
.insecure {
  margin-bottom: var(--space-4);
  padding: var(--space-3) var(--space-4);
  border: 1px solid var(--color-danger);
  border-radius: var(--radius-card);
  background: var(--color-surface);
  color: var(--color-ink);
}

.insecure__title {
  margin: 0 0 var(--space-1);
  font-weight: 800;
  color: var(--color-danger);
}

.insecure__body {
  margin: 0;
  font-size: var(--text-body);
  line-height: var(--lh-body);
}

.insecure__link {
  display: inline-flex;
  align-items: center;
  min-height: var(--target-min);
  font-weight: 700;
  color: var(--color-accent-ink);
}
</style>
