<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler\Fixture;

use Lmc\Cqrs\Types\Decoder\ImpureResponseDecoderInterface;

/** @phpstan-implements ImpureResponseDecoderInterface<string, string> */
class ImpureTranslationDecoder implements ImpureResponseDecoderInterface
{
    private string $language;

    public function __construct(string $language)
    {
        $this->language = $language;
    }

    public function changeLanguage(string $language): void
    {
        $this->language = $language;
    }

    public function supports($response, $initiator): bool
    {
        return is_string($response);
    }

    public function decode($response)
    {
        return sprintf('translated[%s]: %s', $this->language, $response);
    }
}
