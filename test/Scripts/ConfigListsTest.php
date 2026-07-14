<?php

declare(strict_types=1);

namespace OpenCCK\Scripts;

use PHPUnit\Framework\TestCase;

/**
 * Pins config/lists.php so the keenetic set stays a verbatim copy of the old
 * defaultConfigPatterns(). This is the real safety net for the "byte-identical
 * keenetic output" requirement (KeeneticRegressionTest guards the math, not this list).
 */
final class ConfigListsTest extends TestCase {
    private const KEENETIC = [
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
        'messengers/whatsapp.com.json',
        'music/spotify.com.json',
        'socials/facebook.com.json',
        'socials/instagram.com.json',
        'socials/linkedin.com.json',
        'socials/x.com.json',
        'tools/medium.com.json',
        'torrent/rutracker.org.json',
        'video/kino.pub.json',
    ];

    private function lists(): array {
        return require __DIR__ . '/../../config/lists.php';
    }

    public function testKeeneticIsVerbatimCuratedSet(): void {
        self::assertSame(self::KEENETIC, $this->lists()['keenetic']);
    }

    public function testAwgSeededAsCopyOfKeenetic(): void {
        $lists = $this->lists();
        self::assertSame($lists['keenetic'], $lists['awg']);
    }

    public function testYoutubeList(): void {
        self::assertSame(['youtube/youtube.com.json'], $this->lists()['youtube']);
    }
}
