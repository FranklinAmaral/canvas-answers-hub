# Dependency audit evidence

Audit captured on 2026-11-11. See the raw before/after JSON files in this directory. These current advisories are not asserted to be the original scanner findings.

## Versões e decisões de compatibilidade

Comparação entre o lockfile original e o lockfile revisado.

| Pacote | Lock original | Lock atualizado |
| --- | --- | --- |
| laravel/framework | 13.5.0 | 13.42.0 |
| symfony/mailer | 8.0.8 | 8.0.15 |
| symfony/http-foundation | 8.0.8 | 7.4.23 |
| symfony/http-kernel | 8.0.8 | 7.4.23 |
| league/commonmark | 2.8.2 | 2.10.7 |
| guzzlehttp/guzzle | 7.10.0 | 7.15.2 |
| symfony/polyfill-intl-idn | 1.36.0 | 1.43.0 |
| symfony/mime | 8.0.8 | 7.4.22 |
| guzzlehttp/psr7 | 2.9.0 | 2.13.2 |
| symfony/routing | 8.0.8 | 7.4.23 |
| league/flysystem | 3.33.0 | 3.36.0 |

Atualização direcionada com `--with-all-dependencies --minimal-changes`. Mantidas
as linhas principais Laravel 13, Guzzle 7 e PSR-7 2. Mudanças transitivas necessárias:
Guzzle promises 2.3.0 → 2.5.1, Laravel prompts 0.3.16 → 0.3.27 e adição do polyfill
PHP 8.6. Os avisos recentes de alguns componentes Symfony afetam toda a linha
8.0; as correções 8.1 exigem PHP 8.5. Foi escolhida a alternativa 7.4 LTS corrigida,
permitida pelo Laravel, preservando PHP 8.4. O piso declarado anteriormente (8.3)
não atendia os pacotes Symfony 8 já travados. Verificar requisitos reais do host
com `composer check-platform-reqs`, que não simula a plataforma.

Vite 7.3.6 → 7.3.8; source-map-js 1.2.1 → 1.2.2. Avisos e faixas vulneráveis
originais estão em `npm-audit-before.json`. Fontes oficiais de licenciamento:
[schema v1.3.5](https://github.com/nette/schema/blob/v1.3.5/license.md) e
[utils v4.1.3](https://github.com/nette/utils/blob/v4.1.3/license.md).

## Avisos aplicáveis ao lockfile original

| Package | Updated lock | Advisory | Affected versions | Official advisory |
| --- | --- | --- | --- | --- |
| guzzlehttp/guzzle | 7.15.2 | CVE-2026-69246 | >=8.0.0,<8.0.1\|<7.15.2 | [source](https://github.com/advisories/GHSA-v5mv-p594-2x33) |
| guzzlehttp/guzzle | 7.15.2 | CVE-2026-69245 | >=8.0.0,<8.0.1\|<7.15.2 | [source](https://github.com/advisories/GHSA-f7vp-7xgx-4w4r) |
| guzzlehttp/guzzle | 7.15.2 | CVE-2026-67354 | <7.15.1 | [source](https://github.com/advisories/GHSA-h95v-h523-3mw8) |
| guzzlehttp/guzzle | 7.15.2 | CVE-2026-67355 | <7.15.1 | [source](https://github.com/advisories/GHSA-wm3w-8rrp-j577) |
| guzzlehttp/guzzle | 7.15.2 | CVE-2026-67353 | <7.15.1 | [source](https://github.com/advisories/GHSA-f283-ghqc-fg79) |
| guzzlehttp/guzzle | 7.15.2 | CVE-2026-59883 | <7.12.3 | [source](https://github.com/advisories/GHSA-g446-98w2-8p5w) |
| guzzlehttp/guzzle | 7.15.2 | CVE-2026-67339 | <7.14.2 | [source](https://github.com/advisories/GHSA-94pj-82f3-465w) |
| guzzlehttp/guzzle | 7.15.2 | CVE-2026-55767 | <7.12.1 | [source](https://github.com/guzzle/guzzle/security/advisories/GHSA-cwxw-98qj-8qjx) |
| guzzlehttp/guzzle | 7.15.2 | CVE-2026-55568 | <7.12.1 | [source](https://github.com/guzzle/guzzle/security/advisories/GHSA-wpwq-4j6v-78m3) |
| guzzlehttp/psr7 | 2.13.2 | CVE-2026-59882 | <2.12.3 | [source](https://github.com/advisories/GHSA-c2w2-prh8-qm98) |
| guzzlehttp/psr7 | 2.13.2 | CVE-2026-55766 | <2.12.1 | [source](https://github.com/guzzle/psr7/security/advisories/GHSA-vm85-hxw5-5432) |
| guzzlehttp/psr7 | 2.13.2 | CVE-2026-49214 | <2.10.2 | [source](https://github.com/guzzle/psr7/security/advisories/GHSA-hq7v-mx3g-29hw) |
| guzzlehttp/psr7 | 2.13.2 | CVE-2026-48998 | <2.10.2 | [source](https://github.com/guzzle/psr7/security/advisories/GHSA-34xg-wgjx-8xph) |
| laravel/framework | v13.42.0 | CVE-2026-114509 | <10.50.4\|>=11.0.0,<11.56.1\|>=12.0.0,<12.68.0\|>=13.0.0,<13.27.0 | [source](https://github.com/advisories/GHSA-f9x3-7vq9-cph6) |
| laravel/framework | v13.42.0 | CVE-2026-114508 | >=13.0.0,<13.27.0\|>=12.45.0,<12.68.0 | [source](https://github.com/advisories/GHSA-5wcm-3v2w-8362) |
| laravel/framework | v13.42.0 | CVE-2026-102279 | >=13.0.0,<13.30.0\|<12.69.0 | [source](https://github.com/advisories/GHSA-jh5r-qr3c-85q8) |
| laravel/framework | v13.42.0 | PKSA-m5cs-t1y6-qpcs | <12.61.1\|>=13.0.0,<13.12.0 | [source](https://github.com/advisories/GHSA-crmm-hgp2-wgrp) |
| laravel/framework | v13.42.0 | PKSA-3r5d-mb8f-1qw9 | <12.60.0\|>=13.0.0,<=13.9.0 | [source](https://github.com/advisories/GHSA-5vg9-5847-vvmq) |
| laravel/framework | v13.42.0 | CVE-2026-48019 | >=9.0.0,<10.0.0\|>=10.0.0,<11.0.0\|>=11.0.0,<12.0.0\|>=12.0.0,<12.60.0\|>=13.0.0,<13.10.0 | [source](https://github.com/laravel/framework/security/advisories/GHSA-5vg9-5847-vvmq) |
| league/commonmark | 2.10.7 | PKSA-m2dq-1fhr-29b1 | >=1.3.0,<=2.10.1 | [source](https://github.com/advisories/GHSA-97jj-33gv-5xf9) |
| league/commonmark | 2.10.7 | PKSA-m4t9-vsgq-8khn | >=2.0.0,<=2.10.1 | [source](https://github.com/advisories/GHSA-3q6v-r5mr-hxv8) |
| league/commonmark | 2.10.7 | PKSA-zyf5-hrxv-hrd7 | >=1.5.0,<2.10.0 | [source](https://github.com/advisories/GHSA-8rr7-cvq3-gmfh) |
| league/commonmark | 2.10.7 | PKSA-nv44-1b4d-6gjg | >=1.5.0,<2.9.1 | [source](https://github.com/advisories/GHSA-jjv6-8j6v-6j52) |
| league/commonmark | 2.10.7 | PKSA-kr3s-894t-g5w2 | >=2.7.0,<2.9.1 | [source](https://github.com/advisories/GHSA-f8fg-pg57-v4j8) |
| league/commonmark | 2.10.7 | PKSA-9q1p-3s19-bp1q | >=0.6.0,<2.9.1 | [source](https://github.com/advisories/GHSA-j8pm-gj4c-rq4x) |
| league/commonmark | 2.10.7 | PKSA-5mzr-szzf-z6cn | >=2.0.0,<2.9.0 | [source](https://github.com/advisories/GHSA-mj63-m3rc-8ppr) |
| league/commonmark | 2.10.7 | PKSA-cqd6-fg4n-nxpf | >=2.0.0,<2.9.0 | [source](https://github.com/advisories/GHSA-mh25-x5hq-wrqp) |
| league/commonmark | 2.10.7 | PKSA-1q6p-sqkj-8mmj | >=1.5.0,<2.9.0 | [source](https://github.com/advisories/GHSA-jfm3-95jq-q3rf) |
| league/commonmark | 2.10.7 | PKSA-mc58-w91n-f5gv | >=1.5.0,<2.9.0 | [source](https://github.com/advisories/GHSA-g2gp-3wwq-f4ph) |
| league/commonmark | 2.10.7 | CVE-2026-71488 | >=0.6.0,<2.9.0 | [source](https://github.com/advisories/GHSA-2q4p-g7hv-5rgv) |
| league/commonmark | 2.10.7 | CVE-2026-71478 | >=1.5.0,<=2.8.3 | [source](https://github.com/advisories/GHSA-29pj-957v-52mc) |
| league/flysystem | 3.36.0 | CVE-2026-102601 | <=3.35.2 | [source](https://github.com/advisories/GHSA-cxf4-7mrp-vvpr) |
| symfony/http-foundation | v7.4.23 | CVE-2026-118514 | >=2.0.0,<3.0.0\|>=3.0.0,<4.0.0\|>=4.0.0,<5.0.0\|>=5.0.0,<5.1.0\|>=5.1.0,<5.2.0\|>=5.2.0,<5.3.0\|>=5.3.0,<5.4.0\|>=5.4.0,<5.4.56\|>=6.0.0,<6.1.0\|>=6.1.0,<6.2.0\|>=6.2.0,<6.3.0\|>=6.3.0,<6.4.0\|>=6.4.0,<6.4.50\|>=7.0.0,<7.1.0\|>=7.1.0,<7.2.0\|>=7.2.0,<7.3.0\|>=7.3.0,<7.4.0\|>=7.4.0,<7.4.23\|>=8.0.0,<8.1.0\|>=8.1.0,<8.1.11 | [source](https://symfony.com/cve-2026-118514) |
| symfony/http-foundation | v7.4.23 | PKSA-pr6q-2w5s-kzzf | >=5.4.50,<5.4.56\|>=6.4.29,<6.4.50\|>=7.3.7,<7.4.0\|>=7.4.0,<7.4.23\|>=8.0.0,<8.1.0\|>=8.1.0,<8.1.11 | [source](https://github.com/symfony/symfony/security/advisories/GHSA-3h27-hjv7-fqjx) |
| symfony/http-foundation | v7.4.23 | PKSA-nxjs-s6hn-vb6m | >=7.2.0,<7.3.0\|>=7.3.0,<7.4.0\|>=7.4.0,<7.4.23\|>=8.0.0,<8.1.0\|>=8.1.0,<8.1.11 | [source](https://github.com/symfony/symfony/security/advisories/GHSA-xg2q-4rrq-657q) |
| symfony/http-foundation | v7.4.23 | PKSA-b2gg-vk1g-6d5q | >=2.0.0,<2.1.0\|>=2.1.0,<2.2.0\|>=2.2.0,<2.3.0\|>=2.3.0,<2.4.0\|>=2.4.0,<2.5.0\|>=2.5.0,<2.6.0\|>=2.6.0,<2.7.0\|>=2.7.0,<2.8.0\|>=2.8.0,<3.0.0\|>=3.0.0,<3.1.0\|>=3.1.0,<3.2.0\|>=3.2.0,<3.3.0\|>=3.3.0,<3.4.0\|>=3.4.0,<4.0.0\|>=4.0.0,<4.1.0\|>=4.1.0,<4.2.0\|>=4.2.0,<4.3.0\|>=4.3.0,<4.4.0\|>=4.4.0,<5.0.0\|>=5.0.0,<5.1.0\|>=5.1.0,<5.2.0\|>=5.2.0,<5.3.0\|>=5.3.0,<5.4.0\|>=5.4.0,<5.4.56\|>=6.0.0,<6.1.0\|>=6.1.0,<6.2.0\|>=6.2.0,<6.3.0\|>=6.3.0,<6.4.0\|>=6.4.0,<6.4.50\|>=7.0.0,<7.1.0\|>=7.1.0,<7.2.0\|>=7.2.0,<7.3.0\|>=7.3.0,<7.4.0\|>=7.4.0,<7.4.23\|>=8.0.0,<8.1.0\|>=8.1.0,<8.1.11 | [source](https://github.com/symfony/symfony/security/advisories/GHSA-5mx8-mwrp-5693) |
| symfony/http-foundation | v7.4.23 | CVE-2026-108082 | >=5.4.1,<5.4.54\|>=6.0.1,<6.1.0\|>=6.1.0,<6.2.0\|>=6.2.0,<6.3.0\|>=6.3.0,<6.4.0\|>=6.4.0,<6.4.46\|>=7.0.0,<7.1.0\|>=7.1.0,<7.2.0\|>=7.2.0,<7.3.0\|>=7.3.0,<7.4.0\|>=7.4.0,<7.4.19\|>=8.0.0,<8.1.0\|>=8.1.0,<8.1.7 | [source](https://symfony.com/cve-2026-108082) |
| symfony/http-foundation | v7.4.23 | CVE-2026-48736 | >=6.4.0,<6.4.41\|>=7.0.0,<7.1.0\|>=7.1.0,<7.2.0\|>=7.2.0,<7.3.0\|>=7.3.0,<7.4.0\|>=7.4.0,<7.4.13\|>=8.0.0,<8.0.13 | [source](https://symfony.com/cve-2026-48736) |
| symfony/http-kernel | v7.4.23 | CVE-2026-123339 | >=2.8.44,<3.0.0\|>=3.4.14,<4.0.0\|>=4.0.14,<5.0.0\|>=5.0.0,<5.1.0\|>=5.1.0,<5.2.0\|>=5.2.0,<5.3.0\|>=5.3.0,<5.4.0\|>=5.4.0,<5.4.56\|>=6.0.0,<6.1.0\|>=6.1.0,<6.2.0\|>=6.2.0,<6.3.0\|>=6.3.0,<6.4.0\|>=6.4.0,<6.4.50\|>=7.0.0,<7.1.0\|>=7.1.0,<7.2.0\|>=7.2.0,<7.3.0\|>=7.3.0,<7.4.0\|>=7.4.0,<7.4.23\|>=8.0.0,<8.1.0\|>=8.1.0,<8.1.11 | [source](https://github.com/symfony/symfony/security/advisories/GHSA-wg5q-m2ww-f3c9) |
| symfony/http-kernel | v7.4.23 | CVE-2026-45075 | >=7.4.0,<7.4.12\|>=8.0.0,<8.0.12 | [source](https://symfony.com/cve-2026-45075) |
| symfony/mailer | v8.0.15 | CVE-2026-45068 | >=2.0.0,<3.0.0\|>=3.0.0,<4.0.0\|>=4.0.0,<5.0.0\|>=5.0.0,<5.1.0\|>=5.1.0,<5.2.0\|>=5.2.0,<5.3.0\|>=5.3.0,<5.4.0\|>=5.4.0,<5.4.52\|>=6.0.0,<6.1.0\|>=6.1.0,<6.2.0\|>=6.2.0,<6.3.0\|>=6.3.0,<6.4.0\|>=6.4.0,<6.4.40\|>=7.0.0,<7.1.0\|>=7.1.0,<7.2.0\|>=7.2.0,<7.3.0\|>=7.3.0,<7.4.0\|>=7.4.0,<7.4.12\|>=8.0.0,<8.0.12 | [source](https://symfony.com/cve-2026-45068) |
| symfony/mime | v7.4.22 | CVE-2026-45070 | >=2.0.0,<3.0.0\|>=3.0.0,<4.0.0\|>=4.0.0,<5.0.0\|>=5.0.0,<5.1.0\|>=5.1.0,<5.2.0\|>=5.2.0,<5.3.0\|>=5.3.0,<5.4.0\|>=5.4.0,<5.4.55\|>=6.0.0,<6.1.0\|>=6.1.0,<6.2.0\|>=6.2.0,<6.3.0\|>=6.3.0,<6.4.0\|>=6.4.0,<6.4.49\|>=7.0.0,<7.1.0\|>=7.1.0,<7.2.0\|>=7.2.0,<7.3.0\|>=7.3.0,<7.4.0\|>=7.4.0,<7.4.22\|>=8.0.0,<8.1.0\|>=8.1.0,<8.1.10 | [source](https://symfony.com/cve-2026-45070) |
| symfony/mime | v7.4.22 | CVE-2026-45067 | >=2.0.0,<3.0.0\|>=3.0.0,<4.0.0\|>=4.0.0,<5.0.0\|>=5.0.0,<5.1.0\|>=5.1.0,<5.2.0\|>=5.2.0,<5.3.0\|>=5.3.0,<5.4.0\|>=5.4.0,<5.4.52\|>=6.0.0,<6.1.0\|>=6.1.0,<6.2.0\|>=6.2.0,<6.3.0\|>=6.3.0,<6.4.0\|>=6.4.0,<6.4.40\|>=7.0.0,<7.1.0\|>=7.1.0,<7.2.0\|>=7.2.0,<7.3.0\|>=7.3.0,<7.4.0\|>=7.4.0,<7.4.12\|>=8.0.0,<8.0.12 | [source](https://symfony.com/cve-2026-45067) |
| symfony/polyfill-intl-idn | v1.43.0 | CVE-2026-46644 | >=1.17.1,<1.38.1 | [source](https://symfony.com/cve-2026-46644) |
| symfony/routing | v7.4.23 | CVE-2026-118516 | >=4.1.0,<4.2.0\|>=4.2.0,<4.3.0\|>=4.3.0,<4.4.0\|>=4.4.0,<5.0.0\|>=5.0.0,<5.1.0\|>=5.1.0,<5.2.0\|>=5.2.0,<5.3.0\|>=5.3.0,<5.4.0\|>=5.4.0,<5.4.56\|>=6.0.0,<6.1.0\|>=6.1.0,<6.2.0\|>=6.2.0,<6.3.0\|>=6.3.0,<6.4.0\|>=6.4.0,<6.4.50\|>=7.0.0,<7.1.0\|>=7.1.0,<7.2.0\|>=7.2.0,<7.3.0\|>=7.3.0,<7.4.0\|>=7.4.0,<7.4.23\|>=8.0.0,<8.1.0\|>=8.1.0,<8.1.11 | [source](https://symfony.com/cve-2026-118516) |
| symfony/routing | v7.4.23 | CVE-2026-48784 | >=2.0.0,<3.0.0\|>=3.0.0,<4.0.0\|>=4.0.0,<5.0.0\|>=5.0.0,<5.1.0\|>=5.1.0,<5.2.0\|>=5.2.0,<5.3.0\|>=5.3.0,<5.4.0\|>=5.4.0,<5.4.53\|>=6.0.0,<6.1.0\|>=6.1.0,<6.2.0\|>=6.2.0,<6.3.0\|>=6.3.0,<6.4.0\|>=6.4.0,<6.4.41\|>=7.0.0,<7.1.0\|>=7.1.0,<7.2.0\|>=7.2.0,<7.3.0\|>=7.3.0,<7.4.0\|>=7.4.0,<7.4.13\|>=8.0.0,<8.0.13 | [source](https://symfony.com/cve-2026-48784) |
| symfony/routing | v7.4.23 | CVE-2026-45065 | >=2.0.0,<3.0.0\|>=3.0.0,<4.0.0\|>=4.0.0,<5.0.0\|>=5.0.0,<5.1.0\|>=5.1.0,<5.2.0\|>=5.2.0,<5.3.0\|>=5.3.0,<5.4.0\|>=5.4.0,<5.4.52\|>=6.0.0,<6.1.0\|>=6.1.0,<6.2.0\|>=6.2.0,<6.3.0\|>=6.3.0,<6.4.0\|>=6.4.0,<6.4.40\|>=7.0.0,<7.1.0\|>=7.1.0,<7.2.0\|>=7.2.0,<7.3.0\|>=7.3.0,<7.4.0\|>=7.4.0,<7.4.12\|>=8.0.0,<8.0.12 | [source](https://symfony.com/cve-2026-45065) |
