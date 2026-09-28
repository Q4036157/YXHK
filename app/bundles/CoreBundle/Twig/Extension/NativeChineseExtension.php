<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Twig\Extension;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class NativeChineseExtension extends AbstractExtension
{
    private array $labels;

    public function __construct(private readonly TranslatorInterface $translator)
    {
        $this->labels = json_decode(file_get_contents(dirname(__DIR__, 5).'/deploy/windows/localization/display-labels.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('yxhk_chinese', $this->label(...)),
            new TwigFilter('yxhk_chinese_diagnostics', $this->diagnostics(...)),
        ];
    }

    public function label(?string $text): string
    {
        $text ??= '';
        if (!str_starts_with($this->translator->getLocale(), 'zh')) {
            return $text;
        }

        return $this->labels[trim($text)] ?? $text;
    }

    public function diagnostics(string $html): string
    {
        if (!str_starts_with($this->translator->getLocale(), 'zh')) {
            return $html;
        }

        // Only display text changes; paths, numeric values, identifiers and markup remain intact.
        return preg_replace_callback('/>([^<>]+)</', function (array $match): string {
            $plain = html_entity_decode(trim($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $translated = $this->label($plain);
            if ($translated !== $plain) {
                return '>'.htmlspecialchars($translated, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'<';
            }
            if (preg_match('/^PHP Version (.+)$/', $plain, $version)) {
                return '>PHP 版本 '.htmlspecialchars($version[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'<';
            }

            return $match[0];
        }, $html);
    }
}
