<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mailpit;

use App\Mailpit\InvalidPayloadException;
use App\Mailpit\Part;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PartTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function valid(): array
    {
        return [
            'PartID'      => '1.2',
            'Kind'        => 'inline',
            'FileName'    => 'logo.png',
            'ContentType' => 'image/png',
            'ContentID'   => 'logo123',
            'Size'        => 2048,
        ];
    }

    public function testValidPart(): void
    {
        $part = Part::fromArray(self::valid(), 0);

        self::assertSame('1.2', $part->partId);
        self::assertSame(Part::KIND_INLINE, $part->kind);
        self::assertSame('logo.png', $part->fileName);
        self::assertSame('image/png', $part->contentType);
        self::assertSame('logo123', $part->contentId);
        self::assertSame(2048, $part->size);
    }

    public function testOptionalTextFieldsDefaultToEmpty(): void
    {
        $part = Part::fromArray(['PartID' => '2', 'Kind' => 'attachment', 'Size' => 0], 3);

        self::assertSame(Part::KIND_ATTACHMENT, $part->kind);
        self::assertSame('', $part->fileName);
        self::assertSame('', $part->contentType);
        self::assertSame('', $part->contentId);
        self::assertSame(0, $part->size);
    }

    public function testLongTextFieldsAreCutTo255Characters(): void
    {
        $part = Part::fromArray(['FileName' => str_repeat('é', 300) . '.pdf'] + self::valid(), 0);

        self::assertSame(str_repeat('é', 255), $part->fileName);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function invalidParts(): iterable
    {
        $valid = self::valid();

        yield 'pas un objet (texte)'      => ['2', 'champ « Parts[4] » : objet attendu'];
        yield 'pas un objet (liste)'      => [['2', 'inline'], 'champ « Parts[4] » : objet attendu'];
        yield 'PartID absent'             => [array_diff_key($valid, ['PartID' => 0]), '« PartID » manquant ou invalide'];
        yield 'PartID numérique'          => [['PartID' => 2] + $valid, '« PartID » manquant ou invalide'];
        yield 'PartID avec « .. »'        => [['PartID' => '../etc'] + $valid, '« PartID » manquant ou invalide'];
        yield 'PartID avec « / »'         => [['PartID' => '1/2'] + $valid, '« PartID » manquant ou invalide'];
        yield 'PartID trop profond'       => [['PartID' => implode('.', range(1, 11))] + $valid, '« PartID » manquant ou invalide'];
        yield 'Kind inconnu'              => [['Kind' => 'body'] + $valid, '« Kind » doit valoir attachment ou inline'];
        yield 'Kind absent'               => [array_diff_key($valid, ['Kind' => 0]), '« Kind » doit valoir attachment ou inline'];
        yield 'Size absente'              => [array_diff_key($valid, ['Size' => 0]), '« Size » doit être un entier positif'];
        yield 'Size négative'             => [['Size' => -1] + $valid, '« Size » doit être un entier positif'];
        yield 'Size en texte'             => [['Size' => '12'] + $valid, '« Size » doit être un entier positif'];
        yield 'Size décimale'             => [['Size' => 1.5] + $valid, '« Size » doit être un entier positif'];
        yield 'FileName pas un texte'     => [['FileName' => 12] + $valid, '« FileName » doit être un texte'];
        yield 'ContentType pas un texte'  => [['ContentType' => ['image/png']] + $valid, '« ContentType » doit être un texte'];
        yield 'ContentID pas un texte'    => [['ContentID' => false] + $valid, '« ContentID » doit être un texte'];
    }

    #[DataProvider('invalidParts')]
    public function testInvalidPart(mixed $data, string $message): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage($message);

        Part::fromArray($data, 4);
    }
}
