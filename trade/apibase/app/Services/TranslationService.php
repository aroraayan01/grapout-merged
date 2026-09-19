<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Translation;
use Illuminate\Support\Facades\Http;

/**
 * The words in the reader's language.
 *
 * A buyer in Hamburg reads a page written in Moradabad; an enquiry comes
 * back in German. Whatever the platform has been given — a Google or DeepL
 * key, a LibreTranslate server, or the Claude key the voice assistant
 * already has — the app asks it the same way, and remembers every answer
 * so a sentence is paid for once.
 *
 * With nothing configured, nothing is offered: the UI hides the button.
 */
class TranslationService
{
    public const PROVIDERS = ['none', 'google', 'deepl', 'libre', 'claude'];

    /** Languages the UI offers. Codes are what every provider understands. */
    public const LANGUAGES = [
        'en' => 'English', 'hi' => 'हिन्दी', 'ar' => 'العربية', 'de' => 'Deutsch', 'es' => 'Español', 'fr' => 'Français',
        'pt' => 'Português', 'it' => 'Italiano', 'tr' => 'Türkçe', 'ru' => 'Русский', 'zh' => '中文', 'ja' => '日本語',
        'ko' => '한국어', 'id' => 'Bahasa Indonesia', 'vi' => 'Tiếng Việt', 'th' => 'ไทย', 'nl' => 'Nederlands', 'pl' => 'Polski',
    ];

    public function provider(): string
    {
        $p = AppSetting::get('translate_provider');
        if (! in_array($p, self::PROVIDERS, true) || $p === 'none') {
            return 'none';
        }
        if ($p === 'libre' && AppSetting::get('translate_url') === '') {
            return 'none';
        }
        if ($p === 'claude') {
            return AppSetting::get('voice_ai_key') !== '' ? 'claude' : 'none';
        }

        return AppSetting::get('translate_key') !== '' ? $p : 'none';
    }

    public function available(): bool
    {
        return $this->provider() !== 'none';
    }

    /**
     * @param  list<string>  $texts
     * @return list<string>  the same length, in the same order
     */
    public function translate(array $texts, string $target, ?string $source = null): array
    {
        $provider = $this->provider();
        abort_if($provider === 'none', 503, 'Translation is not switched on for this GrapOut.');
        $target = strtolower(substr($target, 0, 5));

        $out = [];
        $missing = [];
        foreach ($texts as $i => $text) {
            $text = (string) $text;
            if (trim($text) === '') {
                $out[$i] = $text;
                continue;
            }
            $hash = sha1($provider . '|' . $target . '|' . $text);
            $cached = Translation::where('hash', $hash)->value('translated');
            if ($cached !== null) {
                $out[$i] = $cached;
            } else {
                $missing[$i] = ['hash' => $hash, 'text' => $text];
            }
        }

        if ($missing !== []) {
            $translated = $this->ask($provider, array_values(array_column($missing, 'text')), $target, $source);
            foreach (array_keys($missing) as $n => $i) {
                $out[$i] = $translated[$n] ?? $missing[$i]['text'];
                Translation::firstOrCreate(['hash' => $missing[$i]['hash']], [
                    'provider' => $provider, 'target' => $target, 'source' => $source, 'text' => $missing[$i]['text'], 'translated' => $out[$i],
                ]);
            }
        }

        ksort($out);

        return array_values($out);
    }

    /** @return list<string> */
    private function ask(string $provider, array $texts, string $target, ?string $source): array
    {
        return match ($provider) {
            'google' => $this->google($texts, $target, $source),
            'deepl' => $this->deepl($texts, $target, $source),
            'libre' => $this->libre($texts, $target, $source),
            'claude' => $this->claude($texts, $target),
        };
    }

    private function google(array $texts, string $target, ?string $source): array
    {
        $r = Http::timeout(15)->asForm()->post('https://translation.googleapis.com/language/translate/v2', array_filter([
            'key' => AppSetting::get('translate_key'), 'q' => $texts, 'target' => $target, 'source' => $source, 'format' => 'text',
        ]));
        abort_unless($r->successful(), 502, 'The translation service did not answer.');

        return array_map(fn ($t) => html_entity_decode((string) ($t['translatedText'] ?? '')), $r->json('data.translations') ?? []);
    }

    private function deepl(array $texts, string $target, ?string $source): array
    {
        $key = AppSetting::get('translate_key');
        $host = str_ends_with($key, ':fx') ? 'https://api-free.deepl.com' : 'https://api.deepl.com';
        $r = Http::timeout(15)->withHeaders(['Authorization' => 'DeepL-Auth-Key ' . $key])
            ->post($host . '/v2/translate', array_filter(['text' => $texts, 'target_lang' => strtoupper($target), 'source_lang' => $source ? strtoupper($source) : null]));
        abort_unless($r->successful(), 502, 'The translation service did not answer.');

        return array_map(fn ($t) => (string) ($t['text'] ?? ''), $r->json('translations') ?? []);
    }

    private function libre(array $texts, string $target, ?string $source): array
    {
        $url = rtrim(AppSetting::get('translate_url'), '/') . '/translate';
        $r = Http::timeout(20)->post($url, array_filter([
            'q' => $texts, 'source' => $source ?: 'auto', 'target' => $target, 'format' => 'text', 'api_key' => AppSetting::get('translate_key') ?: null,
        ]));
        abort_unless($r->successful(), 502, 'The translation service did not answer.');
        $t = $r->json('translatedText');

        return is_array($t) ? array_map('strval', $t) : [(string) $t];
    }

    /**
     * The key and model the voice assistant already uses. One request for
     * the batch: numbered lines in, numbered lines out.
     */
    private function claude(array $texts, string $target): array
    {
        $language = self::LANGUAGES[$target] ?? $target;
        $numbered = implode("\n", array_map(fn ($i, $t) => ($i + 1) . '. ' . str_replace("\n", ' ⏎ ', $t), array_keys($texts), $texts));
        $r = Http::timeout(30)->withHeaders([
            'x-api-key' => AppSetting::get('voice_ai_key'),
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->post('https://api.anthropic.com/v1/messages', [
            'model' => AppSetting::get('voice_ai_model'),
            'max_tokens' => 4000,
            'system' => "You translate trade and business text into {$language}. Reply with ONLY the translated lines, numbered exactly as given, one per line, keeping ' ⏎ ' markers where they appear. Keep product names, currencies, incoterms (FOB, CIF…), numbers and units as they are.",
            'messages' => [['role' => 'user', 'content' => $numbered]],
        ]);
        abort_unless($r->successful(), 502, 'The translation service did not answer.');
        $reply = collect($r->json('content') ?? [])->pluck('text')->implode("\n");

        $out = array_fill(0, count($texts), '');
        foreach (preg_split('/\r?\n/', $reply) as $line) {
            if (preg_match('/^\s*(\d+)\.\s*(.*)$/u', $line, $m) && isset($out[(int) $m[1] - 1])) {
                $out[(int) $m[1] - 1] = str_replace(' ⏎ ', "\n", trim($m[2]));
            }
        }
        foreach ($out as $i => $t) {
            if ($t === '') {
                $out[$i] = $texts[$i];
            }
        }

        return $out;
    }
}
