<?php

declare(strict_types=1);

final class Catalogo
{
    private const PREFERIDOS = [
        'diabetes' => ['E11.9', 'E10.9', 'E14.9', 'E11', 'E10'],
        'hipertension' => ['I10', 'I11.9', 'I15.9'],
        'neumonia' => ['J18.9', 'J18', 'J12.9', 'J15.9'],
        'covid-19' => ['U07.1', 'U07.2', 'U09.9'],
        'infarto' => ['I21.9', 'I21.0', 'I21'],
        'tuberculosis' => ['A16.9', 'A15.0', 'A15.9'],
        'asma' => ['J45.9', 'J45'],
        'epilepsia' => ['G40.9', 'G40'],
        'depresion' => ['F32.9', 'F33.9', 'F32'],
        'ansiedad' => ['F41.9', 'F41.1', 'F41'],
        'obesidad' => ['E66.9', 'E66'],
        'anemia' => ['D64.9', 'D50.9'],
        'insuficiencia cardiaca' => ['I50.9', 'I50.0', 'I50'],
        'enfermedad renal cronica' => ['N18.9', 'N18'],
        'infeccion urinaria' => ['N39.0'],
        'accidente cerebrovascular' => ['I64', 'I63.9', 'I61.9'],
        'accidente vascular cerebral' => ['I64', 'I63.9'],
        'fibrilacion auricular' => ['I48.9', 'I48'],
        'reflujo gastroesofagico' => ['K21.9', 'K21.0'],
        'enfermedad pulmonar obstructiva cronica' => ['J44.9', 'J44.1', 'J44.0'],
        'infeccion de vias urinarias' => ['N39.0'],
    ];

    private const SINONIMOS = [
        'hta' => 'hipertension',
        'dm' => 'diabetes mellitus',
        'dm1' => 'diabetes mellitus tipo 1',
        'dm2' => 'diabetes mellitus tipo 2',
        'epoc' => 'enfermedad pulmonar obstructiva cronica',
        'iam' => 'infarto agudo de miocardio',
        'acv' => 'accidente cerebrovascular',
        'avc' => 'accidente vascular cerebral',
        'ave' => 'accidente vascular encefalico',
        'itu' => 'infeccion de vias urinarias',
        'ira' => 'infeccion respiratoria aguda',
        'icc' => 'insuficiencia cardiaca',
        'erc' => 'enfermedad renal cronica',
        'irc' => 'insuficiencia renal cronica',
        'tbc' => 'tuberculosis',
        'tb' => 'tuberculosis',
        'vih' => 'virus de la inmunodeficiencia',
        'sida' => 'inmunodeficiencia humana',
        'covid' => 'covid-19',
        'covid19' => 'covid-19',
        'fa' => 'fibrilacion auricular',
        'rge' => 'reflujo gastroesofagico',
        'erge' => 'reflujo gastroesofagico',
        'ges' => 'ges',
        'infarto' => 'infarto',
        'neumonia' => 'neumonia',
        'cancer' => 'tumor maligno',
    ];

    /** @var list<array<string, mixed>> */
    private array $items;

    /** @var array<string, array<string, mixed>> */
    private array $porCodigo = [];

    /** @var list<string> */
    private array $normas = [];

    public function __construct(string $rutaJson)
    {
        $cache = dirname($rutaJson) . DIRECTORY_SEPARATOR . 'cie10.cache';
        if (is_readable($cache) && filemtime($cache) >= filemtime($rutaJson)) {
            $cacheDatos = unserialize((string) file_get_contents($cache), ['allowed_classes' => false]);
            if (is_array($cacheDatos) && isset($cacheDatos['items'], $cacheDatos['porCodigo'], $cacheDatos['normas'])) {
                $this->items = $cacheDatos['items'];
                $this->porCodigo = $cacheDatos['porCodigo'];
                $this->normas = $cacheDatos['normas'];
                return;
            }
        }

        $crudo = file_get_contents($rutaJson);
        if ($crudo === false) {
            throw new RuntimeException('No se pudo leer el catálogo CIE-10.');
        }

        $datos = json_decode($crudo, true, 512, JSON_THROW_ON_ERROR);
        $this->items = $datos['items'] ?? [];

        foreach ($this->items as $item) {
            $clave = $this->normalizarCodigo((string) $item['codigo']);
            $this->porCodigo[$clave] = $item;
            $this->normas[] = $this->normalizar((string) $item['descripcion']);
        }

        @file_put_contents($cache, serialize([
            'items' => $this->items,
            'porCodigo' => $this->porCodigo,
            'normas' => $this->normas,
        ]), LOCK_EX);
    }

    public function total(): int
    {
        return count($this->items);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function codigo(string $codigo): ?array
    {
        return $this->porCodigo[$this->normalizarCodigo($codigo)] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function buscar(string $consulta, int $limite = 15): array
    {
        $consulta = trim($consulta);
        if ($consulta === '' || mb_strlen($consulta) < 1) {
            return [];
        }

        $limite = max(1, min(50, $limite));
        $normalizada = $this->expandirSinonimos($this->normalizar($consulta));
        $codigoConsulta = $this->normalizarCodigo($consulta);
        $esCodigo = (bool) preg_match('/^[A-Z][A-Z0-9.]{0,6}$/i', str_replace(' ', '', $consulta));
        $palabras = array_values(array_filter(explode(' ', $normalizada), static fn(string $p): bool => $p !== ''));

        $ranqueados = [];
        foreach ($this->items as $i => $item) {
            $puntaje = $this->puntuar($item, $this->normas[$i], $normalizada, $codigoConsulta, $esCodigo, $palabras);
            if ($puntaje <= 0) {
                continue;
            }
            $ranqueados[] = [$puntaje, $item];
        }

        usort($ranqueados, static function (array $a, array $b): int {
            if ($a[0] === $b[0]) {
                return strlen((string) $a[1]['codigo']) <=> strlen((string) $b[1]['codigo']);
            }
            return $b[0] <=> $a[0];
        });

        $salida = [];
        foreach (array_slice($ranqueados, 0, $limite) as [, $item]) {
            $salida[] = $item;
        }

        return $salida;
    }

    /**
     * @return list<array{capitulo: string, nombre: string, total: int}>
     */
    public function capitulos(): array
    {
        $agrupados = [];
        foreach ($this->items as $item) {
            $clave = (string) ($item['capitulo'] ?: 'Otros');
            if (!isset($agrupados[$clave])) {
                $agrupados[$clave] = [
                    'capitulo' => $clave,
                    'nombre' => (string) $item['capitulo_nombre'],
                    'total' => 0,
                ];
            }
            $agrupados[$clave]['total']++;
        }

        return array_values($agrupados);
    }

    /**
     * @param array<string, mixed> $item
     * @param list<string> $palabras
     */
    private function puntuar(array $item, string $descripcion, string $consulta, string $codigoConsulta, bool $esCodigo, array $palabras): int
    {
        $codigo = $this->normalizarCodigo((string) $item['codigo']);
        $uso = (string) $item['uso'];
        $bonusUso = in_array($uso, ['principal', 'causa_externa', 'causa_externa | principal'], true) ? 40 : 0;
        $bonusPreferido = $this->bonusPreferido($consulta, (string) $item['codigo']);

        if ($codigoConsulta !== '' && $codigo === $codigoConsulta) {
            return 1000 + $bonusUso;
        }

        if ($esCodigo && $codigoConsulta !== '' && str_starts_with($codigo, $codigoConsulta)) {
            return 850 - (strlen($codigo) - strlen($codigoConsulta)) * 8 + $bonusUso;
        }

        if ($consulta === '' || $palabras === []) {
            return 0;
        }

        if (str_starts_with($descripcion, $consulta)) {
            return 700 + $bonusUso + $bonusPreferido + $this->bonusTexto($item, $consulta, $descripcion);
        }

        $todas = true;
        $comoPrefijo = true;
        foreach ($palabras as $palabra) {
            if (!str_contains($descripcion, $palabra)) {
                $todas = false;
                $comoPrefijo = false;
                break;
            }
            if (!$this->palabraComoPrefijo($descripcion, $palabra)) {
                $comoPrefijo = false;
            }
        }

        if (!$todas) {
            return 0;
        }

        $bonusTexto = $this->bonusTexto($item, $consulta, $descripcion);
        if ($comoPrefijo) {
            return 520 + $bonusUso + $bonusPreferido + $bonusTexto;
        }

        return 300 + $bonusUso + $bonusPreferido + $bonusTexto;
    }

    private function bonusPreferido(string $consulta, string $codigo): int
    {
        foreach (self::PREFERIDOS as $clave => $codigos) {
            if ($consulta === $clave || str_starts_with($consulta, $clave) || str_starts_with($clave, $consulta)) {
                $pos = array_search($codigo, $codigos, true);
                if ($pos !== false) {
                    return 280 - ((int) $pos * 40);
                }
            }
        }
        return 0;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function bonusTexto(array $item, string $consulta, string $descripcion): int
    {
        $cobertura = (int) round((strlen($consulta) / max(strlen($descripcion), 1)) * 80);
        $habitual = 0;
        if (str_ends_with((string) $item['codigo'], '.9')) {
            $habitual += 35;
        }
        if (str_contains($descripcion, 'sin complicaciones') || str_contains($descripcion, 'no especificad')) {
            $habitual += 15;
        }
        return $cobertura + $habitual;
    }

    private function palabraComoPrefijo(string $texto, string $palabra): bool
    {
        foreach (preg_split('/\s+/', $texto) ?: [] as $token) {
            if (str_starts_with($token, $palabra)) {
                return true;
            }
        }
        return false;
    }

    private function expandirSinonimos(string $consulta): string
    {
        $compacta = str_replace([' ', '-', '.'], '', $consulta);
        if (isset(self::SINONIMOS[$compacta])) {
            return self::SINONIMOS[$compacta];
        }

        $partes = [];
        foreach (explode(' ', $consulta) as $palabra) {
            $partes[] = self::SINONIMOS[$palabra] ?? $palabra;
        }
        return trim(implode(' ', $partes));
    }

    private function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto), 'UTF-8');
        $descompuesto = function_exists('normalizer_normalize')
            ? (normalizer_normalize($texto, Normalizer::FORM_D) ?: $texto)
            : $this->quitarTildes($texto);
        $sinTildes = preg_replace('/\p{Mn}+/u', '', (string) $descompuesto) ?? $descompuesto;
        $limpio = preg_replace('/[^a-z0-9.\s-]+/u', ' ', $sinTildes) ?? $sinTildes;
        return trim(preg_replace('/\s+/', ' ', $limpio) ?? $limpio);
    }

    private function quitarTildes(string $texto): string
    {
        $mapa = [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ];
        return strtr($texto, $mapa);
    }

    private function normalizarCodigo(string $codigo): string
    {
        return strtoupper(str_replace(['.', ' ', '-'], '', trim($codigo)));
    }
}
