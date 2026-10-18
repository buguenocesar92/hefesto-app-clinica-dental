<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Descubrimiento de OIDC contra Authentik ytrue
 * intercambio del código por tokens.
 *
 * POR QUÉ HAY CACHÉ DEL DESCUBRIMIENTO
 * ------------------------------------
 * Authentik publica en `/.well-known/openid-configuration` las URLs de
 * autorización, token y las claves públicas. Consultarlas en cada login
 * agrega una dependencia de red a cada entrada: si Authentik está lento, acá
 * también, y el login es justo lo que no puede fallar cuando se entra a
 * trabajar.
 *
 * Por eso se cachean, con una duración corta (una hora por omisión) y con
 * una clave por URL del emisor. Si Authentik cambia de dominio, la clave
 * cambia con él y no se sirve una configuración vieja.
 *
 * Si la caché no está disponible, se va a la red. El caché es una mejora,
 * nunca un requisito: si Redis está caído, el login sigue funcionando.
 */
class DescubridorOidc
{
    public function __construct(
        private readonly string $emisor,
        private readonly string $clienteId,
        private readonly string $secretoCliente,
        private readonly string $redireccionUri,
        private readonly int $minutosCache = 60,
    ) {}

    /**
     * URL a la que se manda al navegador para autenticarse.
     */
    public function urlDeAutorizacion(Request $request, string $consultorio = ''): string
    {
        $configuracion = $this->descubrimiento();

        $estado = $request->session()->get('estado_oidc');

        if (! is_string($estado) || $estado === '') {
            $estado = bin2hex(random_bytes(16));
            $request->session()->put('estado_oidc', $estado);
        }

        $parametros = [
            'client_id' => $this->clienteId,
            'redirect_uri' => $this->redireccionUri,
            'response_type' => 'code',
            'scope' => 'openid profile email groups',
            'state' => $estado,
            'nonce' => bin2hex(random_bytes(16)),
        ];

        if ($consultorio !== '') {
            $parametros['login_hint'] = $consultorio;
        }

        return $configuracion['authorization_endpoint'] . '?' . http_build_query($parametros);
    }

    /**
     * Canjea el código de autorización por tokens.
     *
     * @return array<string, mixed>
     */
    public function intercambiarCodigoPorTokens(string $codigo): array
    {
        $configuracion = $this->descubrimiento();

        $respuesta = Http::asForm()
            ->withBasicAuth($this->clienteId, $this->secretoCliente)
            ->timeout(10)
            ->retry(2, 200)
            ->post($configuracion['token_endpoint'], [
                'grant_type' => 'authorization_code',
                'code' => $codigo,
                'redirect_uri' => $this->redireccionUri,
            ]);

        if (! $respuesta->successful()) {
            throw new RuntimeException(
                'Authentik rechazó el intercambio del código. Estado: ' . $respuesta->status()
            );
        }

        $cuerpo = $respuesta->json();

        return is_array($cuerpo) ? $cuerpo : [];
    }

    /**
     * Claves públicas de verificación, en formato que entiende
     * `firebase/php-jwt`.
     *
     * @return array<string, string> identificador de clave => PEM
     */
    public function clavesPublicas(): array
    {
        $configuracion = $this->descubrimiento();

        $respuesta = Http::timeout(10)->retry(2, 200)->get($configuracion['jwks_uri']);

        if (! $respuesta->successful()) {
            throw new RuntimeException(
                'No se pudieron obtener las claves públicas de Authentik. Estado: ' . $respuesta->status()
            );
        }

        $claves = $respuesta->json('keys');

        if (! is_array($claves)) {
            throw new RuntimeException('La respuesta de claves de Authentik no es la esperada.');
        }

        $mapa = [];

        foreach ($claves as $clave) {
            if (! is_array($clave)) {
                continue;
            }

            $identificador = $clave['kid'] ?? null;
            $modulo = $clave['n'] ?? null;
            $exponente = $clave['e'] ?? null;

            if (! is_string($identificador) || ! is_string($exponente) || ! is_string($modulo)) {
                continue;
            }

            // El mapa se indexa por identificador de clave porque eso es lo
            // que trae el `kid` del token y lo que usa `firebase/php-jwt` para
            // elegir con qué clave verificar.
            $mapa[$identificador] = $this->pemDesdeJwk($exponente, $modulo);
        }

        if ($mapa === []) {
            throw new RuntimeException('Authentik no devolvió ninguna clave utilizable.');
        }

        return $mapa;
    }

    /**
     * Configuración del emisor, desde el descubrimiento de OIDC.
     *
     * @return array<string, string>
     */
    private function descubrimiento(): array
    {
        $url = rtrim($this->emisor, '/') . '/.well-known/openid-configuration';

        $respuesta = Cache::remember('oidc:descubrimiento:' . $url, $this->minutosCache * 60, function () use ($url): array {
            $respuesta = Http::timeout(10)->retry(2, 200)->get($url);

            if (! $respuesta->successful()) {
                throw new RuntimeException(
                    'Authentik no respondió el descubrimiento de OIDC. Estado: ' . $respuesta->status()
                );
            }

            $cuerpo = $respuesta->json();

            if (! is_array($cuerpo)) {
                throw new RuntimeException('El descubrimiento de OIDC no devolvió un objeto válido.');
            }

            return $cuerpo;
        });

        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $clave) {
            if (! isset($respuesta[$clave]) || ! is_string($respuesta[$clave])) {
                throw new RuntimeException(
                    'El descubrimiento de OIDC de Authentik no trae ' . $clave . '. Revise config/authentik.php.'
                );
            }
        }

        /** @var array<string, string> $respuesta */
        return $respuesta;
    }

    /**
     * Convierte una clave JWK (RSA) en PEM, que es lo que verifica la firma.
     *
     * Se arma a mano en lugar de agregar una dependencia a un archivo que se
     * ejecuta en cada login. La estructura es la de una clave publica RSA en
     * ASN.1 (`SubjectPublicKeyInfo`), tal como la publica Authentik.
     *
     * @param  string  $exponente  valor `e` en base64 url-safe
     * @param  string  $modulo  valor `n` en base64 url-safe
     */
    private function pemDesdeJwk(string $exponente, string $modulo): string
    {
        $exponenteBinario = $this->base64UrlABinario($exponente);
        $moduloBinario = $this->base64UrlABinario($modulo);

        // Identificador de objeto de `rsaEncryption` (1.2.840.113549.1.1.1),
        // seguido de un NULL que en el algoritmo dice "sin parámetros".
        $oid = "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01";
        $algoritmo = "\x30" . $this->derLongitud(strlen($oid) + 2) . $oid . "\x05\x00";

        // El exponente va delante del módulo, ambos como enteros.
        $clave = $this->derEntero($exponenteBinario) . $this->derEntero($moduloBinario);
        $claveSecuencia = "\x30" . $this->derLongitud(strlen($clave)) . $clave;

        // La cadena de bits lleva delante el número de bits sin usar, que acá
        // es cero porque la secuencia ya es múltiplo de ocho.
        $bits = "\x03" . $this->derLongitud(strlen($claveSecuencia) + 1) . "\x00" . $claveSecuencia;

        $secuencia = "\x30" . $this->derLongitud(strlen($algoritmo) + strlen($bits))
            . $algoritmo
            . $bits;

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($secuencia), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private function derEntero(string $binario): string
    {
        // Un entero ASN.1 lleva un 0x00 inicial si el bit más alto está en 1,
        // para que no se lea como negativo.
        if (ord($binario[0]) & 0x80) {
            $binario = "\x00" . $binario;
        }

        return "\x02" . $this->derLongitud(strlen($binario)) . $binario;
    }

    private function derLongitud(int $longitud): string
    {
        if ($longitud < 0x80) {
            return chr($longitud);
        }

        $bytes = ltrim(pack('N', $longitud), "\x00");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private function base64UrlABinario(string $valor): string
    {
        $relleno = strlen($valor) % 4;

        if ($relleno !== 0) {
            $valor .= str_repeat('=', 4 - $relleno);
        }

        $binario = base64_decode(strtr($valor, '-_', '+/'), true);

        if ($binario === false) {
            throw new RuntimeException('La clave de Authentik no es base64 válido.');
        }

        return $binario;
    }
}