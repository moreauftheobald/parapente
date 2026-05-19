<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Weather\Reliability\ReliabilityExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * BackOffice — Export des données de fiabilité (phase 2.5).
 *
 * Trois actions :
 *  - GET /admin/reliability/export/consensus-compare.csv  filtré par
 *    balise + variable courants (lien depuis l'écran compare) ;
 *  - GET /admin/reliability/export/model-reliability.csv  idem côté
 *    fiabilité par modèle ;
 *  - GET /admin/reliability/export.json  payload complet avec tous les
 *    paramètres, le panel, les stats agrégées et les deux datasets.
 *    Destiné à l'analyse externe (Claude chat, R, Python, …).
 *
 * Cf. RELIABILITY_ANALYSIS_CONTEXT.md (racine du repo) pour la doc
 * détaillée du schéma et la méthodologie d'analyse.
 */
class ReliabilityExportController extends Controller
{
    public function __construct(private ReliabilityExportService $service)
    {
    }

    /** Export complet en JSON (paramètres + panel + datasets + stats). */
    public function json(): JsonResponse
    {
        return response()
            ->json($this->service->buildJsonPayload(), 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /** CSV des tuples balise_consensus_compare, filtre optionnel. */
    public function consensusCompareCsv(Request $request): StreamedResponse
    {
        $baliseId = $request->filled('balise')   ? (int) $request->input('balise')   : null;
        $variable = $request->filled('variable') ? (string) $request->input('variable') : null;

        $headers = [
            'balise_id', 'balise_name', 'target_at', 'horizon_bucket', 'variable',
            'consensus_a', 'consensus_b', 'consensus_c', 'observation',
            'err_a', 'err_b', 'err_c',
            'observation_count', 'models_count', 'mad_value', 'computed_at',
        ];
        $rows = $this->service->consensusCompareCsvRows($baliseId, $variable);

        return $this->streamCsv(
            $this->buildFilename('consensus-compare', $baliseId, $variable),
            $headers,
            $rows
        );
    }

    /** CSV des tuples model_reliability, filtre optionnel. */
    public function modelReliabilityCsv(Request $request): StreamedResponse
    {
        $baliseId = $request->filled('balise')   ? (int) $request->input('balise')   : null;
        $variable = $request->filled('variable') ? (string) $request->input('variable') : null;

        $headers = [
            'weather_model_id', 'weather_model_code', 'weather_model_name',
            'balise_id', 'balise_name', 'horizon_bucket', 'variable',
            'mae', 'rmse', 'bias_signed', 'weight_factor', 'samples_n', 'computed_at',
        ];
        $rows = $this->service->modelReliabilityCsvRows($baliseId, $variable);

        return $this->streamCsv(
            $this->buildFilename('model-reliability', $baliseId, $variable),
            $headers,
            $rows
        );
    }

    /**
     * CSV des MAE par horizon (common + full), filtre optionnel.
     * 1 ligne par (balise × variable × bucket × set), 8 lignes par
     * couple (balise, variable) — 4 buckets × 2 sets.
     */
    public function horizonStatsCsv(Request $request): StreamedResponse
    {
        $baliseId = $request->filled('balise')   ? (int) $request->input('balise')   : null;
        $variable = $request->filled('variable') ? (string) $request->input('variable') : null;

        $headers = [
            'balise_id', 'balise_name', 'variable', 'set', 'horizon_bucket',
            'common_targets_count', 'bucket_target_count', 'n',
            'mae_a', 'mae_b', 'mae_c',
        ];
        $rows = $this->service->horizonStatsCsvRows($baliseId, $variable);

        return $this->streamCsv(
            $this->buildFilename('horizon-mae', $baliseId, $variable),
            $headers,
            $rows
        );
    }

    /**
     * Streame un CSV propre (UTF-8 BOM pour Excel, délimiteur virgule,
     * échappement standard).
     *
     * @param iterable<array<string, scalar|null>> $rows
     */
    private function streamCsv(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            // BOM UTF-8 pour qu'Excel reconnaisse l'encodage
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, ',', '"', '\\');
            foreach ($rows as $row) {
                $line = [];
                foreach ($headers as $h) {
                    $v = $row[$h] ?? null;
                    $line[] = $v === null ? '' : (string) $v;
                }
                fputcsv($out, $line, ',', '"', '\\');
            }
            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf(
            'attachment; filename="%s"',
            $filename
        ));
        return $response;
    }

    private function buildFilename(string $prefix, ?int $baliseId, ?string $variable): string
    {
        $parts = [$prefix, now()->format('Y-m-d_His')];
        if ($baliseId !== null) {
            $parts[] = 'balise' . $baliseId;
        }
        if ($variable !== null) {
            $parts[] = $variable;
        }
        return implode('_', $parts) . '.csv';
    }
}
