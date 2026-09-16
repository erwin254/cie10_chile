<?php

declare(strict_types=1);

final class Snomed
{
    private const BASE = 'https://tx.hl7chile.cl/r4';
    private const SYSTEM = 'http://snomed.info/sct';
    private const VALUESET = 'http://snomed.info/sct?fhir_vs=isa/404684003';
    private const VACIAS = [
        'de', 'del', 'la', 'el', 'los', 'las', 'y', 'o', 'u', 'a', 'en', 'con', 'por',
        'para', 'que', 'un', 'una', 'unos', 'unas', 'al', 'lo', 'se', 'su', 'sus',
        'due', 'to', 'of', 'the', 'and', 'or', 'in', 'with', 'sin', 'otra', 'otro',
        'debida', 'debido', 'asociada', 'asociado', 'causa', 'causada',
    ];

    private const TIPOS = [
        'disorder' => 'Trastorno',
        'finding' => 'Hallazgo',
        'procedure' => 'Procedimiento',
        'situation' => 'Situación',
        'organism' => 'Organismo',
        'body structure' => 'Estructura corporal',
        'substance' => 'Sustancia',
        'observable entity' => 'Entidad observable',
        'qualifier value' => 'Calificador',
        'morphologic abnormality' => 'Anomalía morfológica',
    ];

    public function __construct(private readonly ?Catalogo $cie10 = null)
    {
    }

    public function salud(): array
    {
        $meta = $this->get('/metadata', ['_format' => 'json']);
        return [
            'estado' => isset($meta['resourceType']) ? 'ok' : 'error',
            'nombre' => 'SNOMED CT',
            'edicion' => 'Edición Chile',
            'servidor' => self::BASE,
            'fuente' => 'Servidor terminológico HL7 Chile (tx.hl7chile.cl)',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function buscar(string $consulta, int $limite = 15): array
    {
        $consulta = trim($consulta);
        if ($consulta === '') {
            return [];
        }

        $limite = max(1, min(40, $limite));
        $contains = $this->expandir($consulta, self::VALUESET, $limite);
        if ($contains === []) {
            $contains = $this->expandir($consulta, 'http://snomed.info/sct?fhir_vs', $limite);
        }
        if ($contains === []) {
            return [];
        }

        $cieConsulta = $this->cie10 instanceof Catalogo ? $this->buscarCie10EnTexto($consulta) : null;
        $salida = [];
        foreach ($contains as $item) {
            $codigo = (string) ($item['code'] ?? '');
            if ($codigo === '') {
                continue;
            }
            $detalle = $this->desdeExpansion($item);
            $detalle['tipo'] = $detalle['tipo'] ?? ($detalle['capitulo'] ?: 'Hallazgo clínico');
            if ($this->pareceEspanol((string) $detalle['descripcion'])) {
                $detalle = $this->conCie10($detalle);
            }
            if (empty($detalle['cie10']) && $cieConsulta !== null) {
                $detalle['cie10'] = $cieConsulta['codigo'];
                $detalle['cie10_descripcion'] = $cieConsulta['descripcion'];
            }
            $salida[] = $detalle;
        }

        return $salida;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function codigo(string $codigo): ?array
    {
        $codigo = trim($codigo);
        if ($codigo === '' || !preg_match('/^[0-9]+$/', $codigo)) {
            return null;
        }

        $lookup = $this->get('/CodeSystem/$lookup', [
            'system' => self::SYSTEM,
            'code' => $codigo,
            'displayLanguage' => 'es',
            'property' => 'parent',
            '_format' => 'json',
        ]);

        if (($lookup['resourceType'] ?? '') === 'OperationOutcome' || !isset($lookup['parameter'])) {
            return null;
        }

        return $this->conCie10($this->desdeLookup($codigo, $lookup, []));
    }

    /**
     * @param list<string> $codigos
     * @return array<string, array<string, mixed>>
     */
    private function lookupVarios(array $codigos): array
    {
        $mh = curl_multi_init();
        $handles = [];

        foreach ($codigos as $codigo) {
            $url = self::BASE . '/CodeSystem/$lookup?' . http_build_query([
                'system' => self::SYSTEM,
                'code' => $codigo,
                'displayLanguage' => 'es',
                'property' => 'parent',
                '_format' => 'json',
            ]);
            $ch = $this->curl($url);
            $handles[$codigo] = $ch;
            curl_multi_add_handle($mh, $ch);
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $salida = [];
        foreach ($handles as $codigo => $ch) {
            $codigo = (string) $codigo;
            $body = curl_multi_getcontent($ch);
            $json = json_decode((string) $body, true);
            if (is_array($json) && isset($json['parameter'])) {
                $salida[$codigo] = $this->desdeLookup($codigo, $json, []);
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        return $salida;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function desdeExpansion(array $item): array
    {
        $fsn = $this->fsnDeDesignaciones($item['designation'] ?? []);
        $tipo = $this->tipoDeFsn($fsn);
        return [
            'codigo' => (string) ($item['code'] ?? ''),
            'descripcion' => (string) ($item['display'] ?? ''),
            'categoria' => $tipo,
            'seccion' => $tipo,
            'capitulo' => $tipo,
            'capitulo_nombre' => 'Edición Chile',
            'uso' => 'principal',
            'sistema' => self::SYSTEM,
            'tipo' => $tipo,
            'cie10' => null,
            'cie10_descripcion' => null,
        ];
    }

    /**
     * @param array<string, mixed> $lookup
     * @param list<array<string, mixed>> $designaciones
     * @return array<string, mixed>
     */
    private function desdeLookup(string $codigo, array $lookup, array $designaciones): array
    {
        $display = $codigo;
        $padre = '';
        $padreNombre = '';
        $fsn = $this->fsnDeDesignaciones($designaciones);

        foreach ($lookup['parameter'] ?? [] as $param) {
            $nombre = (string) ($param['name'] ?? '');
            if ($nombre === 'display') {
                $display = (string) ($param['valueString'] ?? $display);
            }
            if ($nombre === 'property' && is_array($param['part'] ?? null)) {
                $props = [];
                foreach ($param['part'] as $part) {
                    $props[(string) ($part['name'] ?? '')] = $part;
                }
                if (($props['code']['valueCode'] ?? '') === 'parent') {
                    $padre = (string) ($props['value']['valueCode'] ?? '');
                    $padreNombre = (string) ($props['description']['valueString'] ?? $padre);
                }
            }
        }

        $tipo = $this->tipoDeFsn($fsn);
        return [
            'codigo' => $codigo,
            'descripcion' => $display,
            'categoria' => $padre !== '' ? $padre : $tipo,
            'seccion' => $padreNombre !== '' ? $padreNombre : $tipo,
            'capitulo' => $tipo,
            'capitulo_nombre' => $padreNombre !== '' ? $padreNombre : 'Edición Chile',
            'uso' => 'principal',
            'sistema' => self::SYSTEM,
            'tipo' => $tipo,
            'cie10' => null,
            'cie10_descripcion' => null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $designaciones
     */
    private function fsnDeDesignaciones(array $designaciones): string
    {
        foreach ($designaciones as $d) {
            $uso = (string) ($d['use']['code'] ?? '');
            if ($uso === '900000000000003001') {
                return (string) ($d['value'] ?? '');
            }
        }
        return '';
    }

    private function tipoDeFsn(string $fsn): string
    {
        if (preg_match('/\(([^)]+)\)\s*$/', $fsn, $m)) {
            $clave = strtolower(trim($m[1]));
            return self::TIPOS[$clave] ?? ucfirst($clave);
        }
        return 'Hallazgo clínico';
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function conCie10(array $item, string ...$extras): array
    {
        $item['cie10'] = $item['cie10'] ?? null;
        $item['cie10_descripcion'] = $item['cie10_descripcion'] ?? null;
        if (!$this->cie10 instanceof Catalogo) {
            return $item;
        }

        $candidatosTexto = [];
        foreach (array_merge([(string) ($item['descripcion'] ?? '')], $extras) as $candidato) {
            $candidato = trim((string) $candidato);
            if ($candidato !== '') {
                $candidatosTexto[] = $candidato;
            }
        }

        foreach (array_unique($candidatosTexto) as $textoBusqueda) {
            $mapeo = $this->buscarCie10EnTexto($textoBusqueda);
            if ($mapeo !== null) {
                $item['cie10'] = $mapeo['codigo'];
                $item['cie10_descripcion'] = $mapeo['descripcion'];
                return $item;
            }
        }

        return $item;
    }

    /**
     * @return array{codigo: string, descripcion: string}|null
     */
    private function buscarCie10EnTexto(string $texto): ?array
    {
        $tokens = $this->tokens($texto);
        $consulta = implode(' ', array_slice($tokens, 0, 6));
        if ($consulta === '') {
            return null;
        }

        $mejor = null;
        $mejorSim = 0.0;
        $candidatos = $this->cie10->buscar($consulta, 8);
        if ($candidatos === [] && count($tokens) > 2) {
            $candidatos = $this->cie10->buscar(implode(' ', array_slice($tokens, 0, 2)), 8);
        }
        foreach ($candidatos as $candidato) {
            $sim = $this->puntajeCie10($tokens, $candidato);
            if ($sim > $mejorSim) {
                $mejorSim = $sim;
                $mejor = $candidato;
            }
        }

        if ($mejor === null || $mejorSim < 0.28) {
            $corto = implode(' ', array_slice($tokens, 0, 2));
            if ($corto !== '' && $corto !== $consulta) {
                foreach ($this->cie10->buscar($corto, 8) as $candidato) {
                    $sim = $this->puntajeCie10($tokens, $candidato) - 0.04;
                    if ($sim > $mejorSim) {
                        $mejorSim = $sim;
                        $mejor = $candidato;
                    }
                }
            }
        }

        if ($mejor !== null && $mejorSim >= 0.28) {
            return [
                'codigo' => (string) $mejor['codigo'],
                'descripcion' => (string) $mejor['descripcion'],
            ];
        }

        return null;
    }

    private function pareceEspanol(string $texto): bool
    {
        return (bool) preg_match('/[áéíóúñü]/i', $texto)
            || (bool) preg_match('/\b(dolor|enfermedad|trastorno|sindrome|síndrome|inflamacion|infeccion|diabetes|hipertension|neumonia| tumores?)\b/iu', $texto);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function expandir(string $filtro, string $valueset, int $limite): array
    {
        $expansion = $this->get('/ValueSet/$expand', [
            'url' => $valueset,
            'filter' => $filtro,
            'displayLanguage' => 'es',
            'count' => $limite,
            '_format' => 'json',
        ]);
        $contains = $expansion['expansion']['contains'] ?? [];
        return is_array($contains) ? $contains : [];
    }

    /**
     * @param list<string> $tokens
     * @param array<string, mixed> $candidato
     */
    private function puntajeCie10(array $tokens, array $candidato): float
    {
        $desc = $this->normalizarTexto((string) $candidato['descripcion']);
        $cieTokens = $this->tokens((string) $candidato['descripcion']);
        $sim = $this->similitud($tokens, $cieTokens);
        $consultaNorm = implode(' ', $tokens);
        if (str_starts_with($desc, $consultaNorm)) {
            $sim += 0.22;
        }
        if (str_ends_with((string) $candidato['codigo'], '.9')) {
            $sim += 0.08;
        }
        if (str_contains($desc, 'no especificad') || str_contains($desc, 'sin complicaciones')) {
            $sim += 0.1;
        }
        if (!in_array('insulinodependiente', $tokens, true) && str_contains($desc, 'insulinodependiente')) {
            $sim -= 0.35;
        }
        if (in_array('1', $tokens, true) && preg_match('/tipo 2\b/', $desc)) {
            $sim -= 0.5;
        }
        if (in_array('2', $tokens, true) && preg_match('/tipo 1\b/', $desc)) {
            $sim -= 0.5;
        }
        if (!in_array('1', $tokens, true) && preg_match('/tipo 1\b/', $desc)) {
            $sim -= 0.2;
        }
        if (!in_array('2', $tokens, true) && preg_match('/tipo 2\b/', $desc)) {
            $sim -= 0.2;
        }
        foreach (['neonatal', 'embarazo', 'obesa', 'ulcera', 'coma', 'madre', 'puerperio'] as $extra) {
            if (!in_array($extra, $tokens, true) && str_contains($desc, $extra)) {
                $sim -= 0.4;
            }
        }
        return $sim;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $texto): array
    {
        $partes = preg_split('/\s+/', $this->normalizarTexto($texto)) ?: [];
        $salida = [];
        foreach ($partes as $parte) {
            if ((strlen($parte) < 2 && !ctype_digit($parte)) || in_array($parte, self::VACIAS, true)) {
                continue;
            }
            $salida[] = $parte;
        }
        return array_values(array_unique($salida));
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private function similitud(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $inter = count(array_intersect($a, $b));
        $union = count(array_unique([...$a, ...$b]));
        return $union > 0 ? $inter / $union : 0.0;
    }

    private function normalizarTexto(string $texto): string
    {
        $texto = mb_strtolower(trim($texto), 'UTF-8');
        $mapa = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ];
        return trim((string) preg_replace('/[^a-z0-9.\s]+/u', ' ', strtr($texto, $mapa)));
    }

    /**
     * @param array<string, scalar> $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query): array
    {
        $url = self::BASE . $path . '?' . http_build_query($query);
        $ch = $this->curl($url);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status >= 400) {
            throw new RuntimeException($error !== '' ? $error : 'El servidor SNOMED-CT no respondió.');
        }

        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            throw new RuntimeException('Respuesta SNOMED-CT inválida.');
        }
        if (($json['resourceType'] ?? '') === 'OperationOutcome') {
            $msg = $json['issue'][0]['diagnostics'] ?? 'Error en SNOMED-CT.';
            throw new RuntimeException((string) $msg);
        }
        return $json;
    }

    /**
     * @return resource|\CurlHandle
     */
    private function curl(string $url)
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => [
                'Accept: application/fhir+json',
                'Accept-Language: es-CL,es;q=0.9',
            ],
        ];
        if (defined('CURLSSLOPT_NATIVE_CA')) {
            $opts[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        }
        curl_setopt_array($ch, $opts);
        return $ch;
    }
}
