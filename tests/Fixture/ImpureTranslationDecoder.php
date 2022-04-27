<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler\Fixture;

use Lmc\Cqrs\Types\Decoder\ImpureResponseDecoderInterface;

/** @phpstan-implements ImpureResponseDecoderInterface<string, string> */
class ImpureTranslationDecoder implements ImpureResponseDecoderInterface
{
    public function __construct(private string $language)
    {
    }

    public function changeLanguage(string $language): void
    {
        $this->language = $language;
    }

    public function supports(mixed $response, mixed $initiator): bool
    {
        return is_string($response);
    }

    public function decode(mixed $response): mixed
    {
        return sprintf('translated[%s]: %s', $this->language, $response);
    }
}
