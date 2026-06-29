<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Persistance de la dernière vue carte de l'utilisateur connecté
 * ({lat, lng, zoom, basemap}). Appelé en debounce par le front au
 * pan/zoom. Stockage léger dans `users.map_view` (JSON).
 *
 * Les invités gardent leur vue uniquement en localStorage ; pour un
 * connecté, le profil prime au boot (suivi multi-appareils), avec
 * fallback localStorage puis défaut national.
 */
class MeMapViewController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat'     => ['required', 'numeric', 'between:-90,90'],
            'lng'     => ['required', 'numeric', 'between:-180,180'],
            'zoom'    => ['required', 'integer', 'between:1,20'],
            'basemap' => ['nullable', 'string', 'max:32'],
        ]);

        $user = $request->user();
        $user->map_view = [
            'lat'     => round((float) $data['lat'], 5),
            'lng'     => round((float) $data['lng'], 5),
            'zoom'    => (int) $data['zoom'],
            'basemap' => $data['basemap'] ?? null,
        ];
        $user->save();

        return response()->json(['ok' => true]);
    }
}
