<?php

declare(strict_types=1);

namespace FeerSzo\Hybrid\Dto;

/**
 * Profil dostępności uczestnika (domena TI — Tyfloinformatyka).
 *
 * Niezależny od tego, czy TI działa w tym samym procesie co Dydaktyka,
 * czy jest osobnym serwisem — to jedyny "kształt danych", jaki Dydaktyka
 * zna o profilu uczestnika.
 */
final class AccessibilityProfileDto
{
    /**
     * @param string[] $assistiveTools               narzędzia asystujące, np. ["czytnik ekranu", "lupa ekranowa"]
     * @param string[] $preferredMaterialFormats      preferowane formaty, np. ["audio", "braille", "duza_czcionka"]
     * @param string[] $tyfloTechnologies              konkretne technologie tyflo w użyciu, np. ["NVDA", "JAWS"]
     */
    public function __construct(
        public readonly int $clientId,
        public readonly array $assistiveTools = [],
        public readonly array $preferredMaterialFormats = [],
        public readonly array $tyfloTechnologies = [],
        public readonly string $notes = '',
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            clientId: (int)($data['client_id'] ?? $data['clientId'] ?? 0),
            assistiveTools: array_values((array)($data['assistive_tools'] ?? $data['assistiveTools'] ?? [])),
            preferredMaterialFormats: array_values((array)($data['preferred_material_formats'] ?? $data['preferredMaterialFormats'] ?? [])),
            tyfloTechnologies: array_values((array)($data['tyflo_technologies'] ?? $data['tyfloTechnologies'] ?? [])),
            notes: (string)($data['notes'] ?? ''),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'client_id'                   => $this->clientId,
            'assistive_tools'             => $this->assistiveTools,
            'preferred_material_formats'  => $this->preferredMaterialFormats,
            'tyflo_technologies'          => $this->tyfloTechnologies,
            'notes'                       => $this->notes,
        ];
    }
}
