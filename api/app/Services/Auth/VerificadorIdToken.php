<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;

/**
 * Verificación del id_token que entrega Authentik.
 *
 * QUÉ COMPRUEBA
 * -------------
 * 1. La firma, con la clave pública que corresponde al `kid` del token.
 * 2. El emisor (`iss`): que el token venga del Authentik configurado.
 * 3. La audiencia (`aud`): que sea para esta aplicación y no para otra.
 * 4. El algoritmo: solo RS256. Sin esto, un token con `alg: none` sería
 *    aceptado.
 * 5. La expiración (`exp`) y, si viene, la de no uso antes de (`nbf`).
 *
 * POR QUÉ VERIFICAR LA FIRMA Y NO CONFIAR EN EL CONTENIDO
 * -------------------------------------------------------
 * Un token firmado es la prueba de que quien lo emitió es Authentik. Sin
 * verificar la firma, el contenido es texto que cualquiera puede escribir, y
 * con él obtener acceso a historias clínicas.
 */
class VerificadorIdToken
{
    /**
     * @param  array<string, string>  $clavesPublicas  identificador de clave => PEM
     */
    public function __construct(
        private readonly array $clavesPublicas,
        private readonly string $emisorEsperado = '',
        private readonly string $audienciaEsperada = '',
    ) {}

    /**
     * Verifica el token y devuelve sus claims.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException si el token no es válido.
     */
    public function verificar(string $token): array
    {
        $claves = [];

        foreach ($this->clavesPublicas as $identificador => $pem) {
            $claves[$identificador] = new Key($pem, 'RS256');
        }

        if ($claves === []) {
            throw new RuntimeException('No hay claves públicas para verificar el token.');
        }

        try {
            $decodificado = JWT::decode($token, $claves);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'El token de Authentik no es válido o su firma no se pudo verificar: ' . $e->getMessage(),
                previous: $e,
            );
        }

        $claims = (array) $decodificado;

        if ($this->emisorEsperado !== '' && ($claims['iss'] ?? null) !== $this->emisorEsperado) {
            throw new RuntimeException('El token viene de un emisor que no es el configurado.');
        }

        if ($this->audienciaEsperada !== '' && ! $this->audienciaCoincide($claims)) {
            throw new RuntimeException('El token no es para esta aplicación.');
        }

        return $claims;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function audienciaCoincide(array $claims): bool
    {
        $audiencia = $claims['aud'] ?? null;

        if (is_string($audiencia)) {
            return $audiencia === $this->audienciaEsperada;
        }

        if (is_array($audiencia)) {
            return in_array($this->audienciaEsperada, $audiencia, true);
        }

        return false;
    }
}