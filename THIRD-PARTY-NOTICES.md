# Third-Party Notices

Avapeek itself is licensed under the GNU Affero General Public License v3.0 or later (AGPL-3.0-or-later); see [LICENSE](LICENSE).

This file lists the third-party components (production, non-dev dependencies) that ship in Avapeek release images, together with their licenses. Each component remains under its own license. Full license texts are included in each package's distribution.

Notable points:

- `heic-to` (LGPL-3.0) is loaded as a separate lazily-loaded chunk in the frontend, not bundled into the main application bundle.
- `nette/*` packages are dual-licensed; Avapeek uses them under the BSD-3-Clause license.

Generated from `backend/composer.lock` (`packages` only, no `packages-dev`) and `frontend/package-lock.json` (entries without `"dev": true`).

## Summary

- Total components: 316 (Composer: 110, npm: 206)

### Counts by license

| License | Count |
|---|---:|
| MIT | 274 |
| MPL-2.0 | 12 |
| ISC | 10 |
| BSD-3-Clause | 9 |
| Apache-2.0 | 5 |
| BSD-2-Clause | 2 |
| BSD-3-Clause OR GPL-2.0-only OR GPL-3.0-only | 2 |
| 0BSD | 1 |
| LGPL-3.0 | 1 |

## Backend (Composer, production)

| Package | Version | License | Source |
|---|---|---|---|
| aws/aws-crt-php | v1.2.7 | Apache-2.0 | <https://github.com/awslabs/aws-crt-php> |
| aws/aws-sdk-php | 3.384.7 | Apache-2.0 | <https://aws.amazon.com/sdk-for-php> |
| brick/math | 0.14.8 | MIT | <https://github.com/brick/math/tree/0.14.8> |
| carbonphp/carbon-doctrine-types | 3.2.0 | MIT | <https://github.com/CarbonPHP/carbon-doctrine-types/tree/3.2.0> |
| dflydev/dot-access-data | v3.0.3 | MIT | <https://github.com/dflydev/dflydev-dot-access-data> |
| doctrine/deprecations | 1.1.6 | MIT | <https://www.doctrine-project.org/> |
| doctrine/inflector | 2.1.0 | MIT | <https://www.doctrine-project.org/projects/inflector.html> |
| doctrine/lexer | 3.0.1 | MIT | <https://www.doctrine-project.org/projects/lexer.html> |
| dragonmantank/cron-expression | v3.6.0 | MIT | <https://github.com/dragonmantank/cron-expression/tree/v3.6.0> |
| egulias/email-validator | 4.0.4 | MIT | <https://github.com/egulias/EmailValidator> |
| fruitcake/php-cors | v1.4.0 | MIT | <https://github.com/fruitcake/php-cors> |
| graham-campbell/result-type | v1.1.4 | MIT | <https://github.com/GrahamCampbell/Result-Type/tree/v1.1.4> |
| guzzlehttp/guzzle | 7.11.1 | MIT | <https://github.com/guzzle/guzzle/tree/7.11.1> |
| guzzlehttp/promises | 2.5.0 | MIT | <https://github.com/guzzle/promises/tree/2.5.0> |
| guzzlehttp/psr7 | 2.11.0 | MIT | <https://github.com/guzzle/psr7/tree/2.11.0> |
| guzzlehttp/uri-template | v1.0.6 | MIT | <https://github.com/guzzle/uri-template/tree/v1.0.6> |
| laminas/laminas-diactoros | 3.8.0 | BSD-3-Clause | <https://laminas.dev> |
| laravel/framework | v13.15.0 | MIT | <https://laravel.com> |
| laravel/octane | v2.17.5 | MIT | <https://github.com/laravel/octane> |
| laravel/prompts | v0.3.18 | MIT | <https://github.com/laravel/prompts/tree/v0.3.18> |
| laravel/sanctum | v4.3.2 | MIT | <https://github.com/laravel/sanctum> |
| laravel/serializable-closure | v2.0.13 | MIT | <https://github.com/laravel/serializable-closure> |
| laravel/tinker | v3.0.2 | MIT | <https://github.com/laravel/tinker/tree/v3.0.2> |
| lcobucci/clock | 3.6.0 | MIT | <https://github.com/lcobucci/clock/tree/3.6.0> |
| league/commonmark | 2.8.2 | BSD-3-Clause | <https://commonmark.thephpleague.com> |
| league/config | v1.2.0 | BSD-3-Clause | <https://config.thephpleague.com> |
| league/flysystem | 3.34.0 | MIT | <https://github.com/thephpleague/flysystem/tree/3.34.0> |
| league/flysystem-aws-s3-v3 | 3.34.0 | MIT | <https://github.com/thephpleague/flysystem-aws-s3-v3/tree/3.34.0> |
| league/flysystem-local | 3.31.0 | MIT | <https://github.com/thephpleague/flysystem-local/tree/3.31.0> |
| league/mime-type-detection | 1.16.0 | MIT | <https://github.com/thephpleague/mime-type-detection/tree/1.16.0> |
| league/uri | 7.8.1 | MIT | <https://uri.thephpleague.com> |
| league/uri-interfaces | 7.8.1 | MIT | <https://uri.thephpleague.com> |
| minishlink/web-push | v11.0.0 | MIT | <https://github.com/web-push-libs/web-push-php> |
| monolog/monolog | 3.10.0 | MIT | <https://github.com/Seldaek/monolog> |
| mtdowling/jmespath.php | 2.9.1 | MIT | <https://github.com/jmespath/jmespath.php/tree/2.9.1> |
| nesbot/carbon | 3.11.4 | MIT | <https://carbonphp.github.io/carbon/> |
| nette/schema | v1.3.5 | BSD-3-Clause OR GPL-2.0-only OR GPL-3.0-only | <https://nette.org> |
| nette/utils | v4.1.4 | BSD-3-Clause OR GPL-2.0-only OR GPL-3.0-only | <https://nette.org> |
| nikic/php-parser | v5.7.0 | BSD-3-Clause | <https://github.com/nikic/PHP-Parser/tree/v5.7.0> |
| nunomaduro/termwind | v2.4.0 | MIT | <https://github.com/nunomaduro/termwind/tree/v2.4.0> |
| paragonie/constant_time_encoding | v3.1.3 | MIT | <https://github.com/paragonie/constant_time_encoding> |
| php-http/discovery | 1.20.0 | MIT | <http://php-http.org> |
| php-http/httplug | 2.4.1 | MIT | <http://httplug.io> |
| php-http/promise | 1.3.1 | MIT | <http://httplug.io> |
| phpdocumentor/reflection-common | 2.2.0 | MIT | <http://www.phpdoc.org> |
| phpdocumentor/reflection-docblock | 6.0.3 | MIT | <https://github.com/phpDocumentor/ReflectionDocBlock/tree/6.0.3> |
| phpdocumentor/type-resolver | 2.0.0 | MIT | <https://github.com/phpDocumentor/TypeResolver/tree/2.0.0> |
| phpoption/phpoption | 1.9.5 | Apache-2.0 | <https://github.com/schmittjoh/php-option/tree/1.9.5> |
| phpstan/phpdoc-parser | 2.3.2 | MIT | <https://github.com/phpstan/phpdoc-parser/tree/2.3.2> |
| pragmarx/google2fa | v8.0.3 | MIT | <https://github.com/antonioribeiro/google2fa/tree/v8.0.3> |
| psr/clock | 1.0.0 | MIT | <https://github.com/php-fig/clock> |
| psr/container | 2.0.2 | MIT | <https://github.com/php-fig/container> |
| psr/event-dispatcher | 1.0.0 | MIT | <https://github.com/php-fig/event-dispatcher/tree/1.0.0> |
| psr/http-client | 1.0.3 | MIT | <https://github.com/php-fig/http-client> |
| psr/http-factory | 1.1.0 | MIT | <https://github.com/php-fig/http-factory> |
| psr/http-message | 2.0 | MIT | <https://github.com/php-fig/http-message> |
| psr/log | 3.0.2 | MIT | <https://github.com/php-fig/log> |
| psr/simple-cache | 3.0.0 | MIT | <https://github.com/php-fig/simple-cache/tree/3.0.0> |
| psy/psysh | v0.12.23 | MIT | <https://psysh.org> |
| ralouphie/getallheaders | 3.0.3 | MIT | <https://github.com/ralouphie/getallheaders/tree/develop> |
| ramsey/collection | 2.1.1 | MIT | <https://github.com/ramsey/collection/tree/2.1.1> |
| ramsey/uuid | 4.9.2 | MIT | <https://github.com/ramsey/uuid/tree/4.9.2> |
| spomky-labs/base64url | v2.0.4 | MIT | <https://github.com/Spomky-Labs/base64url> |
| spomky-labs/cbor-php | 3.4.2 | MIT | <https://github.com/Spomky-Labs/cbor-php/tree/3.4.2> |
| spomky-labs/pki-framework | 1.6.3 | MIT | <https://github.com/spomky-labs/pki-framework> |
| symfony/clock | v8.1.0 | MIT | <https://symfony.com> |
| symfony/console | v8.1.0 | MIT | <https://symfony.com> |
| symfony/css-selector | v8.1.0 | MIT | <https://symfony.com> |
| symfony/deprecation-contracts | v3.7.1 | MIT | <https://symfony.com> |
| symfony/error-handler | v8.1.0 | MIT | <https://symfony.com> |
| symfony/event-dispatcher | v8.1.0 | MIT | <https://symfony.com> |
| symfony/event-dispatcher-contracts | v3.7.0 | MIT | <https://symfony.com> |
| symfony/filesystem | v8.1.0 | MIT | <https://symfony.com> |
| symfony/finder | v8.1.0 | MIT | <https://symfony.com> |
| symfony/http-foundation | v8.1.0 | MIT | <https://symfony.com> |
| symfony/http-kernel | v8.1.0 | MIT | <https://symfony.com> |
| symfony/mailer | v8.1.0 | MIT | <https://symfony.com> |
| symfony/mime | v8.1.0 | MIT | <https://symfony.com> |
| symfony/polyfill-ctype | v1.37.0 | MIT | <https://symfony.com> |
| symfony/polyfill-intl-grapheme | v1.38.1 | MIT | <https://symfony.com> |
| symfony/polyfill-intl-idn | v1.38.1 | MIT | <https://symfony.com> |
| symfony/polyfill-intl-normalizer | v1.38.0 | MIT | <https://symfony.com> |
| symfony/polyfill-mbstring | v1.38.2 | MIT | <https://symfony.com> |
| symfony/polyfill-php80 | v1.37.0 | MIT | <https://symfony.com> |
| symfony/polyfill-php81 | v1.38.1 | MIT | <https://symfony.com> |
| symfony/polyfill-php83 | v1.43.0 | MIT | <https://symfony.com> |
| symfony/polyfill-php84 | v1.38.1 | MIT | <https://symfony.com> |
| symfony/polyfill-php85 | v1.38.1 | MIT | <https://symfony.com> |
| symfony/polyfill-php86 | v1.38.0 | MIT | <https://symfony.com> |
| symfony/polyfill-uuid | v1.37.0 | MIT | <https://symfony.com> |
| symfony/process | v8.1.7 | MIT | <https://symfony.com> |
| symfony/property-access | v8.1.4 | MIT | <https://symfony.com> |
| symfony/property-info | v8.1.7 | MIT | <https://symfony.com> |
| symfony/psr-http-message-bridge | v8.1.0 | MIT | <https://symfony.com> |
| symfony/routing | v8.1.0 | MIT | <https://symfony.com> |
| symfony/serializer | v8.1.7 | MIT | <https://symfony.com> |
| symfony/service-contracts | v3.7.0 | MIT | <https://symfony.com> |
| symfony/string | v8.1.0 | MIT | <https://symfony.com> |
| symfony/translation | v8.1.0 | MIT | <https://symfony.com> |
| symfony/translation-contracts | v3.7.0 | MIT | <https://symfony.com> |
| symfony/type-info | v8.1.5 | MIT | <https://symfony.com> |
| symfony/uid | v7.4.17 | MIT | <https://symfony.com> |
| symfony/var-dumper | v8.1.0 | MIT | <https://symfony.com> |
| tijsverkoyen/css-to-inline-styles | v2.4.0 | BSD-3-Clause | <https://github.com/tijsverkoyen/CssToInlineStyles> |
| vlucas/phpdotenv | v5.6.3 | BSD-3-Clause | <https://github.com/vlucas/phpdotenv/tree/v5.6.3> |
| voku/portable-ascii | 2.1.1 | MIT | <https://github.com/voku/portable-ascii> |
| web-auth/cose-lib | 4.8.2 | MIT | <https://github.com/web-auth> |
| web-auth/webauthn-lib | 4.9.3 | MIT | <https://github.com/web-auth> |
| web-token/jwt-library | 4.2.3 | MIT | <https://github.com/web-token> |
| webmozart/assert | 2.4.0 | MIT | <https://github.com/webmozarts/assert/tree/2.4.0> |

## Frontend (npm, production)

| Package | Version | License | Source |
|---|---|---|---|
| @babel/generator | 8.0.0-rc.6 | MIT | <https://registry.npmjs.org/@babel/generator/-/generator-8.0.0-rc.6.tgz> |
| @babel/helper-string-parser | 7.29.11 | MIT | <https://registry.npmjs.org/@babel/helper-string-parser/-/helper-string-parser-7.29.11.tgz> |
| @babel/helper-string-parser | 8.0.0-rc.6 | MIT | <https://registry.npmjs.org/@babel/helper-string-parser/-/helper-string-parser-8.0.0-rc.6.tgz> |
| @babel/helper-validator-identifier | 7.29.7 | MIT | <https://registry.npmjs.org/@babel/helper-validator-identifier/-/helper-validator-identifier-7.29.7.tgz> |
| @babel/helper-validator-identifier | 8.0.0-rc.6 | MIT | <https://registry.npmjs.org/@babel/helper-validator-identifier/-/helper-validator-identifier-8.0.0-rc.6.tgz> |
| @babel/parser | 7.29.11 | MIT | <https://registry.npmjs.org/@babel/parser/-/parser-7.29.11.tgz> |
| @babel/parser | 8.0.0-rc.6 | MIT | <https://registry.npmjs.org/@babel/parser/-/parser-8.0.0-rc.6.tgz> |
| @babel/types | 7.29.11 | MIT | <https://registry.npmjs.org/@babel/types/-/types-7.29.11.tgz> |
| @babel/types | 8.0.0-rc.6 | MIT | <https://registry.npmjs.org/@babel/types/-/types-8.0.0-rc.6.tgz> |
| @emnapi/core | 1.10.0 | MIT | <https://registry.npmjs.org/@emnapi/core/-/core-1.10.0.tgz> |
| @emnapi/runtime | 1.10.0 | MIT | <https://registry.npmjs.org/@emnapi/runtime/-/runtime-1.10.0.tgz> |
| @emnapi/wasi-threads | 1.2.1 | MIT | <https://registry.npmjs.org/@emnapi/wasi-threads/-/wasi-threads-1.2.1.tgz> |
| @jridgewell/gen-mapping | 0.3.13 | MIT | <https://registry.npmjs.org/@jridgewell/gen-mapping/-/gen-mapping-0.3.13.tgz> |
| @jridgewell/remapping | 2.3.5 | MIT | <https://registry.npmjs.org/@jridgewell/remapping/-/remapping-2.3.5.tgz> |
| @jridgewell/resolve-uri | 3.1.2 | MIT | <https://registry.npmjs.org/@jridgewell/resolve-uri/-/resolve-uri-3.1.2.tgz> |
| @jridgewell/source-map | 0.3.11 | MIT | <https://registry.npmjs.org/@jridgewell/source-map/-/source-map-0.3.11.tgz> |
| @jridgewell/sourcemap-codec | 1.5.5 | MIT | <https://registry.npmjs.org/@jridgewell/sourcemap-codec/-/sourcemap-codec-1.5.5.tgz> |
| @jridgewell/trace-mapping | 0.3.31 | MIT | <https://registry.npmjs.org/@jridgewell/trace-mapping/-/trace-mapping-0.3.31.tgz> |
| @lucide/vue | 1.22.0 | ISC | <https://registry.npmjs.org/@lucide/vue/-/vue-1.22.0.tgz> |
| @napi-rs/wasm-runtime | 1.1.4 | MIT | <https://registry.npmjs.org/@napi-rs/wasm-runtime/-/wasm-runtime-1.1.4.tgz> |
| @oxc-project/types | 0.132.0 | MIT | <https://registry.npmjs.org/@oxc-project/types/-/types-0.132.0.tgz> |
| @rolldown/binding-android-arm64 | 1.0.2 | MIT | <https://registry.npmjs.org/@rolldown/binding-android-arm64/-/binding-android-arm64-1.0.2.tgz> |
| @rolldown/binding-darwin-arm64 | 1.0.2 | MIT | <https://registry.npmjs.org/@rolldown/binding-darwin-arm64/-/binding-darwin-arm64-1.0.2.tgz> |
| @rolldown/binding-darwin-x64 | 1.0.2 | MIT | <https://registry.npmjs.org/@rolldown/binding-darwin-x64/-/binding-darwin-x64-1.0.2.tgz> |
| @rolldown/binding-freebsd-x64 | 1.0.2 | MIT | <https://registry.npmjs.org/@rolldown/binding-freebsd-x64/-/binding-freebsd-x64-1.0.2.tgz> |
| @rolldown/binding-linux-arm-gnueabihf | 1.0.2 | MIT | <https://registry.npmjs.org/@rolldown/binding-linux-arm-gnueabihf/-/binding-linux-arm-gnueabihf-1.0.2.tgz> |
| @rolldown/binding-linux-arm64-gnu | 1.0.2 | MIT | <https://registry.npmjs.org/@rolldown/binding-linux-arm64-gnu/-/binding-linux-arm64-gnu-1.0.2.tgz> |
| @rolldown/binding-linux-arm64-musl | 1.0.2 | MIT | <https://registry.npmjs.org/@rolldown/binding-linux-arm64-musl/-/binding-linux-arm64-musl-1.0.2.tgz> |
| @rolldown/binding-linux-ppc64-gnu | 1.0.2 | MIT | <https://registry.npmjs.org/@rolldown/binding-linux-ppc64-gnu/-/binding-linux-ppc64-gnu-1.0.2.tgz> |
| @rolldown/binding-linux-s390x-gnu | 1.0.2 | MIT | <https://registry.npmjs.org/@rolldown/binding-linux-s390x-gnu/-/binding-linux-s390x-gnu-1.0.2.tgz> |
| @rolldown/binding-linux-x64-gnu | 1.0.2 | MIT | <https://registry.npmjs.org/@rolldown/binding-linux-x64-gnu/-/binding-linux-x64-gnu-1.0.2.tgz> |
| @rolldown/binding-linux-x64-musl | 1.0.2 | MIT | <https://registry.npmjs.org/@rolldown/binding-linux-x64-musl/-/binding-linux-x64-musl-1.0.2.tgz> |
| @rolldown/binding-openharmony-arm64 | 1.0.2 | MIT | <https://registry.npmjs.org/@rolldown/binding-openharmony-arm64/-/binding-openharmony-arm64-1.0.2.tgz> |
| @rolldown/binding-wasm32-wasi | 1.0.2 | MIT | <https://registry.npmjs.org/@rolldown/binding-wasm32-wasi/-/binding-wasm32-wasi-1.0.2.tgz> |
| @rolldown/binding-win32-arm64-msvc | 1.0.2 | MIT | <https://registry.npmjs.org/@rolldown/binding-win32-arm64-msvc/-/binding-win32-arm64-msvc-1.0.2.tgz> |
| @rolldown/binding-win32-x64-msvc | 1.0.2 | MIT | <https://registry.npmjs.org/@rolldown/binding-win32-x64-msvc/-/binding-win32-x64-msvc-1.0.2.tgz> |
| @rolldown/pluginutils | 1.0.1 | MIT | <https://registry.npmjs.org/@rolldown/pluginutils/-/pluginutils-1.0.1.tgz> |
| @simplewebauthn/browser | 11.0.0 | MIT | <https://registry.npmjs.org/@simplewebauthn/browser/-/browser-11.0.0.tgz> |
| @simplewebauthn/types | 11.0.0 | MIT | <https://registry.npmjs.org/@simplewebauthn/types/-/types-11.0.0.tgz> |
| @tybys/wasm-util | 0.10.2 | MIT | <https://registry.npmjs.org/@tybys/wasm-util/-/wasm-util-0.10.2.tgz> |
| @types/jsesc | 2.5.1 | MIT | <https://registry.npmjs.org/@types/jsesc/-/jsesc-2.5.1.tgz> |
| @types/node | 24.12.4 | MIT | <https://registry.npmjs.org/@types/node/-/node-24.12.4.tgz> |
| @types/trusted-types | 2.0.7 | MIT | <https://registry.npmjs.org/@types/trusted-types/-/trusted-types-2.0.7.tgz> |
| @vue-macros/common | 3.1.2 | MIT | <https://registry.npmjs.org/@vue-macros/common/-/common-3.1.2.tgz> |
| @vue/compiler-core | 3.5.35 | MIT | <https://registry.npmjs.org/@vue/compiler-core/-/compiler-core-3.5.35.tgz> |
| @vue/compiler-dom | 3.5.35 | MIT | <https://registry.npmjs.org/@vue/compiler-dom/-/compiler-dom-3.5.35.tgz> |
| @vue/compiler-sfc | 3.5.35 | MIT | <https://registry.npmjs.org/@vue/compiler-sfc/-/compiler-sfc-3.5.35.tgz> |
| @vue/compiler-ssr | 3.5.35 | MIT | <https://registry.npmjs.org/@vue/compiler-ssr/-/compiler-ssr-3.5.35.tgz> |
| @vue/devtools-api | 7.7.9 | MIT | <https://registry.npmjs.org/@vue/devtools-api/-/devtools-api-7.7.9.tgz> |
| @vue/devtools-api | 8.1.2 | MIT | <https://registry.npmjs.org/@vue/devtools-api/-/devtools-api-8.1.2.tgz> |
| @vue/devtools-kit | 7.7.9 | MIT | <https://registry.npmjs.org/@vue/devtools-kit/-/devtools-kit-7.7.9.tgz> |
| @vue/devtools-kit | 8.1.2 | MIT | <https://registry.npmjs.org/@vue/devtools-kit/-/devtools-kit-8.1.2.tgz> |
| @vue/devtools-shared | 7.7.9 | MIT | <https://registry.npmjs.org/@vue/devtools-shared/-/devtools-shared-7.7.9.tgz> |
| @vue/devtools-shared | 8.1.2 | MIT | <https://registry.npmjs.org/@vue/devtools-shared/-/devtools-shared-8.1.2.tgz> |
| @vue/reactivity | 3.5.35 | MIT | <https://registry.npmjs.org/@vue/reactivity/-/reactivity-3.5.35.tgz> |
| @vue/runtime-core | 3.5.35 | MIT | <https://registry.npmjs.org/@vue/runtime-core/-/runtime-core-3.5.35.tgz> |
| @vue/runtime-dom | 3.5.35 | MIT | <https://registry.npmjs.org/@vue/runtime-dom/-/runtime-dom-3.5.35.tgz> |
| @vue/server-renderer | 3.5.35 | MIT | <https://registry.npmjs.org/@vue/server-renderer/-/server-renderer-3.5.35.tgz> |
| @vue/shared | 3.5.35 | MIT | <https://registry.npmjs.org/@vue/shared/-/shared-3.5.35.tgz> |
| acorn | 8.16.0 | MIT | <https://registry.npmjs.org/acorn/-/acorn-8.16.0.tgz> |
| agent-base | 6.0.2 | MIT | <https://registry.npmjs.org/agent-base/-/agent-base-6.0.2.tgz> |
| ansi-regex | 5.0.1 | MIT | <https://registry.npmjs.org/ansi-regex/-/ansi-regex-5.0.1.tgz> |
| ansi-regex | 5.0.1 | MIT | <https://registry.npmjs.org/ansi-regex/-/ansi-regex-5.0.1.tgz> |
| ansi-styles | 4.3.0 | MIT | <https://registry.npmjs.org/ansi-styles/-/ansi-styles-4.3.0.tgz> |
| ast-kit | 2.2.0 | MIT | <https://registry.npmjs.org/ast-kit/-/ast-kit-2.2.0.tgz> |
| ast-walker-scope | 0.9.0 | MIT | <https://registry.npmjs.org/ast-walker-scope/-/ast-walker-scope-0.9.0.tgz> |
| asynckit | 0.4.0 | MIT | <https://registry.npmjs.org/asynckit/-/asynckit-0.4.0.tgz> |
| axios | 1.16.1 | MIT | <https://registry.npmjs.org/axios/-/axios-1.16.1.tgz> |
| birpc | 2.9.0 | MIT | <https://registry.npmjs.org/birpc/-/birpc-2.9.0.tgz> |
| buffer-from | 1.1.2 | MIT | <https://registry.npmjs.org/buffer-from/-/buffer-from-1.1.2.tgz> |
| call-bind-apply-helpers | 1.0.2 | MIT | <https://registry.npmjs.org/call-bind-apply-helpers/-/call-bind-apply-helpers-1.0.2.tgz> |
| camelcase | 5.3.1 | MIT | <https://registry.npmjs.org/camelcase/-/camelcase-5.3.1.tgz> |
| chokidar | 5.0.0 | MIT | <https://registry.npmjs.org/chokidar/-/chokidar-5.0.0.tgz> |
| client-zip | 2.5.1 | MIT | <https://registry.npmjs.org/client-zip/-/client-zip-2.5.1.tgz> |
| cliui | 6.0.0 | ISC | <https://registry.npmjs.org/cliui/-/cliui-6.0.0.tgz> |
| color-convert | 2.0.1 | MIT | <https://registry.npmjs.org/color-convert/-/color-convert-2.0.1.tgz> |
| color-name | 1.1.4 | MIT | <https://registry.npmjs.org/color-name/-/color-name-1.1.4.tgz> |
| combined-stream | 1.0.8 | MIT | <https://registry.npmjs.org/combined-stream/-/combined-stream-1.0.8.tgz> |
| commander | 2.20.3 | MIT | <https://registry.npmjs.org/commander/-/commander-2.20.3.tgz> |
| confbox | 0.2.4 | MIT | <https://registry.npmjs.org/confbox/-/confbox-0.2.4.tgz> |
| confbox | 0.1.8 | MIT | <https://registry.npmjs.org/confbox/-/confbox-0.1.8.tgz> |
| copy-anything | 4.0.5 | MIT | <https://registry.npmjs.org/copy-anything/-/copy-anything-4.0.5.tgz> |
| csstype | 3.2.3 | MIT | <https://registry.npmjs.org/csstype/-/csstype-3.2.3.tgz> |
| debug | 4.4.3 | MIT | <https://registry.npmjs.org/debug/-/debug-4.4.3.tgz> |
| decamelize | 1.2.0 | MIT | <https://registry.npmjs.org/decamelize/-/decamelize-1.2.0.tgz> |
| delayed-stream | 1.0.0 | MIT | <https://registry.npmjs.org/delayed-stream/-/delayed-stream-1.0.0.tgz> |
| detect-libc | 2.1.2 | Apache-2.0 | <https://registry.npmjs.org/detect-libc/-/detect-libc-2.1.2.tgz> |
| dijkstrajs | 1.0.3 | MIT | <https://registry.npmjs.org/dijkstrajs/-/dijkstrajs-1.0.3.tgz> |
| dunder-proto | 1.0.1 | MIT | <https://registry.npmjs.org/dunder-proto/-/dunder-proto-1.0.1.tgz> |
| emoji-regex | 8.0.0 | MIT | <https://registry.npmjs.org/emoji-regex/-/emoji-regex-8.0.0.tgz> |
| emoji-regex | 8.0.0 | MIT | <https://registry.npmjs.org/emoji-regex/-/emoji-regex-8.0.0.tgz> |
| entities | 7.0.1 | BSD-2-Clause | <https://registry.npmjs.org/entities/-/entities-7.0.1.tgz> |
| es-define-property | 1.0.1 | MIT | <https://registry.npmjs.org/es-define-property/-/es-define-property-1.0.1.tgz> |
| es-errors | 1.3.0 | MIT | <https://registry.npmjs.org/es-errors/-/es-errors-1.3.0.tgz> |
| es-object-atoms | 1.1.2 | MIT | <https://registry.npmjs.org/es-object-atoms/-/es-object-atoms-1.1.2.tgz> |
| es-set-tostringtag | 2.1.0 | MIT | <https://registry.npmjs.org/es-set-tostringtag/-/es-set-tostringtag-2.1.0.tgz> |
| estree-walker | 2.0.2 | MIT | <https://registry.npmjs.org/estree-walker/-/estree-walker-2.0.2.tgz> |
| exifr | 7.1.3 | MIT | <https://registry.npmjs.org/exifr/-/exifr-7.1.3.tgz> |
| exsolve | 1.0.8 | MIT | <https://registry.npmjs.org/exsolve/-/exsolve-1.0.8.tgz> |
| fdir | 6.5.0 | MIT | <https://registry.npmjs.org/fdir/-/fdir-6.5.0.tgz> |
| find-up | 4.1.0 | MIT | <https://registry.npmjs.org/find-up/-/find-up-4.1.0.tgz> |
| follow-redirects | 1.16.0 | MIT | <https://registry.npmjs.org/follow-redirects/-/follow-redirects-1.16.0.tgz> |
| form-data | 4.0.5 | MIT | <https://registry.npmjs.org/form-data/-/form-data-4.0.5.tgz> |
| fsevents | 2.3.3 | MIT | <https://registry.npmjs.org/fsevents/-/fsevents-2.3.3.tgz> |
| function-bind | 1.1.2 | MIT | <https://registry.npmjs.org/function-bind/-/function-bind-1.1.2.tgz> |
| get-caller-file | 2.0.5 | ISC | <https://registry.npmjs.org/get-caller-file/-/get-caller-file-2.0.5.tgz> |
| get-intrinsic | 1.3.0 | MIT | <https://registry.npmjs.org/get-intrinsic/-/get-intrinsic-1.3.0.tgz> |
| get-proto | 1.0.1 | MIT | <https://registry.npmjs.org/get-proto/-/get-proto-1.0.1.tgz> |
| gopd | 1.2.0 | MIT | <https://registry.npmjs.org/gopd/-/gopd-1.2.0.tgz> |
| has-symbols | 1.1.0 | MIT | <https://registry.npmjs.org/has-symbols/-/has-symbols-1.1.0.tgz> |
| has-tostringtag | 1.0.2 | MIT | <https://registry.npmjs.org/has-tostringtag/-/has-tostringtag-1.0.2.tgz> |
| hasown | 2.0.4 | MIT | <https://registry.npmjs.org/hasown/-/hasown-2.0.4.tgz> |
| heic-to | 1.5.2 | LGPL-3.0 | <https://registry.npmjs.org/heic-to/-/heic-to-1.5.2.tgz> |
| hookable | 5.5.3 | MIT | <https://registry.npmjs.org/hookable/-/hookable-5.5.3.tgz> |
| https-proxy-agent | 5.0.1 | MIT | <https://registry.npmjs.org/https-proxy-agent/-/https-proxy-agent-5.0.1.tgz> |
| is-fullwidth-code-point | 3.0.0 | MIT | <https://registry.npmjs.org/is-fullwidth-code-point/-/is-fullwidth-code-point-3.0.0.tgz> |
| is-what | 5.5.0 | MIT | <https://registry.npmjs.org/is-what/-/is-what-5.5.0.tgz> |
| jiti | 2.7.0 | MIT | <https://registry.npmjs.org/jiti/-/jiti-2.7.0.tgz> |
| jsesc | 3.1.0 | MIT | <https://registry.npmjs.org/jsesc/-/jsesc-3.1.0.tgz> |
| json5 | 2.2.3 | MIT | <https://registry.npmjs.org/json5/-/json5-2.2.3.tgz> |
| lightningcss | 1.32.0 | MPL-2.0 | <https://registry.npmjs.org/lightningcss/-/lightningcss-1.32.0.tgz> |
| lightningcss-android-arm64 | 1.32.0 | MPL-2.0 | <https://registry.npmjs.org/lightningcss-android-arm64/-/lightningcss-android-arm64-1.32.0.tgz> |
| lightningcss-darwin-arm64 | 1.32.0 | MPL-2.0 | <https://registry.npmjs.org/lightningcss-darwin-arm64/-/lightningcss-darwin-arm64-1.32.0.tgz> |
| lightningcss-darwin-x64 | 1.32.0 | MPL-2.0 | <https://registry.npmjs.org/lightningcss-darwin-x64/-/lightningcss-darwin-x64-1.32.0.tgz> |
| lightningcss-freebsd-x64 | 1.32.0 | MPL-2.0 | <https://registry.npmjs.org/lightningcss-freebsd-x64/-/lightningcss-freebsd-x64-1.32.0.tgz> |
| lightningcss-linux-arm-gnueabihf | 1.32.0 | MPL-2.0 | <https://registry.npmjs.org/lightningcss-linux-arm-gnueabihf/-/lightningcss-linux-arm-gnueabihf-1.32.0.tgz> |
| lightningcss-linux-arm64-gnu | 1.32.0 | MPL-2.0 | <https://registry.npmjs.org/lightningcss-linux-arm64-gnu/-/lightningcss-linux-arm64-gnu-1.32.0.tgz> |
| lightningcss-linux-arm64-musl | 1.32.0 | MPL-2.0 | <https://registry.npmjs.org/lightningcss-linux-arm64-musl/-/lightningcss-linux-arm64-musl-1.32.0.tgz> |
| lightningcss-linux-x64-gnu | 1.32.0 | MPL-2.0 | <https://registry.npmjs.org/lightningcss-linux-x64-gnu/-/lightningcss-linux-x64-gnu-1.32.0.tgz> |
| lightningcss-linux-x64-musl | 1.32.0 | MPL-2.0 | <https://registry.npmjs.org/lightningcss-linux-x64-musl/-/lightningcss-linux-x64-musl-1.32.0.tgz> |
| lightningcss-win32-arm64-msvc | 1.32.0 | MPL-2.0 | <https://registry.npmjs.org/lightningcss-win32-arm64-msvc/-/lightningcss-win32-arm64-msvc-1.32.0.tgz> |
| lightningcss-win32-x64-msvc | 1.32.0 | MPL-2.0 | <https://registry.npmjs.org/lightningcss-win32-x64-msvc/-/lightningcss-win32-x64-msvc-1.32.0.tgz> |
| local-pkg | 1.2.1 | MIT | <https://registry.npmjs.org/local-pkg/-/local-pkg-1.2.1.tgz> |
| locate-path | 5.0.0 | MIT | <https://registry.npmjs.org/locate-path/-/locate-path-5.0.0.tgz> |
| magic-string | 0.30.21 | MIT | <https://registry.npmjs.org/magic-string/-/magic-string-0.30.21.tgz> |
| magic-string-ast | 1.0.3 | MIT | <https://registry.npmjs.org/magic-string-ast/-/magic-string-ast-1.0.3.tgz> |
| math-intrinsics | 1.1.0 | MIT | <https://registry.npmjs.org/math-intrinsics/-/math-intrinsics-1.1.0.tgz> |
| mime-db | 1.52.0 | MIT | <https://registry.npmjs.org/mime-db/-/mime-db-1.52.0.tgz> |
| mime-types | 2.1.35 | MIT | <https://registry.npmjs.org/mime-types/-/mime-types-2.1.35.tgz> |
| mitt | 3.0.1 | MIT | <https://registry.npmjs.org/mitt/-/mitt-3.0.1.tgz> |
| mitt | 2.1.0 | MIT | <https://registry.npmjs.org/mitt/-/mitt-2.1.0.tgz> |
| mlly | 1.8.2 | MIT | <https://registry.npmjs.org/mlly/-/mlly-1.8.2.tgz> |
| ms | 2.1.3 | MIT | <https://registry.npmjs.org/ms/-/ms-2.1.3.tgz> |
| muggle-string | 0.4.1 | MIT | <https://registry.npmjs.org/muggle-string/-/muggle-string-0.4.1.tgz> |
| nanoid | 3.3.12 | MIT | <https://registry.npmjs.org/nanoid/-/nanoid-3.3.12.tgz> |
| p-limit | 2.3.0 | MIT | <https://registry.npmjs.org/p-limit/-/p-limit-2.3.0.tgz> |
| p-locate | 4.1.0 | MIT | <https://registry.npmjs.org/p-locate/-/p-locate-4.1.0.tgz> |
| p-try | 2.2.0 | MIT | <https://registry.npmjs.org/p-try/-/p-try-2.2.0.tgz> |
| path-exists | 4.0.0 | MIT | <https://registry.npmjs.org/path-exists/-/path-exists-4.0.0.tgz> |
| pathe | 2.0.3 | MIT | <https://registry.npmjs.org/pathe/-/pathe-2.0.3.tgz> |
| perfect-debounce | 1.0.0 | MIT | <https://registry.npmjs.org/perfect-debounce/-/perfect-debounce-1.0.0.tgz> |
| perfect-debounce | 2.1.0 | MIT | <https://registry.npmjs.org/perfect-debounce/-/perfect-debounce-2.1.0.tgz> |
| picocolors | 1.1.1 | ISC | <https://registry.npmjs.org/picocolors/-/picocolors-1.1.1.tgz> |
| picomatch | 4.0.4 | MIT | <https://registry.npmjs.org/picomatch/-/picomatch-4.0.4.tgz> |
| picomatch | 4.0.4 | MIT | <https://registry.npmjs.org/picomatch/-/picomatch-4.0.4.tgz> |
| picomatch | 4.0.4 | MIT | <https://registry.npmjs.org/picomatch/-/picomatch-4.0.4.tgz> |
| picomatch | 4.0.4 | MIT | <https://registry.npmjs.org/picomatch/-/picomatch-4.0.4.tgz> |
| picomatch | 4.0.4 | MIT | <https://registry.npmjs.org/picomatch/-/picomatch-4.0.4.tgz> |
| pinia | 3.0.4 | MIT | <https://registry.npmjs.org/pinia/-/pinia-3.0.4.tgz> |
| pkg-types | 1.3.1 | MIT | <https://registry.npmjs.org/pkg-types/-/pkg-types-1.3.1.tgz> |
| pkg-types | 2.3.1 | MIT | <https://registry.npmjs.org/pkg-types/-/pkg-types-2.3.1.tgz> |
| pngjs | 5.0.0 | MIT | <https://registry.npmjs.org/pngjs/-/pngjs-5.0.0.tgz> |
| postcss | 8.5.15 | MIT | <https://registry.npmjs.org/postcss/-/postcss-8.5.15.tgz> |
| proxy-from-env | 2.1.0 | MIT | <https://registry.npmjs.org/proxy-from-env/-/proxy-from-env-2.1.0.tgz> |
| qrcode | 1.5.4 | MIT | <https://registry.npmjs.org/qrcode/-/qrcode-1.5.4.tgz> |
| quansync | 0.2.11 | MIT | <https://registry.npmjs.org/quansync/-/quansync-0.2.11.tgz> |
| readdirp | 5.0.0 | MIT | <https://registry.npmjs.org/readdirp/-/readdirp-5.0.0.tgz> |
| require-directory | 2.1.1 | MIT | <https://registry.npmjs.org/require-directory/-/require-directory-2.1.1.tgz> |
| require-main-filename | 2.0.0 | ISC | <https://registry.npmjs.org/require-main-filename/-/require-main-filename-2.0.0.tgz> |
| rfdc | 1.4.1 | MIT | <https://registry.npmjs.org/rfdc/-/rfdc-1.4.1.tgz> |
| rolldown | 1.0.2 | MIT | <https://registry.npmjs.org/rolldown/-/rolldown-1.0.2.tgz> |
| scule | 1.3.0 | MIT | <https://registry.npmjs.org/scule/-/scule-1.3.0.tgz> |
| set-blocking | 2.0.0 | ISC | <https://registry.npmjs.org/set-blocking/-/set-blocking-2.0.0.tgz> |
| source-map | 0.6.1 | BSD-3-Clause | <https://registry.npmjs.org/source-map/-/source-map-0.6.1.tgz> |
| source-map-js | 1.2.1 | BSD-3-Clause | <https://registry.npmjs.org/source-map-js/-/source-map-js-1.2.1.tgz> |
| source-map-support | 0.5.21 | MIT | <https://registry.npmjs.org/source-map-support/-/source-map-support-0.5.21.tgz> |
| speakingurl | 14.0.1 | BSD-3-Clause | <https://registry.npmjs.org/speakingurl/-/speakingurl-14.0.1.tgz> |
| string-width | 4.2.3 | MIT | <https://registry.npmjs.org/string-width/-/string-width-4.2.3.tgz> |
| string-width | 4.2.3 | MIT | <https://registry.npmjs.org/string-width/-/string-width-4.2.3.tgz> |
| strip-ansi | 6.0.1 | MIT | <https://registry.npmjs.org/strip-ansi/-/strip-ansi-6.0.1.tgz> |
| strip-ansi | 6.0.1 | MIT | <https://registry.npmjs.org/strip-ansi/-/strip-ansi-6.0.1.tgz> |
| superjson | 2.2.6 | MIT | <https://registry.npmjs.org/superjson/-/superjson-2.2.6.tgz> |
| terser | 5.51.2 | BSD-2-Clause | <https://registry.npmjs.org/terser/-/terser-5.51.2.tgz> |
| tinyglobby | 0.2.16 | MIT | <https://registry.npmjs.org/tinyglobby/-/tinyglobby-0.2.16.tgz> |
| tslib | 2.8.1 | 0BSD | <https://registry.npmjs.org/tslib/-/tslib-2.8.1.tgz> |
| typescript | 6.0.3 | Apache-2.0 | <https://registry.npmjs.org/typescript/-/typescript-6.0.3.tgz> |
| ufo | 1.6.4 | MIT | <https://registry.npmjs.org/ufo/-/ufo-1.6.4.tgz> |
| undici-types | 7.16.0 | MIT | <https://registry.npmjs.org/undici-types/-/undici-types-7.16.0.tgz> |
| unplugin | 3.0.0 | MIT | <https://registry.npmjs.org/unplugin/-/unplugin-3.0.0.tgz> |
| unplugin-utils | 0.3.1 | MIT | <https://registry.npmjs.org/unplugin-utils/-/unplugin-utils-0.3.1.tgz> |
| vite | 8.0.14 | MIT | <https://registry.npmjs.org/vite/-/vite-8.0.14.tgz> |
| vue | 3.5.35 | MIT | <https://registry.npmjs.org/vue/-/vue-3.5.35.tgz> |
| vue-observe-visibility | 2.0.0-alpha.1 | MIT | <https://registry.npmjs.org/vue-observe-visibility/-/vue-observe-visibility-2.0.0-alpha.1.tgz> |
| vue-resize | 2.0.0-alpha.1 | MIT | <https://registry.npmjs.org/vue-resize/-/vue-resize-2.0.0-alpha.1.tgz> |
| vue-router | 5.1.0 | MIT | <https://registry.npmjs.org/vue-router/-/vue-router-5.1.0.tgz> |
| vue-sonner | 2.0.9 | MIT | <https://registry.npmjs.org/vue-sonner/-/vue-sonner-2.0.9.tgz> |
| vue-virtual-scroller | 2.0.0-beta.8 | MIT | <https://registry.npmjs.org/vue-virtual-scroller/-/vue-virtual-scroller-2.0.0-beta.8.tgz> |
| webpack-virtual-modules | 0.6.2 | MIT | <https://registry.npmjs.org/webpack-virtual-modules/-/webpack-virtual-modules-0.6.2.tgz> |
| which-module | 2.0.1 | ISC | <https://registry.npmjs.org/which-module/-/which-module-2.0.1.tgz> |
| workbox-core | 7.4.1 | MIT | <https://registry.npmjs.org/workbox-core/-/workbox-core-7.4.1.tgz> |
| workbox-window | 7.4.1 | MIT | <https://registry.npmjs.org/workbox-window/-/workbox-window-7.4.1.tgz> |
| wrap-ansi | 6.2.0 | MIT | <https://registry.npmjs.org/wrap-ansi/-/wrap-ansi-6.2.0.tgz> |
| y18n | 4.0.3 | ISC | <https://registry.npmjs.org/y18n/-/y18n-4.0.3.tgz> |
| yaml | 2.9.0 | ISC | <https://registry.npmjs.org/yaml/-/yaml-2.9.0.tgz> |
| yargs | 15.4.1 | MIT | <https://registry.npmjs.org/yargs/-/yargs-15.4.1.tgz> |
| yargs-parser | 18.1.3 | ISC | <https://registry.npmjs.org/yargs-parser/-/yargs-parser-18.1.3.tgz> |
