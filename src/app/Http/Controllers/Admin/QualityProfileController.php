<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\QualityAxis;
use App\Models\QualityProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class QualityProfileController extends Controller
{
    public function index(): View
    {
        $profiles = QualityProfile::orderBy('sort_order')
            ->with('axes')
            ->get();

        return view('admin.quality-profiles.index', [
            'profiles'      => $profiles,
            'availableAxes' => QualityAxis::AVAILABLE_AXES,
        ]);
    }

    public function create(): View
    {
        return view('admin.quality-profiles.form', [
            'profile'       => null,
            'availableAxes' => QualityAxis::AVAILABLE_AXES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'label'      => ['required', 'string', 'max:100'],
            'slug'       => ['nullable', 'string', 'max:50', 'unique:quality_profiles,slug'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'active'     => ['nullable', 'boolean'],
            'axes'       => ['required', 'array'],
            'axes.*.weight'        => ['required', 'integer', 'min:0', 'max:100'],
            'axes.*.scoring_curve' => ['required', 'string'],
        ]);

        $slug = ! empty($data['slug'])
            ? Str::slug($data['slug'])
            : Str::slug($data['label']);

        $profile = QualityProfile::create([
            'label'      => $data['label'],
            'slug'       => $slug,
            'sort_order' => $data['sort_order'],
            'active'     => (bool) ($data['active'] ?? false),
        ]);

        $this->syncAxes($profile, $data['axes']);

        return redirect()
            ->route('admin.quality-profiles.index')
            ->with('status', "Profil « {$profile->label} » créé.");
    }

    public function edit(QualityProfile $qualityProfile): View
    {
        $qualityProfile->load('axes');

        return view('admin.quality-profiles.form', [
            'profile'       => $qualityProfile,
            'availableAxes' => QualityAxis::AVAILABLE_AXES,
        ]);
    }

    public function update(Request $request, QualityProfile $qualityProfile): RedirectResponse
    {
        $data = $request->validate([
            'label'      => ['required', 'string', 'max:100'],
            'slug'       => ['nullable', 'string', 'max:50', Rule::unique('quality_profiles', 'slug')->ignore($qualityProfile->id)],
            'sort_order' => ['required', 'integer', 'min:0'],
            'active'     => ['nullable', 'boolean'],
            'axes'       => ['required', 'array'],
            'axes.*.weight'        => ['required', 'integer', 'min:0', 'max:100'],
            'axes.*.scoring_curve' => ['required', 'string'],
        ]);

        $slug = ! empty($data['slug'])
            ? Str::slug($data['slug'])
            : Str::slug($data['label']);

        $qualityProfile->update([
            'label'      => $data['label'],
            'slug'       => $slug,
            'sort_order' => $data['sort_order'],
            'active'     => (bool) ($data['active'] ?? false),
        ]);

        $this->syncAxes($qualityProfile, $data['axes']);

        return redirect()
            ->route('admin.quality-profiles.index')
            ->with('status', "Profil « {$qualityProfile->label} » mis à jour.");
    }

    public function destroy(QualityProfile $qualityProfile): RedirectResponse
    {
        $label = $qualityProfile->label;
        $qualityProfile->delete();

        return redirect()
            ->route('admin.quality-profiles.index')
            ->with('status', "Profil « {$label} » supprimé.");
    }

    private function syncAxes(QualityProfile $profile, array $axesData): void
    {
        $profile->axes()->delete();

        foreach ($axesData as $axisKey => $axisInput) {
            if (! isset(QualityAxis::AVAILABLE_AXES[$axisKey])) {
                continue;
            }

            $weight = (int) ($axisInput['weight'] ?? 0);
            if ($weight <= 0) {
                continue;
            }

            $curve = json_decode($axisInput['scoring_curve'], true);
            if (! is_array($curve) || empty($curve)) {
                continue;
            }

            $profile->axes()->create([
                'axis'          => $axisKey,
                'weight'        => $weight,
                'scoring_curve' => $curve,
            ]);
        }
    }
}
