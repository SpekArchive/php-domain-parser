<?php

/**
 * PHP Domain Parser: Public Suffix List based URL parsing.
 *
 * @see http://github.com/jeremykendall/php-domain-parser for the canonical source repository
 *
 * @copyright Copyright (c) 2017 Jeremy Kendall (http://jeremykendall.net)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Pdp;

use JsonSerializable;
use function array_reverse;
use function count;
use function implode;
use function in_array;
use function reset;
use function substr;
use const IDNA_DEFAULT;

final class PublicSuffix extends HostParser implements JsonSerializable, PublicSuffixInterface
{
    private const PSL_SECTION = [PublicSuffixListInterface::PRIVATE_DOMAINS, PublicSuffixListInterface::ICANN_DOMAINS, ''];

    private ?string $publicSuffix;

    private string $section;

    private array $labels;

    private int $asciiIDNAOption;

    private int $unicodeIDNAOption;

    private bool $isTransitionalDifferent;

    /**
     * @param mixed|null $publicSuffix a public suffix in a type that is supported
     */
    private function __construct(
        $publicSuffix = null,
        string $section = '',
        int $asciiIDNAOption = IDNA_DEFAULT,
        int $unicodeIDNAOption = IDNA_DEFAULT
    ) {
        $infos = $this->parse($publicSuffix, $asciiIDNAOption, $unicodeIDNAOption);
        $this->labels = $infos['labels'];
        $this->isTransitionalDifferent = $infos['isTransitionalDifferent'];
        $this->publicSuffix = $this->setPublicSuffix();
        $this->section = $this->setSection($section);
        $this->asciiIDNAOption = $asciiIDNAOption;
        $this->unicodeIDNAOption = $unicodeIDNAOption;
    }

    public static function __set_state(array $properties): self
    {
        return new self(
            $properties['publicSuffix'],
            $properties['section'],
            $properties['asciiIDNAOption'],
            $properties['unicodeIDNAOption']
        );
    }

    /**
     * @param mixed $publicSuffix a public suffix
     */
    public static function fromICANNSection($publicSuffix = null, int $asciiIDNAOption = IDNA_DEFAULT, int $unicodeIDNAOption = IDNA_DEFAULT): self
    {
        return new self($publicSuffix, PublicSuffixListInterface::ICANN_DOMAINS, $asciiIDNAOption, $unicodeIDNAOption);
    }

    /**
     * @param mixed $publicSuffix a public suffix
     */
    public static function fromPrivateSection($publicSuffix = null, int $asciiIDNAOption = IDNA_DEFAULT, int $unicodeIDNAOption = IDNA_DEFAULT): self
    {
        return new self($publicSuffix, PublicSuffixListInterface::PRIVATE_DOMAINS, $asciiIDNAOption, $unicodeIDNAOption);
    }

    /**
     * @param mixed $publicSuffix a public suffix
     */
    public static function fromUnknownSection($publicSuffix = null, int $asciiIDNAOption = IDNA_DEFAULT, int $unicodeIDNAOption = IDNA_DEFAULT): self
    {
        return new self($publicSuffix, '', $asciiIDNAOption, $unicodeIDNAOption);
    }

    public static function fromNull(int $asciiIDNAOption = IDNA_DEFAULT, int $unicodeIDNAOption = IDNA_DEFAULT): self
    {
        return new self(null, '', $asciiIDNAOption, $unicodeIDNAOption);
    }

    public function count(): int
    {
        return count($this->labels);
    }

    /**
     * Set the public suffix content.
     *
     * @throws InvalidDomain if the public suffix is invalid
     */
    private function setPublicSuffix(): ?string
    {
        if ([] === $this->labels) {
            return null;
        }

        $publicSuffix = implode('.', array_reverse($this->labels));
        if ('' !== reset($this->labels)) {
            return $publicSuffix;
        }

        throw InvalidDomain::dueToInvalidPublicSuffix($publicSuffix);
    }

    /**
     * Set the public suffix section.
     *
     * @throws UnableToResolveDomain if the submitted section is not supported
     */
    private function setSection(string $section): string
    {
        if (!in_array($section, self::PSL_SECTION, true)) {
            throw new UnableToResolveDomain('"'.$section.'" is an unknown Public Suffix List section');
        }

        if (null === $this->publicSuffix) {
            return '';
        }

        return $section;
    }

    public function jsonSerialize(): ?string
    {
        return $this->getContent();
    }

    public function getContent(): ?string
    {
        return $this->publicSuffix;
    }

    public function __toString(): string
    {
        return (string) $this->publicSuffix;
    }

    public function isResolvable(): bool
    {
        return null !== $this->publicSuffix
            && '.' !== substr($this->publicSuffix, -1, 1)
            && 1 < count($this->labels)
            ;
    }

    public function getAsciiIDNAOption(): int
    {
        return $this->asciiIDNAOption;
    }

    public function getUnicodeIDNAOption(): int
    {
        return $this->unicodeIDNAOption;
    }

    public function isTransitionalDifferent(): bool
    {
        return $this->isTransitionalDifferent;
    }

    public function isKnown(): bool
    {
        return '' !== $this->section;
    }

    public function isICANN(): bool
    {
        return PublicSuffixListInterface::ICANN_DOMAINS === $this->section;
    }

    public function isPrivate(): bool
    {
        return PublicSuffixListInterface::PRIVATE_DOMAINS === $this->section;
    }

    public function toAscii(): HostInterface
    {
        if (null === $this->publicSuffix) {
            return $this;
        }

        $publicSuffix = $this->idnToAscii($this->publicSuffix, $this->asciiIDNAOption);
        if ($publicSuffix === $this->publicSuffix) {
            return $this;
        }

        return new self($publicSuffix, $this->section, $this->asciiIDNAOption, $this->unicodeIDNAOption);
    }

    public function toUnicode(): HostInterface
    {
        if (null === $this->publicSuffix || false === strpos($this->publicSuffix, 'xn--')) {
            return $this;
        }

        return new self(
            $this->idnToUnicode($this->publicSuffix, $this->unicodeIDNAOption),
            $this->section,
            $this->asciiIDNAOption,
            $this->unicodeIDNAOption
        );
    }

    public function withAsciiIDNAOption(int $option): self
    {
        if ($option === $this->asciiIDNAOption) {
            return $this;
        }

        return new self($this->publicSuffix, $this->section, $option, $this->unicodeIDNAOption);
    }

    public function withUnicodeIDNAOption(int $option): self
    {
        if ($option === $this->unicodeIDNAOption) {
            return $this;
        }

        return new self($this->publicSuffix, $this->section, $this->asciiIDNAOption, $option);
    }
}
