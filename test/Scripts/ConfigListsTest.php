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

    private const AWG = [
        'ai/aistudio.google.com.json',
        'ai/chatgpt.com.json',
        'ai/claude.ai.json',
        'ai/grok.com.json',
        'ai/perplexity.ai.json',
        'custom/*.json',
        'discord/*.json',
        'messengers/messenger.com.json',
        'messengers/whatsapp.com.json',
        'music/spotify.com.json',
        'socials/facebook.com.json',
        'socials/instagram.com.json',
        'socials/linkedin.com.json',
        'socials/x.com.json',
        'tools/medium.com.json',
    ];

    private function lists(): array {
        return require __DIR__ . '/../../config/lists.php';
    }

    public function testKeeneticIsVerbatimCuratedSet(): void {
        self::assertSame(self::KEENETIC, $this->lists()['keenetic']);
    }

    /**
     * awg started as a copy of keenetic and was curated down in abd44e3e (router-only
     * entries dropped: games, hosting, jetbrains, torrent, video). What still has to
     * hold is containment — awg must never route something keenetic does not.
     */
    public function testAwgIsCuratedSubsetOfKeenetic(): void {
        self::assertSame(self::AWG, $this->lists()['awg']);
        self::assertSame([], array_diff(self::AWG, self::KEENETIC));
    }

    public function testYoutubeListDeclaresFilesAndSubtractsKeenetic(): void {
        self::assertSame(
            ['files' => ['youtube/youtube.com.json'], 'subtract' => ['keenetic']],
            $this->lists()['youtube']
        );
    }
}
