<?php
namespace App\Support;

final class ApprovedClientTexts
{
    public static function text(string $key, string $locale): string
    {
        $entry = config('client-texts.texts.'.$key);
        if (!in_array($locale, ['es', 'en'], true) || ($entry['status'] ?? null) !== 'approved'
            || !is_string($entry[$locale] ?? null)) throw new \LogicException('Approved client text unavailable.');
        return $entry[$locale];
    }

    /** Pure config compilation: no application/config reads while waoo.php loads. */
    public static function mailDefinitions(array $source): array
    {
        $definitions = [];
        foreach ($source['texts'] as $key => $entry) {
            if (!str_starts_with($key, 'mail_') || $entry['status'] !== 'approved') continue;
            foreach (['es', 'en'] as $locale) {
                $blocks = explode("\n\n", $entry[$locale]);
                $subject = preg_replace('/^(Asunto|Subject): /', '', array_shift($blocks));
                $closing = array_pop($blocks);
                $paragraphs = []; $buttons = [];
                foreach ($blocks as $block) {
                    if (preg_match('/^\[(?:Botón|Button): (.+)\]$/u', $block, $m)) {
                        foreach (preg_split('/\s+·\s+/u', $m[1]) as $label) {
                            $buttons[] = ['label' => $label, 'variable' => $key === 'mail_thank_you_review'
                                ? ($buttons === [] ? 'review_link' : 'tip_link') : 'link'];
                        }
                    } elseif (str_starts_with($block, '[Aquí va la tabla') || str_starts_with($block, '[Table with')) {
                        $paragraphs[] = '__totals__';
                    } else $paragraphs[] = $block;
                }
                preg_match_all('/\{(\w+)\}/', $subject."\n".implode("\n", $paragraphs)."\n".$closing, $matches);
                $required = array_values(array_diff(array_unique([...$matches[1], ...array_column($buttons, 'variable')]), ['balance_due']));
                $definitions[substr($key, 5)][$locale] = ['version' => $source['version'],
                    'approval_reference' => 'owner-approved-2026-10-20', 'subject' => $subject,
                    'paragraphs' => $paragraphs, 'button' => $buttons[0] ?? null, 'button2' => $buttons[1] ?? null,
                    'closing' => $closing, 'required_variables' => $required];
            }
        }
        return $definitions;
    }

    /** Only the approved travel-fee inline link is markup. Everything else is escaped. */
    public static function paragraphHtml(string $text): string
    {
        return preg_replace_callback('~\[(costo de transporte|travel fee)\]\((https://waooparty\.com/(?:es/)?travel-fee\?zip=[0-9]{5}(?:-[0-9]{4})?)\)~u',
            fn ($m) => '<a href="'.$m[2].'" style="color:#1F2A6B">'.$m[1].'</a>', e($text));
    }
}
