/**
 * Abstrakcja podpisu elektronicznego. Sterowniki są szkieletami — realny podpis kwalifikowany
 * wymaga: PKCS#11 (karta/token, np. pkcs11js + biblioteka dostawcy), przekierowania do profilu
 * zaufanego (deep-link) albo e-Dowodu, oraz budowy PAdES (np. przez usługę EU DSS).
 * Celowo rzucają błąd, zamiast udawać podpis.
 */
export interface Signer {
  readonly kind: 'pkcs11' | 'trusted_profile' | 'edowod';
  signPades(pdf: Buffer): Promise<Buffer>;
}

class NotImplementedSigner implements Signer {
  constructor(readonly kind: Signer['kind'], private readonly hint: string) {}
  async signPades(): Promise<Buffer> {
    throw new Error(`Podpis „${this.kind}” nie jest jeszcze skonfigurowany. ${this.hint}`);
  }
}

export function getSigner(kind: Signer['kind']): Signer {
  switch (kind) {
    case 'pkcs11':
      return new NotImplementedSigner(kind, 'Wskaż bibliotekę PKCS#11 dostawcy karty i zaimplementuj C_Sign + PAdES.');
    case 'trusted_profile':
      return new NotImplementedSigner(kind, 'Wymaga integracji z usługą podpisu (przekierowanie + callback ezdclient://sign).');
    case 'edowod':
      return new NotImplementedSigner(kind, 'Wymaga czytnika NFC/PKCS#11 i aplikacji e-Dowód.');
  }
}
