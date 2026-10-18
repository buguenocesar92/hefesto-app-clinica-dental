<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Consultorio;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Identidad contra Authentik por OIDC, con cookies de sesión.
 *
 * POR QUÉ NO HAY CONTRASEÑAS EN ESTA APLICACIÓN
 * ---------------------------------------------
 * Authentik ya resuelve contraseñas, segundo factor, recuperación y bloqueo
 * de cuentas. Reimplementarlo acá sería peor en todos los sentidos, y sin
 * una revisión de seguridad es exactamente el tipo de cosa que no se
 * improvisa. Esta aplicación solo consume un `id_token` firmado y verifica
 * su firma.
 *
 * CÓMO SE VERIFICA LA FIRMA
 * -------------------------
 * Con `firebase/php-jwt`, contra las claves públicas que publica el propio
 * Authentik en su descubrimiento. Ver `VerificadorIdToken`, que además
 * comprueba `iss`, `aud` y `exp`.
 *
 * EL CONSULTORIO VIENE DEL TOKEN
 * -----------------------------
 * De los grupos. El grupo `consultorio-<id>` declara a qué consultorio
 * pertenece la persona, y el rol se toma del equipo correspondiente. Si no
 * hay grupo de consultorio, el login falla con un mensaje claro en vez de
 * crear un usuario sin consultorio: un usuario sin consultorio no puede
 * operar y terminaría en un 403 más adelante, que es más difícil de
 * entender.
 */
class ProveedorOidcAuthentik
{
    /** Prefijo del grupo que declara el consultorio. */
    public const PREFIJO_GRUPO_CONSULTORIO = 'consultorio-';

    public function __construct(
        private readonly DescubridorOidc $descubridor,
        private readonly string $emisor = '',
        private readonly string $clienteId = '',
    ) {}

    /**
     * Redirige el navegador a la pantalla de Authentik.
     */
    public function redirigir(Request $request, string $consultorio = ''): \Illuminate\Http\RedirectResponse
    {
        $url = $this->descubridor->urlDeAutorizacion($request, $consultorio);

        return redirect()->away($url);
    }

    /**
     * Cambia el código de autorización por tokens y valida el id_token.
     *
     * @return array<string, mixed> claims verificados
     */
    public function validarCallback(Request $request): array
    {
        $codigo = $request->query('code');

        if (! is_string($codigo) || $codigo === '') {
            throw new RuntimeException('Authentik no devolvió un código de autorización.');
        }

        $tokens = $this->descubridor->intercambiarCodigoPorTokens($codigo);

        if (! isset($tokens['id_token']) || ! is_string($tokens['id_token'])) {
            throw new RuntimeException('La respuesta de Authentik no trae id_token.');
        }

        return (new VerificadorIdToken(
            clavesPublicas: $this->descubridor->clavesPublicas(),
            emisorEsperado: $this->emisor,
            audienciaEsperada: $this->clienteId,
        ))->verificar($tokens['id_token']);
    }

    /**
     * Traduce los claims a un usuario del consultorio, o null si la cuenta no
     * está registrada.
     *
     * Busca el usuario por correo dentro del consultorio que declara el
     * token. El correo es el identificador de negocio; el `sub` de Authentik
     * se guarda aparte como `idioma_authentik`, para no atar el historial
     * clínico a un identificador del proveedor.
     *
     * @param  array<string, mixed>  $claims
     */
    public function usuarioDesdeClaims(array $claims): ?Usuario
    {
        $correo = $this->correoDesde($claims);
        $grupos = $this->gruposDesde($claims);
        $consultorioId = $this->consultorioDesde($grupos);

        if ($correo === null || $consultorioId === null) {
            return null;
        }

        $consultorio = Consultorio::query()->find($consultorioId);

        if ($consultorio === null || ! $consultorio->activo) {
            Log::warning('Intento de login contra un consultorio inexistente o inactivo.', [
                'consultorio_id' => $consultorioId,
                'correo' => $correo,
            ]);

            return null;
        }

        $usuario = Usuario::query()
            ->where('tenant_id', $consultorioId)
            ->where('correo', $correo)
            ->first();

        if ($usuario === null || ! $usuario->activo) {
            // No se crea al vuelo. Un consultorio decide quién entra; si el
            // alta es automática, cualquiera con una cuenta de Authentik
            // obtiene acceso a historias clínicas.
            return null;
        }

        $usuario->forceFill([
            'ultimo_acceso' => now(),
            'idioma_authentik' => $this->subDesde($claims),
        ])->save();

        return $usuario;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function correoDesde(array $claims): ?string
    {
        foreach (['email', 'preferred_username', 'sub'] as $clave) {
            $valor = $claims[$clave] ?? null;

            if (is_string($valor) && $valor !== '' && str_contains($valor, '@')) {
                return mb_strtolower($valor);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return list<string>
     */
    private function gruposDesde(array $claims): array
    {
        $grupos = [];

        foreach (['groups', 'groups_claim', 'roles'] as $clave) {
            $valor = $claims[$clave] ?? null;

            if (is_array($valor)) {
                foreach ($valor as $grupo) {
                    if (is_string($grupo)) {
                        $grupos[] = $grupo;
                    }
                }
            }
        }

        return $grupos;
    }

    /**
     * @param  list<string>  $grupos
     */
    private function consultorioDesde(array $grupos): ?string
    {
        foreach ($grupos as $grupo) {
            if (! str_starts_with($grupo, self::PREFIJO_GRUPO_CONSULTORIO)) {
                continue;
            }

            $resto = substr($grupo, strlen(self::PREFIJO_GRUPO_CONSULTORIO));

            if ($resto !== '') {
                return $resto;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function subDesde(array $claims): ?string
    {
        $sub = $claims['sub'] ?? null;

        return is_string($sub) ? $sub : null;
    }
}