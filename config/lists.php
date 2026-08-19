<?php

declare(strict_types=1);

/**
 * Single source of truth for named config sets. Each list maps to file paths or globs
 * relative to config/. Consumed by scripts/lib/cidr4.php::resolveList() and, through it,
 * by both route generators via --list=<name>.
 */
return [
    'keenetic' => [
        'ai/aistudio.google.com.json',
        'ai/chatgpt.com.json',
        'ai/claude.ai.json',
        'ai/grok.com.json',
        'ai/perplexity.ai.json',
        'custom/*.json',
        'discord/*.json',
        'games/roblox.com.json',
        'hosting/bitninja.com.json',
        'hosting/hetzner.com.json',
        'hosting/namecheap.com.json',
        'hosting/cloudlinux.com.json',
        'jetbrains/*.json',
        'messengers/messenger.com.json',
        // 'messengers/telegram.org.json', // use official
        'messengers/whatsapp.com.json', // yandex VPN?
        'music/spotify.com.json',
        'socials/facebook.com.json',
        'socials/instagram.com.json',
        'socials/linkedin.com.json',
        'socials/x.com.json',
        'tools/medium.com.json',
        'torrent/rutracker.org.json',
        'video/kino.pub.json',
    ],
    // Kept strictly disjoint from 'keenetic': the YouTube config carries broad
    // non-Google blocks (Cloudflare 104.16.0.0/12 and 172.64.0.0/13, Azure
    // 52.160.0.0/11, plus GGC prefixes aggregated up to /7) that otherwise land in
    // both .bat files and hijack traffic owned by the main list — ChatGPT resolves
    // entirely into those two Cloudflare blocks.
    'youtube' => [
        'files' => [
            'youtube/youtube.com.json',
        ],
        'subtract' => ['keenetic'],
    ],
    // Seeded as a copy of 'keenetic'; curated/edited going forward.
    'awg' => [
        'ai/aistudio.google.com.json',
        'ai/chatgpt.com.json',
        'ai/claude.ai.json',
        'ai/grok.com.json',
        'ai/perplexity.ai.json',
        'custom/*.json',
        'discord/*.json',
        'messengers/messenger.com.json',
        // 'messengers/telegram.org.json', // use official
        'messengers/whatsapp.com.json', // yandex VPN?
        'music/spotify.com.json',
        'socials/facebook.com.json',
        'socials/instagram.com.json',
        'socials/linkedin.com.json',
        'socials/x.com.json',
        'tools/medium.com.json',
    ],
];
